<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountType;
use App\Libraries\Accounting\DefaultChart;
use App\Libraries\Accounting\NormalBalance;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\RoutingKey;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ParityCaseTrait;

class DefaultChartTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ParityCaseTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    public function testInstall_IsInstalledByTheMigration(): void
    {
        $this->assertGreaterThan(30, $this->countRows('accounting_accounts'));
        $this->assertSame($this->expectedRoutingRows(), $this->countRows('accounting_routing'));
    }

    public function testInstall_DoesNothingWhenTheChartAlreadyHasAccounts(): void
    {
        $before = $this->countRows('accounting_accounts');

        $installed = (new DefaultChart($this->accountingDb()))->install();

        $this->assertFalse($installed);
        $this->assertSame($before, $this->countRows('accounting_accounts'));
    }

    public function testInstall_CreatesTheFiveRootGroupsAsSystemAccounts(): void
    {
        $roots = $this->accountingDb()->table('accounting_accounts')->where('parent_id', null)->orderBy('code')->get()->getResult();

        $this->assertSame(
            ['asset', 'liability', 'equity', 'revenue', 'expense'],
            array_column($roots, 'account_type'),
        );

        foreach ($roots as $root) {
            $this->assertSame('1', (string) $root->is_group);
            $this->assertSame('1', (string) $root->is_system);
        }
    }

    public function testInstall_GivesEveryAccountTheNormalBalanceOfItsTypeUnlessContra(): void
    {
        $contraCodes = ['112', '112001', '112002', '112003', '112004', '112005', '112006', '412'];

        foreach ($this->accountingDb()->table('accounting_accounts')->get()->getResult() as $account) {
            $expected = AccountType::from($account->account_type)->normalBalance();

            if (in_array($account->code, $contraCodes, true)) {
                $expected = $expected === NormalBalance::Debit ? NormalBalance::Credit : NormalBalance::Debit;
            }

            $this->assertSame($expected->value, $account->normal_balance, "Account {$account->code}");
        }
    }

    public function testInstall_KeepsEveryChildInTheTypeOfItsParent(): void
    {
        $accounts = [];

        foreach ($this->accountingDb()->table('accounting_accounts')->get()->getResult() as $account) {
            $accounts[(int) $account->account_id] = $account;
        }

        foreach ($accounts as $account) {
            if ($account->parent_id === null) {
                continue;
            }

            $parent = $accounts[(int) $account->parent_id];

            $this->assertSame($parent->account_type, $account->account_type, "Account {$account->code}");
            $this->assertSame('1', (string) $parent->is_group, "Parent of {$account->code} must be a group");
        }
    }

    public function testInstall_MapsEveryRoutingKeyToAnActivePostingAccount(): void
    {
        foreach (RoutingKey::cases() as $key) {
            $row = $this->accountingDb()->table('accounting_routing r')
                ->select('r.routing_type, a.is_group, a.active, a.system_key')
                ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
                ->where('r.routing_key', $key->value)
                ->get()
                ->getRow();

            $this->assertNotNull($row, $key->value);
            $this->assertSame($key->defaultType()->value, $row->routing_type, $key->value);

            if ($key->defaultSystemKey() === null) {
                $this->assertNull($row->system_key, $key->value);

                continue;
            }

            $this->assertSame($key->isEntity() ? '1' : '0', (string) $row->is_group, $key->value);
            $this->assertSame('1', (string) $row->active, $key->value);
            $this->assertSame($key->defaultSystemKey(), $row->system_key, $key->value);
        }
    }

    /**
     * Code, name, parent code and whether it is a group, as a fresh account of the reference
     * system shows them (evidence chrt1 to chrt4). The entity accounts of the demonstration
     * data are left out: they belong to its customers, suppliers and warehouse.
     *
     * @return list<array{string, string, string, bool}>
     */
    public static function observedChartProvider(): array
    {
        return [
            ['11', 'الأصول الثابتة', '1', true],
            ['111', 'حسابات الأصول الثابتة', '11', true],
            ['111001', 'أثاث', '111', true],
            ['111002', 'الأجهزة والمعدات', '111', true],
            ['111003', 'وسائل النقل', '111', true],
            ['111004', 'مباني', '111', true],
            ['111005', 'أراضي', '111', true],
            ['112', 'مجمع الإهلاك', '11', true],
            ['112001', 'مجمع إهلاك - أثاث', '112', true],
            ['112002', 'مجمع إهلاك - الأجهزة والمعدات', '112', true],
            ['112003', 'مجمع إهلاك - وسائل النقل', '112', true],
            ['112004', 'مجمع إهلاك - مباني', '112', true],
            ['112005', 'مجمع إهلاك - أراضي', '112', true],
            ['12', 'الأصول المتداولة', '1', true],
            ['1201', 'الخزينة', '12', true],
            ['120101', 'الخزينة الأساسية', '1201', false],
            ['1202', 'البنك', '12', true],
            ['1203', 'المخزون', '12', true],
            ['1204', 'المدينون', '12', true],
            ['12041', 'العملاء', '1204', true],
            ['12042', 'أطراف مدينة أخرى', '1204', false],
            ['1205', 'عهد الموظفين', '12', true],
            ['1206', 'أوراق القبض', '12', true],
            ['1208', 'عجز & زيادة الصندوق', '12', false],
            ['1209', 'تغيير عملة', '12', false],
            ['1210', 'المشتريات', '12', false],
            ['21', 'الخصوم المتداولة', '2', true],
            ['211', 'الدائنون', '21', true],
            ['212', 'أوراق الدفع', '21', true],
            ['214', 'أرصدة افتتاحية', '21', false],
            ['215', 'القيمة المضافة المطلوبة', '21', true],
            ['215001', 'القيمة المضافة المحصلة', '215', false],
            ['215002', 'القيمة المضافة المدفوعة', '215', false],
            ['22', 'الخصوم طويلة الأجل', '2', true],
            ['2211', 'الموردون', '211', true],
            ['2212', 'شركات الشحن', '211', true],
            ['2213', 'أطراف دائنة أخرى', '211', false],
            ['31', 'رأس المال', '3', false],
            ['32', 'أرباح وخسائر مرحلة', '3', false],
            ['41', 'إيرادات المبيعات', '4', true],
            ['411', 'المبيعات', '41', false],
            ['412', 'مردودات المبيعات', '41', false],
            ['42', 'إيرادات أخرى', '4', true],
            ['4201', 'إيرادات أخرى', '42', false],
            ['4202', 'أرباح وخسائر رأسمالية', '42', false],
            ['4203', 'خصم مكتسب', '42', false],
            ['51', 'تكلفة المبيعات', '5', true],
            ['511', 'تكلفة المبيعات', '51', false],
            ['512', 'شحن مشتريات', '51', false],
            ['513', 'خصم مسموح به', '51', false],
            ['52', 'مصروفات إدارية وعمومية', '5', true],
            ['5201', 'إيجار', '52', false],
            ['5202', 'كهرباء', '52', false],
            ['5203', 'هاتف وانترنت', '52', false],
            ['5204', 'صيانة', '52', false],
            ['5205', 'مياه', '52', false],
            ['5206', 'مصاريف حكومية', '52', false],
            ['53', 'مصروفات الإهلاك', '5', true],
            ['54', 'مصروفات أخرى', '5', true],
            ['541', 'مصروفات أخرى', '54', false],
            ['542', 'الديون المعدومة', '54', false],
            ['543', 'عجز وزيادة المخزون', '54', false],
            ['544', 'إعادة تقييم', '54', false],
            ['545', 'إيجار', '54', false],
        ];
    }

    #[DataProvider('observedChartProvider')]
    public function testInstall_FollowsTheChartOfTheReferenceSystem(string $code, string $name, string $parentCode, bool $isGroup): void
    {
        $db      = $this->accountingDb();
        $account = $db->table('accounting_accounts')->where('code', $code)->get()->getRow();

        $this->assertNotNull($account, "Account {$code} is missing");
        $this->assertSame($name, $account->name, $code);
        $this->assertSame($isGroup ? '1' : '0', (string) $account->is_group, $code);
        $this->assertSame($parentCode, $db->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code, $code);
    }

    public function testInstall_AddsOnlyTheGiftCardTheCounterCustomersTheBranchChequesAndSalesChargeAccountsToTheReferenceChart(): void
    {
        $codes    = array_column($this->accountingDb()->table('accounting_accounts')->select('code')->get()->getResultArray(), 'code');
        $observed = array_merge(['1', '2', '3', '4', '5'], array_column(self::observedChartProvider(), 0));

        $this->assertEqualsCanonicalizing([...$observed, '216', '12043', '12044', '2214', '120601', '120602', '212001', '546', '4204', '4208', '4209', '5410', '111006', '112006', '5301', '5420'], $codes);
    }

    public function testInstall_PutsInputTaxAmongTheLiabilitiesBesideOutputTax(): void
    {
        $db     = $this->accountingDb();
        $output = $db->table('accounting_accounts')->where('system_key', 'tax_output')->get()->getRow();
        $input  = $db->table('accounting_accounts')->where('system_key', 'tax_input')->get()->getRow();

        $this->assertSame('liability', $input->account_type);
        $this->assertSame($output->parent_id, $input->parent_id);
        $this->assertSame('215', $db->table('accounting_accounts')->where('account_id', $input->parent_id)->get()->getRow()->code);
    }

    public function testInstall_RebuildsTheChartOnAnEmptyInstall(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('accounting_routing')->truncate();
        $db->table('accounting_accounts')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $installed = (new DefaultChart($db))->install();

        $this->assertTrue($installed);
        $this->assertGreaterThan(30, $this->countRows('accounting_accounts'));
        $this->assertSame($this->expectedRoutingRows(), $this->countRows('accounting_routing'));
    }

    public function testInstall_RoutesEachPaymentTypeToItsDefaultAccount(): void
    {
        $db = $this->accountingDb();

        foreach (PaymentType::cases() as $type) {
            $row = $db->table('accounting_routing')->where('routing_key', $type->routingKey())->get()->getRow();

            if ($type->defaultSystemKey() === null) {
                $this->assertNull($row, $type->value);

                continue;
            }

            $this->assertNotNull($row, $type->value);
            $this->assertSame($this->accountId($type->defaultSystemKey()), (int) $row->account_id, $type->value);
        }
    }

    private function expectedRoutingRows(): int
    {
        $paymentRows = array_filter(PaymentType::cases(), static fn (PaymentType $type): bool => $type->defaultSystemKey() !== null);

        return count(RoutingKey::cases()) + count($paymentRows);
    }

    public function testInstall_HoldsLeafAccountsForChequesUnderTheGroupsOfNotesNotTheCodesOfTheReferenceSystem(): void
    {
        $case = $this->referenceCase('T-54', 'cheque_accounts', 'the-chart-has-other-accounts-for-cheques-than-the-reference-system', 'differs');

        $this->reinstallChart();

        $row      = fn (string $code): ?object => $this->accountingDb()->table('accounting_accounts')->where('code', $code)->get()->getRow();
        $codeOf   = fn (string $systemKey): string => (string) ($this->accountingDb()->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow()?->code ?? 'none');
        $accounts = [
            'notes_receivable' => $codeOf('notes_receivable'),
            'under_collection' => $codeOf('cheques_under_collection'),
            'deposit_fees'     => $codeOf('cheque_deposit_fees'),
            'notes_payable'    => $codeOf('notes_payable'),
        ];

        $this->assertSameAsFixture($case['expected']['accounts'], $accounts, 'accounts for cheques');
        $this->assertSame([$case['expected']['detail']['1206_is_a_group'], $case['expected']['detail']['212_is_a_group'], $case['expected']['detail']['216_is']], [(bool) $row('1206')->is_group, (bool) $row('212')->is_group, $row('216')->name]);
    }

    public function testInstall_PutsTheLeafAccountsOfTheChequesUnderTheirGroupsAndRoutesThemAndTheDefaultRevenue(): void
    {
        $db       = $this->accountingDb();
        $expected = [
            'notes_receivable'         => ['120601', '1206', 'asset', 'debit', 'cheques.receivable'],
            'cheques_under_collection' => ['120602', '1206', 'asset', 'debit', 'cheques.collection'],
            'notes_payable'            => ['212001', '212', 'liability', 'credit', 'cheques.payable'],
            'cheque_deposit_fees'      => ['546', '54', 'expense', 'debit', 'cheques.fees'],
            'revenues_default'         => ['4201', '42', 'revenue', 'credit', 'revenues.default'],
        ];

        foreach ($expected as $systemKey => [$code, $parentCode, $type, $normal, $routingKey]) {
            $account = $db->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();

            $this->assertNotNull($account, $systemKey);
            $this->assertSame([$code, $type, $normal, '0', '1'], [$account->code, $account->account_type, $account->normal_balance, (string) $account->is_group, (string) $account->is_system], $systemKey);
            $this->assertSame($parentCode, $db->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code, $systemKey);
            $this->assertSame((int) $account->account_id, (int) $db->table('accounting_routing')->where('routing_key', $routingKey)->get()->getRow()->account_id, $routingKey);
        }
    }

    public function testInstall_RoutesAdjustmentsAndShippingToTheirOwnRevenueAccountsAutomatically(): void
    {
        $db = $this->accountingDb();

        foreach (['sales.adjustment' => 'sales_adjustment', 'sales.shipping' => 'sales_shipping'] as $key => $systemKey) {
            $row = $db->table('accounting_routing')->where('routing_key', $key)->get()->getRow();

            $this->assertSame('auto', $row->routing_type, $key);
            $this->assertSame($this->accountId($systemKey), (int) $row->account_id, $key);
            $this->assertSame('revenue', $db->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow()->account_type);
        }
    }
}
