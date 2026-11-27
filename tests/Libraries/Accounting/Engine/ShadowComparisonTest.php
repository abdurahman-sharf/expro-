<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\JournalDraft;
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

        $this->assertSame(['only_legacy' => ['5|debit|100.0000|1|'], 'only_engine' => ['6|debit|100.0000|1|']], ShadowComparison::differences($this->draft($base), $this->draft([new JournalLineDraft(6, debit: '100'), new JournalLineDraft(9, credit: '100')])));
    }

    public function testCompare_WritesDownADifferenceAndAFailureOfTheEngine(): void
    {
        $legacy = $this->draft([new JournalLineDraft(5, debit: '100'), new JournalLineDraft(9, credit: '100')]);

        $this->assertFalse((new ShadowComparison())->compare('expense', $legacy, null, 3, 'expense', 41, 2));
        $this->assertFalse((new ShadowComparison())->compare('expense', $legacy, null, 3, 'expense', 42, 1, new PostingException(PostingError::RuleMissing, 'No rule.')));

        $recorded = (new ShadowComparison())->recorded('expense');

        $this->assertSame([42, 41], array_column($recorded, 'source_id'));
        $this->assertSame('rule_missing: No rule.', $recorded[0]['differences']['engine_failed']);
        $this->assertSame(['5|debit|100.0000|1|', '9|credit|100.0000|1|'], $recorded[1]['differences']['only_legacy']);
        $this->assertSame([], (new ShadowComparison())->recorded('sale'));
    }
}
