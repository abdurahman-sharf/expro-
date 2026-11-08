<?php

namespace Tests\Support;

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocuments;

/**
 * What a test needs to work with the four documents of the purchase cycle through the real service: the cycle switched on, a
 * clerk who holds every permission of the four families, documents made in one line, and the tables emptied again. A class that
 * uses it also uses AccountingFixtureTrait, EmployeeFixtureTrait, ItemFixtureTrait, SaleAccountingFixtureTrait and
 * PurchaseAccountingFixtureTrait.
 */
trait PurchaseCycleFixtureTrait
{
    protected int $clerk;
    protected int $goods;
    protected bool $cycleStarted = false;

    protected function startCycle(): void
    {
        $this->cycleStarted = true;
        $this->clearCycle();
        $this->setConfigValue('enable_purchase_cycle', '1');
        $this->setConfigValue('purchase_order_allow_excess', '0');

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $this->setConfigValue($kind->manualStatusSetting(), '0');
        }

        $this->clerk = $this->employeeWithGrants($this->cycleGrants());
        $this->goods = $this->item('4.00');
    }

    protected function stopCycle(): void
    {
        if (! $this->cycleStarted) {
            return;
        }

        $this->cycleStarted = false;
        $this->clearCycle();
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', [...array_column(PurchaseDocumentKind::cases(), 'value'), 'purchase_cycle_settings', 'document_numbering'])->delete();
        $this->setConfigValue('enable_purchase_cycle', '0');
        $this->setConfigValue('purchase_order_allow_excess', '0');

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $this->setConfigValue($kind->manualStatusSetting(), '0');
        }
    }

    protected function clearCycle(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['purchase_order_receiving_lines', 'purchase_order_receivings', 'purchase_order_lines', 'purchase_orders', 'supplier_quotation_lines', 'supplier_quotations', 'quotation_request_lines', 'quotation_requests', 'purchase_request_lines', 'purchase_requests', 'document_numbers', 'document_sequences'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return list<string>
     */
    protected function cycleGrants(): array
    {
        $grants = [];

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $grants[] = $kind->module();

            foreach ([PurchaseDocuments::ADD, PurchaseDocuments::EDIT, PurchaseDocuments::DELETE, PurchaseDocuments::VIEW_ALL, PurchaseDocuments::CONVERT] as $action) {
                $grants[] = $kind->permission($action);
            }
        }

        return $grants;
    }

    protected function documentsOf(PurchaseDocumentKind $kind): PurchaseDocuments
    {
        return new PurchaseDocuments($kind, $this->accountingDb());
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    protected function documentInput(array $override = []): array
    {
        return [
            'supplier_id'   => $this->supplierId,
            'location_id'   => self::LOCATION_ID,
            'document_date' => '2026-10-20',
            'due_date'      => '2026-10-30',
            'note'          => 'cycle document',
            'lines'         => [['item_id' => $this->goods, 'quantity' => '10', 'unit_price' => '4.00', 'description' => 'first line']],
            ...$override,
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    protected function makeDocument(PurchaseDocumentKind $kind, array $override = [], ?int $by = null): int
    {
        return $this->documentsOf($kind)->create($this->documentInput($override), $by ?? $this->clerk);
    }

    /**
     * What a document of the cycle must leave untouched until a receiving is saved.
     *
     * @return array<string, int>
     */
    protected function bookCounts(): array
    {
        $counts = [];

        foreach (['accounting_journals', 'accounting_journal_lines', 'inventory', 'item_quantities', 'receivings', 'receivings_items', 'receivings_payments', 'treasury_transfers'] as $table) {
            $counts[$table] = $this->countRows($table);
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    protected function auditActions(PurchaseDocumentKind $kind, int $documentId): array
    {
        return array_column($this->accountingDb()->table('accounting_audit_log')->select('action')->where(['entity_type' => $kind->value, 'entity_id' => $documentId])->orderBy('audit_id', 'ASC')->get()->getResultArray(), 'action');
    }
}
