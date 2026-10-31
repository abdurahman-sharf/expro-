<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\StatementDisplay;
use App\Libraries\Accounting\TrialBalanceFilter;
use CodeIgniter\Test\CIUnitTestCase;

class TrialBalanceFilterTest extends CIUnitTestCase
{
    public function testFromRequest_ReadsALevelFromOneToFiveAndCutsWhatIsDeeperThanTheChart(): void
    {
        $this->assertSame(1, TrialBalanceFilter::fromRequest('1', null, null, null)->level);
        $this->assertSame(5, TrialBalanceFilter::fromRequest('5', null, null, null)->level);
        $this->assertSame(5, TrialBalanceFilter::fromRequest('7', null, null, null)->level, 'The chart is cut off at the fifth level');
    }

    public function testFromRequest_RejectsALevelThatIsNotAWholeNumberFromOneUp(): void
    {
        foreach ([null, '', '0', '-1', 'abc', '2.5', ['3'], 3] as $level) {
            $this->assertNull(TrialBalanceFilter::fromRequest($level, null, null, null)->level, 'A level of ' . json_encode($level) . ' means every level');
        }
    }

    public function testFromRequest_ReadsTheChoiceOfWhatToShowAndFallsBackToAll(): void
    {
        $this->assertSame(StatementDisplay::WithActivity, TrialBalanceFilter::fromRequest(null, 'activity', null, null)->display);
        $this->assertSame(StatementDisplay::HideZero, TrialBalanceFilter::fromRequest(null, 'hide_zero', null, null)->display);
        $this->assertSame(StatementDisplay::All, TrialBalanceFilter::fromRequest(null, 'whatever', null, null)->display);
        $this->assertSame(StatementDisplay::All, TrialBalanceFilter::fromRequest(null, ['activity'], null, null)->display);
    }

    public function testFromRequest_ReadsAMainAccountOnlyAsAPositiveWholeNumber(): void
    {
        $this->assertSame(31, TrialBalanceFilter::fromRequest(null, null, '31', null)->accountId);

        foreach ([null, '', '0', '-4', 'x', ['31']] as $account) {
            $this->assertNull(TrialBalanceFilter::fromRequest(null, null, $account, null)->accountId);
        }
    }

    public function testFromRequest_ReadsAListOfAccountsOnceEachAndSkipsWhatIsNotANumber(): void
    {
        $filter = TrialBalanceFilter::fromRequest(null, null, null, ['5', '5', 'x', '0', ['9'], '7']);

        $this->assertSame([5, 7], $filter->accountIds);
        $this->assertSame([], TrialBalanceFilter::fromRequest(null, null, null, '5')->accountIds, 'A single value is not a list');
    }

    public function testFromRequest_KeepsNoMoreAccountsThanTheLimit(): void
    {
        $many = array_map('strval', range(1, TrialBalanceFilter::MAX_ACCOUNTS + 50));

        $this->assertCount(TrialBalanceFilter::MAX_ACCOUNTS, TrialBalanceFilter::fromRequest(null, null, null, $many)->accountIds);
    }

    public function testIsLimitedToAccounts_IsTrueOnlyWhenAnAccountIsNamed(): void
    {
        $this->assertFalse((new TrialBalanceFilter())->isLimitedToAccounts());
        $this->assertFalse((new TrialBalanceFilter(level: 2, display: StatementDisplay::HideZero))->isLimitedToAccounts());
        $this->assertTrue((new TrialBalanceFilter(accountId: 4))->isLimitedToAccounts());
        $this->assertTrue((new TrialBalanceFilter(accountIds: [4]))->isLimitedToAccounts());
    }

    public function testIsNarrowed_IsTrueWhenAnythingButTheDefaultIsAsked(): void
    {
        $this->assertFalse((new TrialBalanceFilter())->isNarrowed());
        $this->assertTrue((new TrialBalanceFilter(level: 2))->isNarrowed());
        $this->assertTrue((new TrialBalanceFilter(display: StatementDisplay::WithActivity))->isNarrowed());
        $this->assertTrue((new TrialBalanceFilter(accountIds: [4]))->isNarrowed());
    }
}
