<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\SupplierAdvances;
use CodeIgniter\Database\BaseConnection;

/**
 * The data facts that the reports of WP9 and WP13 asked the owner to look up by hand after the
 * migrations: no return of sale carries a refund invoice that was not issued with the new code, and no
 * opening balance of a supplier is dated after today. (The third fact, that no party's aging total moved,
 * is kept in the data of TIE-CUSTOMERS and TIE-SUPPLIERS and compared by accounting:readiness_compare.)
 * Each is read, and nothing is changed here.
 */
final class Wave3Checks extends CheckGroup
{
    public const REFUND_INVOICE_MIGRATION = 'RefundInvoice';
    private const LISTED                  = 50;

    public function __construct(BaseConnection $db, private MigrationChecks $migrations)
    {
        parent::__construct($db);
    }

    public function run(): array
    {
        return [
            $this->check('return_document', 'WP9-RETURN-DOCUMENT', 'No return of sale carries a refund invoice the migration did not leave', ['sales.return_document', 'sales.return_of_sale_id'], fn (): CheckResult => $this->returnDocument()),
            $this->check('supplier_opening_dates', 'WP13-SUPPLIER-OPENING', 'No opening balance of a supplier is dated after today', ['receivings.receiving_type', 'supplier_advances.kind', 'supplier_advances.advance_date'], fn (): CheckResult => $this->supplierOpening()),
        ];
    }

    private function returnDocument(): CheckResult
    {
        $appliedAt = $this->migrations->appliedAt(self::REFUND_INVOICE_MIGRATION);
        $migrated  = $appliedAt !== null && preg_match('/^\d{4}-\d{2}-\d{2} /', $appliedAt) === 1 ? $appliedAt : null;
        $rows      = $this->db->table('sales')
            ->select('sale_id, sale_type, return_of_sale_id, return_document, sale_time')
            ->where('return_document IS NOT NULL', null, false)
            ->orderBy('sale_id', 'ASC')
            ->get()
            ->getResult();

        $notReturns = array_values(array_filter($rows, static fn (object $row): bool => (int) $row->sale_type !== SALE_TYPE_RETURN));
        $older      = $migrated === null ? [] : array_values(array_filter($rows, static fn (object $row): bool => (string) $row->sale_time < $migrated));

        $data = [
            'with_document'              => count($rows),
            'not_returns'                => count($notReturns),
            'dated_before_the_migration' => count($older),
        ];

        if ($notReturns !== []) {
            return CheckResult::fail(
                count($notReturns) . ' sale(s) that are not returns carry the document of a return: the release writes it only on a return of sale.',
                array_slice(array_map(static fn (object $row): string => sprintf('sale #%d (type %d) carries the document %s', $row->sale_id, $row->sale_type, $row->return_document), $notReturns), 0, self::LISTED),
                $data,
            );
        }

        if ($rows !== []) {
            $findings = array_map(static fn (object $row): string => sprintf('sale #%d, return of sale #%s, saved %s', $row->sale_id, $row->return_of_sale_id ?? '-', $row->sale_time), $rows);

            if ($older !== []) {
                array_unshift($findings, count($older) . ' of them are dated before the migration ' . self::REFUND_INVOICE_MIGRATION . " ran on {$migrated}: backdated refund invoices, or marked by hand, because the migration leaves every existing return a credit note.");
            }

            return CheckResult::review(count($rows) . ' return(s) were issued as a refund invoice since the migration ran: expected only when the release has been in use, and 0 on a database that was migrated and not used yet.', array_slice($findings, 0, self::LISTED), $data);
        }

        return CheckResult::pass('No sale carries the document of a return: every return is still a credit note.', [], $data);
    }

    private function supplierOpening(): CheckResult
    {
        $today     = date('Y-m-d');
        $purchases = $this->db->table('receivings')
            ->select('receiving_id, supplier_id, receiving_time')
            ->where('receiving_type', SupplierAdvances::OPENING_TYPE)
            ->where('receiving_time >=', date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00')
            ->orderBy('receiving_id', 'ASC')
            ->get()
            ->getResult();

        $advances = $this->db->table('supplier_advances')
            ->select('advance_id, supplier_id, advance_date')
            ->where('kind', SupplierAdvances::OPENING)
            ->where('advance_date >', $today)
            ->orderBy('advance_id', 'ASC')
            ->get()
            ->getResult();

        $findings = [
            ...array_map(static fn (object $row): string => sprintf('purchase #%d: opening balance owed to supplier #%d dated %s', $row->receiving_id, $row->supplier_id, substr((string) $row->receiving_time, 0, 10)), $purchases),
            ...array_map(static fn (object $row): string => sprintf('advance #%d: opening balance owed by supplier #%d dated %s', $row->advance_id, $row->supplier_id, $row->advance_date), $advances),
        ];

        $data = ['owed_to_supplier' => count($purchases), 'owed_by_supplier' => count($advances)];

        if ($findings === []) {
            return CheckResult::pass('No opening balance of a supplier is dated after today.', [], $data);
        }

        return CheckResult::review(count($findings) . ' opening balance(s) of suppliers are dated after today: they count in the balance of the supplier and in what is open, and in no aging bucket until their day comes; review them with the accountant.', array_slice($findings, 0, self::LISTED), $data);
    }
}
