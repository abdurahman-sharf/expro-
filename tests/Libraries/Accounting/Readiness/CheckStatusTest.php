<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckStatus;
use CodeIgniter\Test\CIUnitTestCase;

class CheckStatusTest extends CIUnitTestCase
{
    public function testBlocks_IsTrueForAFailureAndForACheckThatBroke(): void
    {
        $this->assertTrue(CheckStatus::Fail->blocks());
        $this->assertTrue(CheckStatus::Error->blocks());
    }

    public function testBlocks_IsFalseForWhatPassedWhatIsForReviewAndWhatWasSkipped(): void
    {
        $this->assertFalse(CheckStatus::Pass->blocks());
        $this->assertFalse(CheckStatus::Review->blocks());
        $this->assertFalse(CheckStatus::Skipped->blocks());
    }

    public function testValue_IsTheWordTheReportPrints(): void
    {
        $this->assertSame(['pass', 'fail', 'review', 'skipped', 'error'], array_map(static fn (CheckStatus $status): string => $status->value, CheckStatus::cases()));
    }
}
