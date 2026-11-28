<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\PostingMode;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\ExpenseDocument;
use App\Libraries\Accounting\ExpenseFacts;
use App\Libraries\Accounting\ExpenseJournal;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class ExpenseJournalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $treasuryId;

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

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Journal drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->accountingDb()->table('accounting_posting_shadow_diffs')->truncate();
    }

    protected function tearDown(): void
    {
        $this->mode(PostingMode::Shadow);
        $this->accountingDb()->table('accounting_posting_shadow_diffs')->truncate();

        parent::tearDown();
    }

    private function mode(PostingMode $mode): void
    {
        $this->accountingDb()->table('accounting_posting_modes')->replace(['company_id' => 1, 'operation' => 'expense', 'mode' => $mode->value]);
    }

    private function expense(string $amount = '115.00', string $tax = '15.00', string $payment = 'Cash', ?int $category = null, ?int $treasury = null, int $id = 31): ExpenseDocument
    {
        return new ExpenseDocument($id, '2026-10-21', $amount, $tax, $payment, $category, $treasury, 1, false);
    }

    /**
     * Both journals of an expense, or the failure of each, side by side.
     *
     * @return array{0: JournalDraft|string|null, 1: JournalDraft|string|null}
     */
    private function both(ExpenseDocument $expense): array
    {
        $outcome = static function (callable $build): JournalDraft|string|null {
            try {
                return $build();
            } catch (PostingException $failure) {
                return $failure->error->value;
            }
        };

        return [
            $outcome(static fn () => (new ExpensePostingBuilder(new RoutingResolver()))->expense($expense, 1, 1)),
            $outcome(static fn () => (new PostingEngine())->build(ExpenseFacts::of($expense, 1, 1))),
        ];
    }

    public function testDraft_AgreesWithTheCodeOfTheExpenseOnEveryKindOfExpense(): void
    {
        $category = $this->customAccount('52', '5299', 'Office rent');
        $cases    = [];

        foreach (['115.00', '-115.00', '0'] as $amount) {
            foreach (['0', '15.00', '115.00'] as $tax) {
                foreach ([lang('Sales.cash'), lang('Sales.check'), lang('Sales.debit'), lang('Sales.credit'), lang('Sales.bank_transfer'), lang('Sales.wallet'), lang('Sales.due'), lang('Sales.giftcard'), 'Barter'] as $payment) {
                    foreach ([null, $category] as $categoryId) {
                        foreach ([null, $this->treasuryId] as $treasuryId) {
                            $signed  = str_starts_with($amount, '-') && $tax !== '0' ? '-' . $tax : $tax;
                            $cases[] = $this->expense($amount, $signed, $payment, $categoryId, $treasuryId);
                        }
                    }
                }
            }
        }

        $cases[] = $this->expense('100.00', '-15.00');
        $cases[] = $this->expense('100.00', '150.00');

        foreach ($cases as $index => $expense) {
            [$legacy, $engine] = $this->both($expense);
            $label             = "case {$index}: {$expense->amount}/{$expense->taxAmount}/{$expense->paymentType}/" . ($expense->categoryAccountId ?? '-') . '/' . ($expense->treasuryId ?? '-');

            if (is_string($legacy) || is_string($engine) || $legacy === null || $engine === null) {
                $this->assertSame(is_string($legacy) || $legacy === null ? $legacy : 'a journal', is_string($engine) || $engine === null ? $engine : 'a journal', $label);

                continue;
            }

            $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), $label);
            $this->assertSame([$legacy->sourceType, $legacy->sourceId, $legacy->revision, $legacy->date, $legacy->branchId, $legacy->entryType, $legacy->description], [$engine->sourceType, $engine->sourceId, $engine->revision, $engine->date, $engine->branchId, $engine->entryType, $engine->description], $label);
        }

        $this->assertGreaterThan(300, count($cases));
    }

    public function testDraft_AgreesWithTheCodeOfTheExpenseWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        foreach ([RoutingKey::TaxInput, RoutingKey::ExpensesDefault, RoutingKey::Treasuries] as $key) {
            foreach (['cancelled', 'per_item'] as $type) {
                $this->reinstallChart();
                $this->reopenEntityAccounts(['treasury' => [$this->treasuryId]]);
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                $both = $this->both($this->expense(treasury: $key === RoutingKey::Treasuries ? $this->treasuryId : null));

                $this->assertIsString($both[0], "{$key->value} {$type}");
                $this->assertSame($both[0], $both[1], "{$key->value} {$type}");
            }
        }
    }

    public function testDraft_PostsTheJournalOfTheCodeAndWritesNoDifferenceInShadow(): void
    {
        $this->mode(PostingMode::Shadow);

        $draft = (new ExpenseJournal())->draft($this->expense(), 1, 1);

        $this->assertEquals((new ExpensePostingBuilder(new RoutingResolver()))->expense($this->expense(), 1, 1), $draft);
        $this->assertSame(0, $this->countRows('accounting_posting_shadow_diffs'));
    }

    public function testDraft_WritesTheDifferenceWhenTheRulesSayOtherwiseAndStillPostsTheJournalOfTheCode(): void
    {
        $this->mode(PostingMode::Shadow);
        $set = (new PostingEngine())->rules()->current('expense', '2026-10-21');
        $this->accountingDb()->table('accounting_posting_rules')->where(['rule_set_id' => $set->ruleSetId, 'line_no' => 3])->update(['amount' => 'tax * 2']);

        try {
            $draft = (new ExpenseJournal())->draft($this->expense(), 1, 1);
        } finally {
            $this->accountingDb()->table('accounting_posting_rules')->where(['rule_set_id' => $set->ruleSetId, 'line_no' => 3])->update(['amount' => 'tax']);
        }

        $recorded = (new ShadowComparison())->recorded('expense');

        $this->assertEquals((new ExpensePostingBuilder(new RoutingResolver()))->expense($this->expense(), 1, 1), $draft);
        $this->assertCount(1, $recorded);
        $this->assertSame([31, $set->ruleSetId], [$recorded[0]['source_id'], (int) $this->accountingDb()->table('accounting_posting_shadow_diffs')->get()->getRow()->rule_set_id]);
        $this->assertSame([$this->accountId('tax_input') . '|debit|30.0000|1||||'], $recorded[0]['differences']['only_engine']);
    }

    public function testDraft_PostsTheJournalOfTheEngineInEngineMode(): void
    {
        $this->mode(PostingMode::Engine);
        $set = (new PostingEngine())->rules()->current('expense', '2026-10-21');
        $this->accountingDb()->table('accounting_posting_rules')->where(['rule_set_id' => $set->ruleSetId, 'line_no' => 2])->update(['account' => '{"first":[{"role":"sales.cogs"}]}']);

        try {
            $draft = (new ExpenseJournal())->draft($this->expense(), 1, 1);
        } finally {
            $this->accountingDb()->table('accounting_posting_rules')->where(['rule_set_id' => $set->ruleSetId, 'line_no' => 2])->update(['account' => '{"first":[{"field":"category_account_id"},{"role":"expenses.default"}]}']);
        }

        $this->assertSame($this->accountId('cogs'), $draft->lines[1]->accountId);
        $this->assertSame(0, $this->countRows('accounting_posting_shadow_diffs'));
    }

    public function testDraft_PostsTheJournalOfTheCodeAndAsksNothingOfTheEngineInLegacyMode(): void
    {
        $this->mode(PostingMode::Legacy);
        $set = (new PostingEngine())->rules()->current('expense', '2026-10-21');
        $this->accountingDb()->table('accounting_posting_rules')->where(['rule_set_id' => $set->ruleSetId, 'line_no' => 3])->update(['amount' => 'tax * 2']);

        try {
            $draft = (new ExpenseJournal())->draft($this->expense(), 1, 1);
        } finally {
            $this->accountingDb()->table('accounting_posting_rules')->where(['rule_set_id' => $set->ruleSetId, 'line_no' => 3])->update(['amount' => 'tax']);
        }

        $this->assertEquals((new ExpensePostingBuilder(new RoutingResolver()))->expense($this->expense(), 1, 1), $draft);
        $this->assertSame(0, $this->countRows('accounting_posting_shadow_diffs'));
    }

    public function testCrossesBranches_FollowsTheRulesInEngineModeOnly(): void
    {
        $this->assertTrue((new ExpenseJournal())->crossesBranches('2026-10-21'));

        $this->mode(PostingMode::Engine);
        $set = (new PostingEngine())->rules()->current('expense', '2026-10-21');
        $this->accountingDb()->table('accounting_posting_rule_sets')->where('rule_set_id', $set->ruleSetId)->update(['cross_branch' => 0]);

        try {
            $this->assertFalse((new ExpenseJournal())->crossesBranches('2026-10-21'));
        } finally {
            $this->accountingDb()->table('accounting_posting_rule_sets')->where('rule_set_id', $set->ruleSetId)->update(['cross_branch' => 1]);
        }
    }
}
