<?php

namespace Tests\Database;

use App\Database\Migrations\ChequesAndRevenues;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class ChequesAndRevenuesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['cheque_books', 'cheques', 'cheque_events', 'revenue_categories', 'revenues'];
    private const KEYS   = ['notes_receivable' => 'cheques.receivable', 'cheques_under_collection' => 'cheques.collection', 'notes_payable' => 'cheques.payable', 'cheque_deposit_fees' => 'cheques.fees', 'revenues_default' => 'revenues.default'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
    }

    protected function tearDown(): void
    {
        (new ChequesAndRevenues())->up();

        $this->reinstallChart();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    private function accountOf(string $systemKey): ?object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();
    }

    private function routedTo(string $routingKey): ?string
    {
        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', $routingKey)->get()->getRow();

        return $row === null || $row->account_id === null ? null : (string) $row->account_id;
    }

    /**
     * A chart as it was before the migration: without the leaf accounts and their routing rows, and the account of other revenue without a key.
     */
    private function chartBefore(): void
    {
        (new ChequesAndRevenues())->down();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'revenues.default')->delete();
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'revenues_default')->update(['system_key' => null, 'is_system' => 0]);
    }

    public function testUp_CreatesTheTablesAndRegistersTheModulesAndThePermissionWithoutGrantingThem(): void
    {
        $this->chartBefore();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        (new ChequesAndRevenues())->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $db = $this->accountingDb();

        $this->assertSame(['accounting_cheques', 'receipt_vouchers', 'revenue_categories'], array_column($db->table('modules')->whereIn('module_id', ['accounting_cheques', 'receipt_vouchers', 'revenue_categories'])->orderBy('module_id')->get()->getResultArray(), 'module_id'));
        $this->assertSame(['acc_cheques_manage', 'accounting_cheques', 'receipt_vouchers', 'revenue_categories'], array_column($db->table('permissions')->whereIn('permission_id', ['acc_cheques_manage', 'accounting_cheques', 'receipt_vouchers', 'revenue_categories'])->orderBy('permission_id')->get()->getResultArray(), 'permission_id'));
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', ['acc_cheques_manage', 'accounting_cheques', 'receipt_vouchers', 'revenue_categories'])->countAllResults());
        $this->assertSame('accounting_cheques', $db->table('permissions')->where('permission_id', 'acc_cheques_manage')->get()->getRow()->module_id);
    }

    public function testUp_OpensTheLeafAccountsInAChartThatHasNoneAndRoutesThem(): void
    {
        $this->chartBefore();

        foreach (['notes_receivable', 'cheques_under_collection', 'notes_payable', 'cheque_deposit_fees'] as $systemKey) {
            $this->assertNull($this->accountOf($systemKey), $systemKey);
        }

        (new ChequesAndRevenues())->up();

        $expected = ['notes_receivable' => ['120601', '1206'], 'cheques_under_collection' => ['120602', '1206'], 'notes_payable' => ['212001', '212'], 'cheque_deposit_fees' => ['546', '54']];

        foreach ($expected as $systemKey => [$code, $parent]) {
            $account = $this->accountOf($systemKey);

            $this->assertSame([$code, '0', '1'], [$account->code, (string) $account->is_group, (string) $account->is_system], $systemKey);
            $this->assertSame($parent, $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code, $systemKey);
            $this->assertSame((string) $account->account_id, $this->routedTo(self::KEYS[$systemKey]), $systemKey);
        }
    }

    public function testUp_AdoptsTheAccountOfOtherRevenueAsTheDefaultRevenue(): void
    {
        $this->chartBefore();

        $this->assertNull($this->accountOf('revenues_default'));

        (new ChequesAndRevenues())->up();

        $account = $this->accountOf('revenues_default');

        $this->assertSame('4201', $account->code);
        $this->assertSame('1', (string) $account->is_system);
        $this->assertSame((string) $account->account_id, $this->routedTo('revenues.default'));
    }

    public function testUp_TakesTheNextFreeCodeWhenTheFirstOneIsTaken(): void
    {
        $this->chartBefore();
        $this->customAccount('1206', '120601', 'Taken by the owner');

        (new ChequesAndRevenues())->up();

        $this->assertSame('120603', $this->accountOf('notes_receivable')->code);
        $this->assertSame('Taken by the owner', $this->accountingDb()->table('accounting_accounts')->where('code', '120601')->get()->getRow()->name);
    }

    public function testUp_KeepsTheAccountThatAlreadyHoldsTheKeyAndTheRoutingTheOwnerChose(): void
    {
        $this->chartBefore();

        $owned = $this->customAccount('1206', '120650', 'Owner notes');

        $this->accountingDb()->table('accounting_routing')->insert(['routing_key' => 'cheques.receivable', 'routing_type' => 'auto', 'account_id' => $owned]);

        (new ChequesAndRevenues())->up();

        $this->assertSame((string) $owned, $this->routedTo('cheques.receivable'));
    }

    public function testUp_ChangesNothingWhenItRunsAgain(): void
    {
        $accounts = $this->countRows('accounting_accounts');
        $routing  = $this->countRows('accounting_routing');
        $modules  = $this->countRows('modules');

        (new ChequesAndRevenues())->up();

        $this->assertSame([$accounts, $routing, $modules], [$this->countRows('accounting_accounts'), $this->countRows('accounting_routing'), $this->countRows('modules')]);
    }

    public function testUp_DoesNotTouchAChartThatIsNotInstalledYet(): void
    {
        $this->chartBefore();

        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('accounting_routing')->truncate();
        $db->table('accounting_accounts')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new ChequesAndRevenues())->up();

        $this->assertSame(0, $this->countRows('accounting_accounts'));
        $this->assertSame(0, $this->countRows('accounting_routing'));
    }

    public function testDown_KeepsAnAccountThatHasEntries(): void
    {
        $account = $this->accountOf('notes_receivable');

        $this->setConfigValue('accounting_base_currency', 'SAR');

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-20',
            lines: [new JournalLineDraft((int) $account->account_id, debit: '5.00'), new JournalLineDraft($this->accountId('cash'), credit: '5.00')],
            sourceType: 'sale',
            sourceId: 1,
        ));

        (new ChequesAndRevenues())->down();

        $this->assertNotNull($this->accountOf('notes_receivable'));
        $this->assertNull($this->accountOf('notes_payable'));
        $this->assertNull($this->routedTo('cheques.payable'));
    }
}
