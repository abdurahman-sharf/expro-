<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\TrialBalanceType;
use CodeIgniter\Test\CIUnitTestCase;

class TrialBalanceTypeTest extends CIUnitTestCase
{
    public function testFromRequest_ReadsTheFourLayoutsByTheirNames(): void
    {
        $this->assertSame(TrialBalanceType::Balances, TrialBalanceType::fromRequest('balances'));
        $this->assertSame(TrialBalanceType::TotalsAndBalances, TrialBalanceType::fromRequest('totals_balances'));
        $this->assertSame(TrialBalanceType::Movement, TrialBalanceType::fromRequest('movement'));
        $this->assertSame(TrialBalanceType::ReviewTotals, TrialBalanceType::fromRequest('review_totals'));
    }

    public function testFromRequest_FallsBackToTotalsAndBalancesForAnythingElse(): void
    {
        $this->assertSame(TrialBalanceType::TotalsAndBalances, TrialBalanceType::fromRequest(null));
        $this->assertSame(TrialBalanceType::TotalsAndBalances, TrialBalanceType::fromRequest(''));
        $this->assertSame(TrialBalanceType::TotalsAndBalances, TrialBalanceType::fromRequest('trial-balance'));
    }

    public function testShowsOpening_IsTrueForTheTwoLayoutsThatStartFromWhereThePeriodOpened(): void
    {
        $this->assertTrue(TrialBalanceType::Movement->showsOpening());
        $this->assertTrue(TrialBalanceType::ReviewTotals->showsOpening());
        $this->assertFalse(TrialBalanceType::Balances->showsOpening());
        $this->assertFalse(TrialBalanceType::TotalsAndBalances->showsOpening());
    }

    public function testShowsMovement_IsFalseForTheBalancesLayoutAlone(): void
    {
        $this->assertFalse(TrialBalanceType::Balances->showsMovement());
        $this->assertTrue(TrialBalanceType::TotalsAndBalances->showsMovement());
        $this->assertTrue(TrialBalanceType::Movement->showsMovement());
        $this->assertTrue(TrialBalanceType::ReviewTotals->showsMovement());
    }

    public function testIsGross_IsTrueForTheReviewLayoutAlone(): void
    {
        $this->assertTrue(TrialBalanceType::ReviewTotals->isGross());
        $this->assertFalse(TrialBalanceType::Movement->isGross());
        $this->assertFalse(TrialBalanceType::Balances->isGross());
        $this->assertFalse(TrialBalanceType::TotalsAndBalances->isGross());
    }
}
