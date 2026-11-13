<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Integrations\EntityTranslations;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationError;
use App\Libraries\Integrations\IntegrationException;
use App\Libraries\Integrations\TranslationKind;
use App\Models\Item;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * The second-language names: what is stored, for which records and in which languages, who may change
 * them, that a record, a journal and a ledger are never touched, and what a document is given to print.
 */
class EntityTranslationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private EntityTranslations $translations;

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->translations = new EntityTranslations();
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();

        foreach ($this->itemIds as $itemId) {
            Database::connect()->table('item_quantities')->where('item_id', $itemId)->delete();
            Database::connect()->table('inventory')->where('trans_items', $itemId)->delete();
            Database::connect()->table('items')->where('item_id', $itemId)->delete();
        }

        $this->itemIds = [];
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function item(): int
    {
        return $this->itemIds[] = $this->createTestItem();
    }

    private function refusal(callable $action): IntegrationException
    {
        try {
            $action();
        } catch (IntegrationException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    public function testSave_KeepsTheNameOfACustomerWithItsAddressNoteAndAuditsIt(): void
    {
        $customer = $this->insertCustomer();
        $manager  = $this->integrationManager();

        $id = $this->translations->save('customer', (string) $customer, 'en', 'Omar Khaled Trading', 'Riyadh, King Fahd Road', $manager);

        $row = Database::connect()->table('entity_translations')->where('translation_id', $id)->get()->getRow();

        $this->assertSame('customer', $row->entity_kind);
        $this->assertSame((string) $customer, $row->entity_key);
        $this->assertSame('en', $row->language);
        $this->assertSame('Omar Khaled Trading', $row->name);
        $this->assertSame('Riyadh, King Fahd Road', $row->address_note);
        $this->assertSame($manager, (int) $row->created_by);

        $entry = Database::connect()->table('accounting_audit_log')->where('action', 'entity_translation_saved')->get()->getRow();

        $this->assertSame($id, (int) $entry->entity_id);
        $this->assertSame(['kind' => 'customer', 'key' => (string) $customer, 'language' => 'en', 'before' => null, 'after' => ['name' => 'Omar Khaled Trading', 'address_note' => 'Riyadh, King Fahd Road']], json_decode($entry->details, true));
    }

    public function testSave_KeepsTheNameOfASupplierAnItemAndACategory(): void
    {
        $supplier = $this->insertSupplier();
        $item     = $this->item();

        $this->translations->save('supplier', (string) $supplier, 'en', 'Gulf Supplies Co', '', null);
        $this->translations->save('item', (string) $item, 'en', 'Test item in English', '', null);
        $this->translations->save('category', 'Test Category', 'en', 'Test category in English', '', null);

        $found = $this->translations->search(null, '', 50, 0);

        $this->assertSame(3, $found['total']);
        $this->assertEqualsCanonicalizing(['supplier', 'item', 'category'], array_map(static fn ($row): string => $row->kind->value, $found['rows']));
    }

    public function testSave_ChangesTheNameOfARecordInPlaceAndAuditsWhatItWas(): void
    {
        $item = $this->item();
        $id   = $this->translations->save('item', (string) $item, 'en', 'First', '', null);

        $again = $this->translations->save('item', (string) $item, 'en', 'Second', '', null);

        $this->assertSame($id, $again);
        $this->assertSame(1, Database::connect()->table('entity_translations')->countAllResults());

        $entries = Database::connect()->table('accounting_audit_log')->where('action', 'entity_translation_saved')->orderBy('audit_id')->get()->getResult();

        $this->assertCount(2, $entries);
        $this->assertSame(['name' => 'First', 'address_note' => ''], json_decode($entries[1]->details, true)['before']);
        $this->assertSame(['name' => 'Second', 'address_note' => ''], json_decode($entries[1]->details, true)['after']);
    }

    public function testSave_WritesNothingAndAuditsNothingForTheSameNameAgain(): void
    {
        $item = $this->item();

        $this->translations->save('item', (string) $item, 'en', 'Same', '', null);
        $this->translations->save('item', (string) $item, 'en', '  Same ', '', null);

        $this->assertSame(['entity_translation_saved'], $this->integrationAudit('entity_translation'));
    }

    public function testSave_KeepsOneNameForEachLanguageOfOneRecord(): void
    {
        $item = $this->item();

        $this->translations->save('item', (string) $item, 'en', 'English', '', null);
        $this->translations->save('item', (string) $item, 'de-DE', 'Deutsch', '', null);

        $this->assertSame(2, Database::connect()->table('entity_translations')->countAllResults());
    }

    public function testSave_AcceptsAKeyWithLeadingZerosAsTheSameRecord(): void
    {
        $item = $this->item();
        $id   = $this->translations->save('item', (string) $item, 'en', 'One', '', null);

        $this->assertSame($id, $this->translations->save('item', '0' . $item, 'en', 'One', '', null));
    }

    public function testSave_RequiresThePermissionToManage(): void
    {
        $outsider = $this->employeeWithGrants([IntegrationAccess::MODULE, IntegrationAccess::CONFIG]);

        $refusal = $this->refusal(fn () => $this->translations->save('item', (string) $this->item(), 'en', 'Name', '', $outsider));

        $this->assertSame(IntegrationError::PermissionDenied, $refusal->error);
        $this->assertSame(0, Database::connect()->table('entity_translations')->countAllResults());
    }

    public function testSave_RejectsAKindOfRecordItDoesNotKnow(): void
    {
        $refusal = $this->refusal(fn () => $this->translations->save('sale', '1', 'en', 'Name', '', null));

        $this->assertSame(IntegrationError::TranslationKind, $refusal->error);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function missingRecords(): array
    {
        return [
            'a customer that does not exist' => ['customer', '987654'],
            'a supplier that does not exist' => ['supplier', '987654'],
            'an item that does not exist'    => ['item', '987654'],
            'a key that is not a number'     => ['item', 'abc'],
            'an empty key'                   => ['customer', ''],
            'a category no item carries'     => ['category', 'No such category'],
            'an empty category'              => ['category', ''],
        ];
    }

    #[DataProvider('missingRecords')]
    public function testSave_RejectsARecordThatDoesNotExist(string $kind, string $key): void
    {
        $refusal = $this->refusal(fn () => $this->translations->save($kind, $key, 'en', 'Name', '', null));

        $this->assertSame(IntegrationError::TranslationEntityMissing, $refusal->error);
        $this->assertSame(0, Database::connect()->table('entity_translations')->countAllResults());
    }

    public function testSave_RejectsADeletedRecordAndACategoryOfDeletedItemsOnly(): void
    {
        $customer = $this->insertCustomer();
        $item     = $this->item();

        Database::connect()->table('customers')->where('person_id', $customer)->update(['deleted' => 1]);
        Database::connect()->table('items')->where('item_id', $item)->update(['deleted' => 1]);

        $this->assertSame(IntegrationError::TranslationEntityMissing, $this->refusal(fn () => $this->translations->save('customer', (string) $customer, 'en', 'Name', '', null))->error);
        $this->assertSame(IntegrationError::TranslationEntityMissing, $this->refusal(fn () => $this->translations->save('item', (string) $item, 'en', 'Name', '', null))->error);
        $this->assertSame(IntegrationError::TranslationEntityMissing, $this->refusal(fn () => $this->translations->save('category', 'Test Category', 'en', 'Name', '', null))->error);

        Database::connect()->table('customers')->where('person_id', $customer)->update(['deleted' => 0]);
    }

    public function testSave_RejectsADeletedSupplier(): void
    {
        $supplier = $this->insertSupplier();

        Database::connect()->table('suppliers')->where('person_id', $supplier)->update(['deleted' => 1]);

        $this->assertSame(IntegrationError::TranslationEntityMissing, $this->refusal(fn () => $this->translations->save('supplier', (string) $supplier, 'en', 'Name', '', null))->error);

        Database::connect()->table('suppliers')->where('person_id', $supplier)->update(['deleted' => 0]);
    }

    public function testSave_AsksForALanguageWhenNoneIsGiven(): void
    {
        $refusal = $this->refusal(fn () => $this->translations->save('item', (string) $this->item(), '  ', 'Name', '', null));

        $this->assertSame(IntegrationError::TranslationNoLanguage, $refusal->error);
        $this->assertSame(0, Database::connect()->table('entity_translations')->countAllResults());
    }

    public function testSave_RejectsALanguageThatIsNotALanguageOfTheApplication(): void
    {
        $refusal = $this->refusal(fn () => $this->translations->save('item', (string) $this->item(), 'xx', 'Name', '', null));

        $this->assertSame(IntegrationError::TranslationLanguage, $refusal->error);
        $this->assertSame('xx', $refusal->context['code']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badNames(): array
    {
        return ['empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('n', 256)], 'a control character' => ["Na\x00me"], 'a line break' => ["Two\nlines"]];
    }

    #[DataProvider('badNames')]
    public function testSave_RejectsAnEmptyOrTooLongOrUnprintableName(string $name): void
    {
        $refusal = $this->refusal(fn () => $this->translations->save('item', (string) $this->item(), 'en', $name, '', null));

        $this->assertSame(IntegrationError::TranslationName, $refusal->error);
    }

    public function testSave_AcceptsANameOfExactly255CharactersAndAMarkupNameStoredAsText(): void
    {
        $item = $this->item();

        $this->translations->save('item', (string) $item, 'en', str_repeat('n', 255), '', null);
        $this->translations->save('item', (string) $item, 'de-DE', '<b>Bold</b>', '', null);

        $this->assertSame('<b>Bold</b>', Database::connect()->table('entity_translations')->where('language', 'de-DE')->get()->getRow()->name);
    }

    public function testSave_RejectsAnAddressNoteForAnItemAndACategoryAndATooLongOne(): void
    {
        $customer = $this->insertCustomer();

        $this->assertSame(IntegrationError::TranslationNote, $this->refusal(fn () => $this->translations->save('item', (string) $this->item(), 'en', 'Name', 'Note', null))->error);
        $this->assertSame(IntegrationError::TranslationNote, $this->refusal(fn () => $this->translations->save('category', 'Test Category', 'en', 'Name', 'Note', null))->error);
        $this->assertSame(IntegrationError::TranslationNote, $this->refusal(fn () => $this->translations->save('customer', (string) $customer, 'en', 'Name', str_repeat('n', 256), null))->error);
        $this->assertSame(IntegrationError::TranslationNote, $this->refusal(fn () => $this->translations->save('customer', (string) $customer, 'en', 'Name', "Line\nbreak", null))->error);
    }

    public function testSave_ChangesNoRecordNoJournalAndNoLedger(): void
    {
        $customer = $this->insertCustomer();
        $item     = $this->item();
        $before   = $this->databaseFingerprint();

        $this->translations->save('customer', (string) $customer, 'en', 'Name', 'Note', null);
        $this->translations->save('item', (string) $item, 'en', 'Name', '', null);
        $this->translations->save('category', 'Test Category', 'en', 'Name', '', null);

        $after = $this->databaseFingerprint();
        $db    = Database::connect();

        foreach (['customers', 'suppliers', 'people', 'items', 'item_quantities', 'accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'sales', 'sales_payments', 'receivings'] as $table) {
            $this->assertSame($before[$db->prefixTable($table)] ?? null, $after[$db->prefixTable($table)] ?? null, $table);
        }
    }

    public function testDelete_RemovesTheNameAndAuditsWhatItWas(): void
    {
        $item = $this->item();
        $id   = $this->translations->save('item', (string) $item, 'en', 'Gone', '', null);

        $this->translations->delete($id, $this->integrationManager());

        $this->assertSame(0, Database::connect()->table('entity_translations')->countAllResults());

        $entry = Database::connect()->table('accounting_audit_log')->where('action', 'entity_translation_deleted')->get()->getRow();

        $this->assertSame(['kind' => 'item', 'key' => (string) $item, 'language' => 'en', 'before' => ['name' => 'Gone', 'address_note' => '']], json_decode($entry->details, true));
    }

    public function testDelete_RejectsATranslationThatDoesNotExistAndRequiresThePermission(): void
    {
        $id = $this->translations->save('item', (string) $this->item(), 'en', 'Kept', '', null);

        $this->assertSame(IntegrationError::TranslationNotFound, $this->refusal(fn () => $this->translations->delete(987654, null))->error);
        $this->assertSame(IntegrationError::PermissionDenied, $this->refusal(fn () => $this->translations->delete($id, $this->employeeWithGrants([IntegrationAccess::MODULE])))->error);
        $this->assertSame(1, Database::connect()->table('entity_translations')->countAllResults());
    }

    public function testSearch_FiltersByKindAndByWordAndPages(): void
    {
        $customer = $this->insertCustomer();
        $item     = $this->item();

        $this->translations->save('customer', (string) $customer, 'en', 'Alpha trading', '', null);
        $this->translations->save('item', (string) $item, 'en', 'Beta goods', '', null);
        $this->translations->save('category', 'Test Category', 'en', 'Gamma goods', '', null);

        $this->assertSame(2, $this->translations->search(null, 'goods', 50, 0)['total']);
        $this->assertSame(1, $this->translations->search(TranslationKind::Item, 'goods', 50, 0)['total']);
        $this->assertSame(['Alpha trading'], array_map(static fn ($row): string => $row->name, $this->translations->search(TranslationKind::Customer, '', 50, 0)['rows']));

        $page = $this->translations->search(null, '', 2, 2);

        $this->assertSame(3, $page['total']);
        $this->assertCount(1, $page['rows']);
    }

    public function testSearch_NamesTheRecordEachTranslationBelongsTo(): void
    {
        $item = $this->item();
        $this->translations->save('item', (string) $item, 'en', 'English item', '', null);
        $this->translations->save('category', 'Test Category', 'en', 'English category', '', null);

        $names = array_map(static fn ($row): string => $row->entityName, $this->translations->search(null, '', 50, 0)['rows']);

        $this->assertContains('Test Item', $names);
        $this->assertContains('Test Category', $names);
    }

    public function testForDocument_GivesNothingWhileTheSwitchToPrintIsOffWhateverIsStored(): void
    {
        $customer = $this->insertCustomer();
        $item     = $this->item();

        $this->translations->save('customer', (string) $customer, 'en', 'Name', 'Note', null);
        $this->translations->save('item', (string) $item, 'en', 'Item name', '', null);

        $this->assertSame(['party' => null, 'items' => []], $this->translations->forDocument(TranslationKind::Customer, $customer, [$item]));

        $this->useSecondLanguage('en', false);

        $this->assertSame(['party' => null, 'items' => []], $this->translations->forDocument(TranslationKind::Customer, $customer, [$item]));
    }

    public function testForDocument_GivesThePartyAndTheItemNamesInTheSecondLanguageWhileTheSwitchIsOn(): void
    {
        $customer = $this->insertCustomer();
        $item     = $this->item();
        $other    = $this->item();

        $this->translations->save('customer', (string) $customer, 'en', 'Name', 'Note', null);
        $this->translations->save('customer', (string) $customer, 'de-DE', 'Name DE', 'Note DE', null);
        $this->translations->save('item', (string) $item, 'en', 'Item name', '', null);
        $this->translations->save('item', (string) $item, 'de-DE', 'Item DE', '', null);
        $this->useSecondLanguage('en');

        $given = $this->translations->forDocument(TranslationKind::Customer, $customer, [$item, $other, $item]);

        $this->assertSame(['party' => ['name' => 'Name', 'note' => 'Note'], 'items' => [$item => 'Item name']], $given);
    }

    public function testForDocument_ReadsTheSupplierForAPurchaseAndNothingForNoParty(): void
    {
        $supplier = $this->insertSupplier();
        $customer = $this->insertCustomer();

        $this->translations->save('supplier', (string) $supplier, 'en', 'Supplier EN', '', null);
        $this->translations->save('customer', (string) $customer, 'en', 'Customer EN', '', null);
        $this->useSecondLanguage('en');

        $this->assertSame(['name' => 'Supplier EN', 'note' => ''], $this->translations->forDocument(TranslationKind::Supplier, $supplier, [])['party']);
        $this->assertNull($this->translations->forDocument(TranslationKind::Supplier, $customer, [])['party'], 'A customer number is no supplier.');
        $this->assertNull($this->translations->forDocument(TranslationKind::Customer, null, [])['party']);
    }

    public function testForDocument_ReadsTheItemModelUnchangedWhenAnItemIsSaved(): void
    {
        $item = $this->item();

        $this->translations->save('item', (string) $item, 'en', 'Item name', '', null);

        $this->assertSame('Test Item', model(Item::class)->get_info($item)->name);
    }
}
