<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\TieCheck;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * What a report adds up beside what the books say: equal figures tie, a figure apart does not, and a
 * tolerance lets a cent of rounding through in either direction and nothing more.
 */
class TieCheckTest extends CIUnitTestCase
{
    public function testTies_AgreesOnlyWhenTheFiguresAreEqualWithoutATolerance(): void
    {
        $this->assertTrue((new TieCheck('k', '10.0000', '10.0000'))->ties());
        $this->assertFalse((new TieCheck('k', '10.0001', '10.0000'))->ties());
        $this->assertFalse((new TieCheck('k', '9.9999', '10.0000'))->ties());
        $this->assertSame('0.0001', (new TieCheck('k', '10.0001', '10.0000'))->difference());
    }

    public function testTies_LetsARoundingThroughInEitherDirectionAndNothingBeyond(): void
    {
        $this->assertTrue((new TieCheck('k', '10.0100', '10.0000', '0.0100'))->ties());
        $this->assertTrue((new TieCheck('k', '9.9900', '10.0000', '0.0100'))->ties());
        $this->assertFalse((new TieCheck('k', '10.0200', '10.0000', '0.0100'))->ties());
        $this->assertFalse((new TieCheck('k', '9.9800', '10.0000', '0.0100'))->ties());
    }

    public function testRefused_NamesTheCheckAndTheReasonAndNeverTies(): void
    {
        $tie = TieCheck::refused('Label.key', 'Reason.key', ['USD']);

        $this->assertTrue($tie->isRefused());
        $this->assertFalse($tie->ties());
        $this->assertSame(['Label.key', 'Reason.key', ['USD']], [$tie->labelKey, $tie->refusal, $tie->refusalArguments]);
        $this->assertFalse((new TieCheck('k', '1.0000', '1.0000'))->isRefused());
    }
}
