<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CashMovement;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What every document that moves cash without an invoice asks before it posts: the amount, the way of
 * payment, the treasury and its scope for the direction of the money, the branch, the reason for taking
 * it back, and the reversal of its journal.
 */
class CashMovementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CashMovement $cash;
    private TreasuryService $treasuries;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $db               = $this->accountingDb();
        $this->cash       = new CashMovement($db, new BranchGuard($db));
        $this->treasuries = new TreasuryService();
        $this->manager    = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function refusal(callable $action): AccountingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    private function drawer(string $name, array $scopes = [], int $branchId = 1): int
    {
        return $this->treasuries->create(['name' => $name, 'kind' => 'cash', 'branch_id' => $branchId, ...$scopes], $this->manager);
    }

    /**
     * Posts the journal of a document that put money into a treasury, and gives the treasury it went into.
     */
    private function receiptInto(int $drawerId, string $date, int $sourceId = 700001): int
    {
        (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [new JournalLineDraft($this->treasuries->accountOf($drawerId), debit: '40.00'), new JournalLineDraft($this->customerAccount(), credit: '40.00')],
            sourceType: CustomerAdvances::SOURCE,
            sourceId: $sourceId,
        ));

        return $sourceId;
    }

    public function testAmount_AcceptsWhatIsMoreThanZeroToFourDecimalsAndKeepsFourDecimals(): void
    {
        $this->assertSame('10.0000', $this->cash->amount('10'));
        $this->assertSame('10.5000', $this->cash->amount(' 10.5 '));
        $this->assertSame('0.0001', $this->cash->amount('0.0001'));
        $this->assertSame('99999999999.9999', $this->cash->amount('99999999999.9999'));
    }

    public function testAmount_RejectsNothingNegativesTextTooManyDecimalsAndTooManyDigits(): void
    {
        foreach (['0', '0.0000', '-5', 'ten', '', '1e3', '1.23456', '123456789012', '1,5'] as $amount) {
            $this->assertSame(AccountingError::AdvanceAmountInvalid, $this->refusal(fn () => $this->cash->amount($amount))->error, "amount {$amount}");
        }
    }

    public function testPaymentType_AcceptsTheWaysOfPaymentThatMoveRealMoney(): void
    {
        $this->assertSame(PaymentType::Cash, $this->cash->paymentType(lang('Sales.cash')));
    }

    public function testPaymentType_RejectsCreditSalesGiftCardsRewardPointsAndUnknownWays(): void
    {
        foreach ([lang('Sales.due'), lang('Sales.giftcard'), lang('Sales.rewards'), 'Barter'] as $label) {
            $this->assertSame(AccountingError::CreditPaymentUnknown, $this->refusal(fn () => $this->cash->paymentType($label))->error, "type {$label}");
        }
    }

    public function testRequestKey_KeepsAKeyTrimmedAndCutToSixtyFourCharactersAndTakesAnEmptyOneAsNone(): void
    {
        $this->assertNull($this->cash->requestKey(null));
        $this->assertNull($this->cash->requestKey(''));
        $this->assertNull($this->cash->requestKey('   '));
        $this->assertSame('form-1', $this->cash->requestKey('  form-1 '));
        $this->assertSame(str_repeat('k', 64), $this->cash->requestKey(str_repeat('k', 80)));
    }

    public function testReason_AcceptsOneToTwoHundredAndFiftyFiveCharactersAndTrimsIt(): void
    {
        $this->assertSame('x', $this->cash->reason(' x '));
        $this->assertSame(str_repeat('x', 255), $this->cash->reason(str_repeat('x', 255)));
    }

    public function testReason_RejectsNothingBlanksAndMoreThanTwoHundredAndFiftyFiveCharacters(): void
    {
        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->cash->reason($reason))->error);
        }
    }

    public function testPlace_GivesTheTreasuryItsBranchAndItsAccount(): void
    {
        $drawer = $this->drawer('Counter');
        $place  = $this->cash->place(PaymentType::Cash, $drawer, TreasuryScopes::DEPOSIT, $this->manager);

        $this->assertSame(['treasury' => $drawer, 'branch' => 1, 'account' => $this->treasuries->accountOf($drawer)], $place);
    }

    public function testPlace_GivesTheAccountOfTheWayOfPaymentWhenNoTreasuryIsNamed(): void
    {
        $place = $this->cash->place(PaymentType::Cash, null, TreasuryScopes::WITHDRAW, $this->manager);

        $this->assertNull($place['treasury']);
        $this->assertSame($this->accountId('cash'), $place['account']);
    }

    public function testPlace_RefusesATreasuryTheEmployeeMayNotUseInTheDirectionOfTheMoney(): void
    {
        $other  = $this->employeeWithGrants([]);
        $locked = $this->drawer('Locked', ['deposit_scope' => 'staff', 'deposit_members' => [$other], 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]]);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->cash->place(PaymentType::Cash, $locked, TreasuryScopes::DEPOSIT, $this->manager))->error);
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->cash->place(PaymentType::Cash, $locked, TreasuryScopes::WITHDRAW, $this->manager))->error);

        $this->assertSame($locked, $this->cash->place(PaymentType::Cash, $locked, TreasuryScopes::DEPOSIT, $other)['treasury']);
    }

    public function testPlace_AsksTheScopeOfTheDirectionOfTheMoneyAndNotTheOtherOne(): void
    {
        $other   = $this->employeeWithGrants([]);
        $receipt = $this->drawer('Receipts only', ['deposit_scope' => 'staff', 'deposit_members' => [$other]]);
        $payout  = $this->drawer('Pay-outs only', ['withdraw_scope' => 'staff', 'withdraw_members' => [$other]]);

        $this->assertSame($receipt, $this->cash->place(PaymentType::Cash, $receipt, TreasuryScopes::WITHDRAW, $this->manager)['treasury']);
        $this->assertSame($payout, $this->cash->place(PaymentType::Cash, $payout, TreasuryScopes::DEPOSIT, $this->manager)['treasury']);
    }

    public function testPlace_AppliesTheScopeOfTheTreasuryTheWayOfPaymentIsRoutedToWhenNoTreasuryIsNamed(): void
    {
        $other = $this->employeeWithGrants([]);
        $bank  = $this->treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $this->treasuries->accountOf($bank)]);

        $refusal = $this->refusal(fn () => $this->cash->place(PaymentType::Cash, null, TreasuryScopes::WITHDRAW, $this->manager));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('Routed bank', $refusal->context['name']);
        $this->assertNull($this->cash->place(PaymentType::Cash, null, TreasuryScopes::WITHDRAW, $other)['treasury']);
    }

    public function testPlace_RefusesABranchTheEmployeeDoesNotWorkIn(): void
    {
        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([], [$other]);
        $drawer  = $this->drawer('Branch one drawer');
        $refusal = $this->refusal(fn () => $this->cash->place(PaymentType::Cash, $drawer, TreasuryScopes::DEPOSIT, $limited));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame(BranchError::NotAllowed, $refusal->error);

        $this->assertSame($other, $this->cash->place(PaymentType::Cash, $this->drawer('Branch two drawer', [], $other), TreasuryScopes::DEPOSIT, $limited)['branch']);
    }

    public function testPlace_RefusesADeactivatedTreasuryAndOneThatDoesNotExist(): void
    {
        $drawer = $this->drawer('Closed drawer');

        $this->treasuries->deactivate($drawer, $this->manager);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->cash->place(PaymentType::Cash, $drawer, TreasuryScopes::DEPOSIT, $this->manager))->error);
        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->cash->place(PaymentType::Cash, 99999, TreasuryScopes::DEPOSIT, $this->manager))->error);
    }

    public function testReverse_TakesTheJournalBackOnItsOwnDate(): void
    {
        $drawer = $this->drawer('Counter');
        $source = $this->receiptInto($drawer, $this->daysAgo(3));

        $this->cash->reverse(CustomerAdvances::SOURCE, $source, 1, 'taken twice', $this->manager);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getRow();

        $this->assertSame($this->daysAgo(3), $reversal->journal_date);
        $this->assertSame('0.0000', bcadd($this->treasuries->position($drawer)->balance, '0', 4));
    }

    public function testReverse_RefusesWhenNothingStandsToBeTakenBack(): void
    {
        $drawer = $this->drawer('Counter');
        $source = $this->receiptInto($drawer, $this->daysAgo(1));

        $this->cash->reverse(CustomerAdvances::SOURCE, $source, 1, 'taken twice', $this->manager);

        $this->assertSame(AccountingError::AdvanceAlreadyCancelled, $this->refusal(fn () => $this->cash->reverse(CustomerAdvances::SOURCE, $source, 1, 'again', $this->manager))->error);
        $this->assertSame(AccountingError::AdvanceAlreadyCancelled, $this->refusal(fn () => $this->cash->reverse(CustomerAdvances::SOURCE, 999999, 1, 'none', $this->manager))->error);
    }

    public function testReverse_RefusesAnEmployeeWhoMayNotMakeTheOppositeMovementOfTheTreasury(): void
    {
        $other  = $this->employeeWithGrants([]);
        $drawer = $this->drawer('Counter', ['withdraw_scope' => 'staff', 'withdraw_members' => [$other]]);
        $source = $this->receiptInto($drawer, $this->daysAgo(1));

        $refusal = $this->refusal(fn () => $this->cash->reverse(CustomerAdvances::SOURCE, $source, 1, 'taken twice', $this->manager));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());

        $this->cash->reverse(CustomerAdvances::SOURCE, $source, 1, 'taken twice', $other);

        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());
    }

    public function testReverse_PostsTheReversalTodayWhenThePeriodOfTheJournalIsClosed(): void
    {
        $drawer = $this->drawer('Counter');
        $source = $this->receiptInto($drawer, $this->daysAgo(10));

        $this->closeThrough($this->daysAgo(5));
        $this->cash->reverse(CustomerAdvances::SOURCE, $source, 1, 'taken twice', $this->manager);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getRow();

        $this->assertSame(date('Y-m-d'), $reversal->journal_date);
        $this->assertSame('0.0000', bcadd($this->treasuries->position($drawer)->balance, '0', 4));
    }
}
