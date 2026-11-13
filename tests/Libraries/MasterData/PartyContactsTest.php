<?php

namespace Tests\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\MasterData\PartyContacts;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class PartyContactsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->query('DELETE FROM ' . $db->prefixTable('party_contacts'));
        $db->table('accounting_audit_log')->whereIn('action', ['party_contacts_changed', 'party_contacts_hidden'])->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function contacts(): PartyContacts
    {
        return new PartyContacts();
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [...['first_name' => 'Sara', 'last_name' => 'Nasser', 'email' => 'sara@example.com', 'phone' => '011 123 4567', 'mobile' => '+966 50 123 4567', 'position' => 'Buyer', 'note' => 'Call after 9'], ...$overrides];
    }

    private function refusalOf(callable $action): ?AccountingError
    {
        try {
            $action();
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(int $partyId, bool $withHidden = false): array
    {
        $builder = $this->accountingDb()->table('party_contacts')->where('party_id', $partyId)->orderBy('contact_id', 'ASC');

        if (! $withHidden) {
            $builder->where('deleted', 0);
        }

        return $builder->get()->getResultArray();
    }

    private function auditCount(string $action, int $partyId): int
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['action' => $action, 'entity_id' => $partyId])->countAllResults();
    }

    public function testValidate_AcceptsAFullRowAndLowercasesTheEmailAddress(): void
    {
        $valid = $this->contacts()->validate([$this->row(['email' => 'Sara@Example.COM'])]);

        $this->assertSame('sara@example.com', $valid[0]['email']);
        $this->assertSame(['Sara', 'Nasser', '011 123 4567', '+966 50 123 4567', 'Buyer', 'Call after 9'], [$valid[0]['first_name'], $valid[0]['last_name'], $valid[0]['phone'], $valid[0]['mobile'], $valid[0]['position'], $valid[0]['note']]);
    }

    public function testValidate_AcceptsARowWithAFirstNameAlone(): void
    {
        $valid = $this->contacts()->validate([['first_name' => 'Sara']]);

        $this->assertSame(['Sara', '', '', '', '', '', ''], [$valid[0]['first_name'], $valid[0]['last_name'], $valid[0]['email'], $valid[0]['phone'], $valid[0]['mobile'], $valid[0]['position'], $valid[0]['note']]);
    }

    public function testValidate_DropsARowWithNothingInIt(): void
    {
        $valid = $this->contacts()->validate([['first_name' => '  ', 'last_name' => '', 'email' => ''], $this->row()]);

        $this->assertCount(1, $valid);
    }

    public function testValidate_RejectsARowWithoutAFirstName(): void
    {
        $this->assertSame(AccountingError::ContactNameRequired, $this->refusalOf(fn () => $this->contacts()->validate([$this->row(['first_name' => ''])])));
        $this->assertSame(AccountingError::ContactNameRequired, $this->refusalOf(fn () => $this->contacts()->validate([['email' => 'sara@example.com']])));
    }

    #[DataProvider('provideValidate_RejectsAnEmailAddressThatIsNotValid')]
    public function testValidate_RejectsAnEmailAddressThatIsNotValid(string $email): void
    {
        $this->assertSame(AccountingError::ContactEmailInvalid, $this->refusalOf(fn () => $this->contacts()->validate([$this->row(['email' => $email])])));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideValidate_RejectsAnEmailAddressThatIsNotValid(): iterable
    {
        return ['no at sign' => ['sara.example.com'], 'no domain' => ['sara@'], 'a space inside' => ['sa ra@example.com'], 'two at signs' => ['sara@@example.com']];
    }

    #[DataProvider('provideValidate_RejectsAPhoneOrMobileNumberWithAnythingButDigitsAndSeparators')]
    public function testValidate_RejectsAPhoneOrMobileNumberWithAnythingButDigitsAndSeparators(string $number): void
    {
        $this->assertSame(AccountingError::ContactPhoneInvalid, $this->refusalOf(fn () => $this->contacts()->validate([$this->row(['phone' => $number])])));
        $this->assertSame(AccountingError::ContactPhoneInvalid, $this->refusalOf(fn () => $this->contacts()->validate([$this->row(['mobile' => $number])])));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideValidate_RejectsAPhoneOrMobileNumberWithAnythingButDigitsAndSeparators(): iterable
    {
        return ['letters' => ['call me'], 'a script' => ['<b>1</b>'], 'a semicolon' => ['123;456']];
    }

    #[DataProvider('provideValidate_TurnsArabicIndicAndPersianDigitsOfANumberIntoTheDigitsOfTheBooks')]
    public function testValidate_TurnsArabicIndicAndPersianDigitsOfANumberIntoTheDigitsOfTheBooks(string $typed, string $stored): void
    {
        $valid = $this->contacts()->validate([$this->row(['phone' => $typed, 'mobile' => $typed])]);

        $this->assertSame([$stored, $stored], [$valid[0]['phone'], $valid[0]['mobile']]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideValidate_TurnsArabicIndicAndPersianDigitsOfANumberIntoTheDigitsOfTheBooks(): iterable
    {
        return [
            'Arabic-Indic digits'           => ['٠١٢٣٤٥٦٧٨٩', '0123456789'],
            'Persian digits'                => ['۰۱۲۳۴۵۶۷۸۹', '0123456789'],
            'separators between the digits' => ['+٩٦٦ ٥٠ (١٢٣)-٤٥٦٧', '+966 50 (123)-4567'],
            'both kinds and Latin digits'   => ['٠۱2', '012'],
        ];
    }

    public function testValidate_RefusesALetterAmongArabicIndicDigitsAndLeavesTheOtherFieldsAsTyped(): void
    {
        $this->assertSame(AccountingError::ContactPhoneInvalid, $this->refusalOf(fn () => $this->contacts()->validate([$this->row(['phone' => '٠١٢ab'])])));

        $valid = $this->contacts()->validate([$this->row(['position' => 'الطابق ٣', 'note' => 'غرفة ٤٢'])]);

        $this->assertSame(['الطابق ٣', 'غرفة ٤٢'], [$valid[0]['position'], $valid[0]['note']]);
    }

    public function testReplace_StoresTheDigitsOfAPhoneTypedInArabicIndicDigitsAsLatinDigits(): void
    {
        $customerId = $this->insertCustomer();

        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row(['phone' => '٠١١ ١٢٣', 'mobile' => '۰۵۰'])], 1);

        $this->assertSame(['011 123', '050'], [$this->stored($customerId)[0]['phone'], $this->stored($customerId)[0]['mobile']]);
    }

    #[DataProvider('provideValidate_AcceptsTheLongestValueOfAFieldAndRejectsOneCharacterMore')]
    public function testValidate_AcceptsTheLongestValueOfAFieldAndRejectsOneCharacterMore(string $field, int $max): void
    {
        $filler  = in_array($field, ['phone', 'mobile'], true) ? '1' : 'a';
        $longest = $field === 'email' ? 'a@' . implode('.', [str_repeat('d', 63), str_repeat('d', 63), str_repeat('d', 63), str_repeat('d', $max - 194)]) : str_repeat($filler, $max);

        $this->assertCount(1, $this->contacts()->validate([$this->row([$field => $longest])]));
        $this->assertSame(AccountingError::ContactFieldTooLong, $this->refusalOf(fn () => $this->contacts()->validate([$this->row([$field => $longest . $filler])])));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function provideValidate_AcceptsTheLongestValueOfAFieldAndRejectsOneCharacterMore(): iterable
    {
        $cases = [];

        foreach (PartyContacts::LIMITS as $field => $max) {
            $cases[$field] = [$field, $max];
        }

        return $cases;
    }

    public function testValidate_AcceptsFiftyRowsAndRejectsFiftyOne(): void
    {
        $rows = array_map(fn (int $index): array => $this->row(['first_name' => 'Contact ' . $index]), range(1, PartyContacts::MAX_ROWS));

        $this->assertCount(PartyContacts::MAX_ROWS, $this->contacts()->validate($rows));
        $this->assertSame(AccountingError::ContactsTooMany, $this->refusalOf(fn () => $this->contacts()->validate([...$rows, $this->row(['first_name' => 'One more'])])));
    }

    public function testValidate_CountsTheLengthOfAnArabicNameInCharactersNotBytes(): void
    {
        $this->assertCount(1, $this->contacts()->validate([$this->row(['first_name' => str_repeat('م', 100)])]));
    }

    public function testValidate_KeepsAMarkupNameAsTextForTheViewToEscape(): void
    {
        $valid = $this->contacts()->validate([$this->row(['first_name' => '<script>alert(1)</script>'])]);

        $this->assertSame('<script>alert(1)</script>', $valid[0]['first_name']);
    }

    public function testFromRequest_ReturnsNullWithoutTheMarkerSoAnOlderFormLeavesTheListAlone(): void
    {
        $this->assertNull(PartyContacts::fromRequest(['first_name' => 'Omar']));
        $this->assertNull(PartyContacts::fromRequest([PartyContacts::MARKER => '0', PartyContacts::FIELD => [$this->row()]]));
    }

    public function testFromRequest_ReadsTheRowsOfAFormThatCarriesTheMarker(): void
    {
        $this->assertSame([$this->row()], PartyContacts::fromRequest([PartyContacts::MARKER => '1', PartyContacts::FIELD => [$this->row(), 'junk']]));
        $this->assertSame([], PartyContacts::fromRequest([PartyContacts::MARKER => '1']));
        $this->assertSame([], PartyContacts::fromRequest([PartyContacts::MARKER => '1', PartyContacts::FIELD => 'junk']));
    }

    public function testReplace_StoresTheRowsInTheOrderGivenAndWritesOneAuditEntry(): void
    {
        $customerId = $this->insertCustomer();
        $result     = $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row(['first_name' => 'First']), $this->row(['first_name' => 'Second'])], 1);

        $this->assertSame(['added' => 2, 'updated' => 0, 'removed' => []], $result);
        $this->assertSame(['First', 'Second'], array_column($this->stored($customerId), 'first_name'));
        $this->assertSame([0, 1], array_map('intval', array_column($this->stored($customerId), 'sort')));
        $this->assertSame(1, $this->auditCount('party_contacts_changed', $customerId));
    }

    public function testReplace_UpdatesAContactByItsNumberAndHidesTheOnesTheListNoLongerNames(): void
    {
        $customerId = $this->insertCustomer();
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row(['first_name' => 'Keep']), $this->row(['first_name' => 'Drop'])], 1);
        [$keep, $drop] = array_map('intval', array_column($this->stored($customerId), 'contact_id'));

        $result = $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [['contact_id' => $keep] + $this->row(['first_name' => 'Kept', 'mobile' => '0500000000'])], 1);

        $this->assertSame(['added' => 0, 'updated' => 1, 'removed' => [$drop]], $result);
        $this->assertSame(['Kept'], array_column($this->stored($customerId), 'first_name'));
        $this->assertSame('1', (string) $this->accountingDb()->table('party_contacts')->where('contact_id', $drop)->get()->getRow()->deleted);
        $this->assertSame(2, $this->auditCount('party_contacts_changed', $customerId));
    }

    public function testReplace_WritesNoAuditEntryWhenNothingChanged(): void
    {
        $customerId = $this->insertCustomer();
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row()], 1);
        $contactId = (int) $this->stored($customerId)[0]['contact_id'];

        $result = $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [['contact_id' => $contactId] + $this->row()], 1);

        $this->assertSame(['added' => 0, 'updated' => 0, 'removed' => []], $result);
        $this->assertSame(1, $this->auditCount('party_contacts_changed', $customerId));
    }

    public function testReplace_EmptiesTheListWhenNoRowIsGiven(): void
    {
        $customerId = $this->insertCustomer();
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row()], 1);

        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [], 1);

        $this->assertSame([], $this->contacts()->forParty(PartyContacts::CUSTOMER, $customerId));
        $this->assertCount(1, $this->stored($customerId, true));
    }

    public function testReplace_WritesNothingWhenOneRowIsRefused(): void
    {
        $customerId = $this->insertCustomer();
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row(['first_name' => 'Original'])], 1);
        $contactId = (int) $this->stored($customerId)[0]['contact_id'];

        $refusal = $this->refusalOf(fn () => $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [['contact_id' => $contactId] + $this->row(['first_name' => 'Changed']), $this->row(['email' => 'not an address'])], 1));

        $this->assertSame(AccountingError::ContactEmailInvalid, $refusal);
        $this->assertSame(['Original'], array_column($this->stored($customerId), 'first_name'));
        $this->assertSame(1, $this->auditCount('party_contacts_changed', $customerId));
    }

    public function testReplace_RejectsAContactNumberThatBelongsToAnotherParty(): void
    {
        $first  = $this->insertCustomer();
        $second = $this->insertCustomer('Hind', 'Saad');
        $this->contacts()->replace(PartyContacts::CUSTOMER, $first, [$this->row(['first_name' => 'Of the first'])], 1);
        $foreign = (int) $this->stored($first)[0]['contact_id'];

        $refusal = $this->refusalOf(fn () => $this->contacts()->replace(PartyContacts::CUSTOMER, $second, [['contact_id' => $foreign] + $this->row(['first_name' => 'Hijacked'])], 1));

        $this->assertSame(AccountingError::ContactUnknown, $refusal);
        $this->assertSame(['Of the first'], array_column($this->stored($first), 'first_name'));
        $this->assertSame([], $this->stored($second));
    }

    public function testReplace_RejectsAContactNumberOfAHiddenContact(): void
    {
        $customerId = $this->insertCustomer();
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row()], 1);
        $contactId = (int) $this->stored($customerId)[0]['contact_id'];
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [], 1);

        $refusal = $this->refusalOf(fn () => $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [['contact_id' => $contactId] + $this->row()], 1));

        $this->assertSame(AccountingError::ContactUnknown, $refusal);
    }

    public function testPrepare_RejectsAContactNumberForAPartyThatIsNew(): void
    {
        $this->assertSame(AccountingError::ContactUnknown, $this->refusalOf(fn () => $this->contacts()->prepare(PartyContacts::CUSTOMER, null, [['contact_id' => 5] + $this->row()])));
        $this->assertCount(1, $this->contacts()->prepare(PartyContacts::CUSTOMER, null, [$this->row()]));
    }

    public function testReplace_KeepsTheContactsOfACustomerAndOfASupplierApart(): void
    {
        $customerId = $this->insertCustomer();
        $supplierId = $this->insertSupplier();
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row(['first_name' => 'Customer side'])], 1);
        $this->contacts()->replace(PartyContacts::SUPPLIER, $supplierId, [$this->row(['first_name' => 'Supplier side'])], 1);

        $this->assertSame(['Customer side'], array_column($this->contacts()->forParty(PartyContacts::CUSTOMER, $customerId), 'first_name'));
        $this->assertSame(['Supplier side'], array_column($this->contacts()->forParty(PartyContacts::SUPPLIER, $supplierId), 'first_name'));
        $this->assertSame([], $this->contacts()->forParty(PartyContacts::SUPPLIER, $customerId));
        $this->assertSame([], $this->contacts()->forParty(PartyContacts::CUSTOMER, $supplierId));
    }

    public function testReplace_ChangesNoJournalAndNoBalance(): void
    {
        $db         = $this->accountingDb();
        $customerId = $this->insertCustomer();
        $tables     = array_map(static fn (string $table): string => $db->prefixTable($table), ['accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'sales', 'sales_payments', 'customer_credit_applications']);
        $checksum   = static fn (): array => array_column($db->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`')->getResultArray(), 'Checksum', 'Table');
        $before     = $checksum();

        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row(), $this->row(['first_name' => 'Second'])], 1);
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [], 1);
        $this->contacts()->hideWithParty(PartyContacts::CUSTOMER, $customerId, true, 1);

        $this->assertSame($before, $checksum());
    }

    public function testHideWithParty_HidesTheContactsAndKeepsTheirRows(): void
    {
        $customerId = $this->insertCustomer();
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row(['first_name' => 'One']), $this->row(['first_name' => 'Two'])], 1);

        $this->assertSame(2, $this->contacts()->hideWithParty(PartyContacts::CUSTOMER, $customerId, false, 1));

        $this->assertSame([], $this->contacts()->forParty(PartyContacts::CUSTOMER, $customerId));
        $this->assertSame(['One', 'Two'], array_column($this->stored($customerId, true), 'first_name'));
        $this->assertSame(1, $this->auditCount('party_contacts_hidden', $customerId));
    }

    public function testHideWithParty_WritesNoAuditEntryForAPartyWithNoContacts(): void
    {
        $customerId = $this->insertCustomer();

        $this->assertSame(0, $this->contacts()->hideWithParty(PartyContacts::CUSTOMER, $customerId, false, 1));
        $this->assertSame(0, $this->auditCount('party_contacts_hidden', $customerId));
    }

    public function testHideWithParty_ScramblesThePersonalDataWhenTheShopScramblesDeletedCustomers(): void
    {
        $customerId = $this->insertCustomer();
        $this->contacts()->replace(PartyContacts::CUSTOMER, $customerId, [$this->row()], 1);
        $contactId = (int) $this->stored($customerId)[0]['contact_id'];

        $this->contacts()->hideWithParty(PartyContacts::CUSTOMER, $customerId, true, 1);

        $row = $this->accountingDb()->table('party_contacts')->where('contact_id', $contactId)->get()->getRowArray();
        $this->assertSame([(string) $contactId, '', '', '', '', '', ''], [$row['first_name'], $row['last_name'], $row['email'], $row['phone'], $row['mobile'], $row['position'], $row['note']]);
    }

    public function testHideWithParty_LeavesTheContactsOfAnotherPartyAlone(): void
    {
        $first  = $this->insertCustomer();
        $second = $this->insertCustomer('Hind', 'Saad');
        $this->contacts()->replace(PartyContacts::CUSTOMER, $first, [$this->row()], 1);
        $this->contacts()->replace(PartyContacts::CUSTOMER, $second, [$this->row(['first_name' => 'Safe'])], 1);

        $this->contacts()->hideWithParty(PartyContacts::CUSTOMER, $first, true, 1);

        $this->assertSame(['Safe'], array_column($this->contacts()->forParty(PartyContacts::CUSTOMER, $second), 'first_name'));
    }

    public function testForParty_RejectsAKindThatIsNeitherCustomerNorSupplier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contacts()->forParty('employee', 1);
    }

    public function testReplace_RejectsAKindThatIsNeitherCustomerNorSupplier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contacts()->replace('employee', 1, [$this->row()], 1);
    }
}
