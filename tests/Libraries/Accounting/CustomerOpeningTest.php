<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditSettlement;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerLock;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What a customer owed us, or held with us, before the books began: a document with a journal
 * against the account of the customer and the account of opening balances, a revision at a time,
 * reversed and posted again when it changes, whose open amount enters the aging at its date and
 * whose figures tie to the account of the customer.
 */
class CustomerOpeningTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CustomerOpening $openings;
    private int $clerk;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->setConfigValue('accounting_customer_opening', '1');

        $this->openings = new CustomerOpening();
        $this->clerk    = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->stopSelling();
        $this->setConfigValue('accounting_customer_opening', '0');

        parent::tearDown();
    }

    private function refusal(callable $action): AccountingException|PostingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|PostingException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    private function assertRefusedWith(AccountingError $error, callable $action): void
    {
        $refusal = $this->refusal($action);

        $this->assertInstanceOf(AccountingException::class, $refusal);
        $this->assertSame($error, $refusal->error);
    }

    /**
     * @return list<string> "account=debit/credit" of the lines of the journal of a revision.
     */
    private function linesOf(int $revision, ?int $customerId = null): array
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => CustomerOpening::SOURCE, 'source_id' => $customerId ?? $this->customerId, 'revision' => $revision])->get()->getRow();

        $this->assertNotNull($journal, 'No journal stands for revision ' . $revision);

        return array_map(
            static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', 2) . '/' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult(),
        );
    }

    private function journalOf(int $revision, ?int $customerId = null): object
    {
        return $this->accountingDb()->table('accounting_journals')->where(['source_type' => CustomerOpening::SOURCE, 'source_id' => $customerId ?? $this->customerId, 'revision' => $revision])->get()->getRow();
    }

    private function owe(string $amount = '1000.00', ?string $date = null, ?int $customerId = null): int
    {
        return $this->openings->create($customerId ?? $this->customerId, CustomerOpening::OWES_US, $amount, $date ?? $this->daysAgo(45), 'before the books', $this->clerk);
    }

    private function openingRow(int $openingId): object
    {
        return $this->accountingDb()->table('customer_openings')->where('opening_id', $openingId)->get()->getRow();
    }

    /**
     * What stands in the applications of a customer, as "source:id->target:amount", the target of an
     * opening balance being "opening" and its number.
     *
     * @return list<string>
     */
    private function spent(bool $withReleased = false): array
    {
        return array_map(
            static fn (object $row): string => $row->source_type . ':' . $row->source_id . '->' . ($row->invoice_sale_id ?? 'opening' . $row->opening_id) . ':' . bcadd($row->amount, '0', 2) . ($row->released_at === null ? '' : ':released'),
            (new CustomerLedger())->applications($this->customerId, $withReleased),
        );
    }

    private function snapshot(): array
    {
        return [
            'journals' => array_column($this->accountingDb()->table('accounting_journals')->get()->getResultArray(), null, 'journal_id'),
            'lines'    => array_column($this->accountingDb()->table('accounting_journal_lines')->get()->getResultArray(), null, 'line_id'),
            'openings' => array_column($this->accountingDb()->table('customer_openings')->get()->getResultArray(), null, 'opening_id'),
        ];
    }

    public function testCreate_DebitsTheCustomerAndCreditsTheOpeningBalancesAccount(): void
    {
        $openingId = $this->owe('1000.00', $this->daysAgo(45));

        $this->assertSame([$this->customerAccount() . '=1000.00/0.00', $this->accountId('opening_balance') . '=0.00/1000.00'], $this->linesOf(1));

        $journal = $this->journalOf(1);

        $this->assertSame([$this->daysAgo(45), 'posted', 'user', (string) $this->clerk, CustomerOpening::SOURCE, 'general'], [$journal->journal_date, $journal->status, $journal->origin, (string) $journal->created_by, $journal->source_type, $journal->entry_type]);

        $row = $this->openingRow($openingId);

        $this->assertSame(['owes_us', '1000.0000', $this->daysAgo(45), 'posted', '1', 'before the books'], [$row->side, $row->amount, $row->opening_date, $row->status, (string) $row->revision, $row->note]);
        $this->assertSame('1000.00', $this->balance($this->customerAccount()));
        $this->assertSame('-1000.00', $this->balance($this->accountId('opening_balance')));
    }

    public function testCreate_MakesTheOpeningAnOpenInvoiceThatTiesTheBalanceTheAgingAndTheAccount(): void
    {
        $openingId = $this->owe('1000.00');
        $position  = $this->position();
        $figures   = $position->invoice(-$openingId);

        $this->assertNotNull($figures);
        $this->assertTrue($figures->opening);
        $this->assertSame(['1000.00', '1000.00', '0.00'], [bcadd($figures->total, '0', 2), bcadd($figures->open(), '0', 2), bcadd($figures->settled(), '0', 2)]);
        $this->assertSame('1000.0000', $position->open());
        $this->assertSame('0.0000', $position->credit());
        $this->assertSame('1000.0000', $position->accountBalance);
        $this->assertTrue($position->agrees());
        $this->assertTies();
    }

    public function testCreate_WritesNoSaleSoNoReportOfSalesOrListOfInvoicesSeesIt(): void
    {
        $sales = $this->countSales();

        $this->owe();

        $this->assertSame($sales, $this->countSales());
    }

    public function testCreate_EntersTheAgingAtItsOwnDateInThePositionAndInTheAgedLedger(): void
    {
        $this->owe('1000.00', $this->daysAgo(45));

        $aging = $this->position()->aging(date('Y-m-d'));

        $this->assertSame(['0.0000', '0.0000', '1000.0000', '0.0000', '0.0000', '0.0000'], array_values($aging));

        $rows = (new CustomerLedger())->agedLedger(date('Y-m-d'), 30, $this->customerId);

        $this->assertCount(1, $rows);
        $this->assertSame(['0.0000', '0.0000', '1000.0000', '0.0000', '0.0000', '0.0000'], array_values($rows[0]->buckets));
        $this->assertSame('1000.0000', $rows[0]->balance());
    }

    public function testCreate_AddsToWhatTheCustomerOwesOnInvoicesAndAgesEachFromItsOwnDay(): void
    {
        $this->owe('1000.00', $this->daysAgo(45));
        $this->invoiceDated('250.00', $this->daysAgo(5));

        $position = $this->position();

        $this->assertSame('1250.0000', $position->open());
        $this->assertSame(['0.0000', '250.0000', '1000.0000', '0.0000', '0.0000', '0.0000'], array_values($position->aging(date('Y-m-d'))));
        $this->assertSame('1250.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testPosition_OnADayBeforeTheOpeningLeavesItOutAndOnItsDayCountsIt(): void
    {
        $this->owe('1000.00', $this->daysAgo(10));

        $before = $this->position(null, $this->daysAgo(11));
        $on     = $this->position(null, $this->daysAgo(10));

        $this->assertSame(['0.0000', '0.0000'], [$before->open(), $before->accountBalance]);
        $this->assertSame(['1000.0000', '1000.0000'], [$on->open(), $on->accountBalance]);
        $this->assertTrue($before->agrees());
        $this->assertTrue($on->agrees());
    }

    public function testCreate_WeOweCreditsTheCustomerAndIsACreditHeldWithUs(): void
    {
        $openingId = $this->openings->create($this->customerId, CustomerOpening::WE_OWE, '300.00', $this->daysAgo(20), '', $this->clerk);

        $this->assertSame([$this->accountId('opening_balance') . '=300.00/0.00', $this->customerAccount() . '=0.00/300.00'], $this->linesOf(1));

        $position = $this->position();

        $this->assertNull($position->invoice(-$openingId));
        $this->assertSame('0.0000', $position->open());
        $this->assertSame('300.0000', $position->credit());
        $this->assertSame('-300.0000', $position->accountBalance);
        $this->assertSame(CreditSource::OPENING, $position->credits[0]->type);
        $this->assertSame($openingId, $position->credits[0]->sourceId);
        $this->assertTies();
    }

    public function testCreate_ASeparateOpeningOfAnotherCustomerLeavesTheFirstAlone(): void
    {
        $other = $this->anotherCustomer();
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $other, 'Hind Saad');

        $this->owe('1000.00');
        $this->owe('400.00', null, $other);

        $this->assertSame('1000.0000', $this->position()->open());
        $this->assertSame('400.0000', $this->position($other)->open());
        $this->assertTies();
        $this->assertTies($other);
    }

    public function testCreate_IsSpentByTheCreditOfTheCustomerTheOldestFirstBeforeAnyInvoice(): void
    {
        $openingId = $this->owe('1000.00', $this->daysAgo(60));
        $invoice   = $this->invoiceDated('100.00', $this->daysAgo(2), null, null, [$this->payment(lang('Sales.cash'), '300.00')]);

        $position = $this->position();

        $this->assertSame(['invoice_excess:' . $invoice . '->opening' . $openingId . ':200.00'], $this->spent());
        $this->assertSame('200.00', bcadd($position->invoice(-$openingId)->applied, '0', 2));
        $this->assertSame('800.00', bcadd($position->invoice(-$openingId)->open(), '0', 2));
        $this->assertSame('0.0000', $position->credit());
        $this->assertSame('800.0000', $position->accountBalance);
        $this->assertTies();
    }

    public function testCreate_IsSpentByCreditNotesToo(): void
    {
        $openingId = $this->owe('500.00', $this->daysAgo(60));
        $invoice   = $this->invoiceDated('100.00', $this->daysAgo(2));

        $this->creditNoteOf($invoice, '100.00');

        $position = $this->position();

        $this->assertSame('500.00', bcadd($position->invoice(-$openingId)->open(), '0', 2));
        $this->assertSame('0.00', bcadd($position->invoice($invoice)->open(), '0', 2));
        $this->assertTies();
    }

    public function testCreate_TheCreditItHoldsIsSpentOnTheNextInvoiceOfTheCustomer(): void
    {
        $openingId = $this->openings->create($this->customerId, CustomerOpening::WE_OWE, '300.00', $this->daysAgo(20), '', $this->clerk);
        $invoice   = $this->invoiceDated('100.00', $this->daysAgo(2));

        $position = $this->position();

        $this->assertSame([CreditSource::OPENING . ':' . $openingId . '->' . $invoice . ':100.00'], $this->spent());
        $this->assertSame('0.00', bcadd($position->invoice($invoice)->open(), '0', 2));
        $this->assertSame('200.0000', $position->credit());
        $this->assertSame('-200.0000', $position->accountBalance);
        $this->assertTies();
    }

    public function testCreate_RefusesWhenTheSettingIsOffAndSavesNothing(): void
    {
        $this->setConfigValue('accounting_customer_opening', '0');
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $this->assertRefusedWith(AccountingError::CustomerOpeningOff, fn () => $this->owe());
        $this->assertRefusedWith(AccountingError::CustomerOpeningOff, fn () => $this->openings->create($this->customerId, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', null));
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);
    }

    public function testCreate_RequiresThePermissionToManageTheOpeningBalances(): void
    {
        $clerk  = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]);
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->openings->create($this->customerId, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', $clerk));
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);

        $openingId = $this->owe();

        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->openings->revise($openingId, CustomerOpening::OWES_US, '20.00', date('Y-m-d'), '', 'Why', $clerk));
        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->openings->cancel($openingId, 'Why', $clerk));
        $this->assertSame('posted', $this->openingRow($openingId)->status);
    }

    public function testCreate_LetsTheBooksEnterItWithoutAnEmployee(): void
    {
        $openingId = $this->openings->create($this->customerId, CustomerOpening::OWES_US, '75.50', $this->daysAgo(3));

        $this->assertSame('system', $this->journalOf(1)->origin);
        $this->assertNull($this->openingRow($openingId)->created_by);
    }

    public function testCreate_RefusesASecondOpeningWhileOneStandsAndPostsNothingMore(): void
    {
        $this->owe();
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $this->assertRefusedWith(AccountingError::CustomerOpeningExists, fn () => $this->owe('5.00'));
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);
        $this->assertSame('1000.00', $this->balance($this->customerAccount()));
    }

    public function testCreate_AcceptsANewOpeningOnceTheOldOneIsCancelledAsAnotherRevision(): void
    {
        $first = $this->owe();
        $this->openings->cancel($first, 'Entered for the wrong customer', $this->clerk);

        $second = $this->owe('250.00', $this->daysAgo(10));

        $this->assertSame([2], [(int) $this->openingRow($second)->revision]);
        $this->assertSame(['reversed', 'posted'], [$this->openingRow($first)->status, $this->openingRow($second)->status]);
        $this->assertSame('250.00', $this->balance($this->customerAccount()));
        $this->assertSame('250.0000', $this->position()->open());
        $this->assertTies();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusedEntries(): array
    {
        return [
            'a side that is neither'      => ['both', '10.00', '2026-01-01'],
            'an amount of nothing'        => [CustomerOpening::OWES_US, '0', '2026-01-01'],
            'a negative amount'           => [CustomerOpening::OWES_US, '-5.00', '2026-01-01'],
            'an amount that is not one'   => [CustomerOpening::OWES_US, 'ten', '2026-01-01'],
            'five decimals'               => [CustomerOpening::OWES_US, '1.00001', '2026-01-01'],
            'no amount'                   => [CustomerOpening::WE_OWE, '', '2026-01-01'],
            'a date that is not a day'    => [CustomerOpening::OWES_US, '10.00', '2026-02-30'],
            'no date'                     => [CustomerOpening::OWES_US, '10.00', ''],
            'a date typed the wrong way'  => [CustomerOpening::OWES_US, '10.00', '01/02/2026'],
        ];
    }

    #[DataProvider('refusedEntries')]
    public function testCreate_RefusesWhatCannotBeEnteredAndSavesNothing(string $side, string $amount, string $date): void
    {
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $this->assertInstanceOf(AccountingException::class, $this->refusal(fn () => $this->openings->create($this->customerId, $side, $amount, $date, '', $this->clerk)));
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);
    }

    public function testCreate_RefusesADayAfterTodayAndAcceptsTodayAndTheDayBefore(): void
    {
        $this->assertRefusedWith(AccountingError::InvalidDate, fn () => $this->openings->create($this->customerId, CustomerOpening::OWES_US, '10.00', date('Y-m-d', strtotime('+1 day')), '', $this->clerk));
        $this->assertSame(0, $this->countRows('customer_openings'));

        $openingId = $this->openings->create($this->customerId, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', $this->clerk);

        $this->assertSame(date('Y-m-d'), $this->openingRow($openingId)->opening_date);
    }

    public function testCreate_RefusesACustomerThatDoesNotExistOrWasDeleted(): void
    {
        $this->assertRefusedWith(AccountingError::CustomerOpeningCustomerMissing, fn () => $this->openings->create(9999999, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', $this->clerk));

        $deleted = $this->anotherCustomer('Gone', 'Customer');
        $this->accountingDb()->table('customers')->where('person_id', $deleted)->update(['deleted' => 1]);

        $this->assertRefusedWith(AccountingError::CustomerOpeningCustomerMissing, fn () => $this->openings->create($deleted, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', $this->clerk));
        $this->assertSame(0, $this->countRows('customer_openings'));
    }

    public function testCreate_RefusesACustomerWithoutAnAccountOfTheirOwnAndSavesNothing(): void
    {
        $bare = $this->insertCustomer('No', 'Account');

        $failure = $this->refusal(fn () => $this->openings->create($bare, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', $this->clerk));

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::EntityAccountMissing, $failure->error);
        $this->assertSame(0, $this->countRows('customer_openings'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testCreate_RefusesWhenTheAccountOfOpeningBalancesIsGoneAndSavesNothing(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'equity.opening')->update(['account_id' => null]);

        $failure = $this->refusal(fn () => $this->owe());

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::RoutingAccountUnusable, $failure->error);
        $this->assertSame(0, $this->countRows('customer_openings'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testCreate_IsRefusedInAClosedPeriodAndSavesNothing(): void
    {
        $this->closeThrough($this->daysAgo(30));
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $failure = $this->refusal(fn () => $this->owe('100.00', $this->daysAgo(40)));

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::PeriodClosed, $failure->error);
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);
        $this->assertSame('0.0000', $this->position()->open());
    }

    public function testCreate_RefusesTheLastClosedDayAndAcceptsTheNextOne(): void
    {
        $this->closeThrough($this->daysAgo(30));

        $this->assertInstanceOf(PostingException::class, $this->refusal(fn () => $this->owe('100.00', $this->daysAgo(30))));

        $openingId = $this->owe('100.00', $this->daysAgo(29));

        $this->assertSame('posted', $this->openingRow($openingId)->status);
    }

    public function testCreate_TakesTheBranchOfTheJournalFromTheEmployeeOrTheOneNamed(): void
    {
        $branch  = $this->createBranch('OP', 'Opening branch');
        $manager = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE], [1, $branch]);

        $first = $this->owe();

        $this->assertSame(1, (int) $this->journalOf(1)->branch_id);
        $this->assertSame(1, (int) $this->openingRow($first)->branch_id);

        $this->openings->cancel($first, 'Move it', $manager);

        $second = $this->openings->create($this->customerId, CustomerOpening::OWES_US, '60.00', $this->daysAgo(5), '', $manager, $branch);

        $this->assertSame($branch, (int) $this->journalOf(2)->branch_id);
        $this->assertSame($branch, (int) $this->openingRow($second)->branch_id);
        $this->assertSame($branch, $this->openings->branchOf($second));
        $this->assertNull($this->openings->branchOf(9999999));
    }

    public function testCreate_RefusesABranchTheEmployeeDoesNotWorkInAndABranchThatIsInactive(): void
    {
        $branch  = $this->createBranch('OP', 'Opening branch');
        $idle    = $this->createBranch('ID', 'Idle branch', false);
        $manager = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE], [1]);
        $counts  = [$this->countRows('customer_openings'), $this->countJournals()];

        $outside = $this->refusal(fn () => $this->openings->create($this->customerId, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', $manager, $branch));

        $this->assertInstanceOf(BranchException::class, $outside);
        $this->assertSame(BranchError::NotAllowed, $outside->error);

        $inactive = $this->refusal(fn () => $this->openings->create($this->customerId, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', $this->clerk, $idle));

        $this->assertInstanceOf(BranchException::class, $inactive);
        $this->assertSame(BranchError::Inactive, $inactive->error);

        $missing = $this->refusal(fn () => $this->openings->create($this->customerId, CustomerOpening::OWES_US, '10.00', date('Y-m-d'), '', $this->clerk, 9999999));

        $this->assertInstanceOf(BranchException::class, $missing);
        $this->assertSame(BranchError::NotFound, $missing->error);
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);
    }

    public function testRevise_ReversesTheJournalAndPostsANewRevisionAndKeepsTheOldOneVisible(): void
    {
        $first = $this->owe('1000.00', $this->daysAgo(45));

        $second = $this->openings->revise($first, CustomerOpening::OWES_US, '1200.00', $this->daysAgo(40), 'corrected', 'The statement said 1200', $this->clerk);

        $this->assertNotSame($first, $second);
        $this->assertSame(['reversed', 'The statement said 1200', (string) $this->clerk], [$this->openingRow($first)->status, $this->openingRow($first)->reverse_reason, (string) $this->openingRow($first)->reversed_by]);
        $this->assertSame(['posted', '2', (string) $first, '1200.0000', $this->daysAgo(40)], [$this->openingRow($second)->status, (string) $this->openingRow($second)->revision, (string) $this->openingRow($second)->supersedes_id, $this->openingRow($second)->amount, $this->openingRow($second)->opening_date]);
        $this->assertSame('reversed', $this->journalOf(1)->status);
        $this->assertSame('posted', $this->journalOf(2)->status);
        $this->assertSame([$this->customerAccount() . '=1200.00/0.00', $this->accountId('opening_balance') . '=0.00/1200.00'], $this->linesOf(2));
        $this->assertSame('1200.00', $this->balance($this->customerAccount()));
        $this->assertSame('-1200.00', $this->balance($this->accountId('opening_balance')));
        $this->assertSame('1200.0000', $this->position()->open());
        $this->assertSame([$second, $first], array_map(static fn (object $row): int => (int) $row->opening_id, $this->openings->history($this->customerId)));
        $this->assertSame($second, (int) $this->openings->standingFor($this->customerId)->opening_id);
        $this->assertTies();
    }

    public function testRevise_NeverEditsAJournalInPlaceAndKeepsEveryRowAsItWas(): void
    {
        $first  = $this->owe('1000.00', $this->daysAgo(45));
        $before = $this->snapshot();

        $this->openings->revise($first, CustomerOpening::OWES_US, '900.00', $this->daysAgo(45), '', 'Wrong amount', $this->clerk);

        $after = $this->snapshot();

        foreach ($before['journals'] as $id => $journal) {
            $now = $after['journals'][$id] ?? null;

            $this->assertNotNull($now, "Journal {$id} was deleted");
            $this->assertSame('posted', $journal['status']);
            $this->assertSame('reversed', $now['status']);
            unset($journal['status'], $now['status'], $journal['updated_at'], $now['updated_at']);
            $this->assertSame($journal, $now, "Journal {$id} changed");
        }

        foreach ($before['lines'] as $id => $line) {
            $this->assertSame($line, $after['lines'][$id] ?? null, "Line {$id} changed or was deleted");
        }

        $original = $before['openings'][$first];
        $now      = $after['openings'][$first];

        foreach (['amount', 'opening_date', 'side', 'revision', 'note', 'customer_id', 'branch_id', 'created_by'] as $field) {
            $this->assertSame($original[$field], $now[$field], "The {$field} of the revision that was reversed changed");
        }

        $this->assertSame(count($before['journals']) + 2, count($after['journals']), 'The reversal and the new revision are journals of their own');
    }

    public function testRevise_MovesTheAgingWithTheDateAndTheSide(): void
    {
        $first = $this->owe('1000.00', $this->daysAgo(45));

        $this->assertSame('1000.0000', $this->position()->aging(date('Y-m-d'))['31-60']);

        $second = $this->openings->revise($first, CustomerOpening::OWES_US, '1000.00', $this->daysAgo(5), '', 'Wrong date', $this->clerk);

        $aging = $this->position()->aging(date('Y-m-d'));

        $this->assertSame(['0.0000', '1000.0000', '0.0000'], [$aging['today'] ?? '', $aging['1-30'], $aging['31-60']]);

        $third = $this->openings->revise($second, CustomerOpening::WE_OWE, '200.00', $this->daysAgo(5), '', 'It was the other way round', $this->clerk);

        $position = $this->position();

        $this->assertNull($position->invoice(-$third));
        $this->assertSame(['0.0000', '200.0000', '-200.0000'], [$position->open(), $position->credit(), $position->accountBalance]);
        $this->assertSame('200.00', $this->balance($this->accountId('opening_balance')));
        $this->assertTies();
    }

    public function testRevise_ReleasesWhatWasSpentOnTheRevisionItReplacesAndSpendsItOnTheNewOne(): void
    {
        $first   = $this->owe('1000.00', $this->daysAgo(60));
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(2), null, null, [$this->payment(lang('Sales.cash'), '300.00')]);

        $this->assertSame(['invoice_excess:' . $invoice . '->opening' . $first . ':200.00'], $this->spent());

        $second = $this->openings->revise($first, CustomerOpening::OWES_US, '900.00', $this->daysAgo(60), '', 'Corrected', $this->clerk);

        $this->assertSame(['invoice_excess:' . $invoice . '->opening' . $second . ':200.00'], $this->spent());
        $this->assertSame(['invoice_excess:' . $invoice . '->opening' . $first . ':200.00:released', 'invoice_excess:' . $invoice . '->opening' . $second . ':200.00'], $this->spent(true), 'What was spent on the old revision stays in the history, released');
        $this->assertSame('700.00', bcadd($this->position()->invoice(-$second)->open(), '0', 2));
        $this->assertTies();
    }

    public function testRevise_RequiresAReasonAndAnOpeningThatStandsAndIsFound(): void
    {
        $first = $this->owe();
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '5.00', date('Y-m-d'), '', '   ', $this->clerk));
        $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '5.00', date('Y-m-d'), '', str_repeat('x', 256), $this->clerk));
        $this->assertRefusedWith(AccountingError::CustomerOpeningNotFound, fn () => $this->openings->revise(9999999, CustomerOpening::OWES_US, '5.00', date('Y-m-d'), '', 'Why', $this->clerk));
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);

        $second = $this->openings->revise($first, CustomerOpening::OWES_US, '5.00', date('Y-m-d'), '', str_repeat('x', 255), $this->clerk);

        $this->assertRefusedWith(AccountingError::CustomerOpeningReversed, fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '6.00', date('Y-m-d'), '', 'Again', $this->clerk));
        $this->assertSame('5.00', $this->balance($this->customerAccount()));
        $this->assertSame('posted', $this->openingRow($second)->status);
    }

    public function testRevise_RefusesWhatCannotBeEnteredAndLeavesTheOpeningStanding(): void
    {
        $first  = $this->owe();
        $before = $this->snapshot();

        $this->assertRefusedWith(AccountingError::AdvanceAmountInvalid, fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '0', date('Y-m-d'), '', 'Why', $this->clerk));
        $this->assertRefusedWith(AccountingError::CustomerOpeningSideInvalid, fn () => $this->openings->revise($first, 'x', '5.00', date('Y-m-d'), '', 'Why', $this->clerk));
        $this->assertRefusedWith(AccountingError::InvalidDate, fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '5.00', date('Y-m-d', strtotime('+2 days')), '', 'Why', $this->clerk));
        $this->assertSame($before, $this->snapshot());
    }

    public function testRevise_RefusesWhileTheSettingIsOffButCancelStillWorks(): void
    {
        $first = $this->owe();
        $this->setConfigValue('accounting_customer_opening', '0');

        $this->assertRefusedWith(AccountingError::CustomerOpeningOff, fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '5.00', date('Y-m-d'), '', 'Why', $this->clerk));
        $this->assertSame('posted', $this->openingRow($first)->status);

        $this->openings->cancel($first, 'Switching it off', $this->clerk);

        $this->assertSame('reversed', $this->openingRow($first)->status);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testRevise_RefusesANewDateInAClosedPeriodAndLeavesTheOldRevisionStandingAndEveryJournalAsItWas(): void
    {
        $first = $this->owe('1000.00', $this->daysAgo(10));
        $this->closeThrough($this->daysAgo(15));
        $before = $this->snapshot();

        $failure = $this->refusal(fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '900.00', $this->daysAgo(20), '', 'Wrong date', $this->clerk));

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::PeriodClosed, $failure->error);
        $this->assertSame($before, $this->snapshot(), 'The reversal was undone with the rest: the opening still stands');
        $this->assertSame('posted', $this->openingRow($first)->status);
        $this->assertSame('1000.0000', $this->position()->open());
        $this->assertTies();
    }

    public function testRevise_OfAnOpeningInAClosedPeriodReversesItTodayAndPostsTheNewRevisionOnTheNewDate(): void
    {
        $first = $this->owe('1000.00', $this->daysAgo(30));
        $this->closeThrough($this->daysAgo(20));

        $second = $this->openings->revise($first, CustomerOpening::OWES_US, '800.00', $this->daysAgo(5), '', 'Corrected after the close', $this->clerk);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $this->journalOf(1)->journal_id)->get()->getRow();

        $this->assertSame(date('Y-m-d'), $reversal->journal_date, 'A day in a closed period is not touched: the reversal is dated today');
        $this->assertSame($this->daysAgo(30), $this->journalOf(1)->journal_date);
        $this->assertSame('1000.0000', $this->position(null, $this->daysAgo(25))->open(), 'The books of the closed period stay as they were');
        $this->assertSame('800.0000', $this->position()->open());
        $this->assertSame('800.00', $this->balance($this->customerAccount()));
        $this->assertSame('posted', $this->openingRow($second)->status);
        $this->assertTies();
    }

    public function testRevise_RefusesAnOpeningOfABranchTheEmployeeDoesNotWorkInAndChangesNothing(): void
    {
        $branch  = $this->createBranch('OP', 'Opening branch');
        $admin   = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE], [1, $branch]);
        $first   = $this->openings->create($this->customerId, CustomerOpening::OWES_US, '100.00', $this->daysAgo(5), '', $admin, $branch);
        $manager = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE], [1]);
        $before  = $this->snapshot();

        foreach ([
            fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '5.00', date('Y-m-d'), '', 'Why', $manager),
            fn () => $this->openings->cancel($first, 'Why', $manager),
        ] as $attempt) {
            $failure = $this->refusal($attempt);

            $this->assertInstanceOf(BranchException::class, $failure);
            $this->assertSame(BranchError::NotAllowed, $failure->error);
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function testRevise_KeepsTheBranchOfTheOpeningItReplaces(): void
    {
        $branch = $this->createBranch('OP', 'Opening branch');
        $admin  = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE], [1, $branch]);
        $first  = $this->openings->create($this->customerId, CustomerOpening::OWES_US, '100.00', $this->daysAgo(5), '', $admin, $branch);

        $second = $this->openings->revise($first, CustomerOpening::OWES_US, '120.00', $this->daysAgo(5), '', 'Corrected', $admin);

        $this->assertSame($branch, (int) $this->openingRow($second)->branch_id);
        $this->assertSame($branch, (int) $this->journalOf(2)->branch_id);
    }

    public function testCancel_ReversesTheJournalAndGivesBackWhatWasSpentOnIt(): void
    {
        $first   = $this->owe('1000.00', $this->daysAgo(60));
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(2), null, null, [$this->payment(lang('Sales.cash'), '300.00')]);

        $this->openings->cancel($first, 'Entered twice', $this->clerk);

        $this->assertSame('reversed', $this->openingRow($first)->status);
        $this->assertSame('reversed', $this->journalOf(1)->status);
        $this->assertSame('-200.00', $this->balance($this->customerAccount()), 'What the customer paid beyond the invoice is all that is left on the account');
        $this->assertSame([], $this->spent());
        $this->assertSame(['invoice_excess:' . $invoice . '->opening' . $first . ':200.00:released'], $this->spent(true));

        $position = $this->position();

        $this->assertNull($position->invoice(-$first));
        $this->assertSame('200.0000', $position->credit(), 'What the customer paid beyond the invoice is their credit again');
        $this->assertTies();
        $this->assertNull($this->openings->standingFor($this->customerId));
    }

    public function testCancel_RefusesWhatIsCancelledAndWhatIsNotThere(): void
    {
        $first = $this->owe();
        $this->openings->cancel($first, 'Entered twice', $this->clerk);
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $this->assertRefusedWith(AccountingError::CustomerOpeningReversed, fn () => $this->openings->cancel($first, 'Again', $this->clerk));
        $this->assertRefusedWith(AccountingError::CustomerOpeningNotFound, fn () => $this->openings->cancel(9999999, 'Missing', $this->clerk));
        $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->openings->cancel($first, '', $this->clerk));
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);
    }

    public function testCancel_OfAnOpeningInAClosedPeriodReversesItTodayAndKeepsTheClosedBooks(): void
    {
        $first = $this->owe('1000.00', $this->daysAgo(30));
        $this->closeThrough($this->daysAgo(20));

        $this->openings->cancel($first, 'Entered for the wrong customer', $this->clerk);

        $this->assertSame('1000.0000', $this->position(null, $this->daysAgo(25))->open());
        $this->assertSame('0.0000', $this->position()->open());
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testCancel_WritesTheAuditTrailOfEveryChange(): void
    {
        $first  = $this->owe('1000.00', $this->daysAgo(45));
        $second = $this->openings->revise($first, CustomerOpening::OWES_US, '900.00', $this->daysAgo(45), '', 'Wrong amount', $this->clerk);
        $this->openings->cancel($second, 'Entered by mistake', $this->clerk);

        $rows = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'customer_opening')->orderBy('audit_id')->get()->getResult();

        $this->assertSame(['customer_opening_created', 'customer_opening_revised', 'customer_opening_cancelled'], array_map(static fn (object $row): string => $row->action, $rows));
        $this->assertSame([(string) $first, (string) $second, (string) $second], array_map(static fn (object $row): string => (string) $row->entity_id, $rows));
        $this->assertSame([(string) $this->clerk, (string) $this->clerk, (string) $this->clerk], array_map(static fn (object $row): string => (string) $row->employee_id, $rows));

        $revised   = json_decode($rows[1]->details, true);
        $cancelled = json_decode($rows[2]->details, true);

        $this->assertSame([2, '900.0000', $first], [$revised['revision'], $revised['amount'], $revised['supersedes']]);
        $this->assertSame('Entered by mistake', $cancelled['reason']);
    }

    public function testCreate_LeavesItsJournalToBeTakenBackOnlyFromItsDocument(): void
    {
        $this->owe();
        $journalId = (int) $this->journalOf(1)->journal_id;

        $this->assertRefusedWith(AccountingError::DocumentJournalLocked, fn () => (new JournalService())->reverse($journalId, date('Y-m-d'), 'By hand', null));
        $this->assertSame('posted', $this->journalOf(1)->status);
    }

    public function testCreate_KeepsTheManualOpeningOfTheAccountOfACustomerBlocked(): void
    {
        $this->owe();

        $this->assertNotContains($this->customerAccount(), array_map(static fn (object $account): int => (int) $account->account_id, (new OpeningBalanceService())->allowedAccounts()));
    }

    public function testHistory_ListsEveryRevisionOfACustomerWithItsBranchAndNothingOfAnotherCustomer(): void
    {
        $other = $this->anotherCustomer();
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $other, 'Hind Saad');

        $first  = $this->owe('100.00', $this->daysAgo(5));
        $second = $this->openings->revise($first, CustomerOpening::OWES_US, '110.00', $this->daysAgo(5), '', 'Corrected', $this->clerk);
        $this->owe('7.00', $this->daysAgo(5), $other);

        $history = $this->openings->history($this->customerId);

        $this->assertSame([$second, $first], array_map(static fn (object $row): int => (int) $row->opening_id, $history));
        $this->assertSame(['posted', 'reversed'], array_map(static fn (object $row): string => $row->status, $history));
        $this->assertNotSame('', (string) $history[0]->branch_code);
        $this->assertSame([], $this->openings->history(9999999));
        $this->assertNull($this->openings->standingFor(9999999));
    }

    public function testCreate_TwoRequestsAtTheSameMomentEnterTheOpeningBalanceOnce(): void
    {
        [$first, $second] = $this->raceTwoProcesses('customerOpening', [(string) $this->customerId, '100.00', date('Y-m-d')], [(string) $this->customerId, '100.00', date('Y-m-d')]);

        $this->assertSame(1, (int) $first + (int) $second, 'Of two requests at the same moment, one enters the opening balance and the other finds it entered');
        $this->assertSame(1, $this->countRows('customer_openings'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', CustomerOpening::SOURCE)->countAllResults());
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testRevise_TwoRequestsAtTheSameMomentReviseTheOpeningOnce(): void
    {
        $first = $this->owe('100.00', $this->daysAgo(5));

        [$one, $two] = $this->raceTwoProcesses('reviseCustomerOpening', [(string) $first, '150.00', $this->daysAgo(5)], [(string) $first, '175.00', $this->daysAgo(5)]);

        $this->assertSame(1, (int) $one + (int) $two, 'Of two changes to the same revision, one is made and the other finds it already taken back');
        $this->assertSame(2, $this->countRows('customer_openings'));
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $row): string => $row->status, $this->accountingDb()->table('customer_openings')->orderBy('revision')->get()->getResult()));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', CustomerOpening::SOURCE)->countAllResults());
        $this->assertTies();
    }

    public function testCreate_NumbersEveryRevisionOnceInTheUniqueKeyOfTheJournal(): void
    {
        $first = $this->owe('100.00', $this->daysAgo(5));
        $this->openings->revise($first, CustomerOpening::OWES_US, '110.00', $this->daysAgo(5), '', 'Corrected', $this->clerk);

        $keys = array_map(static fn (object $row): string => $row->source_id . ':' . $row->revision, $this->accountingDb()->table('accounting_journals')->where('source_type', CustomerOpening::SOURCE)->orderBy('revision')->get()->getResult());

        $this->assertSame([$this->customerId . ':1', $this->customerId . ':2'], $keys);
        $this->assertCount(2, array_unique($keys));
    }

    public function testSettle_RunAgainChangesNothingAboutWhatWasSpentOnTheOpening(): void
    {
        $first = $this->owe('1000.00', $this->daysAgo(60));
        $this->invoiceDated('100.00', $this->daysAgo(2), null, null, [$this->payment(lang('Sales.cash'), '300.00')]);
        $spent = $this->spent(true);

        (new CreditSettlement())->settle($this->customerId, $this->employeeId);
        (new CreditSettlement())->settle($this->customerId, $this->employeeId);

        $this->assertSame($spent, $this->spent(true));
        $this->assertSame('800.00', bcadd($this->position()->invoice(-$first)->open(), '0', 2));
    }

    /**
     * The names of the locks the database was asked for while the action ran.
     *
     * @return list<string>
     */
    private function locksTakenBy(callable $action): array
    {
        $names    = [];
        $listener = static function ($query) use (&$names): void {
            if (preg_match("/GET_LOCK\('([^']+)'/", (string) $query->getQuery(), $found) === 1) {
                $names[] = $found[1];
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $names;
    }

    public function testCreate_TakesTheLockOfTheCustomerWhileItEntersTheOpeningBalance(): void
    {
        $name = (new CustomerLock())->nameOf($this->customerId);

        $taken = $this->locksTakenBy(fn () => $this->owe());

        $this->assertContains($name, $taken);
    }

    public function testRevise_AndCancel_TakeTheLockOfTheCustomer(): void
    {
        $first = $this->owe();
        $name  = (new CustomerLock())->nameOf($this->customerId);

        $second = 0;

        $this->assertContains($name, $this->locksTakenBy(function () use ($first, &$second): void {
            $second = $this->openings->revise($first, CustomerOpening::OWES_US, '900.00', $this->daysAgo(45), '', 'Wrong amount', $this->clerk);
        }));
        $this->assertContains($name, $this->locksTakenBy(fn () => $this->openings->cancel($second, 'Entered twice', $this->clerk)));
    }

    public function testCreate_RefusesWhenTheJournalOfTheRevisionIsAlreadyPostedAndSavesNoRow(): void
    {
        (new PostingService())->post(new JournalDraft(
            date: $this->daysAgo(3),
            lines: [new JournalLineDraft($this->customerAccount(), debit: '10.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '10.00')],
            sourceType: CustomerOpening::SOURCE,
            sourceId: $this->customerId,
            revision: 1,
        ));
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $this->assertRefusedWith(AccountingError::CustomerOpeningExists, fn () => $this->owe('50.00'));
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);
    }

    private function rawOpening(int $customerId, int $revision, string $status): void
    {
        $this->accountingDb()->table('customer_openings')->insert([
            'company_id' => 1, 'branch_id' => 1, 'customer_id' => $customerId, 'revision' => $revision, 'side' => 'owes_us', 'amount' => '10.0000', 'opening_date' => date('Y-m-d'), 'status' => $status,
        ]);
    }

    public function testSchema_TheTableRefusesASecondStandingRevisionOfOneCustomerButNotAReversedOne(): void
    {
        $this->rawOpening($this->customerId, 1, 'reversed');
        $this->rawOpening($this->customerId, 2, 'reversed');
        $this->rawOpening($this->customerId, 3, 'posted');

        $this->expectException(Throwable::class);

        $this->rawOpening($this->customerId, 4, 'posted');
    }

    public function testSchema_TheTableRefusesTwoRowsWithTheNumberOfOneRevision(): void
    {
        $this->rawOpening($this->customerId, 1, 'reversed');

        $this->expectException(Throwable::class);

        $this->rawOpening($this->customerId, 1, 'reversed');
    }

    public function testSchema_AnApplicationOfCreditIsSpentOnAnInvoiceOrOnAnOpeningBalanceAndNeverOnBothOrNeither(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $this->rawOpening($this->customerId, 1, 'posted');
        $opening = (int) $this->accountingDb()->table('customer_openings')->get()->getRow()->opening_id;
        $row     = ['customer_id' => $this->customerId, 'source_type' => 'credit_note', 'source_id' => 1, 'amount' => '1.0000'];

        foreach ([$row + ['invoice_sale_id' => $invoice, 'opening_id' => $opening], $row] as $both) {
            try {
                $this->accountingDb()->table('customer_credit_applications')->insert($both);
                $this->fail('An application of credit with both targets or none must be refused.');
            } catch (Throwable $failure) {
                $this->assertStringNotContainsString('An application of credit', $failure->getMessage());
            }
        }

        $this->assertTrue($this->accountingDb()->table('customer_credit_applications')->insert($row + ['invoice_sale_id' => $invoice]));
        $this->assertTrue($this->accountingDb()->table('customer_credit_applications')->insert($row + ['opening_id' => $opening]));
    }

    public function testReviseAndCancel_ValidateTheReasonBeforeTheyLookAtTheOpeningThatStandsOrNot(): void
    {
        $first = $this->owe();
        $this->openings->cancel($first, 'Entered twice', $this->clerk);

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '5.00', date('Y-m-d'), '', $reason, $this->clerk));
            $this->assertRefusedWith(AccountingError::ReasonRequired, fn () => $this->openings->cancel($first, $reason, $this->clerk));
        }
    }

    public function testReviseAndCancel_RefuseAnOpeningMarkedReversedEvenWhenItsJournalStillStands(): void
    {
        $first = $this->owe();
        $this->accountingDb()->table('customer_openings')->where('opening_id', $first)->update(['status' => 'reversed']);
        $counts = [$this->countRows('customer_openings'), $this->countJournals()];

        $this->assertRefusedWith(AccountingError::CustomerOpeningReversed, fn () => $this->openings->cancel($first, 'Again', $this->clerk));
        $this->assertRefusedWith(AccountingError::CustomerOpeningReversed, fn () => $this->openings->revise($first, CustomerOpening::OWES_US, '5.00', date('Y-m-d'), '', 'Again', $this->clerk));
        $this->assertSame('posted', $this->journalOf(1)->status);
        $this->assertSame($counts, [$this->countRows('customer_openings'), $this->countJournals()]);
    }
}
