<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\PostingFacts;
use App\Libraries\Accounting\Engine\PostingRules;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PostingEngineTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PostingRules $rules;
    private PostingEngine $engine;

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
        $this->accountingDb()->table('accounting_posting_rule_sets')->where('operation', 'probe')->delete();
        $this->rules  = new PostingRules();
        $this->engine = new PostingEngine(null, $this->rules);
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('accounting_posting_rule_sets')->where('operation', 'probe')->delete();

        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>       $header
     */
    private function publish(array $lines, string $from = '2000-01-01', array $header = []): int
    {
        return $this->rules->publish('probe', $from, $header + ['source_type' => 'probe', 'description' => 'Probe #{source_id}'], $lines, 1);
    }

    /**
     * @param array<string, mixed> $more
     */
    private function facts(array $more = []): PostingFacts
    {
        return new PostingFacts(...array_merge(['operation' => 'probe', 'sourceId' => 7, 'date' => '2026-10-21', 'branchId' => 1, 'revision' => 2, 'employeeId' => 1], $more));
    }

    /**
     * @return list<string>
     */
    private function shape(?JournalDraft $draft): array
    {
        return $draft === null ? [] : array_map(static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4) . ($line->costCenterId === null ? '' : '@' . $line->costCenterId) . ($line->foreignAmount === null ? '' : '#' . $line->foreignAmount . $line->currencyCode), $draft->lines);
    }

    private function role(string $role): array
    {
        return ['first' => [['role' => $role]]];
    }

    public function testBuild_PostsEachLineOnItsSideWithTheAmountOfItsExpressionAndTheJournalOfTheRules(): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => $this->role('expenses.default'), 'amount' => 'net'],
            ['line_no' => 2, 'side' => 'debit', 'account' => $this->role('tax.input'), 'amount' => 'tax'],
            ['line_no' => 3, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'net + tax'],
        ], header: ['entry_type' => 'revenue']);

        $draft = $this->engine->build($this->facts(['figures' => ['net' => '100', 'tax' => '15']]));

        $this->assertSame([$this->accountId('expenses_default') . ':100.0000:0.0000', $this->accountId('tax_input') . ':15.0000:0.0000', $this->accountId('cash') . ':0.0000:115.0000'], $this->shape($draft));
        $this->assertSame(['probe', 7, 2, 1, '2026-10-21', 'Probe #7', JournalEntryType::Revenue], [$draft->sourceType, $draft->sourceId, $draft->revision, $draft->branchId, $draft->date, $draft->description, $draft->entryType]);
    }

    public function testBuild_PutsANegativeAmountOnTheOtherSideAndLeavesOutALineOfNothing(): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => $this->role('expenses.default'), 'amount' => 'net'],
            ['line_no' => 2, 'side' => 'debit', 'account' => $this->role('tax.input'), 'amount' => 'tax'],
            ['line_no' => 3, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'net + tax'],
        ]);

        $this->assertSame([$this->accountId('expenses_default') . ':0.0000:40.0000', $this->accountId('cash') . ':40.0000:0.0000'], $this->shape($this->engine->build($this->facts(['figures' => ['net' => '-40', 'tax' => '0']]))));
        $this->assertNull($this->engine->build($this->facts(['figures' => ['net' => '0', 'tax' => '0']])));
    }

    public function testBuild_TakesTheAccountFromTheFirstPlaceThatGivesOneWhoseConditionHolds(): void
    {
        $treasury = (new TreasuryService())->create(['name' => 'Branch drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => ['first' => [['field' => 'category_account_id'], ['role' => 'expenses.default']]], 'amount' => 'net'],
            ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['when' => 'is_due', 'system' => 'other_payables'], ['role' => 'treasuries.accounts', 'entity' => 'treasury_id'], ['payment' => 'payment_type']]], 'amount' => 'net'],
        ]);
        $chosen = $this->customAccount('52', '5299', 'Chosen');
        $build  = fn (array $fields, array $flags = []): array => $this->shape($this->engine->build($this->facts(['figures' => ['net' => '10'], 'fields' => $fields, 'flags' => $flags])));

        $this->assertSame([$chosen . ':10.0000:0.0000', $this->accountId('other_payables') . ':0.0000:10.0000'], $build(['category_account_id' => $chosen, 'treasury_id' => $treasury], ['is_due' => true]));
        $this->assertSame([$this->accountId('expenses_default') . ':10.0000:0.0000', (new TreasuryService())->accountOf($treasury) . ':0.0000:10.0000'], $build(['category_account_id' => null, 'treasury_id' => $treasury]));
        $this->assertSame($this->accountId('cash') . ':0.0000:10.0000', $build(['treasury_id' => null, 'payment_type' => lang('Sales.cash')])[1]);
    }

    public function testBuild_RejectsALineNoPlaceGivesAnAccountWithTheFailureTheRuleNames(): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => $this->role('sales.discount'), 'amount' => 'net'],
            ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['when' => 'never', 'system' => 'cash']], 'else' => 'payment_type_unknown'], 'amount' => 'net'],
        ]);

        $this->assertBuildFails(PostingError::RoutingRowCancelled, ['figures' => ['net' => '10']], 'sales.discount');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::SalesDiscount->value)->update(['routing_type' => 'auto', 'account_id' => $this->accountId('sales_discount')]);

        $this->assertBuildFails(PostingError::PaymentTypeUnknown, ['figures' => ['net' => '10'], 'fields' => ['payment_type' => 'Cash']]);
    }

    public function testBuild_RequiresNoJournalOnlyWhenTheConditionOfAPlaceThatSaysSoHolds(): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => ['first' => [['when' => 'on_credit', 'no_journal' => true], ['when' => 'paid', 'system' => 'cash']], 'else' => 'payment_type_unknown'], 'amount' => 'net'],
            ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['system' => 'other_payables']]], 'amount' => 'net'],
        ]);

        $this->assertNull($this->engine->build($this->facts(['figures' => ['net' => '100'], 'flags' => ['on_credit' => true]])));
        $this->assertSame([$this->accountId('cash') . ':100.0000:0.0000', $this->accountId('other_payables') . ':0.0000:100.0000'], $this->shape($this->engine->build($this->facts(['figures' => ['net' => '100'], 'flags' => ['paid' => true]]))));
        $this->assertBuildFails(PostingError::PaymentTypeUnknown, ['figures' => ['net' => '100'], 'fields' => ['payment_type' => 'Cash']]);
    }

    public function testBuild_PostsALineOnceForEachEntryOfTheListItRepeatsOverWithTheFiguresAndFieldsOfTheEntry(): void
    {
        $vat   = $this->accountId('tax_output');
        $other = $this->customAccount('215', '215099', 'Another tax');
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'total'],
            ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['field' => 'tax_account_id'], ['role' => 'tax.output']]], 'amount' => 'tax', 'repeat_over' => 'taxes'],
        ]);

        $draft = $this->engine->build($this->facts([
            'figures' => ['total' => '25'],
            'lists'   => ['taxes' => [['figures' => ['tax' => '15'], 'fields' => ['tax_account_id' => null]], ['figures' => ['tax' => '10'], 'fields' => ['tax_account_id' => $other]], ['figures' => ['tax' => '0']]]],
        ]));

        $this->assertSame([$this->accountId('cash') . ':25.0000:0.0000', $vat . ':0.0000:15.0000', $other . ':0.0000:10.0000'], $this->shape($draft));
    }

    public function testBuild_KeepsTheLinesOfOneEntryTogetherWhenConsecutiveLinesRepeatOverTheSameList(): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => ['first' => [['field' => 'account_id']]], 'amount' => 'net', 'repeat_over' => 'lines'],
            ['line_no' => 2, 'side' => 'debit', 'account' => $this->role('tax.input'), 'amount' => 'tax', 'repeat_over' => 'lines'],
            ['line_no' => 3, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'total'],
        ]);

        $draft = $this->engine->build($this->facts([
            'figures' => ['total' => '130'],
            'lists'   => ['lines' => [['figures' => ['net' => '100', 'tax' => '15'], 'fields' => ['account_id' => $this->accountId('purchases')]], ['figures' => ['net' => '15', 'tax' => '0'], 'fields' => ['account_id' => $this->accountId('expenses_default')]]]],
        ]));

        $this->assertSame([$this->accountId('purchases') . ':100.0000:0.0000', $this->accountId('tax_input') . ':15.0000:0.0000', $this->accountId('expenses_default') . ':15.0000:0.0000', $this->accountId('cash') . ':0.0000:130.0000'], $this->shape($draft));
    }

    public function testBuild_CarriesTheBranchTheCostCenterAndTheForeignAmountOfTheDocument(): void
    {
        $second = $this->createBranch('B2');
        $center = $this->accountingDb()->table('accounting_cost_centers')->insert(['code' => 'CC1', 'name' => 'Center']) ? (int) $this->accountingDb()->insertID() : 0;
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => $this->role('expenses.default'), 'amount' => 'net * 3.75', 'foreign_amount' => 'net', 'cost_center' => ['field' => 'cost_center_id']],
            ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'net * 3.75', 'foreign_amount' => 'net'],
        ]);

        $draft = $this->engine->build($this->facts(['branchId' => $second, 'figures' => ['net' => '10'], 'fields' => ['cost_center_id' => $center], 'currencyCode' => 'USD']));

        $this->assertSame($second, $draft->branchId);
        $this->assertSame([$this->accountId('expenses_default') . ':37.5000:0.0000@' . $center . '#10.0000USD', $this->accountId('cash') . ':0.0000:37.5000#10.0000USD'], $this->shape($draft));
    }

    public function testBuild_FollowsTheVersionThatAppliesOnTheDayOfTheDocument(): void
    {
        $this->publish([['line_no' => 1, 'side' => 'debit', 'account' => $this->role('expenses.default'), 'amount' => 'net'], ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'net']]);
        $this->publish([['line_no' => 1, 'side' => 'debit', 'account' => $this->role('tax.input'), 'amount' => 'net'], ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'net']], '2026-06-01');

        $this->assertSame($this->accountId('expenses_default') . ':10.0000:0.0000', $this->shape($this->engine->build($this->facts(['date' => '2026-05-31', 'figures' => ['net' => '10']])))[0]);
        $this->assertSame($this->accountId('tax_input') . ':10.0000:0.0000', $this->shape((new PostingEngine())->build($this->facts(['date' => '2026-06-01', 'figures' => ['net' => '10']])))[0]);
    }

    public function testBuild_RejectsAnOperationThatHasNoRulesOnTheDay(): void
    {
        $this->publish([['line_no' => 1, 'side' => 'debit', 'account' => $this->role('expenses.default'), 'amount' => 'net']], '2026-06-01');

        $this->assertBuildFails(PostingError::RuleMissing, ['date' => '2026-05-31', 'figures' => ['net' => '10']]);
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function assertBuildFails(PostingError $error, array $facts, ?string $routingKey = null): void
    {
        try {
            $this->engine->build($this->facts($facts));
            $this->fail('The journal was built.');
        } catch (PostingException $failure) {
            $this->assertSame($error, $failure->error);

            if ($routingKey !== null) {
                $this->assertSame($routingKey, $failure->context['routing_key']);
            }
        }
    }
}
