<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The identification and the national address of a customer for the electronic invoice, in a table of
 * their own: the scheme and the number that identify the customer (the eleven codes), and the structured
 * address the standard needs. The customer's tax number and the government flag stay where the customer
 * form already keeps them. Nothing here is read by the books.
 */
final class CustomerParties
{
    public const LIMITS = ['identification_number' => 60, 'street' => 255, 'additional_street' => EInvoiceRules::TEXT_MAX, 'building_number' => 10, 'additional_number' => 10, 'district' => EInvoiceRules::TEXT_MAX, 'city' => EInvoiceRules::TEXT_MAX, 'province' => EInvoiceRules::TEXT_MAX, 'postal_code' => 10];

    /**
     * The tax identification number ZATCA gives (TIN): ten digits beginning with 3, as the official validator checks
     * it (BR-KSA-F-07). A buyer abroad is identified under another scheme (OTH).
     */
    public const TAX_IDENTIFICATION = '/^3\d{9}$/';

    public const FIELDS = ['identification_type', 'identification_number', 'street', 'additional_street', 'building_number', 'additional_number', 'district', 'city', 'province', 'postal_code', 'country_code'];

    private BaseConnection $db;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
    }

    /**
     * @return array<string, string> Every field, empty where the customer has none.
     */
    public function find(int $personId): array
    {
        $row = $this->db->table('customer_einvoice_parties')->where('person_id', $personId)->get()->getRowArray();

        return array_merge(array_fill_keys(self::FIELDS, ''), ['country_code' => 'SA'], array_map(static fn ($value): string => (string) $value, array_intersect_key($row ?? [], array_flip(self::FIELDS))));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string> The fields as they will be saved.
     *
     * @throws EInvoiceException
     */
    public function clean(array $input): array
    {
        $clean = [];

        foreach (self::FIELDS as $field) {
            $clean[$field] = trim((string) ($input[$field] ?? ''));
        }

        $clean['country_code'] = $clean['country_code'] === '' ? 'SA' : strtoupper($clean['country_code']);

        if ($clean['identification_type'] !== '' && PartyIdentificationType::tryFrom($clean['identification_type']) === null) {
            throw new EInvoiceException(EInvoiceError::IdentificationInvalid, 'This identification scheme is not one of the eleven.', ['field' => $clean['identification_type']]);
        }

        if ($clean['identification_number'] !== '' && ! EInvoiceRules::identificationNumber($clean['identification_number'])) {
            throw new EInvoiceException(EInvoiceError::IdentificationInvalid, 'An identification number holds letters and digits only.', ['field' => $clean['identification_number']]);
        }

        if (! EInvoiceRules::within($clean['identification_number'], self::LIMITS['identification_number'])) {
            throw new EInvoiceException(EInvoiceError::IdentificationInvalid, 'An identification number is too long.', ['field' => 'identification_number', 'expected' => self::LIMITS['identification_number']]);
        }

        if ($clean['identification_type'] !== '' && $clean['identification_number'] === '') {
            throw new EInvoiceException(EInvoiceError::IdentificationInvalid, 'A scheme needs its number.', ['field' => $clean['identification_type']]);
        }

        if ($clean['identification_type'] === '' && $clean['identification_number'] !== '') {
            throw new EInvoiceException(EInvoiceError::IdentificationInvalid, 'A number needs its scheme.', ['field' => $clean['identification_number']]);
        }

        if ($clean['identification_type'] === PartyIdentificationType::TaxIdentification->value && preg_match(self::TAX_IDENTIFICATION, $clean['identification_number']) !== 1) {
            throw new EInvoiceException(EInvoiceError::IdentificationInvalid, 'A tax identification number is ten digits beginning with 3.', ['field' => $clean['identification_number']]);
        }

        if (! EInvoiceRules::countryCode($clean['country_code'])) {
            throw new EInvoiceException(EInvoiceError::AddressInvalid, 'A country code has two letters.', ['field' => $clean['country_code']]);
        }

        if ($clean['building_number'] !== '' && $clean['country_code'] === 'SA' && ! EInvoiceRules::buildingNumber($clean['building_number'])) {
            throw new EInvoiceException(EInvoiceError::AddressInvalid, 'A building number has four digits.', ['field' => $clean['building_number']]);
        }

        if ($clean['postal_code'] !== '' && $clean['country_code'] === 'SA' && ! EInvoiceRules::postalCode($clean['postal_code'])) {
            throw new EInvoiceException(EInvoiceError::AddressInvalid, 'A postal code has five digits.', ['field' => $clean['postal_code']]);
        }

        if ($clean['additional_number'] !== '' && preg_match('/^\d{1,10}$/', $clean['additional_number']) !== 1) {
            throw new EInvoiceException(EInvoiceError::AddressInvalid, 'The additional number holds digits only.', ['field' => $clean['additional_number']]);
        }

        foreach (array_slice(self::LIMITS, 1) as $field => $limit) {
            if (! EInvoiceRules::within($clean[$field], $limit)) {
                throw new EInvoiceException(EInvoiceError::AddressInvalid, 'An address field is too long.', ['field' => $field, 'expected' => $limit]);
            }
        }

        return $clean;
    }

    /**
     * Saves what the customer form gave. A customer whose fields are all empty has no row, and one whose
     * fields did not change is not written again.
     *
     * @param array<string, mixed> $input
     *
     * @throws AccountingException|EInvoiceException
     */
    public function save(int $personId, array $input, ?int $employeeId = null): bool
    {
        $clean  = $this->clean($input);
        $before = $this->find($personId);

        if ($clean === $before) {
            return false;
        }

        $blank = $clean === array_merge(array_fill_keys(self::FIELDS, ''), ['country_code' => 'SA']);

        $written = $blank
            ? $this->db->table('customer_einvoice_parties')->where('person_id', $personId)->delete()
            : $this->db->table('customer_einvoice_parties')->replace([
                'person_id'  => $personId,
                'updated_by' => $employeeId,
                ...$clean,
                'identification_type' => $clean['identification_type'] === '' ? null : $clean['identification_type'],
            ]);

        if ($written === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The identification and the address of the customer could not be saved.');
        }

        $this->audit->write('customer_einvoice_party', $personId, 'updated', $employeeId, JournalOrigin::User, ['before' => $before, 'after' => $clean]);

        return true;
    }
}
