<?php

namespace Tests\Models;

use App\Libraries\Accounting\EntityAccountService;
use App\Models\Supplier;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class SupplierTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private EntityAccountService $entities;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_enabled', '1');
        $this->entities = new EntityAccountService();
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    private function saveSupplier(string $company, int $supplierId = -1): int
    {
        $person = [
            'first_name'   => 'Sara',
            'last_name'    => 'Nasser',
            'gender'       => null,
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ];
        $supplier = ['company_name' => $company, 'agency_name' => '', 'category' => 0];

        $this->assertTrue((bool) model(Supplier::class)->save_supplier($person, $supplier, $supplierId));

        $personId = (int) ($person['person_id'] ?? $supplierId);

        if (! in_array($personId, $this->insertedPeople, true)) {
            $this->insertedPeople[] = $personId;
        }

        return $personId;
    }

    private function accountOf(int $supplierId): ?object
    {
        $accountId = $this->entities->accountFor(EntityAccountService::SUPPLIER, $supplierId);

        return $accountId === null ? null : $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow();
    }

    public function testSaveSupplier_OpensASubAccountNamedAfterTheCompanyWhileAccountingIsOn(): void
    {
        $supplierId = $this->saveSupplier('Gulf Supplies');

        $account = $this->accountOf($supplierId);

        $this->assertNotNull($account);
        $this->assertSame('Gulf Supplies', $account->name);
        $this->assertSame('2211001', $account->code);
        $this->assertSame('liability', $account->account_type);
    }

    public function testSaveSupplier_OpensNoAccountWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertNull($this->accountOf($this->saveSupplier('Gulf Supplies')));
    }

    public function testSaveSupplier_RenamesTheAccountWhenTheCompanyChanges(): void
    {
        $supplierId = $this->saveSupplier('Gulf Supplies');

        $this->saveSupplier('Gulf Supplies Group', $supplierId);

        $this->assertSame('Gulf Supplies Group', $this->accountOf($supplierId)->name);
    }

    public function testDelete_RetiresTheAccountOfTheSupplier(): void
    {
        $supplierId = $this->saveSupplier('Gulf Supplies');

        model(Supplier::class)->delete($supplierId);

        $this->assertSame('0', (string) $this->accountOf($supplierId)->active);
    }

    public function testDeleteList_RetiresTheAccountOfEveryDeletedSupplier(): void
    {
        $first  = $this->saveSupplier('Gulf Supplies');
        $second = $this->saveSupplier('Najd Trading');

        model(Supplier::class)->delete_list([$first, $second]);

        $this->assertSame('0', (string) $this->accountOf($first)->active);
        $this->assertSame('0', (string) $this->accountOf($second)->active);
    }
}
