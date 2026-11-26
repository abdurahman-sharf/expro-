<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\PresumedPaidPurchases;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class PresumedPaidPurchasesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

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

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    public function testList_HoldsAPurchasePaidInFullAtTheMomentItWasSaved(): void
    {
        $receivingId = $this->purchaseOf('300.00', null, lang('Sales.cash'));

        $listed = (new PresumedPaidPurchases())->list();

        $this->assertSame([$receivingId], array_column($listed, 'receiving_id'));
        $this->assertSame(['300.00', 'Gulf Supplies'], [$listed[0]['amount'], $listed[0]['supplier']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $listed[0]['date']);
    }

    public function testList_LeavesOutAPurchaseLeftOnAccountAndOnePaidLater(): void
    {
        $this->purchaseOf('300.00');
        $this->payPurchase($this->purchaseOf('200.00'), '200.00', lang('Sales.cash'));

        $this->assertSame([], (new PresumedPaidPurchases())->list());
    }

    public function testList_LeavesOutAPurchaseWhosePaymentAtOnceWasTakenBack(): void
    {
        $receivingId = $this->purchaseOf('300.00', null, lang('Sales.cash'));

        $this->assertTrue($this->purchases->delete_list([$receivingId], 1));

        $this->assertSame([], (new PresumedPaidPurchases())->list());
    }
}
