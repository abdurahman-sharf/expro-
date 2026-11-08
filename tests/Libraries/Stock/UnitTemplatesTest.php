<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\UnitTemplates;
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
final class UnitTemplatesTest extends CIUnitTestCase
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
    private UnitTemplates $templates;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockUnits();
        $this->templates = new UnitTemplates();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('stock_permit_lines')->truncate();
        $db->table('stock_permits')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->stopStockUnits();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function input(array $more = []): array
    {
        return [
            'name'            => 'Cartons',
            'main_unit_name'  => 'Piece',
            'main_small_name' => 'pc',
            'units'           => [['name' => 'Carton', 'factor' => '12', 'small_name' => 'ctn']],
            ...$more,
        ];
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

    public function testCreate_RequiresThePermissionToManageUnits(): void
    {
        $this->switchOnStock('units');

        $this->refusedWith(AccountingError::PermissionDenied, fn (): int => $this->templates->create($this->input(), $this->employeeWithGrants([])));
        $this->assertSame([], $this->templates->all());
    }

    public function testCreate_RefusesWhileTheUnitsSettingIsOff(): void
    {
        $this->refusedWith(AccountingError::UnitsOff, fn (): int => $this->templates->create($this->input(), $this->unitManagerId));
    }

    public function testCreate_StoresTheTemplateWithItsLargerUnitsInOrder(): void
    {
        $this->switchOnStock('units');

        $templateId = $this->templates->create($this->input(['units' => [['name' => 'Carton', 'factor' => '12', 'small_name' => 'ctn'], ['name' => 'Pallet', 'factor' => '480.5', 'small_name' => 'plt']]]), $this->unitManagerId);
        $template   = $this->templates->find($templateId);

        $this->assertSame(['Cartons', 'Piece', 'pc', 1, true], [$template->name, $template->mainUnitName, $template->mainSmallName, $template->version, $template->active]);
        $this->assertSame(['Carton:12.0000:ctn', 'Pallet:480.5000:plt'], array_map(static fn ($unit): string => $unit->name . ':' . $unit->factor . ':' . $unit->smallName, $template->units));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'unit_template_created', 'entity_id' => $templateId])->countAllResults());
    }

    public function testCreate_AcceptsASmallNameOfTwentyFiveCharactersAndRejectsTwentySix(): void
    {
        $this->switchOnStock('units');

        $this->assertGreaterThan(0, $this->templates->create($this->input(['main_small_name' => str_repeat('a', 25)]), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['name' => 'Other', 'main_small_name' => str_repeat('a', 26)]), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['name' => 'Third', 'units' => [['name' => 'Carton', 'factor' => '12', 'small_name' => str_repeat('b', 26)]]]), $this->unitManagerId));
    }

    public function testCreate_RejectsABlankTemplateNameMainUnitOrUnitList(): void
    {
        $this->switchOnStock('units');

        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['name' => '  ']), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['main_unit_name' => '']), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateUnitsInvalid, fn (): int => $this->templates->create($this->input(['units' => []]), $this->unitManagerId));
    }

    public function testCreate_RejectsAFactorThatIsZeroNegativeNotANumberOrHasMoreThanFourDecimals(): void
    {
        $this->switchOnStock('units');

        foreach (['0', '-2', 'abc', '1.00001', '', '0.0000'] as $factor) {
            $this->refusedWith(AccountingError::UnitFactorInvalid, fn (): int => $this->templates->create($this->input(['units' => [['name' => 'Carton', 'factor' => $factor, 'small_name' => 'c']]]), $this->unitManagerId));
        }
    }

    public function testCreate_AcceptsAFactorOfFourDecimalsAndOfASmallFraction(): void
    {
        $this->switchOnStock('units');

        $templateId = $this->templates->create($this->input(['units' => [['name' => 'Half', 'factor' => '0.0001', 'small_name' => 'h'], ['name' => 'Big', 'factor' => '1234.5678', 'small_name' => 'b']]]), $this->unitManagerId);

        $this->assertSame(['0.0001', '1234.5678'], array_map(static fn ($unit): string => $unit->factor, $this->templates->find($templateId)->units));
    }

    public function testCreate_RejectsARepeatedUnitNameOrOneThatIsTheMainUnit(): void
    {
        $this->switchOnStock('units');

        $this->refusedWith(AccountingError::UnitNameRepeated, fn (): int => $this->templates->create($this->input(['units' => [['name' => 'Carton', 'factor' => '12', 'small_name' => 'c'], ['name' => 'carton', 'factor' => '24', 'small_name' => 'c']]]), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitNameRepeated, fn (): int => $this->templates->create($this->input(['units' => [['name' => 'Piece', 'factor' => '12', 'small_name' => 'c']]]), $this->unitManagerId));
    }

    public function testCreate_RejectsASecondTemplateOfTheSameNameAndVersion(): void
    {
        $this->template();

        $this->refusedWith(AccountingError::UnitTemplateNameTaken, fn (): int => $this->templates->create($this->input(), $this->unitManagerId));
    }

    public function testUpdate_ChangesTheUnitsOfATemplateNoLineHasUsed(): void
    {
        $templateId = $this->template();

        $this->templates->update($templateId, $this->input(['name' => 'Boxes', 'units' => [['name' => 'Box', 'factor' => '6', 'small_name' => 'bx']]]), $this->unitManagerId);

        $template = $this->templates->find($templateId);

        $this->assertSame(['Boxes', 'Box:6.0000:bx'], [$template->name, $template->units[0]->name . ':' . $template->units[0]->factor . ':' . $template->units[0]->smallName]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'unit_template_updated', 'entity_id' => $templateId])->countAllResults());
    }

    public function testUpdate_RequiresThePermissionToManageUnits(): void
    {
        $templateId = $this->template();

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->templates->update($templateId, $this->input(['name' => 'Boxes']), $this->employeeWithGrants([])));
        $this->assertSame('Cartons', $this->templates->find($templateId)->name);
    }

    public function testUpdate_RejectsATemplateThatDoesNotExist(): void
    {
        $this->switchOnStock('units');

        $this->refusedWith(AccountingError::UnitTemplateNotFound, fn () => $this->templates->update(999999, $this->input(), $this->unitManagerId));
    }

    public function testUpdate_RejectsTheNameOfAnotherTemplate(): void
    {
        $this->template('Cartons');
        $other = $this->template('Pallets', [['name' => 'Pallet', 'factor' => '100', 'small_name' => 'p']]);

        $this->refusedWith(AccountingError::UnitTemplateNameTaken, fn () => $this->templates->update($other, $this->input(['name' => 'Cartons']), $this->unitManagerId));
    }

    public function testSetActive_SwitchesATemplateOffAndOn(): void
    {
        $templateId = $this->template();

        $this->templates->setActive($templateId, false, $this->unitManagerId);
        $this->assertFalse($this->templates->find($templateId)->active);
        $this->assertSame([], $this->templates->all(true));

        $this->templates->setActive($templateId, true, $this->unitManagerId);
        $this->assertTrue($this->templates->find($templateId)->active);
    }

    public function testSetActive_RequiresThePermissionToManageUnits(): void
    {
        $templateId = $this->template();

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->templates->setActive($templateId, false, $this->employeeWithGrants([])));
        $this->assertTrue($this->templates->find($templateId)->active);
    }

    public function testNewVersion_SavesTheChangedUnitsAsTheNextVersionAndSwitchesTheOldOneOff(): void
    {
        $templateId = $this->template();

        $newId = $this->templates->newVersion($templateId, $this->input(['units' => [['name' => 'Carton', 'factor' => '10', 'small_name' => 'ctn']]]), $this->unitManagerId);

        $old = $this->templates->find($templateId);
        $new = $this->templates->find($newId);

        $this->assertSame([2, $templateId, true, '10.0000'], [$new->version, $new->previousTemplateId, $new->active, $new->units[0]->factor]);
        $this->assertSame([1, false, '12.0000'], [$old->version, $old->active, $old->units[0]->factor], 'the old version keeps its units and is switched off');
    }

    public function testNewVersion_RequiresThePermissionToManageUnits(): void
    {
        $templateId = $this->template();

        $this->refusedWith(AccountingError::PermissionDenied, fn (): int => $this->templates->newVersion($templateId, $this->input(), $this->employeeWithGrants([])));
        $this->assertCount(1, $this->templates->all());
    }

    public function testIsUsed_ReturnsFalseForATemplateNoLineHasUsed(): void
    {
        $this->assertFalse($this->templates->isUsed($this->template()));
    }

    private function useUnitOf(int $templateId): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $db->table('stock_permits')->insert(['company_id' => 1, 'branch_id' => 1, 'permit_type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => 1]);
        $db->table('stock_permit_lines')->insert(['permit_id' => (int) $db->insertID(), 'line_no' => 1, 'item_id' => $itemId, 'quantity' => '24', 'unit_cost' => '5', 'unit_id' => $this->unitIdOf($templateId), 'unit_factor' => '12', 'unit_quantity' => '2', 'unit_cost_entered' => '60']);
    }

    public function testIsUsed_ReturnsTrueOnceADocumentLineWasEnteredInAUnitOfTheTemplate(): void
    {
        $templateId = $this->template();

        $this->useUnitOf($templateId);

        $this->assertTrue($this->templates->isUsed($templateId));
    }

    public function testUpdate_RejectsChangingTheFactorOrTheUnitsOfATemplateADocumentLineUsed(): void
    {
        $templateId = $this->template();

        $this->useUnitOf($templateId);

        $this->refusedWith(AccountingError::UnitTemplateUsed, fn () => $this->templates->update($templateId, $this->input(['units' => [['name' => 'Carton', 'factor' => '10', 'small_name' => 'ctn']]]), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateUsed, fn () => $this->templates->update($templateId, $this->input(['units' => [['name' => 'Case', 'factor' => '12', 'small_name' => 'ctn']]]), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateUsed, fn () => $this->templates->update($templateId, $this->input(['main_unit_name' => 'Item']), $this->unitManagerId));
        $this->assertSame('12.0000', $this->templates->find($templateId)->units[0]->factor);
    }

    public function testUpdate_AcceptsRenamingAUsedTemplateAndSwitchingItOffAndLeavesItsUnitsAlone(): void
    {
        $templateId = $this->template();

        $this->useUnitOf($templateId);
        $this->templates->update($templateId, $this->input(['name' => 'Renamed', 'active' => false]), $this->unitManagerId);

        $template = $this->templates->find($templateId);

        $this->assertSame(['Renamed', false, '12.0000'], [$template->name, $template->active, $template->units[0]->factor]);
    }

    public function testNewVersion_LeavesTheUsedTemplateAndTheUnitsItsLinesPointAtAsTheyWere(): void
    {
        $templateId = $this->template();
        $unitId     = $this->unitIdOf($templateId);

        $this->useUnitOf($templateId);
        $newId = $this->templates->newVersion($templateId, $this->input(['units' => [['name' => 'Carton', 'factor' => '10', 'small_name' => 'ctn']]]), $this->unitManagerId);

        $this->assertSame('12.0000', $this->templates->find($templateId)->units[0]->factor);
        $this->assertSame($unitId, $this->templates->find($templateId)->units[0]->unitId);
        $this->assertSame('10.0000', $this->templates->find($newId)->units[0]->factor);
        $this->assertTrue($this->templates->isUsed($templateId));
        $this->assertFalse($this->templates->isUsed($newId));
    }

    public function testFind_ReturnsNullForAnUnknownTemplate(): void
    {
        $this->assertNull($this->templates->find(999999));
    }
}
