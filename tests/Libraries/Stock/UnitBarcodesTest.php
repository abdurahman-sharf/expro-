<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\ItemStockProfile;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\UnitBarcodes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * @internal
 */
final class UnitBarcodesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use StockUnitsFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private UnitBarcodes $barcodes;
    private int $itemId;
    private int $templateId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockUnits();
        $this->barcodes   = new UnitBarcodes();
        $this->itemId     = $this->createTestItem();
        $this->templateId = $this->template();

        (new ItemStockProfile())->setTemplate($this->itemId, $this->templateId, $this->unitManagerId);
    }

    protected function tearDown(): void
    {
        $this->stopStockUnits();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function refusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The change should have been refused with ' . $error->value . '.');
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    public function testAssign_GivesAUnitABarcodeThatResolvesToTheItemAndTheUnit(): void
    {
        $unitId = $this->unitIdOf($this->templateId);

        $this->barcodes->assign($this->itemId, $unitId, 'CTN-001', $this->unitManagerId);

        $this->assertSame(['itemId' => $this->itemId, 'unitId' => $unitId], $this->barcodes->resolve('CTN-001'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'unit_barcode_assigned')->countAllResults());
    }

    public function testAssign_AcceptsABarcodeOfTheBaseUnitWithNoUnit(): void
    {
        $this->barcodes->assign($this->itemId, null, 'PC-001', $this->unitManagerId);

        $this->assertSame(['itemId' => $this->itemId, 'unitId' => null], $this->barcodes->resolve(' PC-001 '));
    }

    public function testAssign_RequiresThePermissionToManageUnits(): void
    {
        $this->refusedWith(AccountingError::PermissionDenied, fn (): int => $this->barcodes->assign($this->itemId, null, 'X-1', $this->employeeWithGrants([])));
        $this->assertNull($this->barcodes->resolve('X-1'));
    }

    public function testAssign_RejectsABarcodeAnotherUnitAlreadyHas(): void
    {
        $this->barcodes->assign($this->itemId, null, 'DUP-1', $this->unitManagerId);

        $this->refusedWith(AccountingError::UnitBarcodeTaken, fn (): int => $this->barcodes->assign($this->itemId, $this->unitIdOf($this->templateId), 'DUP-1', $this->unitManagerId));
    }

    public function testAssign_RejectsTheNumberOfAnItem(): void
    {
        $other = $this->createTestItem();

        $this->accountingDb()->table('items')->where('item_id', $other)->update(['item_number' => 'ITEM-NUMBER-9']);

        $this->refusedWith(AccountingError::UnitBarcodeTaken, fn (): int => $this->barcodes->assign($this->itemId, null, 'ITEM-NUMBER-9', $this->unitManagerId));
    }

    public function testAssign_RejectsAUnitThatIsNotOfTheTemplateOfTheItem(): void
    {
        $otherTemplate = $this->template('Pallets', [['name' => 'Pallet', 'factor' => '100', 'small_name' => 'p']]);

        $this->refusedWith(AccountingError::UnitNotOfItem, fn (): int => $this->barcodes->assign($this->itemId, $this->unitIdOf($otherTemplate, 'Pallet'), 'PAL-1', $this->unitManagerId));
    }

    public function testAssign_RejectsABlankOrTooLongBarcode(): void
    {
        $this->refusedWith(AccountingError::UnitBarcodeInvalid, fn (): int => $this->barcodes->assign($this->itemId, null, '   ', $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitBarcodeInvalid, fn (): int => $this->barcodes->assign($this->itemId, null, str_repeat('9', 256), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitBarcodeInvalid, fn (): int => $this->barcodes->assign(999999999, null, 'NO-ITEM-1', $this->unitManagerId));
    }

    public function testExists_ReturnsTrueForABarcodeSomeUnitHasEvenWhileTheUnitsSettingIsOffAndFalseForAnother(): void
    {
        $this->barcodes->assign($this->itemId, null, 'EX-001', $this->unitManagerId);
        $this->setConfigValue(StockOptions::UNITS, '0');

        $this->assertTrue($this->barcodes->exists('EX-001'));
        $this->assertTrue($this->barcodes->exists(' EX-001 '));
        $this->assertFalse($this->barcodes->exists('EX-002'));
    }

    public function testResolve_IgnoresBarcodesWhileTheUnitsSettingIsOff(): void
    {
        $this->barcodes->assign($this->itemId, null, 'OFF-1', $this->unitManagerId);
        $this->setConfigValue(StockOptions::UNITS, '0');

        $this->assertNull($this->barcodes->resolve('OFF-1'));
    }

    public function testRemove_TakesTheBarcodeAwayAndRequiresThePermission(): void
    {
        $barcodeId = $this->barcodes->assign($this->itemId, null, 'RM-1', $this->unitManagerId);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->barcodes->remove($barcodeId, $this->employeeWithGrants([])));
        $this->assertNotNull($this->barcodes->resolve('RM-1'));

        $this->barcodes->remove($barcodeId, $this->unitManagerId);

        $this->assertNull($this->barcodes->resolve('RM-1'));
        $this->assertSame([], $this->barcodes->ofItem($this->itemId));
    }

    public function testAssign_RefusesWhileTheUnitsSettingIsOff(): void
    {
        $this->setConfigValue(StockOptions::UNITS, '0');

        $this->refusedWith(AccountingError::UnitsOff, fn (): int => $this->barcodes->assign($this->itemId, null, 'OFF-2', $this->unitManagerId));
        $this->assertSame([], $this->barcodes->ofItem($this->itemId));
    }
}
