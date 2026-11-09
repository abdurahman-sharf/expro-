<?php

namespace Tests\Controllers;

use App\Libraries\MasterData\PartyContacts;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

/**
 * The supplier form: what it keeps besides the name, and in particular the commercial registry number that
 * the purchase documents show beside the tax number.
 */
class SuppliersControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $supplierIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setConfigValue('accounting_enabled', '0');
        $this->withSession(['person_id' => $this->createEmployee(grants: [['permission_id' => 'suppliers', 'menu_group' => 'home']]), 'menu_group' => 'home']);
    }

    protected function tearDown(): void
    {
        if ($this->supplierIds !== []) {
            $db = $this->accountingDb();

            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->table('suppliers')->whereIn('person_id', $this->supplierIds)->delete();
            $db->table('people')->whereIn('person_id', $this->supplierIds)->delete();
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->supplierIds = [];

        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('party_contacts'));
        $this->accountingDb()->table('accounting_audit_log')->whereIn('action', ['party_contacts_changed', 'party_contacts_hidden'])->delete();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $more
     *
     * @return array<string, string>
     */
    private function form(array $more = []): array
    {
        return $more + [
            'first_name'   => 'Sara',
            'last_name'    => 'Nasser',
            'phone_number' => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
            'company_name' => 'Registry Supplies ' . uniqid(),
            'agency_name'  => '',
            'category'     => (string) GOODS_SUPPLIER,
            'tax_id'       => '300123456700003',
            'email'        => '',
        ];
    }

    private function saveSupplier(array $form, int $supplierId = NEW_ENTRY): int
    {
        $result = json_decode($this->post('/suppliers/save/' . $supplierId, $form)->getJSON(), true);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        $this->supplierIds[] = (int) $result['id'];

        return (int) $result['id'];
    }

    private function supplierRow(int $supplierId): object
    {
        return $this->accountingDb()->table('suppliers')->where('person_id', $supplierId)->get()->getRow();
    }

    public function testPostSave_SavesTheCommercialRegistryBesideTheTaxNumber(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '1010123456']));

        $row = $this->supplierRow($supplierId);

        $this->assertSame('1010123456', $row->commercial_registry);
        $this->assertSame('300123456700003', $row->tax_id);
    }

    public function testPostSave_KeepsARegistryNumberThatHasLettersAndSeparators(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '  CR-1010/123 ']));

        $this->assertSame('CR-1010/123', $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testPostSave_CutsARegistryNumberToTheSixtyFourCharactersTheColumnKeeps(): void
    {
        $db   = $this->accountingDb();
        $mode = $db->query('SELECT @@SESSION.sql_mode AS mode')->getRow()->mode;

        $db->query("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_ALL_TABLES')");

        try {
            $supplierId = $this->saveSupplier($this->form(['commercial_registry' => str_repeat('7', 70)]));
        } finally {
            $db->query('SET SESSION sql_mode = ?', [$mode]);
        }

        $this->assertSame(str_repeat('7', 64), $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testPostSave_LeavesAnEmptyRegistryForASupplierWhoseFormGaveNone(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '']));

        $this->assertSame('', $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testPostSave_ClearsTheRegistryWhenTheFormEmptiesIt(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '1010123456']));

        $this->saveSupplier($this->form(['commercial_registry' => '']), $supplierId);

        $this->assertSame('', $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testPostSave_LeavesTheRegistryAloneWhenTheFormDoesNotCarryIt(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '1010123456']));

        $this->saveSupplier($this->form(['company_name' => 'Renamed Supplies ' . uniqid()]), $supplierId);

        $this->assertSame('1010123456', $this->supplierRow($supplierId)->commercial_registry);
    }

    public function testGetView_ShowsTheRegistryFieldBesideTheTaxNumber(): void
    {
        $supplierId = $this->saveSupplier($this->form(['commercial_registry' => '1010123456']));

        $response = $this->get('/suppliers/view/' . $supplierId);

        $response->assertSeeInField('commercial_registry', '1010123456');
        $response->assertSeeInField('tax_id', '300123456700003');
        $response->assertSee(lang('Suppliers.commercial_registry'));
    }

    public function testGetView_ShowsAnEmptyRegistryFieldForANewSupplier(): void
    {
        $response = $this->get('/suppliers/view');

        $response->assertSeeElement('#commercial_registry');
        $response->assertSeeInField('commercial_registry', '');
    }

    // ========== country code and the longest values of the party fields ==========

    /**
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    private function attempt(array $form, int $supplierId = NEW_ENTRY): array
    {
        $result = json_decode($this->post('/suppliers/save/' . $supplierId, $form)->getJSON(), true);

        if ($result['success'] && $supplierId === NEW_ENTRY) {
            $this->supplierIds[] = (int) $result['id'];
        }

        return $result;
    }

    public function testPostSave_SavesTheCountryCodeInCapitals(): void
    {
        $supplierId = $this->saveSupplier($this->form(['country_code' => ' eg ']));

        $this->assertSame('EG', $this->supplierRow($supplierId)->country_code);
    }

    public function testPostSave_RejectsACountryCodeThatIsNotTwoLettersAndSavesNothing(): void
    {
        $before = $this->countRows('suppliers');

        $result = $this->attempt($this->form(['country_code' => 'EGY']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('EGY', $result['message']);
        $this->assertSame($before, $this->countRows('suppliers'));
    }

    public function testPostSave_ClearsTheCountryWhenTheFormEmptiesItAndKeepsItWhenTheFormDoesNotCarryIt(): void
    {
        $supplierId = $this->saveSupplier($this->form(['country_code' => 'SA']));

        $this->saveSupplier($this->form(['company_name' => 'Renamed ' . uniqid()]), $supplierId);
        $this->assertSame('SA', $this->supplierRow($supplierId)->country_code);

        $this->saveSupplier($this->form(['country_code' => '']), $supplierId);
        $this->assertNull($this->supplierRow($supplierId)->country_code);
    }

    public function testGetView_ShowsTheCountryCodeField(): void
    {
        $supplierId = $this->saveSupplier($this->form(['country_code' => 'AE']));

        $body = $this->get('/suppliers/view/' . $supplierId)->getBody();

        $this->assertStringContainsString('name="country_code"', $body);
        $this->assertStringContainsString('value="AE"', $body);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function limitedFieldProvider(): array
    {
        return ['the business name' => ['company_name', 100], 'the city' => ['city', 100], 'the state' => ['state', 100], 'the postal code' => ['zip', 20]];
    }

    #[DataProvider('limitedFieldProvider')]
    public function testPostSave_AcceptsTheLongestValueAndRejectsOneCharacterMore(string $field, int $max): void
    {
        $accepted = $this->attempt($this->form([$field => str_repeat('a', $max)]));
        $refused  = $this->attempt($this->form([$field => str_repeat('a', $max + 1)]));

        $this->assertTrue($accepted['success']);
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString((string) $max, $refused['message']);

        $this->supplierIds[] = (int) $accepted['id'];
    }

    public function testPostSave_KeepsAValueThatWasLongerBeforeTheLimitWhileItIsNotChanged(): void
    {
        $supplierId = $this->saveSupplier($this->form(['city' => 'short']));
        $long       = str_repeat('c', 150);
        $this->accountingDb()->table('people')->where('person_id', $supplierId)->update(['city' => $long]);

        $kept    = $this->attempt($this->form(['city' => $long, 'company_name' => 'Kept ' . uniqid()]), $supplierId);
        $changed = $this->attempt($this->form(['city' => $long . 'x']), $supplierId);

        $this->assertTrue($kept['success']);
        $this->assertFalse($changed['success']);
        $this->assertSame($long, $this->accountingDb()->table('people')->where('person_id', $supplierId)->get()->getRow()->city);
    }

    // ========== The contact list of a supplier ==========

    /**
     * @param array<string, string> $more
     *
     * @return array<string, string>
     */
    private function contactRow(array $more = []): array
    {
        return $more + ['contact_id' => '', 'first_name' => 'Khaled', 'last_name' => 'Saad', 'email' => 'khaled@example.com', 'phone' => '011 123 4567', 'mobile' => '0500000000', 'position' => 'Sales', 'note' => ''];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contactsOf(int $supplierId, bool $withHidden = false): array
    {
        $builder = $this->accountingDb()->table('party_contacts')->where(['party_kind' => 'supplier', 'party_id' => $supplierId])->orderBy('contact_id', 'ASC');

        if (! $withHidden) {
            $builder->where('deleted', 0);
        }

        return $builder->get()->getResultArray();
    }

    public function testPostSave_StoresTheContactRowsOfASupplier(): void
    {
        $supplierId = $this->saveSupplier($this->form([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->contactRow(['first_name' => 'First']), $this->contactRow(['first_name' => 'Second'])]]));

        $this->assertSame(['First', 'Second'], array_column($this->contactsOf($supplierId), 'first_name'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'supplier', 'entity_id' => $supplierId, 'action' => 'party_contacts_changed'])->countAllResults());
    }

    public function testPostSave_LeavesTheContactsAloneWhenTheFormDoesNotCarryThePanel(): void
    {
        $supplierId = $this->saveSupplier($this->form([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->contactRow()]]));

        $this->saveSupplier($this->form(['comments' => 'older form']), $supplierId);

        $this->assertCount(1, $this->contactsOf($supplierId));
    }

    public function testPostSave_RejectsAnInvalidContactEmailAndSavesNothing(): void
    {
        $before = $this->accountingDb()->table('suppliers')->countAllResults();

        $result = json_decode($this->post('/suppliers/save/' . NEW_ENTRY, $this->form([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->contactRow(['email' => 'nope'])]]))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame($before, $this->accountingDb()->table('suppliers')->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('party_contacts')->countAllResults());
    }

    public function testPostSave_RejectsAContactNumberThatBelongsToAnotherSupplier(): void
    {
        $first   = $this->saveSupplier($this->form([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->contactRow(['first_name' => 'Of the first'])]]));
        $second  = $this->saveSupplier($this->form());
        $foreign = $this->contactsOf($first)[0]['contact_id'];

        $result = json_decode($this->post('/suppliers/save/' . $second, $this->form([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->contactRow(['contact_id' => (string) $foreign, 'first_name' => 'Hijacked'])]]))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(['Of the first'], array_column($this->contactsOf($first), 'first_name'));
    }

    public function testPostDelete_HidesTheContactsWithTheSupplierAndKeepsTheirRows(): void
    {
        $supplierId = $this->saveSupplier($this->form([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->contactRow(['first_name' => 'One']), $this->contactRow(['first_name' => 'Two'])]]));

        $deleted = json_decode($this->post('/suppliers/delete', ['ids' => [(string) $supplierId]])->getJSON(), true);

        $this->assertTrue($deleted['success']);
        $this->assertSame([], $this->contactsOf($supplierId));
        $this->assertSame(['One', 'Two'], array_column($this->contactsOf($supplierId, true), 'first_name'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'supplier', 'entity_id' => $supplierId, 'action' => 'party_contacts_hidden'])->countAllResults());
    }

    public function testGetView_ShowsTheContactPanelWithTheValuesEscaped(): void
    {
        $supplierId = $this->saveSupplier($this->form([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->contactRow(['first_name' => '<script>x()</script>'])]]));

        $html = $this->get('/suppliers/view/' . $supplierId)->getBody();

        $this->assertStringContainsString('id="contacts_panel"', $html);
        $this->assertStringContainsString('value="&lt;script&gt;x()&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<script>x()</script>', $html);
    }

    public function testGetContacts_ListsTheContactsOfASupplier(): void
    {
        $supplierId = $this->saveSupplier($this->form([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->contactRow(['first_name' => 'Listed'])]]));

        $list = json_decode($this->get('/suppliers/contacts/' . $supplierId)->getJSON(), true);

        $this->assertSame(['Listed'], array_column($list['contacts'], 'first_name'));
    }

    public function testGetContacts_ReturnsNotFoundForAnUnknownSupplier(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->get('/suppliers/contacts/987654');
    }
}
