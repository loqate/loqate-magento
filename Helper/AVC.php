<?php

namespace Loqate\ApiIntegration\Helper;

class AVC {
    private string $code;
    private int $matchscore;
    private string $verificationStatus;
    private const VERIFICATION_STATUS_RANKING = [
        'V' => 5, // Verified
        'P' => 4, // Partially Verified
        'A' => 3, // Ambiguous
        'R' => 2, // Reverted
        'U' => 1, // Unverified
    ];
    private int $postMatchLevel;
    private int $preMatchLevel;
    private string $parsingStatus;
    private const PARSING_STATUS_RANKING = [
        'I' => 2, // Identified and Parsed
        'U' => 1, // Unable to parse
    ];
    private int $lexiconIdentificationMatchLevel;
    private int $contextIdentificationMatchLevel;
    private string $postcodeStatus;
    private int $postcodeStatusNumeric;

    public function __construct(string $code) {
        $this->code = $code;
        $this->parse();
    }

    private function parse(): void
    {
        $parts = explode('-', $this->code);

        if (count($parts) !== 4) {
            throw new InvalidArgumentException("Invalid AVC format. Expected 4 parts.");
        }

        [$part1, $part2, $this->postcodeStatus, $matchscore] = $parts;

        $this->matchscore = $this->numericOrThrow($matchscore, 'Matchscore');

        [$this->verificationStatus, $postMatchLevelString, $preMatchLevelString] = str_split($part1);

        if(!array_key_exists($this->verificationStatus, self::VERIFICATION_STATUS_RANKING)) {
            throw new InvalidArgumentException("Invalid Verification Status: {$this->verificationStatus}");
        }

        $this->postMatchLevel = $this->numericOrThrow($postMatchLevelString, 'Post-Processed Verification Match Level');

        $this->preMatchLevel = $this->numericOrThrow($preMatchLevelString, 'Pre-Processed Verification Match Level');

        [$this->parsingStatus, $lexiconLevelString, $contextLevelString] = str_split($part2);

        if(!array_key_exists($this->parsingStatus, self::PARSING_STATUS_RANKING)) {
            throw new InvalidArgumentException("Invalid Parsing Status: {$this->parsingStatus}");
        }

        $this->lexiconIdentificationMatchLevel = $this->numericOrThrow($lexiconLevelString, 'Lexicon Identification Match Level');

        $this->contextIdentificationMatchLevel = $this->numericOrThrow($contextLevelString, 'Context Identification Match Level');

        $this->postcodeStatusNumeric = $this->postcodeStatusToNumeric($this->postcodeStatus);

    }

    private function numericOrThrow(string $value, string $valueName): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("$valueName must be numeric.");
        }
        return (int)$value;
    }

    private function postcodeStatusToNumeric(string $value): int
    {
        $parts = str_split($value);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Invalid Postcode Status format. Expected 2 parts.");
        }
        return $this->numericOrThrow($parts[1], 'Postcode Status');
    }

    public function compareTo(AVC $other): array
    {
        $fields = [
            'matchscore' => [$this->matchscore, $other->matchscore],
            'verificationStatus' => [self::VERIFICATION_STATUS_RANKING[$this->verificationStatus], self::VERIFICATION_STATUS_RANKING[$other->verificationStatus]],
            'postMatchLevel' => [$this->postMatchLevel, $other->postMatchLevel],
            'preMatchLevel' => [$this->preMatchLevel, $other->preMatchLevel],
            'parsingStatus' => [self::PARSING_STATUS_RANKING[$this->parsingStatus], self::PARSING_STATUS_RANKING[$other->parsingStatus]],
            'lexiconIdentificationMatchLevel' => [$this->lexiconIdentificationMatchLevel, $other->lexiconIdentificationMatchLevel],
            'contextIdentificationMatchLevel' => [$this->contextIdentificationMatchLevel, $other->contextIdentificationMatchLevel],
            'postcodeStatus' => [$this->postcodeStatusNumeric, $other->postcodeStatusNumeric],
        ];

        $results = [];
        $hasBetter = false;
        $hasPoorer = false;

        foreach ($fields as $field => [$thisVal, $otherVal]) {
            if ($thisVal > $otherVal) {
                $results[$field] = 'better';
                $hasBetter = true;
            } elseif ($thisVal < $otherVal) {
                $results[$field] = 'poorer';
                $hasPoorer = true;
            } else {
                $results[$field] = 'equal';
            }
        }

        if ($hasPoorer) {
            $results['overall'] = 'poorer';
        } elseif ($hasBetter) {
            $results['overall'] = 'better';
        } else {
            $results['overall'] = 'equal';
        }

        return $results;
    }
}