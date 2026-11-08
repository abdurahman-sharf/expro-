<?php

namespace App\Libraries\EInvoicing;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The seller and the buyer of a document, from what the shop keeps, refused with a named reason when the
 * standard would refuse the document: the business rules of the seller and the buyer (BR-KSA-08 to
 * BR-KSA-10, BR-KSA-14, BR-KSA-25, BR-KSA-37, BR-KSA-39, BR-KSA-40, BR-KSA-42, BR-KSA-44, BR-KSA-63, BR-KSA-66
 * and BR-KSA-67 of the XML implementation standard). Whether a customer is a business is read from the tax
 * number, the government flag and the type the customer form keeps.
 */
final class EInvoiceParties
{
    private BaseConnection $db;
    private EInvoiceSettings $settings;
    private CustomerParties $parties;

    public function __construct(?BaseConnection $db = null, ?EInvoiceSettings $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->settings = $settings ?? new EInvoiceSettings($this->db);
        $this->parties  = new CustomerParties($this->db);
    }

    /**
     * @throws EInvoiceException
     */
    public function seller(): EInvoiceParty
    {
        $values = $this->settings->all();
        $name   = $this->settings->sellerName();
        $vat    = $this->settings->sellerVatNumber();

        if ($name === '') {
            throw new EInvoiceException(EInvoiceError::SellerNameMissing, 'The company has no name.');
        }

        if (! EInvoiceRules::vatNumber($vat)) {
            throw new EInvoiceException(EInvoiceError::SellerVatInvalid, 'The tax number of the company is not fifteen digits that begin and end with 3.', ['actual' => $vat]);
        }

        $type = PartyIdentificationType::forSellerOrNull($values['seller_identification_type']);

        if ($type === null || $values['seller_identification_number'] === '') {
            throw new EInvoiceException(EInvoiceError::SellerIdentificationMissing, 'The company has no identification for the invoice.');
        }

        foreach (['seller_street' => 'street', 'seller_building_number' => 'building number', 'seller_district' => 'district', 'seller_city' => 'city', 'seller_postal_code' => 'postal code'] as $field => $label) {
            if ($values[$field] === '') {
                throw new EInvoiceException(EInvoiceError::SellerAddressIncomplete, 'The address of the company has no ' . $label . '.', ['field' => $label]);
            }
        }

        return new EInvoiceParty(
            $name,
            $vat,
            $type,
            $values['seller_identification_number'],
            new EInvoiceAddress($values['seller_street'], $values['seller_building_number'], $values['seller_district'], $values['seller_city'], $values['seller_postal_code'], 'SA', $values['seller_additional_street'], $values['seller_additional_number'], $values['seller_province']),
        );
    }

    /**
     * Whether the customer is to get a tax invoice between businesses: one with a tax number, a government
     * entity or a customer entered as a company.
     */
    public function defaultSubtype(?int $customerId): EInvoiceSubtype
    {
        $customer = $customerId === null ? null : $this->customer($customerId);

        if ($customer === null) {
            return EInvoiceSubtype::Simplified;
        }

        return trim((string) $customer->tax_id) !== '' || (int) $customer->is_government_entity === 1 || (string) $customer->customer_type === 'commercial'
            ? EInvoiceSubtype::Standard
            : EInvoiceSubtype::Simplified;
    }

    /**
     * @throws EInvoiceException
     */
    public function buyer(?int $customerId, EInvoiceSubtype $subtype): EInvoiceParty
    {
        $customer = $customerId === null ? null : $this->customer($customerId);

        if ($customer === null) {
            if ($subtype === EInvoiceSubtype::Standard) {
                throw new EInvoiceException(EInvoiceError::StandardNeedsCustomer, 'A tax invoice between businesses needs a customer.');
            }

            return new EInvoiceParty(UblInvoiceXml::CASH_BUYER);
        }

        $stored = $this->parties->find((int) $customerId);
        $name   = trim((string) $customer->company_name) !== '' ? trim((string) $customer->company_name) : trim($customer->first_name . ' ' . $customer->last_name);
        $vat    = trim((string) $customer->tax_id);

        if ($vat !== '' && ! EInvoiceRules::vatNumber($vat)) {
            throw new EInvoiceException(EInvoiceError::BuyerVatInvalid, 'The tax number of the customer is not fifteen digits that begin and end with 3.', ['name' => $name, 'actual' => $vat]);
        }

        $type = PartyIdentificationType::tryFrom($stored['identification_type']);

        if ($subtype === EInvoiceSubtype::Standard) {
            if ($name === '') {
                throw new EInvoiceException(EInvoiceError::BuyerNameMissing, 'The customer has no name.');
            }

            if ($vat === '' && ($type === null || $stored['identification_number'] === '')) {
                throw new EInvoiceException(EInvoiceError::BuyerIdentificationMissing, 'The customer has neither a tax number nor an identification.', ['name' => $name]);
            }
        }

        return new EInvoiceParty(
            $name === '' ? UblInvoiceXml::CASH_BUYER : $name,
            $vat,
            $vat === '' ? $type : null,
            $vat === '' ? $stored['identification_number'] : '',
            $this->buyerAddress($stored, $subtype, $name),
        );
    }

    /**
     * @param array<string, string> $stored
     *
     * @throws EInvoiceException
     */
    private function buyerAddress(array $stored, EInvoiceSubtype $subtype, string $name): ?EInvoiceAddress
    {
        $required = ['street' => 'street', 'city' => 'city'];

        if ($stored['country_code'] === 'SA') {
            $required += ['building_number' => 'building number', 'postal_code' => 'postal code', 'district' => 'district'];
        }

        $missing = array_filter($required, static fn (string $label, string $field): bool => $stored[$field] === '', ARRAY_FILTER_USE_BOTH);

        if ($missing !== []) {
            if ($subtype === EInvoiceSubtype::Standard) {
                throw new EInvoiceException(EInvoiceError::BuyerAddressIncomplete, 'The address of the customer has no ' . reset($missing) . '.', ['name' => $name, 'field' => reset($missing)]);
            }

            return null;
        }

        return new EInvoiceAddress($stored['street'], $stored['building_number'], $stored['district'], $stored['city'], $stored['postal_code'], $stored['country_code'], $stored['additional_street'], $stored['additional_number'], $stored['province']);
    }

    private function customer(int $customerId): ?object
    {
        return $this->db->table('customers c')
            ->select('c.company_name, c.tax_id, c.is_government_entity, c.customer_type, p.first_name, p.last_name')
            ->join('people p', 'p.person_id = c.person_id')
            ->where('c.person_id', $customerId)
            ->get()
            ->getRow();
    }
}
