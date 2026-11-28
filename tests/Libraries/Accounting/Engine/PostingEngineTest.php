<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\PostingFacts;
use App\Libraries\Accounting\Engine\PostingRules;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ForeignConversion;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PurchaseFacts;
use App\Libraries\Accounting\ReceivingDocument;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
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

    public function testBuild_RequiresAutoTaxOnTheJournalLineOfARuleLineThatSaysSo(): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => $this->role('expenses.default'), 'amount' => 'net'],
            ['line_no' => 2, 'side' => 'debit', 'account' => $this->role('tax.input'), 'amount' => 'tax', 'auto_tax' => true],
            ['line_no' => 3, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'net + tax'],
        ]);

        $draft = $this->engine->build($this->facts(['figures' => ['net' => '100', 'tax' => '15']]));

        $this->assertSame([false, true, false], array_map(static fn ($line): bool => $line->autoTax, $draft->lines));
    }

    /**
     * Rules of a foreign journal: debits of the entries of a list, a tax and a credit of the total, the
     * rounding put as the rounding says.
     *
     * @param array<string, mixed> $rounding
     */
    private function publishForeign(array $rounding): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => ['first' => [['field' => 'account_id']]], 'amount' => 'value', 'repeat_over' => 'parts'],
            ['line_no' => 2, 'side' => 'debit', 'account' => $this->role('tax.input'), 'amount' => 'tax'],
            ['line_no' => 3, 'side' => 'credit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'total'],
        ], header: ['foreign_rounding' => $rounding]);
    }

    /**
     * @param list<string> $parts
     */
    private function foreignFacts(array $parts, string $tax, string $rate, ?string $currency = 'USD'): PostingFacts
    {
        $accounts = [$this->accountId('expenses_default'), $this->accountId('other_payables'), $this->accountId('revenues_default')];
        $entries  = array_map(static fn (string $value, int $index): array => ['fields' => ['account_id' => $accounts[$index % 3]], 'figures' => ['value' => $value]], $parts, array_keys($parts));
        $total    = array_reduce([...$parts, $tax], static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0');

        return $this->facts(['figures' => ['tax' => $tax, 'total' => $total], 'lists' => ['parts' => $entries], 'currencyCode' => $currency, 'currencyRate' => $rate, 'baseDecimals' => 2]);
    }

    /**
     * What the code makes of the same journal written in the currency: ForeignConversion with the line given.
     *
     * @param list<string> $parts
     */
    private function convertedByTheCode(array $parts, string $tax, string $rate, int $balancing): JournalDraft
    {
        $accounts = [$this->accountId('expenses_default'), $this->accountId('other_payables'), $this->accountId('revenues_default')];
        $lines    = array_map(static fn (string $value, int $index): JournalLineDraft => new JournalLineDraft($accounts[$index % 3], debit: $value), $parts, array_keys($parts));
        $total    = array_reduce([...$parts, $tax], static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0');

        if (bccomp($tax, '0', 4) > 0) {
            $lines[] = new JournalLineDraft($this->accountId('tax_input'), debit: $tax);
        }

        $lines[] = new JournalLineDraft($this->accountId('cash'), credit: $total);

        return ForeignConversion::toBase(new JournalDraft('2026-10-21', $lines, 'probe', 7, branchId: 1), new DocumentCurrency('USD', $rate, 2), 2, $balancing);
    }

    public function testBuild_RequiresAForeignJournalInTheBaseWithTheRoundingOnTheFirstLargestDebitTheRulesDoNotExcept(): void
    {
        $this->publishForeign(['balancing' => 'largest_debit', 'except' => [2]]);

        foreach ([
            'the largest of three that round'          => [['33.33', '33.33', '33.34'], '15.01', '3.7512', 2],
            'the first of two that are as large'       => [['50.00', '50.00'], '7.77', '3.7333', 0],
            'not the tax, though it is the largest'    => [['10.01', '20.02'], '99.99', '0.2667', 1],
            'one line among many that are smaller'     => [['0.01', '0.02', '0.03', '0.05', '0.04'], '0.01', '3.3333', 3],
        ] as $what => [$parts, $tax, $rate, $balancing]) {
            $engine = $this->engine->build($this->foreignFacts($parts, $tax, $rate));

            $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($this->convertedByTheCode($parts, $tax, $rate, $balancing), $engine), $what);
            $this->assertSame([], array_values(array_filter($engine->lines, static fn ($line): bool => $line->currencyCode !== 'USD')), $what);
        }
    }

    public function testBuild_RequiresTheRoundingOnTheFirstLineWhenTheRulesSaySoAndNoConversionInTheBase(): void
    {
        $this->publishForeign(['balancing' => 'first']);

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($this->convertedByTheCode(['33.33', '33.34'], '0', '3.7512', 0), $this->engine->build($this->foreignFacts(['33.33', '33.34'], '0', '3.7512'))));
        $this->assertSame([$this->accountId('expenses_default') . ':10.0000:0.0000', $this->accountId('other_payables') . ':20.0000:0.0000', $this->accountId('cash') . ':0.0000:30.0000'], $this->shape($this->engine->build($this->foreignFacts(['10.00', '20.00'], '0', '3.75', null))));
    }

    public function testBuild_RequiresTheRoundingOnTheFirstLargestLineOfTheLinesNamedAndElseOnTheFirstOfTheOthers(): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => ['first' => [['system' => 'cash']]], 'amount' => 'total'],
            ['line_no' => 2, 'side' => 'credit', 'account' => ['first' => [['field' => 'account_id']]], 'amount' => 'value', 'repeat_over' => 'parts'],
            ['line_no' => 3, 'side' => 'credit', 'account' => $this->role('tax.output'), 'amount' => 'tax'],
            ['line_no' => 4, 'side' => 'credit', 'account' => ['first' => [['system' => 'other_payables']]], 'amount' => 'shipping'],
        ], header: ['foreign_rounding' => ['balancing' => 'largest_of', 'lines' => [2], 'else' => [4]]]);

        $accounts = [$this->accountId('revenues_default'), $this->accountId('expenses_default')];
        $facts    = fn (array $parts, string $tax, string $shipping): PostingFacts => $this->facts([
            'figures'      => ['tax' => $tax, 'shipping' => $shipping, 'total' => array_reduce([...$parts, $tax, $shipping], static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0')],
            'lists'        => ['parts' => array_map(static fn (string $value, int $index): array => ['fields' => ['account_id' => $accounts[$index % 2]], 'figures' => ['value' => $value]], $parts, array_keys($parts))],
            'currencyCode' => 'USD', 'currencyRate' => '3.7512', 'baseDecimals' => 2,
        ]);
        $byTheCode = function (array $parts, string $tax, string $shipping, int $balancing) use ($accounts): JournalDraft {
            $total = array_reduce([...$parts, $tax, $shipping], static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0');
            $lines = [new JournalLineDraft($this->accountId('cash'), debit: $total)];

            foreach ($parts as $index => $value) {
                $lines[] = new JournalLineDraft($accounts[$index % 2], credit: $value);
            }

            foreach ([[$this->accountId('tax_output'), $tax], [$this->accountId('other_payables'), $shipping]] as [$account, $amount]) {
                if (bccomp($amount, '0', 4) > 0) {
                    $lines[] = new JournalLineDraft($account, credit: $amount);
                }
            }

            return ForeignConversion::toBase(new JournalDraft('2026-10-21', $lines, 'probe', 7, branchId: 1), new DocumentCurrency('USD', '3.7512', 2), 2, $balancing);
        };

        foreach ([
            'the largest of the goods'           => [['33.33', '33.34', '33.33'], '15.01', '20.00', 2],
            'the first of two that are as large' => [['50.00', '50.00'], '15.00', '0', 1],
            'the shipping when there are none'   => [[], '3.00', '20.01', 2],
        ] as $what => [$parts, $tax, $shipping, $balancing]) {
            $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($byTheCode($parts, $tax, $shipping, $balancing), $this->engine->build($facts($parts, $tax, $shipping))), $what);
        }

        try {
            $this->engine->build($facts([], '3.00', '0'));
            $this->fail('A journal with neither line was brought to the base.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::ForeignRoundingTooBig, $failure->error);
        }
    }

    public function testBuild_RejectsAForeignJournalWithNoRateOrARoundingLargerThanRounding(): void
    {
        $this->publishForeign(['balancing' => 'largest_debit']);

        $this->assertBuildFails(PostingError::ForeignRateMismatch, ['figures' => ['tax' => '0', 'total' => '10'], 'lists' => ['parts' => [['fields' => ['account_id' => $this->accountId('expenses_default')], 'figures' => ['value' => '10']]]], 'currencyCode' => 'USD']);

        try {
            $this->engine->build($this->foreignFacts(['0.01'], '0', '0.4'));
            $this->fail('The journal was built.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::ForeignRoundingTooBig, $failure->error);
        }
    }

    /**
     * The code and the engine on foreign purchases of many lines, before the rules of the purchase use the
     * rounding: the rules here are the lines of a purchase under another name.
     */
    public function testBuild_RequiresTheJournalOfTheCodeForRandomForeignPurchasesOfManyLines(): void
    {
        $this->publish([
            ['line_no' => 1, 'side' => 'debit', 'account' => ['first' => [['role' => 'inventory.locations', 'entity' => 'location_id']]], 'amount' => 'value', 'repeat_over' => 'locations'],
            ['line_no' => 2, 'side' => 'debit', 'account' => $this->role('purchases.purchases'), 'amount' => 'net'],
            ['line_no' => 3, 'side' => 'debit', 'account' => $this->role('tax.input'), 'amount' => 'tax'],
            ['line_no' => 4, 'side' => 'credit', 'account' => ['first' => [['when' => 'has_supplier', 'role' => 'purchases.suppliers', 'entity' => 'supplier_id']], 'else' => 'supplier_required'], 'amount' => 'total'],
        ], header: ['source_type' => 'purchase', 'foreign_rounding' => ['balancing' => 'largest_debit', 'except' => [3]]]);

        $entities  = new EntityAccountService();
        $supplier  = $this->insertSupplier();
        $locations = [$this->insertStockLocation('Rounding store A'), $this->insertStockLocation('Rounding store B'), $this->insertStockLocation('Rounding store C')];

        $entities->ensure(EntityAccountService::SUPPLIER, $supplier, 'Rounding supplier');

        foreach ($locations as $location) {
            $entities->ensure(EntityAccountService::STOCK_LOCATION, $location, 'Rounding store ' . $location);
        }

        $builder = new ReceivingPostingBuilder(new RoutingResolver(), new StockLedger(), static fn (): int => 2);
        $random  = new Randomizer(new Xoshiro256StarStar(20261128));
        $count   = 0;

        for ($case = 0; $case < 300; $case++) {
            $lines = [];

            for ($index = 0, $size = $random->getInt(1, 7); $index < $size; $index++) {
                $lines[] = new ReceivingLine($index + 1, $index, (string) $random->getInt(1, 9), '1', $random->getInt(1, 99999) / 100 . '', '0', 0, ['0', '5', '15'][$random->getInt(0, 2)], $locations[$random->getInt(0, 2)], $random->getInt(0, 3) > 0, '0', vatWorkedOnce: $case % 2 === 0);
            }

            $rate     = number_format($random->getInt(1, 99999) / 10000, 4, '.', '');
            $purchase = new ReceivingDocument(900 + $case, '2026-10-21', ReceivingType::Receive, $supplier, 1, null, null, $lines, currency: new DocumentCurrency('USD', $rate, 2));

            try {
                $legacy = $builder->purchase($purchase, 1, 1)->journal;
            } catch (PostingException $failure) {
                $legacy = $failure->error->value;
            }

            try {
                $given  = PurchaseFacts::receive($purchase, 1, 1, 2);
                $engine = $this->engine->build(new PostingFacts('probe', $given->sourceId, $given->date, $given->branchId, 1, 1, $given->figures, $given->fields, $given->flags, $given->lists, $given->currencyCode, null, currencyRate: $given->currencyRate, baseDecimals: 2));
            } catch (PostingException $failure) {
                $engine = $failure->error->value;
            }

            if (! $legacy instanceof JournalDraft || ! $engine instanceof JournalDraft) {
                $this->assertSame($legacy instanceof JournalDraft ? 'a journal' : $legacy, $engine instanceof JournalDraft ? 'a journal' : $engine, "case {$case}");

                continue;
            }

            $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), "case {$case} at {$rate}");
            $count++;
        }

        $this->assertGreaterThan(250, $count, 'Most of the purchases came to a journal');
        $this->removeInsertedEntities();
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
