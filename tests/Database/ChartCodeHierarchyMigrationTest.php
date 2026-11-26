<?php

namespace Tests\Database;

use App\Database\Migrations\ChartCodeHierarchy;
use App\Libraries\Accounting\BooksSnapshot;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class ChartCodeHierarchyMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $supplierAccount;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->installFiscalYears();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->supplierAccount = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier(), 'Gulf Supplies');

        $this->chartAsItWasFirstOpened();
    }

    protected function tearDown(): void
    {
        $this->clearJournals();
        $this->removeInsertedEntities();
        (new ChartCodeHierarchy())->up();
        $this->reinstallChart();

        parent::tearDown();
    }

    /**
     * Puts the accounts the migration is for back where a chart opened before it had them: the four
     * accounts under the creditors with codes that do not start with the code of that group, the
     * account of a supplier under the suppliers following its code, and the opening balances among
     * the current liabilities.
     */
    private function chartAsItWasFirstOpened(): void
    {
        $db = $this->accountingDb();

        foreach (['2111' => '2211', '2112' => '2212', '2113' => '2213', '2114' => '2214'] as $now => $then) {
            $db->table('accounting_accounts')->where('code', $now)->update(['code' => $then]);
        }

        $supplier = $db->table('accounting_accounts')->where('account_id', $this->supplierAccount)->get()->getRow();

        $db->table('accounting_accounts')->where('account_id', $this->supplierAccount)->update(['code' => '2211' . substr($supplier->code, 4)]);
        $db->table('accounting_accounts')->where('system_key', 'opening_balance')->update(['code' => '214', 'parent_id' => $this->accountIdByCode('21'), 'account_type' => 'liability']);
        $db->table('accounting_accounts')->update(['legacy_code' => null]);
    }

    /**
     * @return array<int, string> The code of every account by its id.
     */
    private function codes(): array
    {
        return array_column($this->accountingDb()->table('accounting_accounts')->select('account_id, code')->orderBy('account_id')->get()->getResultArray(), 'code', 'account_id');
    }

    private function codeOf(string $systemKey): string
    {
        return (string) $this->accountingDb()->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow()->code;
    }

    private function postSomething(): void
    {
        $posting = new PostingService();
        $entries = [
            [$this->accountId('cash'), $this->accountId('opening_balance'), '500.00'],
            [$this->accountId('purchases'), $this->supplierAccount, '120.00'],
            [$this->accountId('expenses_default'), $this->accountId('other_payables'), '30.00'],
        ];

        foreach ($entries as $index => [$debited, $credited, $amount]) {
            $posting->post(new JournalDraft(
                date: '2026-10-21',
                lines: [new JournalLineDraft($debited, debit: $amount), new JournalLineDraft($credited, credit: $amount)],
                sourceType: 'sale',
                sourceId: 9300 + $index,
                employeeId: 1,
                currencyCode: 'SAR',
            ));
        }
    }

    public function testUp_GivesTheAccountsUnderTheCreditorsCodesThatStartWithItsCodeAndKeepsTheOldOnes(): void
    {
        (new ChartCodeHierarchy())->up();

        $this->assertSame(['2111', '2113', '2114'], [$this->codeOf('payables'), $this->codeOf('other_payables'), $this->codeOf('branches_payable')]);
        $this->assertSame('2112', $this->accountingDb()->table('accounting_accounts')->where('legacy_code', '2212')->get()->getRow()->code);
        $this->assertSame('2211', $this->accountingDb()->table('accounting_accounts')->where('system_key', 'payables')->get()->getRow()->legacy_code);
    }

    public function testUp_MovesTheAccountsUnderARecodedAccountAlongWithIt(): void
    {
        $before = $this->codes()[$this->supplierAccount];

        (new ChartCodeHierarchy())->up();

        $supplier = $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->supplierAccount)->get()->getRow();

        $this->assertSame(['2111' . substr($before, 4), $before], [$supplier->code, $supplier->legacy_code]);
    }

    public function testUp_MovesTheOpeningBalancesToTheEquity(): void
    {
        (new ChartCodeHierarchy())->up();

        $opening = $this->accountingDb()->table('accounting_accounts')->where('system_key', 'opening_balance')->get()->getRow();

        $this->assertSame(['33', '214', 'equity', (string) $this->accountIdByCode('3')], [$opening->code, $opening->legacy_code, $opening->account_type, (string) $opening->parent_id]);
    }

    public function testUp_LeavesEveryBalanceOfEveryAccountAndBranchAsItWas(): void
    {
        $this->postSomething();

        $before = (new BooksSnapshot())->take();

        (new ChartCodeHierarchy())->up();

        $this->assertSame([], BooksSnapshot::differences($before, (new BooksSnapshot())->take()));
        $this->assertSame('650.0000', $before['companies'][1]['totals']['debit']);
    }

    public function testUp_ChangesNoCodeOutsideTheAccountsItIsFor(): void
    {
        $before = $this->codes();

        (new ChartCodeHierarchy())->up();

        $changed = array_keys(array_diff_assoc($this->codes(), $before));
        sort($changed);

        $expected = [$this->accountId('payables'), $this->accountId('other_payables'), $this->accountId('branches_payable'), $this->accountId('opening_balance'), $this->supplierAccount, (int) $this->accountingDb()->table('accounting_accounts')->where('legacy_code', '2212')->get()->getRow()->account_id];
        sort($expected);

        $this->assertSame($expected, $changed);
    }

    public function testUp_LeavesAnAccountWhoseNewCodeIsTakenAsItIs(): void
    {
        $this->customAccount('211', '2113', 'Typed by hand');

        (new ChartCodeHierarchy())->up();

        $this->assertSame(['2213', null], [$this->codeOf('other_payables'), $this->accountingDb()->table('accounting_accounts')->where('system_key', 'other_payables')->get()->getRow()->legacy_code]);
        $this->assertSame('2111', $this->codeOf('payables'));
    }

    public function testUp_ChangesNothingWhenRunAgain(): void
    {
        (new ChartCodeHierarchy())->up();
        $once = $this->codes();

        (new ChartCodeHierarchy())->up();

        $this->assertSame($once, $this->codes());
        $this->assertSame('2211', $this->accountingDb()->table('accounting_accounts')->where('system_key', 'payables')->get()->getRow()->legacy_code);
    }

    public function testDown_PutsEveryCodeAndTheOpeningBalancesBackAndLeavesTheBalancesAsTheyWere(): void
    {
        $this->postSomething();

        $codes   = $this->codes();
        $before  = (new BooksSnapshot())->take();
        $opening = $this->accountingDb()->table('accounting_accounts')->where('system_key', 'opening_balance')->get()->getRow();

        (new ChartCodeHierarchy())->up();
        (new ChartCodeHierarchy())->down();

        $after = $this->accountingDb()->table('accounting_accounts')->where('system_key', 'opening_balance')->get()->getRow();

        $this->assertSame($codes, $this->codes());
        $this->assertSame([$opening->parent_id, $opening->account_type], [$after->parent_id, $after->account_type]);
        $this->assertSame([], BooksSnapshot::differences($before, (new BooksSnapshot())->take()));
        $this->assertFalse($this->accountingDb()->fieldExists('legacy_code', 'accounting_accounts'));
    }
}
