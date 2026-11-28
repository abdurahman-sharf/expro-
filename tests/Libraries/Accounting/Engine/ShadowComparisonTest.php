<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class ShadowComparisonTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function expectsShadowDifferences(): bool
    {
        return true;
    }

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountingDb()->table('accounting_posting_shadow_diffs')->truncate();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('accounting_posting_shadow_diffs')->truncate();

        parent::tearDown();
    }

    /**
     * @param list<JournalLineDraft> $lines
     */
    private function draft(array $lines, int $branchId = 1, string $description = 'Expense #1'): JournalDraft
    {
        return new JournalDraft(date: '2026-10-21', lines: $lines, sourceType: 'expense', sourceId: 1, description: $description, branchId: $branchId);
    }

    public function testDifferences_AcceptsTwoJournalsWhoseLinesAgreeWhateverTheirOrderAndDescription(): void
    {
        $legacy = $this->draft([new JournalLineDraft(5, debit: '100.00'), new JournalLineDraft(7, debit: '15'), new JournalLineDraft(9, credit: '115.0000')]);
        $engine = $this->draft([new JournalLineDraft(9, credit: '115'), new JournalLineDraft(5, debit: '100.0000'), new JournalLineDraft(7, debit: '15.00')], description: 'Another');

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine));
        $this->assertTrue((new ShadowComparison())->compare('expense', $legacy, $engine, null, 'expense', 1, 1));
        $this->assertSame(0, $this->countRows('accounting_posting_shadow_diffs'));
    }

    public function testDifferences_RejectsAnotherAccountSideAmountBranchOrCostCenter(): void
    {
        $base = [new JournalLineDraft(5, debit: '100'), new JournalLineDraft(9, credit: '100')];

        foreach ([
            'account'     => $this->draft([new JournalLineDraft(6, debit: '100'), new JournalLineDraft(9, credit: '100')]),
            'side'        => $this->draft([new JournalLineDraft(5, credit: '100'), new JournalLineDraft(9, debit: '100')]),
            'amount'      => $this->draft([new JournalLineDraft(5, debit: '100.01'), new JournalLineDraft(9, credit: '100.01')]),
            'branch'      => $this->draft($base, 2),
            'cost center' => $this->draft([new JournalLineDraft(5, debit: '100', costCenterId: 3), new JournalLineDraft(9, credit: '100')]),
            'a line more' => $this->draft([...$base, new JournalLineDraft(5, debit: '0.01'), new JournalLineDraft(9, credit: '0.01')]),
        ] as $what => $engine) {
            $differences = ShadowComparison::differences($this->draft($base), $engine);

            $this->assertNotSame(['only_legacy' => [], 'only_engine' => []], $differences, $what);
        }

        $this->assertSame(['only_legacy' => ['5|debit|100.0000|1||||'], 'only_engine' => ['6|debit|100.0000|1||||']], ShadowComparison::differences($this->draft($base), $this->draft([new JournalLineDraft(6, debit: '100'), new JournalLineDraft(9, credit: '100')])));
    }

    public function testDifferences_RejectsAnotherForeignAmountOrCurrencyOnALine(): void
    {
        $legacy = $this->draft([new JournalLineDraft(5, debit: '375', foreignAmount: '100', currencyCode: 'USD'), new JournalLineDraft(9, credit: '375', foreignAmount: '100', currencyCode: 'USD')]);

        foreach ([
            'foreign amount'    => [new JournalLineDraft(5, debit: '375', foreignAmount: '101', currencyCode: 'USD'), new JournalLineDraft(9, credit: '375', foreignAmount: '100', currencyCode: 'USD')],
            'currency'          => [new JournalLineDraft(5, debit: '375', foreignAmount: '100', currencyCode: 'EUR'), new JournalLineDraft(9, credit: '375', foreignAmount: '100', currencyCode: 'USD')],
            'no foreign amount' => [new JournalLineDraft(5, debit: '375'), new JournalLineDraft(9, credit: '375', foreignAmount: '100', currencyCode: 'USD')],
        ] as $what => $lines) {
            $differences = ShadowComparison::differences($legacy, $this->draft($lines));

            $this->assertCount(1, $differences['only_legacy'], $what);
            $this->assertSame('5|debit|375.0000|1||100.0000|USD|', $differences['only_legacy'][0], $what);
        }

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $this->draft([new JournalLineDraft(9, credit: '375.00', foreignAmount: '100.0000', currencyCode: 'USD'), new JournalLineDraft(5, debit: '375', foreignAmount: '100.00', currencyCode: 'USD')])));
    }

    public function testDifferences_RejectsALineMarkedAsTheTaxWorkedOutOfAnotherOnOneSideOnly(): void
    {
        $legacy = $this->draft([new JournalLineDraft(5, debit: '100'), new JournalLineDraft(6, debit: '15', autoTax: true), new JournalLineDraft(9, credit: '115')]);
        $engine = $this->draft([new JournalLineDraft(5, debit: '100'), new JournalLineDraft(6, debit: '15'), new JournalLineDraft(9, credit: '115')]);

        $this->assertSame(['only_legacy' => ['6|debit|15.0000|1||||auto_tax'], 'only_engine' => ['6|debit|15.0000|1||||']], ShadowComparison::differences($legacy, $engine));
        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $legacy));
    }

    public function testDifferences_RejectsAnotherDateSourceKindOrBranchOfTheJournal(): void
    {
        $lines  = [new JournalLineDraft(5, debit: '100'), new JournalLineDraft(9, credit: '100')];
        $legacy = new JournalDraft(date: '2026-10-21', lines: $lines, sourceType: 'expense', sourceId: 1, branchId: 1);

        foreach ([
            'date'   => [new JournalDraft(date: '2026-10-22', lines: $lines, sourceType: 'expense', sourceId: 1, branchId: 1), '2026-10-22|expense|general|1'],
            'source' => [new JournalDraft(date: '2026-10-21', lines: $lines, sourceType: 'revenue', sourceId: 1, branchId: 1), '2026-10-21|revenue|general|1'],
            'kind'   => [new JournalDraft(date: '2026-10-21', lines: $lines, sourceType: 'expense', sourceId: 1, branchId: 1, entryType: JournalEntryType::Revenue), '2026-10-21|expense|revenue|1'],
            'branch' => [new JournalDraft(date: '2026-10-21', lines: $lines, sourceType: 'expense', sourceId: 1, branchId: 2), '2026-10-21|expense|general|2'],
        ] as $what => [$engine, $head]) {
            $differences = ShadowComparison::differences($legacy, $engine);

            $this->assertSame('head 2026-10-21|expense|general|1', $differences['only_legacy'][0], $what);
            $this->assertSame('head ' . $head, $differences['only_engine'][0], $what);
        }
    }

    public function testCompare_WritesDownADifferenceAndAFailureOfTheEngine(): void
    {
        $legacy = $this->draft([new JournalLineDraft(5, debit: '100'), new JournalLineDraft(9, credit: '100')]);

        $this->assertFalse((new ShadowComparison())->compare('expense', $legacy, null, 3, 'expense', 41, 2));
        $this->assertFalse((new ShadowComparison())->compare('expense', $legacy, null, 3, 'expense', 42, 1, new PostingException(PostingError::RuleMissing, 'No rule.')));

        $recorded = (new ShadowComparison())->recorded('expense');

        $this->assertSame([42, 41], array_column($recorded, 'source_id'));
        $this->assertSame('rule_missing: No rule.', $recorded[0]['differences']['engine_failed']);
        $this->assertSame(['5|debit|100.0000|1||||', '9|credit|100.0000|1||||'], $recorded[1]['differences']['only_legacy']);
        $this->assertSame([], (new ShadowComparison())->recorded('sale'));
    }
}
