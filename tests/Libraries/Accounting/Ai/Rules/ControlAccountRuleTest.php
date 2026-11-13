<?php

namespace Tests\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\Rules\ControlAccountRule;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AiFixtureTrait;

/**
 * A manual journal on the account of a customer, a supplier or a stock location, which the documents own.
 *
 * @internal
 */
final class ControlAccountRuleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AiFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ControlAccountRule $rule;
    private EntityAccountService $entities;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startAgent();
        $this->rule     = new ControlAccountRule();
        $this->entities = new EntityAccountService();
    }

    protected function tearDown(): void
    {
        $this->stopAgent();

        parent::tearDown();
    }

    private function onCustomer(string $postedAt = '2026-10-10 10:00:00', ?int $branchId = null): int
    {
        $customer = $this->insertCustomer();
        $this->entities->fill();

        return $this->manualJournal('2026-10-10', $this->entities->accountFor('customer', $customer), $this->accountId('sales_revenue'), '300.00', 'Adjust', $postedAt, $branchId);
    }

    public function testDetect_FlagsAManualJournalOnTheAccountOfACustomer(): void
    {
        $journal = $this->onCustomer();

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('AI-CONTROL-MANUAL', $finding->rule);
        $this->assertSame(Finding::HIGH, $finding->severity);
        $this->assertSame((string) $journal, $finding->key);
        $this->assertSame($journal, $finding->subjectId);
        $this->assertSame(1, $finding->branchId);
        $this->assertSame(1, $finding->templateArgs['accounts']);
        $this->assertSame('journal', $finding->evidence[0]['type']);
        $this->assertSame('line', $finding->evidence[1]['type']);
        $this->assertSame(1, $finding->evidence[1]['line_no']);
        $this->assertSame('customer', $finding->evidence[1]['entity_type']);
        $this->assertSame('300.0000', $finding->evidence[1]['debit']);
    }

    public function testDetect_FlagsTheAccountOfASupplierAndOfAStockLocation(): void
    {
        $supplier = $this->insertSupplier();
        $location = $this->insertStockLocation();
        $this->entities->fill();

        $one = $this->manualJournal('2026-10-10', $this->rentExpense(), $this->entities->accountFor('supplier', $supplier), '100.00', 'Supplier', '2026-10-10 10:00:00');
        $two = $this->manualJournal('2026-10-10', $this->entities->accountFor('stock_location', $location), $this->cashAccount(), '100.00', 'Stock', '2026-10-10 10:00:00');

        $this->assertSame([(string) $one, (string) $two], array_map(static fn (Finding $finding): string => $finding->key, $this->rule->detect($this->ruleContext())));
    }

    public function testDetect_GivesOneFindingForAJournalWithTwoControlLines(): void
    {
        $one = $this->insertCustomer('Ali', 'One');
        $two = $this->insertCustomer('Ali', 'Two');
        $this->entities->fill();
        $this->manualJournal('2026-10-10', $this->entities->accountFor('customer', $one), $this->entities->accountFor('customer', $two), '300.00', 'Move', '2026-10-10 10:00:00');

        $findings = $this->rule->detect($this->ruleContext());

        $this->assertCount(1, $findings);
        $this->assertSame(2, $findings[0]->templateArgs['accounts']);
        $this->assertCount(3, $findings[0]->evidence);
    }

    public function testDetect_FlagsNothingForAManualJournalOnOrdinaryAccounts(): void
    {
        $this->manualJournal('2026-10-10', $this->rentExpense(), $this->cashAccount(), '300.00', 'Rent', '2026-10-10 10:00:00');

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LeavesOutTheJournalsOfDocuments(): void
    {
        $customer = $this->insertCustomer();
        $this->entities->fill();

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-10',
            lines: [new JournalLineDraft($this->entities->accountFor('customer', $customer), debit: '300.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '300.00')],
            sourceType: 'sale',
            sourceId: 9,
            branchId: 1,
        ));
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'sale')->update(['posted_at' => '2026-10-10 10:00:00']);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LeavesOutAJournalThatWasReversed(): void
    {
        $journal = $this->onCustomer();
        (new JournalService())->reverse($journal, '2026-10-11', 'Wrong', null);
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->update(['posted_at' => '2026-10-11 10:00:00']);

        $this->assertSame([], $this->rule->detect($this->ruleContext()));
    }

    public function testDetect_LooksOnlyAtJournalsPostedSinceTheStartOfTheScan(): void
    {
        $this->onCustomer('2026-10-10 10:00:00');

        $this->assertCount(1, $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-10')));
        $this->assertSame([], $this->rule->detect($this->ruleContext(null, '2026-11-08', '2026-10-11')));
    }

    public function testDetect_KeepsToTheBranchesGiven(): void
    {
        $branch = $this->createBranch();
        $this->onCustomer('2026-10-10 10:00:00', $branch);

        $this->assertCount(1, $this->rule->detect($this->ruleContext([$branch])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([1])));
        $this->assertSame([], $this->rule->detect($this->ruleContext([])));
    }

    public function testDetect_GivesTheSameFindingsEveryTime(): void
    {
        $this->onCustomer();

        $this->assertEquals($this->rule->detect($this->ruleContext()), $this->rule->detect($this->ruleContext()));
        $this->assertSame('AI-CONTROL-MANUAL', $this->rule->code());
    }
}
