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
            'purchases'   => $this->accountId('purchases'),
            'cogs'        => $this->accountId('cogs'),
            'revenue'     => $this->accountId('sales_revenue'),
            'discount'    => $this->accountId('sales_discount'),
            'shipping'    => $this->accountId('sales_shipping'),
            'adjustment'  => $this->accountId('sales_adjustment'),
            'rounding'    => $this->accountId('tax_rounding'),
            'chosen_tax'  => $this->customAccount('21', '2196', 'Chosen tax'),
        ];

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.discount')->update(['routing_type' => 'auto', 'account_id' => $this->roles['discount']]);

        $location     = $this->insertStockLocation('Matrix store');
        $this->roles += ['location_id' => $location, 'store' => (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $location, 'Matrix store')];
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * Each case: the rules of the posting matrix it checks, the operation, the facts of a made-up
     * document (an account written as @role is the account of that role), and the lines expected as
     * role, side and amount, and auto_tax for a line marked as the tax worked out of another.
     *
     * @return array<string, array{list<string>, string, array<string, mixed>, list<list<string>>}>
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
            'P-33 a purchase paid when it was saved from a treasury takes it off the supplier' => [
                ['P-33'], 'purchase_payment',
                ['figures' => ['paid' => '345.00', 'amount' => '345.00'], 'fields' => ['supplier_id' => '@supplier_id', 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => true, 'by_payment' => false]],
                [['treasury', 'credit', '345.0000'], ['supplier', 'debit', '345.0000']],
            ],
            'P-33 a return the supplier paid back in cash turns around' => [
                ['P-33'], 'purchase_payment',
                ['figures' => ['paid' => '-46.00', 'amount' => '-46.00'], 'fields' => ['supplier_id' => '@supplier_id', 'treasury_id' => null, 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => false, 'by_payment' => true]],
                [['cash', 'debit', '46.0000'], ['supplier', 'credit', '46.0000']],
            ],
            'P-34 a payment against a purchase from a treasury' => [
                ['P-34'], 'supplier_payment',
                ['figures' => ['paid' => '120.00', 'amount' => '120.00'], 'fields' => ['supplier_id' => '@supplier_id', 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => true, 'by_payment' => false]],
                [['treasury', 'credit', '120.0000'], ['supplier', 'debit', '120.0000']],
            ],
            'P-34 money a supplier gave back against a purchase' => [
                ['P-34'], 'supplier_payment',
                ['figures' => ['paid' => '-20.00', 'amount' => '-20.00'], 'fields' => ['supplier_id' => '@supplier_id', 'treasury_id' => null, 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => false, 'by_payment' => true]],
                [['cash', 'debit', '20.0000'], ['supplier', 'credit', '20.0000']],
            ],
            'P-34 a payment to a supplier without a purchase is credit with the supplier' => [
                ['P-34'], 'supplier_advance',
                ['figures' => ['amount' => '700.00'], 'fields' => ['party_id' => '@supplier_id', 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['by_treasury' => true, 'by_payment' => false]],
                [['treasury', 'credit', '700.0000'], ['supplier', 'debit', '700.0000']],
            ],
            'P-21 a payment of a foreign purchase at a lower rate leaves an exchange gain' => [
                ['P-21'], 'supplier_payment_fx',
                ['figures' => ['fx_diff' => '4.00'], 'fields' => ['supplier_id' => '@supplier_id'], 'flags' => ['is_gain' => true, 'is_loss' => false]],
                [['supplier', 'debit', '4.0000'], ['fx_gain', 'credit', '4.0000']],
            ],
            'P-21 a payment of a foreign purchase at a higher rate leaves an exchange loss' => [
                ['P-21'], 'supplier_payment_fx',
                ['figures' => ['fx_diff' => '-4.00'], 'fields' => ['supplier_id' => '@supplier_id'], 'flags' => ['is_gain' => false, 'is_loss' => true]],
                [['supplier', 'credit', '4.0000'], ['fx_loss', 'debit', '4.0000']],
            ],
            'P-28 P-29 P-30 a purchase of stock and of what is not stocked, with tax, owed to the supplier' => [
                ['P-28', 'P-29', 'P-30'], 'purchase',
                ['figures' => ['net' => '100.00', 'tax' => '60.00', 'total' => '460.00'], 'fields' => ['supplier_id' => '@supplier_id', 'treasury_id' => null, 'payment_type' => null], 'flags' => ['has_supplier' => true, 'by_treasury' => false, 'by_payment' => false], 'lists' => ['locations' => [['fields' => ['location_id' => '@location_id'], 'figures' => ['value' => '300.00']]]]],
                [['store', 'debit', '300.0000'], ['purchases', 'debit', '100.0000'], ['vat_input', 'debit', '60.0000'], ['supplier', 'credit', '460.0000']],
            ],
            'P-28 a purchase with no supplier paid at once from a treasury' => [
                ['P-28'], 'purchase',
                ['figures' => ['net' => '0', 'tax' => '0', 'total' => '80.00'], 'fields' => ['supplier_id' => null, 'treasury_id' => '@treasury_id', 'payment_type' => 'Cash'], 'flags' => ['has_supplier' => false, 'by_treasury' => true, 'by_payment' => false], 'lists' => ['locations' => [['fields' => ['location_id' => '@location_id'], 'figures' => ['value' => '80.00']]]]],
                [['store', 'debit', '80.0000'], ['treasury', 'credit', '80.0000']],
            ],
            'P-35 a return given back above its cost, the difference of price credited' => [
                ['P-35'], 'purchase_return',
                ['figures' => ['total' => '115.00', 'foreign_total' => '115.00', 'tax' => '15.00', 'foreign_tax' => '15.00', 'net' => '0', 'variance' => '20.00'], 'fields' => ['supplier_id' => '@supplier_id', 'treasury_id' => null, 'payment_type' => null], 'flags' => ['has_supplier' => true, 'by_treasury' => false, 'by_payment' => false], 'lists' => ['locations' => [['fields' => ['location_id' => '@location_id'], 'figures' => ['value' => '80.00']]]]],
                [['supplier', 'debit', '115.0000'], ['vat_input', 'credit', '15.0000'], ['store', 'credit', '80.0000'], ['cogs', 'credit', '20.0000']],
            ],
            'P-35 a return below its cost with a line that is not stocked, the difference of price debited' => [
                ['P-35'], 'purchase_return',
                ['figures' => ['total' => '150.00', 'foreign_total' => '150.00', 'tax' => '0', 'foreign_tax' => '0', 'net' => '100.00', 'variance' => '-30.00'], 'fields' => ['supplier_id' => '@supplier_id', 'treasury_id' => null, 'payment_type' => null], 'flags' => ['has_supplier' => true, 'by_treasury' => false, 'by_payment' => false], 'lists' => ['locations' => [['fields' => ['location_id' => '@location_id'], 'figures' => ['value' => '80.00']]]]],
                [['supplier', 'debit', '150.0000'], ['store', 'credit', '80.0000'], ['purchases', 'credit', '100.0000'], ['cogs', 'debit', '30.0000']],
            ],
            'P-07 P-08 P-09 P-11 P-13 a sale with a discount of its own, an item and a tax of their own, and shipping' => [
                ['P-07', 'P-08', 'P-11', 'P-13'], 'sale',
                ['figures' => ['total' => '238.00', 'discount' => '10.00', 'shipping' => '20.00', 'adjustment' => '0', 'rounding' => '0'], 'fields' => ['customer_id' => '@customer_id', 'shipping_account_id' => null], 'flags' => ['has_customer' => true, 'counter' => false], 'lists' => [
                    'goods' => [['fields' => ['item_account_id' => '@chosen_revenue'], 'figures' => ['value' => '120.00']], ['fields' => ['item_account_id' => null], 'figures' => ['value' => '80.00']]],
                    'taxes' => [['fields' => ['account_id' => null], 'figures' => ['value' => '18.00']], ['fields' => ['account_id' => '@chosen_tax'], 'figures' => ['value' => '10.00']]],
                ]],
                [['customer', 'debit', '238.0000'], ['discount', 'debit', '10.0000'], ['chosen_revenue', 'credit', '120.0000'], ['revenue', 'credit', '80.0000'], ['vat_output', 'credit', '18.0000'], ['chosen_tax', 'credit', '10.0000'], ['shipping', 'credit', '20.0000']],
            ],
            'P-12 P-14 a sale at the counter adjusted down with the rounding of its tax up, shipped by a company' => [
                ['P-12', 'P-14'], 'sale',
                ['figures' => ['total' => '134.51', 'discount' => '0', 'shipping' => '20.00', 'adjustment' => '-0.50', 'rounding' => '0.01'], 'fields' => ['customer_id' => null, 'shipping_account_id' => '@other_payables'], 'flags' => ['has_customer' => false, 'counter' => true], 'lists' => [
                    'goods' => [['fields' => ['item_account_id' => null], 'figures' => ['value' => '100.00']]],
                    'taxes' => [['fields' => ['account_id' => null], 'figures' => ['value' => '15.00']]],
                ]],
                [['counter', 'debit', '134.5100'], ['revenue', 'credit', '100.0000'], ['vat_output', 'credit', '15.0000'], ['other_payables', 'credit', '20.0000'], ['adjustment', 'debit', '0.5000'], ['rounding', 'credit', '0.0100']],
            ],
            'P-10 the cost of the goods that left two stores' => [
                ['P-10'], 'sale_cost',
                ['figures' => ['cost' => '150.00'], 'lists' => ['locations' => [['fields' => ['location_id' => '@location_id'], 'figures' => ['value' => '150.00']]]]],
                [['cogs', 'debit', '150.0000'], ['store', 'credit', '150.0000']],
            ],
            'P-04 P-05 a manual journal whose debit line carries a tax on top of its amount' => [
                ['P-04', 'P-05'], 'manual',
                ['lists' => ['lines' => [
                    ['figures' => ['amount' => '100.00', 'net' => '100.00', 'tax' => '15.00'], 'fields' => ['account_id' => '@other_expenses', 'cost_center_id' => null], 'flags' => ['on_debit' => true, 'on_credit' => false]],
                    ['figures' => ['amount' => '-115.00', 'net' => '-115.00', 'tax' => '0'], 'fields' => ['account_id' => '@cash', 'cost_center_id' => null], 'flags' => ['on_debit' => false, 'on_credit' => true]],
                ]]],
                [['other_expenses', 'debit', '100.0000'], ['vat_input', 'debit', '15.0000', 'auto_tax'], ['cash', 'credit', '115.0000']],
            ],
            'P-04 P-05 a manual journal whose credit line holds its tax in its amount' => [
                ['P-04', 'P-05'], 'manual',
                ['lists' => ['lines' => [
                    ['figures' => ['amount' => '115.00', 'net' => '115.00', 'tax' => '0'], 'fields' => ['account_id' => '@cash', 'cost_center_id' => null], 'flags' => ['on_debit' => true, 'on_credit' => false]],
                    ['figures' => ['amount' => '-115.00', 'net' => '-100.00', 'tax' => '-15.00'], 'fields' => ['account_id' => '@other_incomes', 'cost_center_id' => null], 'flags' => ['on_debit' => false, 'on_credit' => true]],
                ]]],
                [['cash', 'debit', '115.0000'], ['other_incomes', 'credit', '100.0000'], ['vat_output', 'credit', '15.0000', 'auto_tax']],
            ],
        ];
    }

    /**
     * @param list<string>                         $references
     * @param array<string, mixed>                 $facts
     * @param list<list<string>>  $expected
     */
    #[DataProvider('caseProvider')]
    public function testBuild_PostsTheLinesThePostingMatrixSaysForTheRule(array $references, string $operation, array $facts, array $expected): void
    {
        $draft = (new PostingEngine())->build($this->facts($operation, $facts));
        $names = array_flip(array_filter($this->roles, static fn (string $role): bool => ! str_ends_with($role, '_id'), ARRAY_FILTER_USE_KEY));

        $this->assertNotNull($draft);
        $this->assertSame(
            $expected,
            array_map(static function ($line) use ($names): array {
                $debit = bccomp($line->debit, '0', 4) > 0;

                return [$names[$line->accountId] ?? 'account ' . $line->accountId, $debit ? 'debit' : 'credit', bcadd($debit ? $line->debit : $line->credit, '0', 4), ...($line->autoTax ? ['auto_tax'] : [])];
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
