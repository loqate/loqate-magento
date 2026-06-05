<?php

declare(strict_types=1);

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiIntegration\Helper\AVC;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AVC (Address Verification Code) comparison logic.
 *
 * An AVC code has the form "<status><post><pre>-<parse><lex><ctx>-<postcode>-<matchscore>",
 * e.g. "P40-U00-P0-95". compareTo() grades every field and returns an "overall"
 * verdict: "better" only if at least one field is better and none is poorer;
 * "poorer" wins as soon as any single field is poorer.
 */
class AVCTest extends TestCase
{
    public function testIdenticalCodesAreEqual(): void
    {
        $avc = new AVC('P40-U00-P0-95');

        $result = $avc->compareTo(new AVC('P40-U00-P0-95'));

        $this->assertSame('equal', $result['overall']);
    }

    public function testStrongerAddressIsBetterAcrossEveryField(): void
    {
        $candidate = new AVC('V55-I22-P9-99');

        $result = $candidate->compareTo(new AVC('P40-U00-P0-95'));

        $this->assertSame('better', $result['overall']);
        $this->assertSame('better', $result['verificationStatus']);
        $this->assertSame('better', $result['matchscore']);
        $this->assertSame('better', $result['parsingStatus']);
        $this->assertSame('better', $result['postcodeStatus']);
    }

    public function testWeakerAddressIsPoorer(): void
    {
        $candidate = new AVC('P40-U00-P0-95');

        $result = $candidate->compareTo(new AVC('V55-I22-P9-99'));

        $this->assertSame('poorer', $result['overall']);
    }

    /**
     * A single poorer field must drag the overall verdict to "poorer", even when
     * other fields are better - this is what protects the verification threshold.
     */
    public function testAnySinglePoorerFieldMakesOverallPoorer(): void
    {
        // Higher matchscore (99 > 95) but weaker verification status (P < V).
        $candidate = new AVC('P40-U00-P0-99');

        $result = $candidate->compareTo(new AVC('V40-U00-P0-95'));

        $this->assertSame('better', $result['matchscore']);
        $this->assertSame('poorer', $result['verificationStatus']);
        $this->assertSame('poorer', $result['overall']);
    }
}
