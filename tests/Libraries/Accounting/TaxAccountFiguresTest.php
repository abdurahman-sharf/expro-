<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\TaxAccountFigures;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxAccountFiguresTest extends CIUnitTestCase
{
    private function figures(string $movement, string $reported, int $others): TaxAccountFigures
    {
        return new TaxAccountFigures(7, '215001', 'Tax collected', ['output'], $movement, $reported, $others);
    }

    public function testOther_IsWhatTheBooksHoldLessWhatTheReportCounts(): void
    {
        $this->assertSame('-100.0000', $this->figures('198.5000', '298.5000', 1)->other());
        $this->assertSame('15.0000', $this->figures('15.0000', '0.0000', 1)->other());
        $this->assertSame('0.0000', $this->figures('298.5000', '298.5000', 0)->other());
    }

    public function testTies_IsTrueWhenNothingBeyondTheDocumentsReachedTheAccount(): void
    {
        $this->assertTrue($this->figures('298.5000', '298.5000', 0)->ties());
        $this->assertTrue($this->figures('0.0000', '0.0000', 0)->ties());
    }

    public function testTies_IsFalseWhenTheBalanceDiffers(): void
    {
        $this->assertFalse($this->figures('198.5000', '298.5000', 1)->ties());
        $this->assertFalse($this->figures('15.0000', '0.0000', 0)->ties());
    }

    public function testTies_IsFalseWhenJournalsOfOtherKindsLeftTheBalanceWhereItWas(): void
    {
        $this->assertFalse($this->figures('298.5000', '298.5000', 2)->ties(), 'A manual journal and its reversal add up to nothing and are still not tax of a document');
    }
}
