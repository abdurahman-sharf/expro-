<?php

namespace Tests\Libraries\Accounting\Engine;

use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\PostingExpression;
use App\Libraries\Accounting\Engine\PostingFacts;
use App\Libraries\Accounting\Engine\PostingRules;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

/**
 * T-PM: every line of the posting rules is checked against made-up documents whose journal is known
 * from the posting matrix, rule by rule. A rule that no case covers fails the test, so a rule added to
 * the table needs its case here.
 */
class PostingMatrixTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * @var array<string, int>
     */
    private array $roles = [];

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

        $treasury    = (new TreasuryService())->create(['name' => 'Matrix drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->roles = [
            'treasury'         => (int) (new TreasuryService())->accountOf($treasury),
            'treasury_id'      => $treasury,
            'cash'             => $this->accountId('cash'),
            'other_expenses'   => $this->accountId('expenses_default'),
            'chosen_expense'   => $this->customAccount('52', '5298', 'Chosen expense'),
            'chosen_revenue'   => $this->customAccount('42', '4297', 'Chosen revenue'),
            'other_incomes'    => $this->accountId('revenues_default'),
            'vat_output'       => $this->accountId('tax_output'),
            'vat_input'        => $this->accountId('tax_input'),
            'other_payables'   => $this->accountId('other_payables'),
            'cash_difference'  => $this->accountId('cash_difference'),
            'counter'          => $this->accountId('counter_customers'),
            'fx_gain'          => $this->accountId('fx_gain'),
            'fx_loss'          => $this->accountId('fx_loss'),
        ];

        $customer = $this->insertCustomer();
        $supplier = $this->insertSupplier();

        $this->roles += [
            'customer_id' => $customer,
            'customer'    => (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customer, 'Matrix customer'),
            'supplier_id' => $supplier,
            'supplier'    => (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $supplier, 'Matrix supplier'),
        ];
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * Each case: the rules of the posting matrix it checks, the operation, the facts of a made-up
     * document (an account written as @role is the account of that role), and the lines expected as
     * role, side and amount.
     *
     * @return array<string, array{list<string>, string, array<string, mixed>, list<array{string, string, string}>}>
     */
    public static function caseProvider(): array
    {
        return [
            'P-45 P-46 an expense paid from a treasury with tax in its amount' => [
                ['P-45', 'P-46'], 'expense',
                ['figures' => ['gross' => '115.00', 'tax' => '15.00', 'net' => '100.00'], 'fields' => ['category_account_id' => null, 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['is_due' => false, 'has_treasury' => true, 'no_treasury' => false]],
                [['treasury', 'credit', '115.0000'], ['other_expenses', 'debit', '100.0000'], ['vat_input', 'debit', '15.0000']],
            ],
            'P-45 an expense of a category with an account and no tax, paid in cash' => [
                ['P-45'], 'expense',
                ['figures' => ['gross' => '80.00', 'tax' => '0', 'net' => '80.00'], 'fields' => ['category_account_id' => '@chosen_expense', 'treasury_id' => null, 'payment_type' => 'Cash'], 'flags' => ['is_due' => false, 'has_treasury' => false, 'no_treasury' => true]],
                [['cash', 'credit', '80.0000'], ['chosen_expense', 'debit', '80.0000']],
            ],
            'P-45 P-46 an expense not paid yet is owed to other creditors' => [
                ['P-45', 'P-46'], 'expense',
                ['figures' => ['gross' => '230.00', 'tax' => '30.00', 'net' => '200.00'], 'fields' => ['category_account_id' => null, 'treasury_id' => '@treasury_id', 'payment_type' => 'Due'], 'flags' => ['is_due' => true, 'has_treasury' => false, 'no_treasury' => false]],
                [['other_payables', 'credit', '230.0000'], ['other_expenses', 'debit', '200.0000'], ['vat_input', 'debit', '30.0000']],
            ],
            'P-45 P-46 money that came back turns every line around' => [
                ['P-45', 'P-46'], 'expense',
                ['figures' => ['gross' => '-115.00', 'tax' => '-15.00', 'net' => '-100.00'], 'fields' => ['category_account_id' => null, 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['is_due' => false, 'has_treasury' => true, 'no_treasury' => false]],
                [['treasury', 'debit', '115.0000'], ['other_expenses', 'credit', '100.0000'], ['vat_input', 'credit', '15.0000']],
            ],
            'P-47 a receipt voucher received into a treasury with tax in its amount' => [
                ['P-47'], 'revenue',
                ['figures' => ['gross' => '115.00', 'tax' => '15.00', 'net' => '100.00'], 'fields' => ['account_id' => null, 'category_account_id' => null, 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['has_treasury' => true, 'no_treasury' => false]],
                [['treasury', 'debit', '115.0000'], ['other_incomes', 'credit', '100.0000'], ['vat_output', 'credit', '15.0000']],
            ],
            'P-47 a receipt voucher on the account chosen on it, received by its way of payment' => [
                ['P-47'], 'revenue',
                ['figures' => ['gross' => '80.00', 'tax' => '0', 'net' => '80.00'], 'fields' => ['account_id' => '@chosen_revenue', 'category_account_id' => null, 'treasury_id' => null, 'payment_type' => 'Cash'], 'flags' => ['has_treasury' => false, 'no_treasury' => true]],
                [['cash', 'debit', '80.0000'], ['chosen_revenue', 'credit', '80.0000']],
            ],
            'P-16 P-17 a payment of a sale into a treasury takes it off the customer' => [
                ['P-17'], 'sale_payment',
                ['figures' => ['paid' => '200.00', 'adjustment' => '0', 'amount' => '200.00'], 'fields' => ['customer_id' => '@customer_id', 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => true, 'by_payment' => false, 'has_customer' => true, 'counter' => false]],
                [['customer', 'credit', '200.0000'], ['treasury', 'debit', '200.0000']],
            ],
            'P-16 P-17 a counter sale paid in cash and refunded in part turns around on a refund' => [
                ['P-17'], 'sale_payment',
                ['figures' => ['paid' => '-30.00', 'adjustment' => '0', 'amount' => '-30.00'], 'fields' => ['customer_id' => null, 'treasury_id' => null, 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => false, 'by_payment' => true, 'has_customer' => false, 'counter' => true]],
                [['counter', 'debit', '30.0000'], ['cash', 'credit', '30.0000']],
            ],
            'P-16 the rounding of cash a customer paid over goes to cash differences' => [
                ['P-16'], 'sale_payment',
                ['figures' => ['paid' => '0', 'adjustment' => '-0.05', 'amount' => '0'], 'fields' => ['customer_id' => '@customer_id', 'treasury_id' => null, 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => false, 'by_payment' => false, 'has_customer' => true, 'counter' => false]],
                [['customer', 'debit', '0.0500'], ['cash_difference', 'credit', '0.0500']],
            ],
            'P-18 an advance from a customer into a treasury is credit of the customer' => [
                ['P-18'], 'customer_advance',
                ['figures' => ['amount' => '500.00'], 'fields' => ['party_id' => '@customer_id', 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => true, 'by_payment' => false]],
                [['treasury', 'debit', '500.0000'], ['customer', 'credit', '500.0000']],
            ],
            'P-21 a payment of a foreign sale at a lower rate leaves an exchange loss' => [
                ['P-21'], 'sale_payment_fx',
                ['figures' => ['fx_diff' => '5.00'], 'fields' => ['customer_id' => '@customer_id'], 'flags' => ['has_customer' => true, 'counter' => false, 'is_loss' => true, 'is_gain' => false]],
                [['customer', 'credit', '5.0000'], ['fx_loss', 'debit', '5.0000']],
            ],
            'P-21 a payment of a foreign counter sale at a higher rate leaves an exchange gain' => [
                ['P-21'], 'sale_payment_fx',
                ['figures' => ['fx_diff' => '-5.00'], 'fields' => ['customer_id' => null], 'flags' => ['has_customer' => false, 'counter' => true, 'is_loss' => false, 'is_gain' => true]],
                [['counter', 'debit', '5.0000'], ['fx_gain', 'credit', '5.0000']],
            ],
            'P-04 P-05 a manual journal whose debit line carries a tax on top of its amount' => [
                ['P-04', 'P-05'], 'manual',
                ['lists' => ['lines' => [
                    ['figures' => ['amount' => '100.00', 'net' => '100.00', 'tax' => '15.00'], 'fields' => ['account_id' => '@other_expenses', 'cost_center_id' => null], 'flags' => ['on_debit' => true, 'on_credit' => false]],
                    ['figures' => ['amount' => '-115.00', 'net' => '-115.00', 'tax' => '0'], 'fields' => ['account_id' => '@cash', 'cost_center_id' => null], 'flags' => ['on_debit' => false, 'on_credit' => true]],
                ]]],
                [['other_expenses', 'debit', '100.0000'], ['vat_input', 'debit', '15.0000'], ['cash', 'credit', '115.0000']],
            ],
            'P-04 P-05 a manual journal whose credit line holds its tax in its amount' => [
                ['P-04', 'P-05'], 'manual',
                ['lists' => ['lines' => [
                    ['figures' => ['amount' => '115.00', 'net' => '115.00', 'tax' => '0'], 'fields' => ['account_id' => '@cash', 'cost_center_id' => null], 'flags' => ['on_debit' => true, 'on_credit' => false]],
                    ['figures' => ['amount' => '-115.00', 'net' => '-100.00', 'tax' => '-15.00'], 'fields' => ['account_id' => '@other_incomes', 'cost_center_id' => null], 'flags' => ['on_debit' => false, 'on_credit' => true]],
                ]]],
                [['cash', 'debit', '115.0000'], ['other_incomes', 'credit', '100.0000'], ['vat_output', 'credit', '15.0000']],
            ],
        ];
    }

    /**
     * @param list<string>                         $references
     * @param array<string, mixed>                 $facts
     * @param list<array{string, string, string}>  $expected
     */
    #[DataProvider('caseProvider')]
    public function testBuild_PostsTheLinesThePostingMatrixSaysForTheRule(array $references, string $operation, array $facts, array $expected): void
    {
        $draft = (new PostingEngine())->build($this->facts($operation, $facts));
        $names = array_flip(array_diff_key($this->roles, ['treasury_id' => true]));

        $this->assertNotNull($draft);
        $this->assertSame(
            $expected,
            array_map(static function ($line) use ($names): array {
                $debit = bccomp($line->debit, '0', 4) > 0;

                return [$names[$line->accountId] ?? 'account ' . $line->accountId, $debit ? 'debit' : 'credit', bcadd($debit ? $line->debit : $line->credit, '0', 4)];
            }, $draft->lines),
            implode(' ', $references),
        );
    }

    public function testRules_HaveACaseForEveryRuleOfTheMatrixAndEveryLineComesToSomethingInACase(): void
    {
        $covered = [];
        $reached = [];

        foreach (self::caseProvider() as [$references, $operation, $facts]) {
            foreach ($references as $reference) {
                $covered[$operation][$reference] = true;
            }

            foreach ((new PostingRules())->current($operation, '2026-10-21')->lines as $line) {
                foreach ($line->repeatOver === null ? [$facts['figures']] : array_map(static fn (array $entry): array => array_merge($facts['figures'] ?? [], $entry['figures'] ?? []), $facts['lists'][$line->repeatOver] ?? []) as $figures) {
                    if (bccomp(PostingExpression::evaluate($line->amount, $figures), '0', 4) !== 0) {
                        $reached[$operation][$line->lineNo] = true;
                    }
                }
            }
        }

        foreach ((new PostingRules())->versions() as $set) {
            foreach ($set->lines as $line) {
                $this->assertNotNull($line->matrixRef, "Line {$line->lineNo} of {$set->operation} version {$set->version} names no rule of the matrix");
                $this->assertArrayHasKey($line->matrixRef, $covered[$set->operation] ?? [], "{$line->matrixRef} of {$set->operation} has no case");
                $this->assertArrayHasKey($line->lineNo, $reached[$set->operation] ?? [], "Line {$line->lineNo} of {$set->operation} comes to nothing in every case");
            }
        }
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function facts(string $operation, array $facts): PostingFacts
    {
        $resolve = fn (array $fields): array => array_map(fn (mixed $value): mixed => is_string($value) && str_starts_with($value, '@') ? $this->roles[substr($value, 1)] : $value, $fields);
        $lists   = array_map(static fn (array $entries): array => array_map(static fn (array $entry): array => ['fields' => $resolve($entry['fields'] ?? [])] + $entry, $entries), $facts['lists'] ?? []);

        return new PostingFacts($operation, 1, '2026-10-21', 1, 1, 1, $facts['figures'] ?? [], $resolve($facts['fields'] ?? []), $facts['flags'] ?? [], $lists);
    }
}
