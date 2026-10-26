<?php

namespace Tests\Commands;

use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingBankAccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
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

        $this->reinstallChart();
    }

    protected function tearDown(): void
    {
        $this->clearJournals();

        parent::tearDown();
    }

    private function runCommand(string $arguments = ''): string
    {
        $this->resetStreamFilterBuffer();
        command(trim('accounting:bank_accounts ' . $arguments));

        return $this->getStreamFilterBuffer();
    }

    private function legacyBank(string $name): int
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => $name, 'kind' => 'bank']);

        $treasuryId = (int) $this->accountingDb()->insertID();

        (new EntityAccountService())->ensure(EntityAccountService::TREASURY, $treasuryId, $name);

        return $treasuryId;
    }

    private function parentCodeOf(int $treasuryId): string
    {
        $account = $this->accountingDb()->table('accounting_accounts')->where(['entity_type' => 'treasury', 'entity_id' => $treasuryId])->get()->getRow();

        return (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code;
    }

    public function testRun_SaysThereIsNothingToMoveWhenNoBankAccountWaits(): void
    {
        $output = $this->runCommand();

        $this->assertStringContainsString('0 bank account(s) are not under the main account of the banks.', $output);
        $this->assertStringContainsString('0 bank account(s) lack the name of the bank or the account number.', $output);
    }

    public function testRun_ListsTheBankAccountsThatWaitAndMovesNone(): void
    {
        $bank = $this->legacyBank('Old bank');

        $output = $this->runCommand();

        $this->assertStringContainsString('1 bank account(s) are not under the main account of the banks.', $output);
        $this->assertStringContainsString("treasury {$bank} (Old bank)", $output);
        $this->assertStringContainsString('1 bank account(s) lack the name of the bank or the account number.', $output);
        $this->assertSame('1201', $this->parentCodeOf($bank));
    }

    public function testRun_MovesTheAccountsToTheBanksWhenAskedTo(): void
    {
        $bank = $this->legacyBank('Old bank');

        $output = $this->runCommand('--move');

        $this->assertMatchesRegularExpression("/Treasury {$bank}: account 1201\\d{2} moved to 1202\\d{3}\\./", $output);
        $this->assertStringContainsString('0 bank account(s) are not under the main account of the banks.', $output);
        $this->assertSame('1202', $this->parentCodeOf($bank));
    }

    public function testRun_StillListsTheAccountsThatLackTheirDetailsAfterTheMove(): void
    {
        $bank = $this->legacyBank('Old bank');

        $output = $this->runCommand('--move');

        $this->assertStringContainsString('1 bank account(s) lack the name of the bank or the account number.', $output);
        $this->assertStringContainsString("treasury {$bank} (Old bank)", $output);
    }

    public function testRun_DoesNotMoveAnythingOnTheRunAfterOneThatMoved(): void
    {
        $this->legacyBank('Old bank');
        $this->runCommand('--move');

        $later = $this->legacyBank('Later bank');

        $this->runCommand();

        $this->assertSame('1201', $this->parentCodeOf($later));
    }
}
