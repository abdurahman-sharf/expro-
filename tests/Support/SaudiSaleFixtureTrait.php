<?php

namespace Tests\Support;

use App\Libraries\Tax\LineVat;
use App\Libraries\Tax\VatCalculator;
use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\VatLineResult;

/**
 * Selling under the Saudi rules in a test: the mode turned on with the electronic invoice switched on and the seller
 * complete, since every sale completed under the mode issues its document, and sales whose lines were classified, at
 * the standard rate with the tax added. A class that uses it also uses AccountingFixtureTrait,
 * SaleAccountingFixtureTrait and EInvoiceSaleFixtureTrait.
 */
trait SaudiSaleFixtureTrait
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $savedTaxProfile = null;

    protected function underTheSaudiRules(): void
    {
        $db = $this->accountingDb();

        $this->savedTaxProfile ??= $db->table('company_tax_profile')->select('ksa_mode, ksa_mode_effective_at')->get()->getRowArray();

        $db->table('company_tax_profile')->update(['ksa_mode' => 1, 'ksa_mode_effective_at' => '2020-01-01 00:00:00']);
        $this->configureEInvoicing();
    }

    /**
     * Turns the mode off again and removes what selling under it wrote; nothing happens when it was not turned on.
     */
    protected function leaveTheSaudiRules(): void
    {
        if ($this->savedTaxProfile === null) {
            return;
        }

        $db = $this->accountingDb();

        $db->table('company_tax_profile')->update($this->savedTaxProfile);
        $this->savedTaxProfile = null;

        $this->clearEInvoices();
        $this->restoreEInvoicingConfig();

        foreach (['sales_items_vat', 'sales_vat_totals', 'einvoice_failures', 'customer_advance_vat_returns', 'customer_advance_vat_uses', 'customer_advance_vat'] as $table) {
            if ($db->tableExists($db->prefixTable($table), false)) {
                $db->table($table)->truncate();
            }
        }
    }

    /**
     * The tax rows of one line classified at the standard rate of 15% with the tax added, and its category.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, VatCategoryTotal>}
     */
    protected function saudiTaxes(int $itemId, string $net, int $line = 0): array
    {
        $exact = bcmul($net, '0.15', 6);
        $vat   = VatCalculator::round($exact, 2);
        $gross = bcadd($net, $vat, 2);

        return [
            [[...$this->taxRow($itemId, $line, 'VAT', '15.0000', 1, $exact), 'vat' => new LineVat(null, 'S', '15.000', null, null, 'VAT'), 'vat_amounts' => new VatLineResult(bcadd($net, '0', 2), $vat, $gross, $exact, 'S|15.000|')]],
            ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, bcadd($net, '0', 2), $vat, false)],
        ];
    }

    /**
     * A sale of one service classified at the standard rate, its net given and the tax added on it.
     *
     * @param list<array<string, mixed>> $payments
     */
    protected function saudiSaleOf(string $net, ?int $customerId = -1, array $payments = [], int $type = SALE_TYPE_INVOICE): int
    {
        $itemId          = $this->item('0', false);
        [$taxes, $total] = $this->saudiTaxes($itemId, $net);

        return $this->save([$this->line($itemId, 0, '1', $net)], $taxes, $payments, $type, $customerId, categories: $total);
    }
}
