<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Tax\LineVat;
use App\Libraries\Tax\VatClassification;
use App\Libraries\Tax\VatClassificationError;
use App\Libraries\Tax\VatClassificationException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class VatClassificationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TODAY = '2026-11-21';

    private VatClassification $classes;
    private int $manager;

    /**
     * @var list<int>
     */
    private array $items = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->classes = new VatClassification(null, null, null, static fn (): string => self::TODAY);
        $this->manager = $this->employeeWithGrants([VatClassification::TAXES_PERMISSION]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('item_vat_classes')->truncate();
        $db->table('sales_items_vat')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'vat_classification')->delete();

        if ($this->items !== []) {
            $db->table('items_taxes')->whereIn('item_id', $this->items)->delete();
            $db->table('item_quantities')->whereIn('item_id', $this->items)->delete();
            $db->table('inventory')->whereIn('trans_items', $this->items)->delete();
            $db->table('items')->whereIn('item_id', $this->items)->delete();
        }

        foreach ($db->table('vat_classes')->like('code', 'TEST', 'after')->get()->getResult() as $class) {
            $db->table('vat_class_rates')->where('class_id', (int) $class->class_id)->delete();
            $db->table('vat_classes')->where('class_id', (int) $class->class_id)->delete();
        }

        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function classId(string $code): int
    {
        return (int) Database::connect()->table('vat_classes')->where('code', $code)->get()->getRow()->class_id;
    }

    /**
     * @param list<string> $percents
     */
    private function item(array $percents = ['15']): int
    {
        $itemId        = $this->createTestItem();
        $this->items[] = $itemId;

        foreach ($percents as $index => $percent) {
            Database::connect()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'Tax ' . $index, 'percent' => $percent]);
        }

        return $itemId;
    }

    private function refusal(callable $action): VatClassificationException
    {
        try {
            $action();
        } catch (VatClassificationException $failure) {
            return $failure;
        }

        $this->fail('The change was not refused.');
    }

    private function testClass(string $code, string $category = 'S', string $percent = '15', string $from = '2026-01-01'): int
    {
        $input = ['code' => $code, 'name' => 'Test class', 'category' => $category, 'tax_name' => 'VAT', 'percent' => $percent, 'valid_from' => $from];

        if ($category !== 'S') {
            $input += ['exemption_code' => $category === 'Z' ? 'VATEX-SA-32' : 'VATEX-SA-29', 'exemption_text' => 'A reason'];
        }

        return $this->classes->saveClass($input, null, $this->manager);
    }

    public function testRateOn_ReturnsTheRateInForceOnTheDate(): void
    {
        $standard = $this->classId('S');

        $this->assertSame('5.000', $this->classes->rateOn($standard, '2020-06-30'));
        $this->assertSame('15.000', $this->classes->rateOn($standard, '2020-07-01'));
        $this->assertSame('15.000', $this->classes->rateOn($standard, '2026-11-21'));
    }

    public function testRateOn_ReturnsNothingBeforeTheFirstRate(): void
    {
        $this->assertNull($this->classes->rateOn($this->classId('S'), '2017-12-31'));
    }

    public function testAddRate_ClosesTheOpenRateTheDayBefore(): void
    {
        $classId = $this->testClass('TEST-S');

        $this->classes->addRate($classId, '17.5', '2027-01-01', $this->manager);

        $rates = $this->classes->ratesOf($classId);

        $this->assertSame('2026-12-31', $rates[0]->valid_to);
        $this->assertSame('17.500', $this->classes->rateOn($classId, '2027-01-01'));
        $this->assertSame('15.000', $this->classes->rateOn($classId, '2026-12-31'));
        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'vat_classification', 'action' => 'rate_added', 'entity_id' => $classId])->countAllResults() - 1);
    }

    public function testAddRate_RejectsAStartBeforeTheLastOne(): void
    {
        $classId = $this->testClass('TEST-S', 'S', '15', self::TODAY);

        $this->assertSame(VatClassificationError::RateNotAfterLast, $this->refusal(fn () => $this->classes->addRate($classId, '16', self::TODAY, $this->manager))->error);
    }

    public function testAddRate_RejectsAPositiveRateForAZeroRatedClass(): void
    {
        $classId = $this->testClass('TEST-Z', 'Z', '0');

        $this->assertSame(VatClassificationError::RateInvalid, $this->refusal(fn () => $this->classes->addRate($classId, '5', '2027-01-01', $this->manager))->error);
    }

    public function testAddRate_RequiresTheTaxesModule(): void
    {
        $classId = $this->testClass('TEST-S');
        $other   = $this->employeeWithGrants(['items']);

        try {
            $this->classes->addRate($classId, '16', '2027-01-01', $other);
            $this->fail('An employee without the taxes module added a rate.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        }
    }

    #[Group('T-01-T-11')]
    #[Group('T-06')]
    public function testSaveClass_RequiresAnExemptionCodeOfItsCategory(): void
    {
        $failure = $this->refusal(fn () => $this->classes->saveClass(['code' => 'TEST-E', 'name' => 'Test', 'category' => 'E', 'exemption_code' => 'VATEX-SA-32', 'exemption_text' => 'Reason', 'tax_name' => 'VAT', 'percent' => '0', 'valid_from' => '2026-01-01'], null, $this->manager));

        $this->assertSame(VatClassificationError::ExemptionCodeInvalid, $failure->error);
    }

    public function testSaveClass_RejectsAnExemptionCodeOnAStandardClass(): void
    {
        $failure = $this->refusal(fn () => $this->classes->saveClass(['code' => 'TEST-S', 'name' => 'Test', 'category' => 'S', 'exemption_code' => 'VATEX-SA-32', 'exemption_text' => 'Reason', 'tax_name' => 'VAT', 'percent' => '15', 'valid_from' => '2026-01-01'], null, $this->manager));

        $this->assertSame(VatClassificationError::ExemptionNotAllowed, $failure->error);
    }

    public function testSaveClass_RequiresTheFirstRateOfANewClass(): void
    {
        $failure = $this->refusal(fn () => $this->classes->saveClass(['code' => 'TEST-S', 'name' => 'Test', 'category' => 'S', 'tax_name' => 'VAT'], null, $this->manager));

        $this->assertSame(VatClassificationError::DateInvalid, $failure->error);
    }

    public function testSaveClass_KeepsTheCategoryOfAClass(): void
    {
        $classId = $this->testClass('TEST-S');

        $this->classes->saveClass(['category' => 'Z', 'name' => 'Renamed'], $classId, $this->manager);

        $class = $this->classes->find($classId);

        $this->assertSame('S', $class->category);
        $this->assertSame('Renamed', $class->name);
    }

    public function testClassifyItems_AuditsEachItemAndTakesAClassAway(): void
    {
        $first  = $this->item();
        $second = $this->item();

        $this->assertSame(2, $this->classes->classifyItems([$first, $second], $this->classId('S'), $this->manager));
        $this->assertSame(0, $this->classes->classifyItems([$first], $this->classId('S'), $this->manager));
        $this->assertSame(1, $this->classes->classifyItems([$first], null, $this->manager));

        $this->assertNull($this->classes->classOf($first));
        $this->assertSame('S', $this->classes->classOf($second)->code);
        $this->assertSame(3, Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'vat_classification', 'action' => 'item_classified'])->countAllResults());
    }

    public function testClassifyItems_LetsTheItemsModuleClassifyFromTheItemForm(): void
    {
        $itemId = $this->item();
        $clerk  = $this->employeeWithGrants([VatClassification::ITEMS_PERMISSION]);

        $this->classes->classifyItems([$itemId], $this->classId('E-FINANCIAL'), $clerk, VatClassification::ITEMS_PERMISSION);

        $this->assertSame('E-FINANCIAL', $this->classes->classOf($itemId)->code);
    }

    public function testClassifyUnclassifiedWithOneTax_LeavesItemsWithSeveralTaxes(): void
    {
        $single  = $this->item(['15']);
        $several = $this->item(['10', '5']);

        $this->classes->classifyUnclassifiedWithOneTax($this->classId('S'), $this->manager);

        $this->assertSame('S', $this->classes->classOf($single)->code);
        $this->assertNull($this->classes->classOf($several));
        $this->assertGreaterThanOrEqual(1, $this->classes->ambiguousCount());
    }

    public function testLineVat_GivesTheClassAndTheRateOfTheDay(): void
    {
        $itemId = $this->item();
        $this->classes->classifyItems([$itemId], $this->classId('Z-EXPORT-GOODS'), $this->manager);

        $vat = $this->classes->lineVat($itemId, '2026-11-21');

        $this->assertSame('Z', $vat->category);
        $this->assertSame('0.000', $vat->percent);
        $this->assertSame('VATEX-SA-32', $vat->exemptionCode);
        $this->assertNull($this->classes->lineVat($this->item(), '2026-11-21'));
    }

    public function testLineVat_RefusesADayBeforeTheFirstRateOfTheClass(): void
    {
        $itemId = $this->item();
        $this->classes->classifyItems([$itemId], $this->classId('S'), $this->manager);

        $this->assertSame(VatClassificationError::NoRateOnDate, $this->refusal(fn () => $this->classes->lineVat($itemId, '2017-06-01'))->error);
    }

    public function testRecordLine_KeepsWhatTheLineWasSoldUnder(): void
    {
        $itemId = $this->item();

        $this->classes->recordLine(900001, $itemId, 1, new LineVat($this->classId('Z-EXPORT-GOODS'), 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT'));

        $this->assertSame('VATEX-SA-32', $this->classes->soldUnder(900001, $itemId, 1)->exemptionCode);
        $this->assertSame('Z', $this->classes->linesOfSale(900001)[$itemId . ':1']->category);
    }

    public function testAddRate_RejectsAStartBeforeToday(): void
    {
        $classId = $this->testClass('TEST-S');

        $this->assertSame(VatClassificationError::RateBeforeToday, $this->refusal(fn () => $this->classes->addRate($classId, '16', '2026-11-20', $this->manager))->error);
        $this->assertSame('15.000', $this->classes->rateOn($classId, '2026-11-20'));
    }

    public function testAddRate_AcceptsAStartToday(): void
    {
        $classId = $this->testClass('TEST-S');

        $this->classes->addRate($classId, '16', self::TODAY, $this->manager);

        $this->assertSame('16.000', $this->classes->rateOn($classId, self::TODAY));
    }

    public function testSaveClass_RejectsAFirstRateAfterToday(): void
    {
        $failure = $this->refusal(fn () => $this->testClass('TEST-S', 'S', '15', '2026-11-22'));

        $this->assertSame(VatClassificationError::FirstRateAfterToday, $failure->error);
        $this->assertSame(0, Database::connect()->table('vat_classes')->where('code', 'TEST-S')->countAllResults());
    }

    public function testSoldUnder_ReadsTheLineItselfAndTheFirstLineOfTheItemForAReturn(): void
    {
        $itemId = $this->item();

        $this->classes->recordLine(900002, $itemId, 1, new LineVat(null, 'S', '15.000', null, null, 'VAT'));
        $this->classes->recordLine(900002, $itemId, 2, new LineVat(null, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT'));

        $this->assertSame('Z', $this->classes->soldUnder(900002, $itemId, 2)->category);
        $this->assertSame('S', $this->classes->soldUnder(900002, $itemId, 7)->category);
    }
}
