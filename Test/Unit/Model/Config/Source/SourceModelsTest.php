<?php

declare(strict_types=1);

namespace Loqate\ApiIntegration\Test\Unit\Model\Config\Source;

use Loqate\ApiIntegration\Model\Config\Source\AVCContextIdentificationMatchLevel;
use Loqate\ApiIntegration\Model\Config\Source\AVCLexiconIdentificationMatchLevel;
use Loqate\ApiIntegration\Model\Config\Source\AVCParsingStatus;
use Loqate\ApiIntegration\Model\Config\Source\AVCPostcodeStatus;
use Loqate\ApiIntegration\Model\Config\Source\AVCPostProcessedMatchLevel;
use Loqate\ApiIntegration\Model\Config\Source\AVCPreProcessedMatchLevel;
use Loqate\ApiIntegration\Model\Config\Source\AVCVerificationStatus;
use Loqate\ApiIntegration\Model\Config\Source\AddressQualityIndex;
use Magento\Framework\Data\OptionSourceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the admin config option-source models.
 *
 * These back the AVC threshold dropdowns in Stores > Configuration > Loqate,
 * so the exact option values must stay aligned with what Helper\Validator and
 * Helper\AVC expect to compare against.
 */
class SourceModelsTest extends TestCase
{
    /**
     * @return array<string, array{0: OptionSourceInterface, 1: list<string>}>
     */
    public static function optionValuesProvider(): array
    {
        return [
            'verification status' => [new AVCVerificationStatus(), ['V', 'P', 'A', 'R', 'U']],
            'parsing status'      => [new AVCParsingStatus(), ['I', 'U']],
            'address quality'     => [new AddressQualityIndex(), ['A', 'B', 'C', 'D', 'E']],
            'postcode status'     => [new AVCPostcodeStatus(), ['P8', 'P7', 'P6', 'P5', 'P4', 'P3', 'P2', 'P1', 'P0']],
            'post-processed'      => [new AVCPostProcessedMatchLevel(), ['5', '4', '3', '2', '1', '0']],
            'pre-processed'       => [new AVCPreProcessedMatchLevel(), ['5', '4', '3', '2', '1', '0']],
            'lexicon level'       => [new AVCLexiconIdentificationMatchLevel(), ['5', '4', '3', '2', '1', '0']],
            'context level'       => [new AVCContextIdentificationMatchLevel(), ['5', '4', '3', '2', '1', '0']],
        ];
    }

    /**
     * @param list<string> $expectedValues
     */
    #[DataProvider('optionValuesProvider')]
    public function testToOptionArrayExposesExpectedValuesInOrder(
        OptionSourceInterface $source,
        array $expectedValues
    ): void {
        $options = $source->toOptionArray();

        $actualValues = array_map(
            static fn ($option) => (string)$option['value'],
            $options
        );

        $this->assertSame($expectedValues, $actualValues);
    }

    /**
     * @param list<string> $expectedValues
     */
    #[DataProvider('optionValuesProvider')]
    public function testToOptionArrayEntriesAreWellFormed(
        OptionSourceInterface $source,
        array $expectedValues
    ): void {
        $options = $source->toOptionArray();

        $this->assertCount(count($expectedValues), $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertNotSame('', (string)$option['label'], 'Every option must have a non-empty label.');
        }
    }

    /**
     * @param list<string> $expectedValues
     */
    #[DataProvider('optionValuesProvider')]
    public function testToArrayIsKeyedByValue(
        OptionSourceInterface $source,
        array $expectedValues
    ): void {
        if (!method_exists($source, 'toArray')) {
            $this->markTestSkipped('Source does not expose toArray().');
        }

        $keys = array_map(
            static fn ($entry) => (string)array_key_first($entry),
            $source->toArray()
        );

        $this->assertSame($expectedValues, $keys);
    }
}
