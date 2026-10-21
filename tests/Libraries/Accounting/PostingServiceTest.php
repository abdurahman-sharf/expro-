<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureLog;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

class PostingServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private PostingService $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearJournals();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->accountingDb()->table('accounting_accounts')->where('active', 0)->update(['active' => 1]);
        $this->service = new PostingService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    public function testPost_WritesBalancedPostedJournalWithItsLines(): void
    {
        $result = $this->service->post($this->balancedDraft(amount: '115.5'));

        $this->assertTrue($result->created);

        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $result->journalId)->get()->getRow();
        $this->assertSame('posted', $journal->status);
        $this->assertSame('115.5000', $journal->total_debit);
        $this->assertSame('115.5000', $journal->total_credit);
        $this->assertSame('SAR', $journal->currency_code);
        $this->assertSame('system', $journal->origin);
        $this->assertSame('sale', $journal->source_type);

        $lines = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $result->journalId)->orderBy('line_no')->get()->getResult();
        $this->assertCount(2, $lines);
        $this->assertSame('115.5000', $lines[0]->debit);
        $this->assertSame('0.0000', $lines[0]->credit);
        $this->assertSame('115.5000', $lines[1]->credit);
    }

    public function testPost_NumbersManualJournalsSequentially(): void
    {
        $first  = $this->service->post($this->balancedDraft(sourceType: 'manual', sourceId: null));
        $second = $this->service->post($this->balancedDraft(sourceType: 'manual', sourceId: null));

        $numbers = $this->accountingDb()->table('accounting_journals')
            ->whereIn('journal_id', [$first->journalId, $second->journalId])
            ->orderBy('journal_id')
            ->get()
            ->getResultArray();

        $this->assertSame(['000001', '000002'], array_column($numbers, 'number'));
        $this->assertSame(['manual', 'manual'], array_column($numbers, 'series'));
    }

    public function testPost_NumbersAutomaticJournalsFromTheirOwnId(): void
    {
        $result  = $this->service->post($this->balancedDraft(sourceId: 7));
        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $result->journalId)->get()->getRow();

        $this->assertSame('auto', $journal->series);
        $this->assertSame(sprintf('%06d', $result->journalId), $journal->number);
    }

    public function testPost_ReturnsTheExistingJournalForTheSameSourceAndRevision(): void
    {
        $first  = $this->service->post($this->balancedDraft(sourceId: 11));
        $second = $this->service->post($this->balancedDraft(sourceId: 11));

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->journalId, $second->journalId);
        $this->assertSame(1, $this->countRows('accounting_journals'));
        $this->assertSame(2, $this->countRows('accounting_journal_lines'));
    }

    public function testPost_AcceptsANewRevisionOfTheSameSource(): void
    {
        $first  = $this->service->post($this->balancedDraft(sourceId: 11, revision: 1));
        $second = $this->service->post($this->balancedDraft(sourceId: 11, revision: 2, amount: '90.00'));

        $this->assertTrue($second->created);
        $this->assertNotSame($first->journalId, $second->journalId);
        $this->assertSame(2, $this->countRows('accounting_journals'));
    }

    public function testPost_RecordsWhoPostedAndTheOrigin(): void
    {
        $draft = new JournalDraft(
            date: '2026-10-21',
            lines: $this->balancedDraft()->lines,
            sourceType: 'manual',
            origin: JournalOrigin::User,
            employeeId: 5,
            currencyCode: 'SAR',
        );

        $result  = $this->service->post($draft);
        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $result->journalId)->get()->getRow();
        $audit   = $this->accountingDb()->table('accounting_audit_log')->where('entity_id', $result->journalId)->get()->getRow();

        $this->assertSame('user', $journal->origin);
        $this->assertSame('5', (string) $journal->posted_by);
        $this->assertSame('posted', $audit->action);
        $this->assertSame('user', $audit->origin);
        $this->assertSame('5', (string) $audit->employee_id);
    }

    public function testPost_UsesTheConfiguredBaseCurrencyWhenTheDraftHasNone(): void
    {
        $draft = new JournalDraft(date: '2026-10-21', lines: $this->balancedDraft()->lines, sourceType: 'sale', sourceId: 3);

        $result  = $this->service->post($draft);
        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $result->journalId)->get()->getRow();

        $this->assertSame('SAR', $journal->currency_code);
    }

    public function testPost_RejectsAMissingBaseCurrency(): void
    {
        $this->setConfigValue('accounting_base_currency', '');
        $draft = new JournalDraft(date: '2026-10-21', lines: $this->balancedDraft()->lines, sourceType: 'sale', sourceId: 3);

        $this->assertPostingFails(PostingError::InvalidCurrency, $draft);
    }

    public function testPost_RejectsAnUnbalancedJournalAndWritesNothing(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('cash'), debit: '100.00'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '99.99'),
        ]);

        $failure = $this->assertPostingFails(PostingError::Unbalanced, $draft);

        $this->assertSame('100.0000', $failure->context['debit']);
        $this->assertSame('99.9900', $failure->context['credit']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_journal_lines'));
    }

    public function testPost_RecordsTheReasonWhenItRejectsAJournal(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('cash'), debit: '100.00'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '40.00'),
        ]);

        $this->assertPostingFails(PostingError::Unbalanced, $draft);

        $failure = $this->accountingDb()->table('accounting_posting_failures')->get()->getRow();
        $this->assertSame('unbalanced', $failure->error_code);
        $this->assertSame('sale', $failure->source_type);
        $this->assertSame('1', (string) $failure->source_id);
    }

    public function testPost_RejectsAJournalWithASingleLine(): void
    {
        $draft = $this->draftWithLines([new JournalLineDraft($this->accountId('cash'), debit: '10.00')]);

        $this->assertPostingFails(PostingError::TooFewLines, $draft);
    }

    public function testPost_RejectsALineWithBothADebitAndACredit(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('cash'), debit: '10.00', credit: '5.00'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '5.00'),
        ]);

        $this->assertPostingFails(PostingError::LineOnBothSides, $draft);
    }

    public function testPost_RejectsALineWithoutAnAmount(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('cash'), debit: '10.00'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
            new JournalLineDraft($this->accountId('cogs')),
        ]);

        $this->assertPostingFails(PostingError::LineWithoutAmount, $draft);
    }

    #[DataProvider('providePost_RejectsAMalformedOrNegativeAmount')]
    public function testPost_RejectsAMalformedOrNegativeAmount(string $amount): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('cash'), debit: $amount),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
        ]);

        $this->assertPostingFails(PostingError::InvalidAmount, $draft);
    }

    public static function providePost_RejectsAMalformedOrNegativeAmount(): iterable
    {
        yield 'negative' => ['-5.00'];

        yield 'too many decimals' => ['1.23456'];

        yield 'text' => ['abc'];

        yield 'empty' => [''];

        yield 'exponent' => ['1e3'];

        yield 'thousands separator' => ['1,000.00'];
    }

    public function testPost_RejectsAGroupAccount(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountIdByCode('1'), debit: '10.00'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
        ]);

        $this->assertPostingFails(PostingError::AccountNotPostable, $draft);
    }

    public function testPost_RejectsAnInactiveAccount(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'cash')->update(['active' => 0]);

        $this->assertPostingFails(PostingError::AccountNotPostable, $this->balancedDraft());
    }

    public function testPost_RejectsAnUnknownAccount(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft(999999, debit: '10.00'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
        ]);

        $this->assertPostingFails(PostingError::AccountNotFound, $draft);
    }

    public function testPost_RejectsAnInvalidDate(): void
    {
        $this->assertPostingFails(PostingError::InvalidDate, $this->draftWithLines($this->balancedDraft()->lines, '2026-02-30'));
        $this->assertPostingFails(PostingError::InvalidDate, $this->draftWithLines($this->balancedDraft()->lines, '21/10/2026'));
    }

    public function testPost_RejectsAManualJournalThatCarriesASourceId(): void
    {
        $this->assertPostingFails(PostingError::InvalidSource, $this->balancedDraft(sourceType: 'manual', sourceId: 4));
    }

    public function testPost_RejectsAnAutomaticJournalWithoutASourceId(): void
    {
        $this->assertPostingFails(PostingError::InvalidSource, $this->balancedDraft(sourceType: 'sale', sourceId: null));
    }

    public function testPost_RejectsAnInvalidSourceType(): void
    {
        $this->assertPostingFails(PostingError::InvalidSource, $this->balancedDraft(sourceType: 'Sale Order', sourceId: 1));
    }

    public function testPost_RejectsAnUnknownCostCenter(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('cash'), debit: '10.00', costCenterId: 999999),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
        ]);

        $this->assertPostingFails(PostingError::CostCenterNotFound, $draft);
    }

    public function testPost_AcceptsAnActiveCostCenter(): void
    {
        $this->accountingDb()->table('accounting_cost_centers')->insert(['code' => 'CC-1', 'name' => 'Main', 'active' => 1]);
        $costCenterId = (int) $this->accountingDb()->insertID();

        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('cash'), debit: '10.00', costCenterId: $costCenterId),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
        ]);

        $result = $this->service->post($draft);

        $line = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $result->journalId)->where('line_no', 1)->get()->getRow();
        $this->assertSame((string) $costCenterId, (string) $line->cost_center_id);
    }

    public function testPost_RejectsAPartyWithAnUnknownType(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('receivables'), debit: '10.00', partyType: 'employee', partyId: 3),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
        ]);

        $this->assertPostingFails(PostingError::InvalidParty, $draft);
    }

    public function testPost_AcceptsACustomerPartyOnAReceivablesLine(): void
    {
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('receivables'), debit: '10.00', partyType: 'customer', partyId: 3),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
        ]);

        $result = $this->service->post($draft);

        $line = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $result->journalId)->where('line_no', 1)->get()->getRow();
        $this->assertSame('customer', $line->party_type);
        $this->assertSame('3', (string) $line->party_id);
    }

    public function testPost_RollsBackEverythingAndRecordsTheFailureWhenTheWriteFails(): void
    {
        $this->service->post($this->balancedDraft(sourceType: 'manual', sourceId: null));
        $this->accountingDb()->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 0]);

        $this->assertPostingFails(PostingError::WriteFailed, $this->balancedDraft(sourceType: 'manual', sourceId: null));

        $this->assertSame(1, $this->countRows('accounting_journals'));
        $this->assertSame(2, $this->countRows('accounting_journal_lines'));
        $this->assertSame(1, $this->countRows('accounting_audit_log'));

        $sequence = $this->accountingDb()->table('accounting_sequences')->where('series', 'manual')->get()->getRow();
        $this->assertSame('0', (string) $sequence->last_number);

        $recorded = $this->accountingDb()->table('accounting_posting_failures')->get()->getResult();
        $this->assertCount(1, $recorded);
        $this->assertSame('write_failed', $recorded[0]->error_code);
    }

    public function testPost_JoinsTheCallersTransactionAndCommitsWithIt(): void
    {
        $db = $this->accountingDb();

        $db->transStart();
        $result = $this->service->post($this->balancedDraft(sourceId: 21));
        $db->transComplete();

        $this->assertTrue($db->transStatus());
        $this->assertSame(1, $this->countRows('accounting_journals'));
        $this->assertSame($result->journalId, (int) $db->table('accounting_journals')->get()->getRow()->journal_id);
    }

    public function testPost_JoinsTheCallersTransactionAndRollsBackWithIt(): void
    {
        $db = $this->accountingDb();

        $db->transStart();
        $this->service->post($this->balancedDraft(sourceId: 22));
        $this->assertSame(1, $this->countRows('accounting_journals'));
        $db->transRollback();

        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_journal_lines'));
        $this->assertSame(0, $this->countRows('accounting_audit_log'));
    }

    public function testPost_LeavesFailureLoggingToTheCallerInsideItsTransaction(): void
    {
        $db    = $this->accountingDb();
        $draft = $this->draftWithLines([
            new JournalLineDraft($this->accountId('cash'), debit: '100.00'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '1.00'),
        ]);

        $db->transStart();

        try {
            $this->service->post($draft);
            $this->fail('The unbalanced journal should have been rejected.');
        } catch (PostingException $failure) {
            $this->assertSame(0, $this->countRows('accounting_posting_failures'));
            $this->assertSame(1, $db->transDepth);
            $db->transRollback();
            (new PostingFailureLog())->record($draft, $failure);
        }

        $this->assertSame(0, $db->transDepth);
        $this->assertSame(1, $this->countRows('accounting_posting_failures'));
    }

    public function testPost_KeepsTheCallersTransactionDepthBalancedWhenTheWriteFails(): void
    {
        $db = $this->accountingDb();

        $this->service->post($this->balancedDraft(sourceType: 'manual', sourceId: null));
        $db->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 0]);

        $db->transStart();
        $this->service->post($this->balancedDraft(sourceId: 31));

        try {
            $this->service->post($this->balancedDraft(sourceType: 'manual', sourceId: null));
            $this->fail('The colliding manual number should have failed the write.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::WriteFailed, $failure->error);
        }

        $this->assertSame(1, $db->transDepth);
        $db->transRollback();

        $this->assertSame(0, $db->transDepth);
        $this->assertSame(1, $this->countRows('accounting_journals'));
    }

    public function testPost_DoesNotRecordAFailureWhenAManualJournalIsRejected(): void
    {
        $draft = new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '10.00')],
            sourceType: 'manual',
            origin: JournalOrigin::User,
            currencyCode: 'SAR',
        );

        $this->assertPostingFails(PostingError::TooFewLines, $draft);

        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testPost_WritesTheReversedJournalIdOfAReversal(): void
    {
        $original = $this->service->post($this->balancedDraft(sourceId: 51));

        $reversal = $this->service->post(new JournalDraft(
            date: '2026-10-22',
            lines: [
                new JournalLineDraft($this->accountId('cash'), credit: '100.00'),
                new JournalLineDraft($this->accountId('sales_revenue'), debit: '100.00'),
            ],
            sourceType: JournalDraft::SOURCE_REVERSAL,
            sourceId: $original->journalId,
            currencyCode: 'SAR',
            reversesJournalId: $original->journalId,
        ));

        $row = $this->accountingDb()->table('accounting_journals')->where('journal_id', $reversal->journalId)->get()->getRow();
        $this->assertSame((string) $original->journalId, (string) $row->reverses_journal_id);
    }

    public function testPost_StampsThePostingTimeWithTheDatabaseClock(): void
    {
        $result = $this->service->post($this->balancedDraft(sourceId: 63));

        $row = $this->accountingDb()->query(
            'SELECT TIMESTAMPDIFF(SECOND, posted_at, created_at) AS gap FROM ' . $this->accountingDb()->prefixTable('accounting_journals') . ' WHERE journal_id = ?',
            [$result->journalId],
        )->getRow();

        $this->assertLessThanOrEqual(1, abs((int) $row->gap));
    }

    public function testPost_NumbersAReversalInTheManualSeries(): void
    {
        $manual   = $this->service->post($this->balancedDraft(sourceType: JournalDraft::SOURCE_MANUAL, sourceId: null));
        $original = $this->service->post($this->balancedDraft(sourceId: 62));

        $reversal = $this->service->post(new JournalDraft(
            date: '2026-10-22',
            lines: [
                new JournalLineDraft($this->accountId('cash'), credit: '100.00'),
                new JournalLineDraft($this->accountId('sales_revenue'), debit: '100.00'),
            ],
            sourceType: JournalDraft::SOURCE_REVERSAL,
            sourceId: $original->journalId,
            currencyCode: 'SAR',
            reversesJournalId: $original->journalId,
        ));

        $manualRow   = $this->accountingDb()->table('accounting_journals')->where('journal_id', $manual->journalId)->get()->getRow();
        $reversalRow = $this->accountingDb()->table('accounting_journals')->where('journal_id', $reversal->journalId)->get()->getRow();
        $this->assertSame('manual', $reversalRow->series);
        $this->assertSame('000001', $manualRow->number);
        $this->assertSame('000002', $reversalRow->number);
    }

    public function testPost_AllowsAnInactiveAccountOnlyForAReversal(): void
    {
        $original = $this->service->post($this->balancedDraft(sourceId: 61));
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'cash')->update(['active' => 0]);

        $lines = [
            new JournalLineDraft($this->accountId('cash'), debit: '10.00'),
            new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00'),
        ];

        $this->assertPostingFails(PostingError::AccountNotPostable, $this->draftWithLines($lines));

        $result = $this->service->post(new JournalDraft(
            date: '2026-10-22',
            lines: $lines,
            sourceType: JournalDraft::SOURCE_REVERSAL,
            sourceId: $original->journalId,
            currencyCode: 'SAR',
            reversesJournalId: $original->journalId,
        ));

        $this->assertTrue($result->created);
    }

    public function testValidate_ReturnsTheNormalizedValuesWithoutWritingAnything(): void
    {
        $prepared = $this->service->validate($this->balancedDraft(amount: '12.5'));

        $this->assertSame('12.5000', $prepared['debit']);
        $this->assertSame('12.5000', $prepared['credit']);
        $this->assertSame('SAR', $prepared['currency']);
        $this->assertCount(2, $prepared['lines']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    private function draftInBranch(?int $branchId, ?int $sourceId = 1, string $sourceType = 'sale', ?int $reverses = null): JournalDraft
    {
        $reversal = $reverses !== null;

        return new JournalDraft(
            date: '2026-10-21',
            lines: [
                new JournalLineDraft($this->accountId('cash'), debit: $reversal ? '0' : '100.00', credit: $reversal ? '100.00' : '0'),
                new JournalLineDraft($this->accountId('sales_revenue'), debit: $reversal ? '100.00' : '0', credit: $reversal ? '0' : '100.00'),
            ],
            sourceType: $sourceType,
            sourceId: $sourceId,
            currencyCode: 'SAR',
            reversesJournalId: $reverses,
            branchId: $branchId,
        );
    }

    private function branchOf(int $journalId): int
    {
        return (int) $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow()->branch_id;
    }

    public function testPost_StoresTheDefaultBranchWhenNoneIsGiven(): void
    {
        $result = $this->service->post($this->balancedDraft());

        $this->assertSame(1, $this->branchOf($result->journalId));
    }

    public function testPost_StoresTheBranchOfTheDraft(): void
    {
        $second = $this->createBranch('B2');

        $result = $this->service->post($this->draftInBranch($second));

        $this->assertSame($second, $this->branchOf($result->journalId));
    }

    public function testPost_AuditsTheJournalInItsBranch(): void
    {
        $second = $this->createBranch('B2');

        $result = $this->service->post($this->draftInBranch($second));

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'journal')->where('entity_id', $result->journalId)->get()->getRow();

        $this->assertSame($second, (int) $audit->branch_id);
    }

    public function testPost_RejectsABranchThatDoesNotExist(): void
    {
        $this->assertPostingFails(PostingError::BranchNotFound, $this->draftInBranch(987654));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPost_RejectsAnInactiveBranch(): void
    {
        $inactive = $this->createBranch('B2', active: false);

        $this->assertPostingFails(PostingError::BranchInactive, $this->draftInBranch($inactive));
    }

    public function testPost_AcceptsAReversalInABranchDeactivatedAfterThePosting(): void
    {
        $second   = $this->createBranch('B2');
        $original = $this->service->post($this->draftInBranch($second, 70));

        $this->accountingDb()->table('branches')->where('branch_id', $second)->update(['active' => 0]);

        $reversal = $this->service->post($this->draftInBranch($second, $original->journalId, JournalDraft::SOURCE_REVERSAL, $original->journalId));

        $this->assertTrue($reversal->created);
        $this->assertSame($second, $this->branchOf($reversal->journalId));
    }

    public function testPost_RejectsASourceAlreadyPostedInAnotherBranch(): void
    {
        $second = $this->createBranch('B2');

        $this->service->post($this->draftInBranch(1, 80));

        $this->assertPostingFails(PostingError::BranchMismatch, $this->draftInBranch($second, 80));
        $this->assertSame(1, $this->countRows('accounting_journals'));
    }

    public function testPost_ReturnsTheExistingJournalForTheSameSourceInTheSameBranch(): void
    {
        $second = $this->createBranch('B2');

        $first  = $this->service->post($this->draftInBranch($second, 81));
        $repeat = $this->service->post($this->draftInBranch($second, 81));

        $this->assertFalse($repeat->created);
        $this->assertSame($first->journalId, $repeat->journalId);
    }

    public function testPost_ReturnsTheExistingJournalWhenTheRepeatNamesNoBranch(): void
    {
        $second = $this->createBranch('B2');

        $first  = $this->service->post($this->draftInBranch($second, 82));
        $repeat = $this->service->post($this->draftInBranch(null, 82));

        $this->assertFalse($repeat->created);
        $this->assertSame($first->journalId, $repeat->journalId);
    }

    public function testValidate_ReturnsTheBranchTheJournalWouldBePostedIn(): void
    {
        $second = $this->createBranch('B2');

        $this->assertSame($second, $this->service->validate($this->draftInBranch($second))['branch_id']);
        $this->assertSame(1, $this->service->validate($this->balancedDraft())['branch_id']);
    }

    private function assertPostingFails(PostingError $expected, JournalDraft $draft): PostingException
    {
        try {
            $this->service->post($draft);
        } catch (PostingException $failure) {
            $this->assertSame($expected, $failure->error);

            return $failure;
        }

        $this->fail('Expected posting to fail with ' . $expected->value . '.');
    }
}
