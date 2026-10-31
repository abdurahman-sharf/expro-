<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ChequeDirection;
use App\Libraries\Accounting\ChequeDocument;
use App\Libraries\Accounting\ChequePostingBuilder;
use App\Libraries\Accounting\ChequeSources;
use App\Libraries\Accounting\ChequeStatus;
use App\Libraries\Accounting\ChequeTransition;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The journals a cheque needs when its status changes, as drafts: what each status debits and
 * credits, the one that is split in two, and the fees of a deposited cheque that is collected.
 */
class ChequePostingBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ChequePostingBuilder $builder;
    private int $bank;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->builder = new ChequePostingBuilder(new RoutingResolver());
        $this->bank    = (new TreasuryService())->create(['name' => 'Builder bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC')), 'branch_id' => 0]);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopBuying();

        parent::tearDown();
    }

    private function cheque(ChequeDirection $direction, string $amount = '100.00'): ChequeDocument
    {
        return new ChequeDocument(
            chequeId: 31,
            direction: $direction,
            status: $direction === ChequeDirection::Received ? ChequeStatus::Received : ChequeStatus::Issued,
            partyId: $direction === ChequeDirection::Received ? $this->customerId : $this->supplierId,
            treasuryId: $this->bank,
            branchId: 3,
            chequeNumber: '710001',
            amount: bcadd($amount, '0', 4),
            issueDate: '2026-10-01',
            dueDate: '2026-10-20',
        );
    }

    /**
     * @return list<string> "account:debit:credit" in line order.
     */
    private function linesOf(JournalDraft $draft): array
    {
        return array_map(static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $draft->lines);
    }

    /**
     * @return list<JournalDraft>
     */
    private function draftsOf(ChequeTransition $transition, ChequeDirection $direction = ChequeDirection::Received, string $fees = '0', string $amount = '100.00'): array
    {
        return $this->builder->drafts($this->cheque($direction, $amount), $transition, '2026-10-05', bcadd($fees, '0', 4), 4, 9);
    }

    private function bankAccount(): int
    {
        return (int) (new TreasuryService())->accountOf($this->bank);
    }

    public function testDrafts_ReceiveCreditsTheCustomerAndDebitsNotesReceivable(): void
    {
        [$draft] = $this->draftsOf(ChequeTransition::Receive);

        $this->assertSame([$this->accountId('notes_receivable') . ':100.0000:0.0000', $this->customerAccount() . ':0.0000:100.0000'], $this->linesOf($draft));
        $this->assertSame(ChequeSources::RECEIVED, $draft->sourceType);
        $this->assertSame(31, $draft->sourceId);
        $this->assertSame(4, $draft->revision);
        $this->assertSame('2026-10-05', $draft->date);
        $this->assertSame(3, $draft->branchId);
        $this->assertSame(9, $draft->employeeId);
        $this->assertSame('Cheque 710001 received', $draft->description);
        $this->assertSame(JournalOrigin::System, $draft->origin);
        $this->assertSame(JournalEntryType::General, $draft->entryType);
    }

    public function testDrafts_DepositMovesTheChequeToFundsUnderCollection(): void
    {
        [$draft] = $this->draftsOf(ChequeTransition::Deposit);

        $this->assertSame([$this->accountId('cheques_under_collection') . ':100.0000:0.0000', $this->accountId('notes_receivable') . ':0.0000:100.0000'], $this->linesOf($draft));
        $this->assertSame(ChequeSources::DEPOSITED, $draft->sourceType);
    }

    public function testDrafts_CollectDebitsTheBankAndCreditsNotesReceivable(): void
    {
        [$draft] = $this->draftsOf(ChequeTransition::Collect);

        $this->assertSame([$this->bankAccount() . ':100.0000:0.0000', $this->accountId('notes_receivable') . ':0.0000:100.0000'], $this->linesOf($draft));
        $this->assertSame(ChequeSources::COLLECTED, $draft->sourceType);
    }

    public function testDrafts_CollectAfterADepositSplitsTheBankAndTheFeesAndClearsFundsUnderCollection(): void
    {
        $drafts = $this->draftsOf(ChequeTransition::DepositCollect, ChequeDirection::Received, '2.50');

        $this->assertCount(1, $drafts);
        $this->assertSame([
            $this->bankAccount() . ':97.5000:0.0000',
            $this->accountId('cheque_deposit_fees') . ':2.5000:0.0000',
            $this->accountId('cheques_under_collection') . ':0.0000:100.0000',
        ], $this->linesOf($drafts[0]));
        $this->assertSame(ChequeSources::DEPOSIT_COLLECTED, $drafts[0]->sourceType);
    }

    public function testDrafts_CollectAfterADepositWithNoFeesPostsNoFeeLine(): void
    {
        [$draft] = $this->draftsOf(ChequeTransition::DepositCollect);

        $this->assertSame([$this->bankAccount() . ':100.0000:0.0000', $this->accountId('cheques_under_collection') . ':0.0000:100.0000'], $this->linesOf($draft));
    }

    public function testDrafts_CollectAfterADepositPostsNoBankLineWhenTheFeesTakeEverything(): void
    {
        [$draft] = $this->draftsOf(ChequeTransition::DepositCollect, ChequeDirection::Received, '100.00');

        $this->assertSame([$this->accountId('cheque_deposit_fees') . ':100.0000:0.0000', $this->accountId('cheques_under_collection') . ':0.0000:100.0000'], $this->linesOf($draft));
    }

    public function testDrafts_CollectAfterADepositRefusesFeesBelowZeroOrAboveTheCheque(): void
    {
        foreach (['-0.01', '100.01'] as $fees) {
            try {
                $this->draftsOf(ChequeTransition::DepositCollect, ChequeDirection::Received, $fees);
                $this->fail('The fees ' . $fees . ' were not refused.');
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::ChequeFeesInvalid, $exception->error, $fees);
            }
        }
    }

    public function testDrafts_RejectGivesTheCustomerBackWhatTheChequeTook(): void
    {
        $drafts = $this->draftsOf(ChequeTransition::Reject);

        $this->assertCount(1, $drafts);
        $this->assertSame([$this->customerAccount() . ':100.0000:0.0000', $this->accountId('notes_receivable') . ':0.0000:100.0000'], $this->linesOf($drafts[0]));
        $this->assertSame(ChequeSources::REJECTED, $drafts[0]->sourceType);
    }

    public function testDrafts_RejectAfterADepositWritesTwoJournalsInTheOrderTheyArePosted(): void
    {
        $drafts = $this->draftsOf(ChequeTransition::DepositReject);

        $this->assertCount(2, $drafts);
        $this->assertSame([$this->customerAccount() . ':100.0000:0.0000', $this->accountId('notes_receivable') . ':0.0000:100.0000'], $this->linesOf($drafts[0]));
        $this->assertSame([$this->accountId('notes_receivable') . ':100.0000:0.0000', $this->accountId('cheques_under_collection') . ':0.0000:100.0000'], $this->linesOf($drafts[1]));
        $this->assertSame([4, 5], [$drafts[0]->revision, $drafts[1]->revision]);
        $this->assertSame([ChequeSources::DEPOSIT_REJECTED, ChequeSources::DEPOSIT_REJECTED], [$drafts[0]->sourceType, $drafts[1]->sourceType]);
    }

    public function testDrafts_DeliverDebitsTheSupplierAndCreditsNotesPayable(): void
    {
        [$draft] = $this->draftsOf(ChequeTransition::Deliver, ChequeDirection::Paid, '0', '200.00');

        $this->assertSame([$this->supplierAccount() . ':200.0000:0.0000', $this->accountId('notes_payable') . ':0.0000:200.0000'], $this->linesOf($draft));
        $this->assertSame(ChequeSources::DELIVERED, $draft->sourceType);
    }

    public function testDrafts_CollectOfAPaidChequeDebitsNotesPayableAndCreditsTheBank(): void
    {
        [$draft] = $this->draftsOf(ChequeTransition::PayCollect, ChequeDirection::Paid, '0', '200.00');

        $this->assertSame([$this->accountId('notes_payable') . ':200.0000:0.0000', $this->bankAccount() . ':0.0000:200.0000'], $this->linesOf($draft));
        $this->assertSame(ChequeSources::PAID_COLLECTED, $draft->sourceType);
    }

    public function testDrafts_RejectOfAPaidChequeGivesTheSupplierBackWhatDeliveryTook(): void
    {
        [$draft] = $this->draftsOf(ChequeTransition::PayReject, ChequeDirection::Paid, '0', '200.00');

        $this->assertSame([$this->accountId('notes_payable') . ':200.0000:0.0000', $this->supplierAccount() . ':0.0000:200.0000'], $this->linesOf($draft));
        $this->assertSame(ChequeSources::PAID_REJECTED, $draft->sourceType);
    }

    public function testDrafts_IssueAndCancelPostNothing(): void
    {
        $this->assertSame([], $this->draftsOf(ChequeTransition::Issue, ChequeDirection::Paid));
        $this->assertSame([], $this->draftsOf(ChequeTransition::Cancel, ChequeDirection::Paid));
    }

    public function testDrafts_RefuseAReceiptWhenTheRoutingOfNotesReceivableIsSetToATypePostingDoesNotApply(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::NotesReceivable->value)->update(['routing_type' => RoutingType::Cancelled->value]);

        try {
            $this->draftsOf(ChequeTransition::Receive);

            $this->fail('A posting failure was expected.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
            $this->assertSame('cheques.receivable', $failure->context['routing_key']);
        }
    }
}
