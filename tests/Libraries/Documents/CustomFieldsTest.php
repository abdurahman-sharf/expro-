<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\CustomFieldDefinition;
use App\Libraries\Documents\CustomFields;
use App\Libraries\Documents\CustomFieldType;
use App\Libraries\Documents\DocumentError;
use App\Libraries\Documents\DocumentException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The definitions and the values of the extra fields: who may define them, what a value must be, what
 * prints, and that no value, definition or print reaches a journal, a line or an amount.
 */
class CustomFieldsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CustomFields $fields;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();
        $this->fields  = new CustomFields();
        $this->manager = $this->employeeWithGrants([CustomFields::PERMISSION, 'custom_fields']);
    }

    protected function tearDown(): void
    {
        $this->wipe();
        $this->stopSelling();

        parent::tearDown();
    }

    private function wipe(): void
    {
        $db = Database::connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('custom_field_values')->truncate();
        $db->table('custom_fields')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('accounting_audit_log')->where('entity_type', 'custom_field')->delete();
    }

    private function refusal(callable $action): DocumentException
    {
        try {
            $action();
        } catch (DocumentException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function define(string $entity, string $name, string $type = 'text', array $input = []): int
    {
        return $this->fields->create($entity, [...['name' => $name, 'type' => $type], ...$input], $this->manager);
    }

    public function testCreate_StoresTheDefinitionAndAuditsIt(): void
    {
        $id = $this->fields->create('item', ['name' => '  Colour  ', 'type' => 'select', 'options' => "Red\r\nBlue\n\nRed2", 'required' => '1', 'printable' => 'on'], $this->manager);

        $field = $this->fields->find($id);

        $this->assertInstanceOf(CustomFieldDefinition::class, $field);
        $this->assertSame('Colour', $field->name);
        $this->assertSame('item', $field->entity);
        $this->assertSame(CustomFieldType::Select, $field->type);
        $this->assertSame(['Red', 'Blue', 'Red2'], $field->options);
        $this->assertTrue($field->required);
        $this->assertTrue($field->printable);
        $this->assertTrue($field->active);

        $entry = Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'custom_field', 'action' => 'custom_field_created'])->get()->getRow();

        $this->assertSame($id, (int) $entry->entity_id);
        $this->assertSame($this->manager, (int) $entry->employee_id);
    }

    public function testCreate_RequiresThePermissionToManageExtraFields(): void
    {
        $clerk = $this->employeeWithGrants(['items', 'custom_fields']);

        $refusal = $this->refusal(fn () => $this->fields->create('item', ['name' => 'Colour', 'type' => 'text'], $clerk));

        $this->assertSame(DocumentError::PermissionDenied, $refusal->error);
        $this->assertSame([], $this->fields->definitions('item', false));
        $this->assertSame(0, Database::connect()->table('accounting_audit_log')->where('entity_type', 'custom_field')->countAllResults());
    }

    public function testUpdate_AndSetActive_RequireThePermissionToManageExtraFields(): void
    {
        $id    = $this->define('item', 'Colour');
        $clerk = $this->employeeWithGrants(['items', 'custom_fields']);

        $this->assertSame(DocumentError::PermissionDenied, $this->refusal(fn () => $this->fields->update($id, ['name' => 'Hue'], $clerk))->error);
        $this->assertSame(DocumentError::PermissionDenied, $this->refusal(fn () => $this->fields->setActive($id, false, $clerk))->error);
        $this->assertSame('Colour', $this->fields->find($id)->name);
        $this->assertTrue($this->fields->find($id)->active);
    }

    public function testCreate_IsFreeForTheSystemItself(): void
    {
        $id = $this->fields->create('sale', ['name' => 'Order reference', 'type' => 'text'], null);

        $this->assertSame('Order reference', $this->fields->find($id)->name);
    }

    public function testPermission_IsGrantedToNobodyByDefaultAndIsNotAMoneyPermission(): void
    {
        $db = Database::connect();

        $this->assertSame('custom_fields', $db->table('permissions')->where('permission_id', CustomFields::PERMISSION)->get()->getRow()->module_id);
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', [CustomFields::PERMISSION, 'custom_fields'])->where('person_id', 1)->countAllResults());
        $this->assertNotContains(CustomFields::PERMISSION, \App\Libraries\Accounting\AccountingGuard::SENSITIVE);
    }

    public function testCreate_RejectsAnEntityThatIsNotProductsOrInvoices(): void
    {
        $this->assertSame(DocumentError::CustomFieldEntity, $this->refusal(fn () => $this->fields->create('customer', ['name' => 'x', 'type' => 'text'], $this->manager))->error);
    }

    public function testCreate_RejectsAnUnknownType(): void
    {
        $this->assertSame(DocumentError::CustomFieldType, $this->refusal(fn () => $this->fields->create('item', ['name' => 'x', 'type' => 'map'], $this->manager))->error);
        $this->assertSame(DocumentError::CustomFieldType, $this->refusal(fn () => $this->fields->create('item', ['name' => 'x'], $this->manager))->error);
    }

    public function testCreate_RejectsAnEmptyTooLongOrControlCharacterName(): void
    {
        foreach (['', '   ', str_repeat('a', 101), "bad\x07"] as $name) {
            $this->assertSame(DocumentError::CustomFieldName, $this->refusal(fn () => $this->fields->create('item', ['name' => $name, 'type' => 'text'], $this->manager))->error, json_encode($name));
        }

        $this->assertSame(DocumentError::CustomFieldName, $this->refusal(fn () => $this->fields->create('item', ['name' => ['a'], 'type' => 'text'], $this->manager))->error);
        $this->assertNotNull($this->fields->find($this->define('item', str_repeat('ش', 100))));
        $this->assertSame('two words', $this->fields->find($this->define('item', "two\n  words"))->name);
    }

    public function testCreate_RejectsANameAnotherFieldOfTheSameKindHasWhateverTheCase(): void
    {
        $this->define('item', 'Colour');

        $this->assertSame(DocumentError::CustomFieldNameTaken, $this->refusal(fn () => $this->define('item', 'colour'))->error);
        $this->assertSame(DocumentError::CustomFieldNameTaken, $this->refusal(fn () => $this->define('item', ' COLOUR '))->error);
        $this->assertNotNull($this->fields->find($this->define('sale', 'Colour')), 'Another kind may reuse a name.');
    }

    public function testCreate_RejectsAListWithoutOptionsWithADuplicateOrWithAnOptionThatIsTooLong(): void
    {
        foreach (['', "\n\n", "Red\nRed", str_repeat('a', 101), "Red\nBl\x07ue"] as $options) {
            $this->assertSame(DocumentError::CustomFieldOptions, $this->refusal(fn () => $this->define('item', 'List ' . md5($options), 'select', ['options' => $options]))->error, json_encode($options));
        }

        $many = implode("\n", array_map(static fn (int $n): string => 'Option ' . $n, range(1, 51)));

        $this->assertSame(DocumentError::CustomFieldOptions, $this->refusal(fn () => $this->define('item', 'Many', 'select', ['options' => $many]))->error);
        $this->assertCount(50, $this->fields->find($this->define('item', 'Fifty', 'select', ['options' => implode("\n", array_map(static fn (int $n): string => 'Option ' . $n, range(1, 50)))]))->options);
    }

    public function testCreate_KeepsNoOptionsForATypeThatHasNone(): void
    {
        $id = $this->define('item', 'Note', 'text', ['options' => "a\nb"]);

        $this->assertSame([], $this->fields->find($id)->options);
    }

    public function testCreate_RefusesTheThirtyFirstFieldOfAKind(): void
    {
        foreach (range(1, CustomFields::MAX_PER_ENTITY) as $number) {
            $this->define('sale', 'Field ' . $number);
        }

        $this->assertSame(DocumentError::CustomFieldLimit, $this->refusal(fn () => $this->define('sale', 'One too many'))->error);
        $this->assertNotNull($this->fields->find($this->define('item', 'Another kind has room')));
    }

    public function testDefinitions_ListsTheFieldsInTheOrderTheyWereMadeAndLeavesOutTheOnesPutOutOfUse(): void
    {
        $first  = $this->define('item', 'First');
        $second = $this->define('item', 'Second');
        $this->define('sale', 'Other kind');
        $this->fields->setActive($first, false, $this->manager);

        $this->assertSame([$second], array_map(static fn (CustomFieldDefinition $field): int => $field->id, $this->fields->definitions('item')));
        $this->assertSame([$first, $second], array_map(static fn (CustomFieldDefinition $field): int => $field->id, $this->fields->definitions('item', false)));
        $this->assertSame([], $this->fields->definitions('customer'));
    }

    public function testUpdate_ChangesTheNameOptionsAndFlagsAndAuditsWhatItWas(): void
    {
        $id = $this->define('item', 'Colour', 'select', ['options' => "Red\nBlue"]);

        $this->fields->update($id, ['name' => 'Hue', 'options' => "Red\nBlue\nGreen", 'required' => '1', 'printable' => '1'], $this->manager);

        $field = $this->fields->find($id);

        $this->assertSame('Hue', $field->name);
        $this->assertSame(['Red', 'Blue', 'Green'], $field->options);
        $this->assertTrue($field->required);
        $this->assertTrue($field->printable);

        $entry   = Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'custom_field', 'action' => 'custom_field_updated'])->get()->getRow();
        $details = json_decode((string) $entry->details, true);

        $this->assertSame('Colour', $details['before']['name']);
        $this->assertSame('Hue', $details['after']['name']);
    }

    public function testUpdate_AuditsNothingWhenNothingChanged(): void
    {
        $id = $this->define('item', 'Colour');

        $this->fields->update($id, ['name' => 'Colour'], $this->manager);

        $this->assertSame(0, Database::connect()->table('accounting_audit_log')->where('action', 'custom_field_updated')->countAllResults());
    }

    public function testUpdate_RejectsTheNameOfAnotherFieldAndAnUnknownField(): void
    {
        $first = $this->define('item', 'First');
        $this->define('item', 'Second');

        $this->assertSame(DocumentError::CustomFieldNameTaken, $this->refusal(fn () => $this->fields->update($first, ['name' => 'second'], $this->manager))->error);
        $this->assertSame(DocumentError::CustomFieldNotFound, $this->refusal(fn () => $this->fields->update(987654, ['name' => 'x'], $this->manager))->error);
        $this->assertSame(DocumentError::CustomFieldNotFound, $this->refusal(fn () => $this->fields->setActive(987654, false, $this->manager))->error);
    }

    public function testUpdate_RefusesToChangeTheTypeOfAFieldThatHoldsAValue(): void
    {
        $id = $this->define('item', 'Weight', 'text');
        $this->fields->save('item', 11, [$id => 'heavy']);

        $this->assertSame(DocumentError::CustomFieldTypeLocked, $this->refusal(fn () => $this->fields->update($id, ['type' => 'number'], $this->manager))->error);
        $this->assertSame(CustomFieldType::Text, $this->fields->find($id)->type);

        $empty = $this->define('item', 'Unused', 'text');
        $this->fields->update($empty, ['type' => 'date'], $this->manager);

        $this->assertSame(CustomFieldType::Date, $this->fields->find($empty)->type);
    }

    public function testSetActive_KeepsTheValuesAndAuditsBothWays(): void
    {
        $id = $this->define('item', 'Colour');
        $this->fields->save('item', 11, [$id => 'Red']);

        $this->fields->setActive($id, false, $this->manager);
        $this->fields->setActive($id, false, $this->manager);

        $this->assertSame([], $this->fields->definitions('item'));
        $this->assertSame([$id => 'Red'], $this->fields->valuesOf('item', 11));

        $this->fields->setActive($id, true, $this->manager);

        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where('action', 'custom_field_deactivated')->countAllResults());
        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where('action', 'custom_field_activated')->countAllResults());
        $this->assertCount(1, $this->fields->definitions('item'));
    }

    public function testSave_StoresTheValuesAndReadsThemBackByField(): void
    {
        $colour = $this->define('item', 'Colour', 'select', ['options' => "Red\nBlue"]);
        $weight = $this->define('item', 'Weight', 'number');
        $note   = $this->define('item', 'Note', 'textarea');

        $this->fields->save('item', 11, [$colour => 'Blue', $weight => '12.5', $note => "two\nlines"]);

        $this->assertSame([$colour => 'Blue', $weight => '12.5', $note => "two\nlines"], $this->fields->valuesOf('item', 11));
        $this->assertSame([], $this->fields->valuesOf('item', 12));
    }

    public function testSave_ReplacesAValueAndTakesAwayTheValueOfAFieldLeftEmpty(): void
    {
        $colour = $this->define('item', 'Colour');
        $weight = $this->define('item', 'Weight', 'number');
        $this->fields->save('item', 11, [$colour => 'Red', $weight => '5']);

        $this->fields->save('item', 11, [$colour => 'Green', $weight => '  ']);

        $this->assertSame([$colour => 'Green'], $this->fields->valuesOf('item', 11));
        $this->assertSame(1, Database::connect()->table('custom_field_values')->where('entity_id', 11)->countAllResults());
    }

    public function testSave_KeepsTheValuesOfAProductApartFromTheValuesOfAnInvoiceWithTheSameNumber(): void
    {
        $item = $this->define('item', 'Colour');
        $sale = $this->define('sale', 'Reference');

        $this->fields->save('item', 7, [$item => 'Red']);
        $this->fields->save('sale', 7, [$sale => 'PO-1']);

        $this->assertSame([$item => 'Red'], $this->fields->valuesOf('item', 7));
        $this->assertSame([$sale => 'PO-1'], $this->fields->valuesOf('sale', 7));
    }

    public function testSave_StoresNothingWhenOneValueIsWrong(): void
    {
        $colour = $this->define('item', 'Colour');
        $weight = $this->define('item', 'Weight', 'number');

        $refusal = $this->refusal(fn () => $this->fields->save('item', 11, [$colour => 'Red', $weight => 'heavy']));

        $this->assertSame(DocumentError::CustomFieldValue, $refusal->error);
        $this->assertSame('Weight', $refusal->context['field']);
        $this->assertSame([], $this->fields->valuesOf('item', 11));
    }

    public function testSave_RequiresAValueForARequiredFieldUnlessTheInvoiceIsOnlySetAside(): void
    {
        $required = $this->define('sale', 'PO number', 'text', ['required' => '1']);

        $refusal = $this->refusal(fn () => $this->fields->save('sale', 21, [$required => '  ']));

        $this->assertSame(DocumentError::CustomFieldRequired, $refusal->error);
        $this->assertSame('PO number', $refusal->context['field']);
        $this->assertSame([], $this->fields->valuesOf('sale', 21));

        $this->fields->save('sale', 21, [$required => ''], false);
        $this->fields->save('sale', 21, [$required => 'PO-9']);

        $this->assertSame([$required => 'PO-9'], $this->fields->valuesOf('sale', 21));
    }

    public function testSave_ReadsAnUntickedRequiredCheckboxAsMissing(): void
    {
        $box = $this->define('sale', 'Gift wrap', 'checkbox', ['required' => '1']);

        $this->assertSame(DocumentError::CustomFieldRequired, $this->refusal(fn () => $this->fields->save('sale', 22, [$box => '0']))->error);
        $this->assertSame(DocumentError::CustomFieldRequired, $this->refusal(fn () => $this->fields->save('sale', 22, []))->error);

        $this->fields->save('sale', 22, [$box => '1']);

        $this->assertSame([$box => '1'], $this->fields->valuesOf('sale', 22));
    }

    public function testSave_RefusesAValueThatIsNotText(): void
    {
        $note = $this->define('item', 'Note');

        $this->assertSame(DocumentError::CustomFieldValue, $this->refusal(fn () => $this->fields->save('item', 11, [$note => ['a']]))->error);
    }

    public function testSave_IgnoresAFieldThatWasPutOutOfUseAndAnIdThatIsNotAField(): void
    {
        $off    = $this->define('item', 'Old', 'number');
        $active = $this->define('item', 'Current');
        $this->fields->setActive($off, false, $this->manager);

        $this->fields->save('item', 11, [$off => 'not a number', $active => 'ok', 999 => 'ghost']);

        $this->assertSame([$active => 'ok'], $this->fields->valuesOf('item', 11));
    }

    public function testProblem_SaysInWordsWhatIsWrongAndNamesTheField(): void
    {
        $weight = $this->define('item', 'Weight', 'number');

        $this->assertNull($this->fields->problem('item', [$weight => '12']));
        $this->assertSame(lang('Documents.error_custom_field_value') . ' (Weight)', $this->fields->problem('item', [$weight => 'x']));
    }

    public function testPrintable_ListsOnlyTheValuesOfThePrintableActiveFieldsWithAValue(): void
    {
        $printed    = $this->define('sale', 'Delivery note', 'text', ['printable' => '1']);
        $hidden     = $this->define('sale', 'Internal remark', 'text');
        $off        = $this->define('sale', 'Retired', 'text', ['printable' => '1']);
        $emptyOne   = $this->define('sale', 'Left empty', 'text', ['printable' => '1']);
        $box        = $this->define('sale', 'Gift', 'checkbox', ['printable' => '1']);
        $day        = $this->define('sale', 'Promised', 'date', ['printable' => '1']);
        $this->fields->save('sale', 31, [$printed => 'DN-7', $hidden => 'secret', $off => 'old', $box => '1', $day => '2026-10-22']);
        $this->fields->setActive($off, false, $this->manager);

        $lines = $this->fields->printable('sale', 31);

        $this->assertSame([['Delivery note', 'DN-7'], ['Gift', lang('Common.yes')], ['Promised', to_date(strtotime('2026-10-22'))]], $lines);
        $this->assertNotNull($emptyOne);
        $this->assertSame([], $this->fields->printable('sale', 32));
    }

    public function testPrintableFor_GivesTheValuesOfManyProductsAtOnce(): void
    {
        $colour = $this->define('item', 'Colour', 'text', ['printable' => '1']);
        $this->fields->save('item', 41, [$colour => 'Red']);
        $this->fields->save('item', 42, [$colour => 'Blue']);

        $this->assertSame([41 => [['Colour', 'Red']], 42 => [['Colour', 'Blue']]], $this->fields->printableFor('item', [41, 42, 43]));
        $this->assertSame([], $this->fields->printableFor('item', []));
        $this->assertSame([], $this->fields->printableFor('customer', [41]));
    }

    public function testPrintable_ReturnsTheValueAsTextForTheViewToEscape(): void
    {
        $note = $this->define('sale', 'Note', 'text', ['printable' => '1']);
        $this->fields->save('sale', 51, [$note => '<script>alert(1)</script>']);

        $this->assertSame([['Note', '<script>alert(1)</script>']], $this->fields->printable('sale', 51));
    }

    public function testSave_ChangesNoRowOfTheBooks(): void
    {
        $this->startSelling();
        $sale   = $this->invoiceOf('115.00');
        $field  = $this->define('sale', 'PO number', 'text', ['printable' => '1']);
        $before = $this->booksFingerprint();

        $this->fields->save('sale', $sale, [$field => 'PO-1']);
        $this->fields->printable('sale', $sale);
        $this->fields->setActive($field, false, $this->manager);

        $this->assertSame($before, $this->booksFingerprint(), 'An extra field changed a row of the books or of the sale.');
    }

    /**
     * @return array<string, mixed>
     */
    private function booksFingerprint(): array
    {
        $db = Database::connect();

        return [
            'journals' => $db->query('SELECT COUNT(*) AS n, COALESCE(SUM(total_debit), 0) AS d, COALESCE(SUM(total_credit), 0) AS c FROM ' . $db->prefixTable('accounting_journals'))->getRowArray(),
            'lines'    => $db->query('SELECT COUNT(*) AS n, COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c FROM ' . $db->prefixTable('accounting_journal_lines'))->getRowArray(),
            'sales'    => $db->query('SELECT COUNT(*) AS n, MD5(GROUP_CONCAT(CONCAT_WS(",", sale_id, sale_status, sale_type, invoice_number, comment) ORDER BY sale_id)) AS h FROM ' . $db->prefixTable('sales'))->getRowArray(),
            'items'    => $db->query('SELECT COUNT(*) AS n, COALESCE(SUM(quantity_purchased * item_unit_price), 0) AS t FROM ' . $db->prefixTable('sales_items'))->getRowArray(),
        ];
    }
}
