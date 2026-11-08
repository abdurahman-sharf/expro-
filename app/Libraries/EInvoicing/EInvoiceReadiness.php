<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingSettings;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What stands between the shop and a document the standard would accept, read and never written: the seller,
 * the way a line without tax is classified, the key and the certificate, and the customers that are
 * businesses and have neither a valid tax number nor an identification. A customer that is not a business
 * needs none for a simplified invoice, so the list puts the businesses first and says which they are.
 */
final class EInvoiceReadiness
{
    private BaseConnection $db;
    private EInvoiceSettings $settings;

    public function __construct(?BaseConnection $db = null, ?EInvoiceSettings $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->settings = $settings ?? new EInvoiceSettings($this->db);
    }

    /**
     * @return list<ReadinessCheck>
     */
    public function checks(): array
    {
        $values = $this->settings->all();
        $vat    = $this->settings->sellerVatNumber();
        $checks = [];

        $checks[] = new ReadinessCheck('accounting', (new AccountingSettings($this->db))->isEnabled() ? ReadinessCheck::PASS : ReadinessCheck::FAIL);
        $checks[] = new ReadinessCheck('enabled', $this->settings->isEnabled() ? ReadinessCheck::PASS : ReadinessCheck::WARN);
        $checks[] = new ReadinessCheck('seller_name', $this->settings->sellerName() !== '' ? ReadinessCheck::PASS : ReadinessCheck::FAIL);
        $checks[] = new ReadinessCheck('seller_vat', $vat === '' ? ReadinessCheck::FAIL : (EInvoiceRules::vatNumber($vat) ? ReadinessCheck::PASS : ReadinessCheck::FAIL), $vat);
        $checks[] = new ReadinessCheck('seller_identification', PartyIdentificationType::forSellerOrNull($values['seller_identification_type']) !== null && $values['seller_identification_number'] !== '' ? ReadinessCheck::PASS : ReadinessCheck::FAIL);
        $checks[] = new ReadinessCheck('seller_address', $this->sellerAddressComplete($values) ? ReadinessCheck::PASS : ReadinessCheck::FAIL);
        $checks[] = new ReadinessCheck('exemption', $values['exempt_category'] !== '' && $values['exempt_reason_code'] !== '' ? ReadinessCheck::PASS : ReadinessCheck::WARN);
        $checks[] = new ReadinessCheck('curve', StampKeys::curveAvailable() ? ReadinessCheck::PASS : ReadinessCheck::WARN);
        $checks[] = $this->signing($values);
        $checks[] = new ReadinessCheck('transport', ReadinessCheck::WARN, EInvoiceService::defaultTransport()->name());

        $businesses = $this->customersWithoutIdentification(1)['businesses'];

        $checks[] = new ReadinessCheck('customers', $businesses === 0 ? ReadinessCheck::PASS : ReadinessCheck::FAIL, (string) $businesses);

        return $checks;
    }

    /**
     * The customers that have neither a valid tax number nor an identification, those that are businesses
     * first.
     *
     * @return array{total: int, businesses: int, rows: list<array{person_id: int, name: string, business: bool, problem: string}>}
     */
    public function customersWithoutIdentification(int $limit = 50, int $offset = 0): array
    {
        $business = "(c.tax_id <> '' OR c.is_government_entity = 1 OR c.customer_type = 'commercial')";
        $missing  = "(c.tax_id NOT REGEXP '^3[0-9]{13}3\$' AND (e.person_id IS NULL OR e.identification_type IS NULL OR e.identification_number = ''))";

        $from = $this->db->table('customers c')
            ->join('people p', 'p.person_id = c.person_id')
            ->join('customer_einvoice_parties e', 'e.person_id = c.person_id', 'left')
            ->where('c.deleted', 0);

        $total      = (clone $from)->where($missing, null, false)->countAllResults();
        $businesses = (clone $from)->where($missing . ' AND ' . $business, null, false)->countAllResults();

        $rows = [];

        foreach ((clone $from)
            ->select("c.person_id, c.company_name, c.tax_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name, ({$business}) AS is_business", false)
            ->where($missing, null, false)
            ->orderBy('is_business', 'DESC')
            ->orderBy('c.person_id', 'ASC')
            ->limit($limit, $offset)
            ->get()
            ->getResult() as $row) {
            $rows[] = [
                'person_id' => (int) $row->person_id,
                'name'      => trim((string) $row->company_name) !== '' ? (string) $row->company_name : (string) $row->name,
                'business'  => (int) $row->is_business === 1,
                'problem'   => trim((string) $row->tax_id) !== '' ? 'vat_invalid' : 'no_identification',
            ];
        }

        return ['total' => $total, 'businesses' => $businesses, 'rows' => $rows];
    }

    /**
     * @param array<string, string> $values
     */
    private function sellerAddressComplete(array $values): bool
    {
        foreach (['seller_street', 'seller_building_number', 'seller_district', 'seller_city', 'seller_postal_code'] as $field) {
            if ($values[$field] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $values
     */
    private function signing(array $values): ReadinessCheck
    {
        if ($values['key_path'] === '' && $values['certificate_path'] === '') {
            return new ReadinessCheck('signing', ReadinessCheck::WARN, 'not_configured');
        }

        try {
            StampKeys::load($values['key_path'], $values['certificate_path']);
        } catch (EInvoiceException $failure) {
            return new ReadinessCheck('signing', ReadinessCheck::FAIL, $failure->error->value);
        }

        return new ReadinessCheck('signing', ReadinessCheck::PASS);
    }
}
