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

    public function testCreateRequiresThePermissionToManageUnits(): void
    {
        $this->switchOn('units');

        $this->refusedWith(AccountingError::PermissionDenied, fn (): int => $this->templates->create($this->input(), $this->employeeWithGrants([])));
        $this->assertSame([], $this->templates->all());
    }

    public function testCreateRefusesWhileTheUnitsSettingIsOff(): void
    {
        $this->refusedWith(AccountingError::UnitsOff, fn (): int => $this->templates->create($this->input(), $this->unitManagerId));
    }

    public function testCreateStoresTheTemplateWithItsLargerUnitsInOrder(): void
    {
        $this->switchOn('units');

        $templateId = $this->templates->create($this->input(['units' => [['name' => 'Carton', 'factor' => '12', 'small_name' => 'ctn'], ['name' => 'Pallet', 'factor' => '480.5', 'small_name' => 'plt']]]), $this->unitManagerId);
        $template   = $this->templates->find($templateId);

        $this->assertSame(['Cartons', 'Piece', 'pc', 1, true], [$template->name, $template->mainUnitName, $template->mainSmallName, $template->version, $template->active]);
        $this->assertSame(['Carton:12.0000:ctn', 'Pallet:480.5000:plt'], array_map(static fn ($unit): string => $unit->name . ':' . $unit->factor . ':' . $unit->smallName, $template->units));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'unit_template_created', 'entity_id' => $templateId])->countAllResults());
    }

    public function testCreateAcceptsASmallNameOfTwentyFiveCharactersAndRejectsTwentySix(): void
    {
        $this->switchOn('units');

        $this->assertGreaterThan(0, $this->templates->create($this->input(['main_small_name' => str_repeat('a', 25)]), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['name' => 'Other', 'main_small_name' => str_repeat('a', 26)]), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['name' => 'Third', 'units' => [['name' => 'Carton', 'factor' => '12', 'small_name' => str_repeat('b', 26)]]]), $this->unitManagerId));
    }

    public function testCreateRejectsABlankTemplateNameMainUnitOrUnitList(): void
    {
        $this->switchOn('units');

        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['name' => '  ']), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['main_unit_name' => '']), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['units' => []]), $this->unitManagerId));
    }

    public function testCreateRejectsAFactorThatIsZeroNegativeNotANumberOrHasMoreThanFourDecimals(): void
    {
        $this->switchOn('units');

        foreach (['0', '-2', 'abc', '1.00001', '', '0.0000'] as $factor) {
            $this->refusedWith(AccountingError::UnitFactorInvalid, fn (): int => $this->templates->create($this->input(['units' => [['name' => 'Carton', 'factor' => $factor, 'small_name' => 'c']]]), $this->unitManagerId));
        }
    }

    public function testCreateAcceptsAFactorOfFourDecimalsAndOfASmallFraction(): void
    {
        $this->switchOn('units');

        $templateId = $this->templates->create($this->input(['units' => [['name' => 'Half', 'factor' => '0.0001', 'small_name' => 'h'], ['name' => 'Big', 'factor' => '1234.5678', 'small_name' => 'b']]]), $this->unitManagerId);

        $this->assertSame(['0.0001', '1234.5678'], array_map(static fn ($unit): string => $unit->factor, $this->templates->find($templateId)->units));
    }

    public function testCreateRejectsARepeatedUnitNameOrOneThatIsTheMainUnit(): void
    {
        $this->switchOn('units');

        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['units' => [['name' => 'Carton', 'factor' => '12', 'small_name' => 'c'], ['name' => 'carton', 'factor' => '24', 'small_name' => 'c']]]), $this->unitManagerId));
        $this->refusedWith(AccountingError::UnitTemplateInvalid, fn (): int => $this->templates->create($this->input(['units' => [['name' => 'Piece', 'factor' => '12', 'small_name' => 'c']]]), $this->unitManagerId));
    }

    public function testCreateRejectsASecondTemplateOfTheSameNameAndVersion(): void
    {
        $this->template();

        $this->refusedWith(AccountingError::UnitTemplateNameTaken, fn (): int => $this->templates->create($this->input(), $this->unitManagerId));
    }

    public function testUpdateChangesTheUnitsOfATemplateNoLineHasUsed(): void
    {
        $templateId = $this->template();

        $this->templates->update($templateId, $this->input(['name' => 'Boxes', 'units' => [['name' => 'Box', 'factor' => '6', 'small_name' => 'bx']]]), $this->unitManagerId);

        $template = $this->templates->find($templateId);

        $this->assertSame(['Boxes', 'Box:6.0000:bx'], [$template->name, $template->units[0]->name . ':' . $template->units[0]->factor . ':' . $template->units[0]->smallName]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'unit_template_updated', 'entity_id' => $templateId])->countAllResults());
    }

    public function testUpdateRequiresThePermissionToManageUnits(): void
    {
        $templateId = $this->template();

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->templates->update($templateId, $this->input(['name' => 'Boxes']), $this->employeeWithGrants([])));
        $this->assertSame('Cartons', $this->templates->find($templateId)->name);
    }

    public function testUpdateRejectsATemplateThatDoesNotExist(): void
    {
        $this->switchOn('units');

        $this->refusedWith(AccountingError::UnitTemplateNotFound, fn () => $this->templates->update(999999, $this->input(), $this->unitManagerId));
    }

    public function testUpdateRejectsTheNameOfAnotherTemplate(): void
    {
        $this->template('Cartons');
        $other = $this->template('Pallets', [['name' => 'Pallet', 'factor' => '100', 'small_name' => 'p']]);

        $this->refusedWith(AccountingError::UnitTemplateNameTaken, fn () => $this->templates->update($other, $this->input(['name' => 'Cartons']), $this->unitManagerId));
    }

    public function testSetActiveSwitchesATemplateOffAndOn(): void
    {
        $templateId = $this->template();

        $this->templates->setActive($templateId, false, $this->unitManagerId);
        $this->assertFalse($this->templates->find($templateId)->active);
        $this->assertSame([], $this->templates->all(true));

        $this->templates->setActive($templateId, true, $this->unitManagerId);
        $this->assertTrue($this->templates->find($templateId)->active);
    }

    public function testSetActiveRequiresThePermissionToManageUnits(): void
    {
        $templateId = $this->template();

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->templates->setActive($templateId, false, $this->employeeWithGrants([])));
        $this->assertTrue($this->templates->find($templateId)->active);
    }

    public function testNewVersionSavesTheChangedUnitsAsTheNextVersionAndSwitchesTheOldOneOff(): void
    {
        $templateId = $this->template();

        $newId = $this->templates->newVersion($templateId, $this->input(['units' => [['name' => 'Carton', 'factor' => '10', 'small_name' => 'ctn']]]), $this->unitManagerId);

        $old = $this->templates->find($templateId);
        $new = $this->templates->find($newId);

        $this->assertSame([2, $templateId, true, '10.0000'], [$new->version, $new->previousTemplateId, $new->active, $new->units[0]->factor]);
        $this->assertSame([1, false, '12.0000'], [$old->version, $old->active, $old->units[0]->factor], 'the old version keeps its units and is switched off');
    }

    public function testNewVersionRequiresThePermissionToManageUnits(): void
    {
        $templateId = $this->template();

        $this->refusedWith(AccountingError::PermissionDenied, fn (): int => $this->templates->newVersion($templateId, $this->input(), $this->employeeWithGrants([])));
        $this->assertCount(1, $this->templates->all());
    }

    public function testIsUsedIsFalseForATemplateNoLineHasUsed(): void
    {
        $this->assertFalse($this->templates->isUsed($this->template()));
    }
}
