<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Roles\RoleService;

/**
 * What a test of the electronic invoice needs besides sales: the settings of the shop (the company, its tax
 * number, its identification and national address), the layer switched on, and the removal of everything the
 * layer wrote when the test is over. A class that uses it also uses AccountingFixtureTrait,
 * SaleAccountingFixtureTrait and EInvoiceFixtureTrait.
 */
trait EInvoiceSaleFixtureTrait
{
    protected const SELLER_VAT = '399999999900003';

    /**
     * @var array<string, ?string>
     */
    private array $savedConfig = [];

    /**
     * @param array<string, string> $override
     */
    protected function configureEInvoicing(array $override = []): void
    {
        $values = [
            'company'                               => 'Maximum Speed Tech Supply LTD',
            'tax_id'                                => self::SELLER_VAT,
            EInvoiceSettings::ENABLED               => '1',
            'einvoice_seller_identification_type'   => 'CRN',
            'einvoice_seller_identification_number' => '1010010000',
            'einvoice_seller_street'                => 'Prince Sultan',
            'einvoice_seller_building_number'       => '1234',
            'einvoice_seller_district'              => 'Al-Murabba',
            'einvoice_seller_city'                  => 'Riyadh',
            'einvoice_seller_postal_code'           => '12345',
            'einvoice_exempt_category'              => '',
            'einvoice_exempt_reason_code'           => '',
            'einvoice_key_path'                     => '',
            'einvoice_certificate_path'             => '',
            ...$override,
        ];

        foreach ($values as $key => $value) {
            $row = $this->accountingDb()->table('app_config')->where('key', $key)->get()->getRow();

            if (! array_key_exists($key, $this->savedConfig)) {
                $this->savedConfig[$key] = $row === null ? null : (string) $row->value;
            }

            $this->setConfigValue($key, $value);
        }
    }

    protected function restoreEInvoicingConfig(): void
    {
        foreach ($this->savedConfig as $key => $value) {
            if ($value === null) {
                $this->accountingDb()->table('app_config')->where('key', $key)->delete();
            } else {
                $this->setConfigValue($key, $value);
            }
        }

        $this->savedConfig = [];
    }

    protected function clearEInvoices(): void
    {
        $db = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['einvoice_transport_log', 'einvoice_documents', 'customer_einvoice_parties'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('einvoice_counters')->update(['last_icv' => 0, 'last_hash' => '']);

        if ($db->tableExists($db->prefixTable('einvoice_unit_counters'), false)) {
            $db->table('einvoice_unit_counters')->update(['last_icv' => 0, 'last_hash' => '']);
        }
        $db->table('accounting_audit_log')->whereIn('entity_type', ['einvoice_document', 'einvoice_settings', 'customer_einvoice_party'])->delete();
    }

    /**
     * The invoice of the reference cycle for a customer (the fixture customer by default), 816.50 with 106.50 of tax.
     *
     * @param list<array<string, mixed>> $payments
     */
    protected function referenceSale(?int $customerId = null, array $payments = []): int
    {
        $cycle = $this->referenceInvoice();

        $this->nameItem($cycle['cream'], 'Cream');
        $this->nameItem($cycle['perfume'], 'Perfume');

        return $this->save($cycle['items'], $cycle['taxes'], $payments, SALE_TYPE_INVOICE, $customerId ?? -1);
    }

    /**
     * The same invoice with no customer, paid in full in cash.
     */
    protected function counterSale(): int
    {
        $cycle = $this->referenceInvoice();

        return $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Cash', '816.50')], SALE_TYPE_INVOICE, null);
    }

    /**
     * A return of the perfume of an invoice of the reference cycle, 253.00 with 33.00 of tax, paid back in cash.
     */
    protected function perfumeReturn(int $invoiceId, int $perfumeId): int
    {
        return $this->save([$this->line($perfumeId, 0, '-1', '220', '120.00')], [$this->tax($perfumeId, 0, '-33.00')], [$this->payment('Cash', '-253.00')], SALE_TYPE_RETURN, -1, true, $invoiceId);
    }

    protected function nameItem(int $itemId, string $name): void
    {
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['name' => $name]);
    }

    protected function makeBusinessCustomer(?int $customerId = null, string $vat = '311111111111113', string $company = 'Buyer Co'): int
    {
        $customerId ??= $this->customerId;

        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['tax_id' => $vat, 'company_name' => $company]);
        $this->accountingDb()->table('customer_einvoice_parties')->replace([
            'person_id'       => $customerId,
            'street'          => 'King Fahd Rd',
            'building_number' => '4321',
            'district'        => 'Olaya',
            'city'            => 'Riyadh',
            'postal_code'     => '11564',
            'country_code'    => 'SA',
        ]);

        return $customerId;
    }

    protected function seesOwnRecordsOnly(int $personId): int
    {
        (new RoleService())->setRecordScope($personId, RecordScope::OWN, null);

        return $personId;
    }

    protected function ownedBy(int $saleId, int $personId): int
    {
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['employee_id' => $personId]);

        return $saleId;
    }

    protected function debitNoteOf(int $createdBy, string $amount = '200', ?int $customerId = null, ?int $branchId = null): int
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $maker = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);
        $input = ['customer_id' => $customerId ?? $this->customerId, 'date' => $this->daysAgo(1), 'amount' => $amount, 'tax_percent' => '15', 'note' => 'Freight', ...($branchId === null ? [] : ['branch_id' => $branchId])];
        $id    = (new SalesDebitNotes())->create($input, $maker);

        $this->accountingDb()->table('sales_debit_notes')->where('debit_note_id', $id)->update(['created_by' => $createdBy]);

        return $id;
    }
}
