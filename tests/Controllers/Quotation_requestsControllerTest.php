<?php

namespace Tests\Controllers;

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\PurchaseCycleChainScreenTestTrait;
use Tests\Support\PurchaseCycleFixtureTrait;
use Tests\Support\PurchaseCycleScreenTestTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screens of the quotation requests: what they show while the cycle is off, who may open and change what, that every
 * change is a POST the service may refuse in words, and that the conversion makes the next document and posts nothing.
 *
 * @internal
 */
final class Quotation_requestsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use PurchaseCycleChainScreenTestTrait;
    use PurchaseCycleFixtureTrait;
    use PurchaseCycleScreenTestTrait;
    use RoleFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function kind(): PurchaseDocumentKind
    {
        return PurchaseDocumentKind::QuotationRequest;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScreen();
    }

    protected function tearDown(): void
    {
        $this->tearDownScreen();

        parent::tearDown();
    }
}
