<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReceivingLoader;
use App\Libraries\Accounting\ReceivingType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * A saved purchase read in the shape posting needs: its lines with the branch of their stock
 * location, and for a transfer between branches the branch the stock goes to.
 */
class ReceivingLoaderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

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

    public function testLoad_KeepsTheBranchOfTheStockLocationOfEachLine(): void
    {
        $itemId   = $this->item('0.00');
        $branch   = $this->createBranch('L2', 'Loader branch');
        $depot    = $this->locationOfBranch($branch, 'Loader depot');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '5', '10')]);

        $this->assertGreaterThan(0, $purchase);

        $document = (new ReceivingLoader())->load($purchase);

        $this->assertSame([1], array_map(static fn ($line): ?int => $line->branchId, $document->lines));
        $this->assertNull($document->toBranchId);
        $this->assertSame([], $document->branchCodes);
        $this->assertGreaterThan(0, $depot);
    }

    public function testLoad_KeepsTheBranchTheStockOfATransferGoesToAndTheCodesOfBothBranches(): void
    {
        $itemId = $this->item('0.00');
        $branch = $this->createBranch('L3', 'Loader branch');
        $depot  = $this->locationOfBranch($branch, 'Loader depot');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '5', '10')]));
        $this->workInBranches(1, $branch);

        $transfer = $this->buy([$this->purchaseLine($itemId, 0, '2', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($itemId, 1, '-2', '0')], null, null);

        $this->assertGreaterThan(0, $transfer);

        $document = (new ReceivingLoader())->load($transfer);
        $first    = (string) $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->code;

        $this->assertSame(ReceivingType::Requisition, $document->type);
        $this->assertSame(1, $document->branchId);
        $this->assertSame($branch, $document->toBranchId);
        $this->assertSame([1 => $first, $branch => 'L3'], $document->branchCodes);
        $this->assertSame([$branch, 1], array_map(static fn ($line): ?int => $line->branchId, $document->lines));
    }

    public function testLoad_IsNullForAPurchaseThatDoesNotExist(): void
    {
        $this->assertNull((new ReceivingLoader())->load(987654));
    }
}
