<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\BankAccountPlacement;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RoutingKey;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The bank accounts opened under the main treasury account before the banks had one of their own
 * are taken to the main account of the banks, and only their place in the chart changes.
 */
class BankAccountPlacementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    private const BLOCKER = 'bank_account_placement_blocker';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BankAccountPlacement $placement;
    private EntityAccountService $accounts;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->placement = new BankAccountPlacement();
        $this->accounts  = new EntityAccountService();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->query('DROP TRIGGER IF EXISTS ' . $this->accountingDb()->prefixTable(self::BLOCKER));
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * A bank account as it was opened before the banks had an account of their own: under the main
     * treasury account and without the name of the bank or the number of the account.
     */
    private function legacyBank(string $name, ?string $holds = null): int
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => $name, 'kind' => 'bank']);

        $treasuryId = (int) $this->accountingDb()->insertID();

        $this->accounts->ensure(EntityAccountService::TREASURY, $treasuryId, $name);

        if ($holds !== null) {
            (new PostingService())->post(new JournalDraft(
                date: '2026-10-21',
                lines: [new JournalLineDraft((int) $this->accounts->accountFor(EntityAccountService::TREASURY, $treasuryId), debit: $holds), new JournalLineDraft($this->accountId('opening_balance'), credit: $holds)],
                sourceType: 'opening',
                sourceId: random_int(1000, 9999),
                currencyCode: 'SAR',
            ));
        }

        return $treasuryId;
    }

    private function accountRow(int $treasuryId): object
    {
        return $this->accountingDb()->table('accounting_accounts')->where(['entity_type' => 'treasury', 'entity_id' => $treasuryId])->get()->getRow();
    }

    private function parentCodeOf(int $treasuryId): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountRow($treasuryId)->parent_id)->get()->getRow()->code;
    }

    /**
     * @return array{int, string, string} Lines, debit and credit that an account holds.
     */
    private function holds(int $accountId): array
    {
        $row = $this->accountingDb()->table('accounting_journal_lines')->select('COUNT(*) AS line_count, COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit', false)->where('account_id', $accountId)->get()->getRow();

        return [(int) $row->line_count, bcadd((string) $row->debit, '0', 4), bcadd((string) $row->credit, '0', 4)];
    }

    public function testPending_ListsTheBankAccountsNotYetUnderTheBanks(): void
    {
        $legacy = $this->legacyBank('Old bank');

        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'Drawer', 'kind' => 'cash']);
        $this->accounts->ensure(EntityAccountService::TREASURY, (int) $this->accountingDb()->insertID(), 'Drawer');

        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'New bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890']);
        $this->accounts->ensure(EntityAccountService::TREASURY, (int) $this->accountingDb()->insertID(), 'New bank', null, RoutingKey::Banks);

        $pending = $this->placement->pending();

        $this->assertCount(1, $pending);
        $this->assertSame($legacy, (int) $pending[0]->treasury_id);
        $this->assertMatchesRegularExpression('/^1201\d{2}$/', (string) $pending[0]->code);
    }

    public function testMove_TakesTheAccountToTheBanksWithoutTouchingWhatItHolds(): void
    {
        $treasuryId = $this->legacyBank('Old bank', '120.00');
        $accountId  = (int) $this->accountRow($treasuryId)->account_id;
        $before     = $this->holds($accountId);

        $moved = $this->placement->move();

        $this->assertCount(1, $moved);
        $this->assertSame($treasuryId, $moved[0]['treasury_id']);
        $this->assertMatchesRegularExpression('/^1201\d{2}$/', $moved[0]['from']);
        $this->assertMatchesRegularExpression('/^1202\d{3}$/', $moved[0]['to']);
        $this->assertSame('1202', $this->parentCodeOf($treasuryId));
        $this->assertSame($accountId, (int) $this->accountRow($treasuryId)->account_id, 'The same account, so its lines went with it');
        $this->assertSame($before, $this->holds($accountId));
        $this->assertSame([1, '120.0000', '0.0000'], $this->holds($accountId));
    }

    public function testMove_LeavesTheBalanceOfEveryAccountAsItWas(): void
    {
        $first  = $this->legacyBank('Old bank', '120.00');
        $second = $this->legacyBank('Older bank', '33.50');
        $before = $this->totals();

        $this->assertCount(2, $this->placement->move());
        $this->assertSame($before, $this->totals());
        $this->assertSame('1202', $this->parentCodeOf($first));
        $this->assertSame('1202', $this->parentCodeOf($second));
    }

    public function testMove_IsDoneOnceAndTheSecondRunFindsNothing(): void
    {
        $this->legacyBank('Old bank', '10.00');

        $this->assertCount(1, $this->placement->move());

        $code = (string) $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'treasury')->get()->getRow()->code;

        $this->assertSame([], $this->placement->move());
        $this->assertSame([], $this->placement->pending());
        $this->assertSame($code, (string) $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'treasury')->get()->getRow()->code);
    }

    public function testMove_LeavesCashTreasuriesWhereTheyAre(): void
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'Drawer', 'kind' => 'cash']);

        $drawer = (int) $this->accountingDb()->insertID();

        $this->accounts->ensure(EntityAccountService::TREASURY, $drawer, 'Drawer');
        $this->legacyBank('Old bank');

        $this->placement->move();

        $this->assertSame('1201', $this->parentCodeOf($drawer));
    }

    public function testMove_MovesNothingWhenOneOfTheAccountsCannotBeMoved(): void
    {
        $first  = $this->legacyBank('Old bank', '120.00');
        $second = $this->legacyBank('Older bank', '33.50');
        $codes  = [(string) $this->accountRow($first)->code, (string) $this->accountRow($second)->code];

        $this->blockUpdatesOf((int) $this->accountRow($second)->account_id);

        try {
            $this->placement->move();
            $this->fail('The move should have failed.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::WriteFailed, $exception->error);
        } finally {
            $this->accountingDb()->query('DROP TRIGGER IF EXISTS ' . $this->accountingDb()->prefixTable(self::BLOCKER));

            $this->assertSame($codes, [(string) $this->accountRow($first)->code, (string) $this->accountRow($second)->code], 'The account that could be moved was put back with the other');
            $this->assertSame('1201', $this->parentCodeOf($first));
            $this->assertSame('1201', $this->parentCodeOf($second));
        }
    }

    public function testIncomplete_ListsTheBankAccountsLackingTheNameOfTheBankOrTheNumber(): void
    {
        $lacking = $this->legacyBank('Old bank');

        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'Half bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank']);

        $half = (int) $this->accountingDb()->insertID();

        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'Whole bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890']);
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'Drawer', 'kind' => 'cash']);

        $this->assertSame([$lacking, $half], array_map(static fn (object $row): int => (int) $row->treasury_id, $this->placement->incomplete()));
    }

    public function testOpenRouting_PointsTheRoutingRowOfTheBanksAtTheAccountOfTheBanks(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->delete();

        $this->assertTrue($this->placement->openRouting());

        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->get()->getRow();

        $this->assertSame($this->accountId('banks'), (int) $row->account_id);
        $this->assertSame('auto', $row->routing_type);
    }

    public function testOpenRouting_AdoptsTheAccountCoded1202OfAChartThatHasNoSystemAccountForTheBanks(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->delete();
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'banks')->update(['system_key' => null, 'is_system' => 0]);

        $this->assertTrue($this->placement->openRouting());

        $account = $this->accountingDb()->table('accounting_accounts')->where('code', '1202')->get()->getRow();

        $this->assertSame('banks', $account->system_key);
        $this->assertSame(1, (int) $account->is_system);
        $this->assertSame((int) $account->account_id, (int) $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->get()->getRow()->account_id);
    }

    public function testOpenRouting_LeavesAChartWithoutAnAccountOfTheBanksAlone(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->delete();
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'banks')->update(['system_key' => null, 'code' => '1299']);

        $this->assertFalse($this->placement->openRouting());
        $this->assertNull($this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->get()->getRow());
        $this->assertSame([], $this->placement->pending());
    }

    public function testOpenRouting_KeepsARowThatAlreadyPointsAtAnAccount(): void
    {
        $custom = $this->customAccount('12', '1290', 'Custom banks', true);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->update(['account_id' => $custom]);

        $this->assertTrue($this->placement->openRouting());
        $this->assertSame($custom, (int) $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->get()->getRow()->account_id);
    }

    /**
     * @return array<int, array{string, string}> What each account of the chart holds, debit and credit.
     */
    private function totals(): array
    {
        $totals = [];

        foreach ($this->accountingDb()->table('accounting_journal_lines')->select('account_id, SUM(debit) AS debit, SUM(credit) AS credit', false)->groupBy('account_id')->orderBy('account_id')->get()->getResult() as $row) {
            $totals[(int) $row->account_id] = [bcadd((string) $row->debit, '0', 4), bcadd((string) $row->credit, '0', 4)];
        }

        return $totals;
    }

    private function blockUpdatesOf(int $accountId): void
    {
        $name = $this->accountingDb()->prefixTable(self::BLOCKER);

        $this->accountingDb()->query("DROP TRIGGER IF EXISTS {$name}");
        $this->accountingDb()->query(
            "CREATE TRIGGER {$name} BEFORE UPDATE ON " . $this->accountingDb()->prefixTable('accounting_accounts')
            . " FOR EACH ROW BEGIN IF NEW.account_id = {$accountId} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'; END IF; END",
        );
    }
}
