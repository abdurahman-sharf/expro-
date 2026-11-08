<?php

namespace App\Libraries\Documents;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\Money;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\OSPOS;

/**
 * The vouchers and the receipts of the money that went in and out, read from the documents that hold
 * it: the payment voucher of an expense, the receipt voucher of a revenue, the receipt of a payment of
 * a customer and the receipt of a payment of a supplier. A document is found only by its own number, only
 * while it stands (not deleted, a payment that is completed, an amount that is money going out or in the
 * way the voucher says), and only by someone who may see it: a branch the viewer does not work in, or a
 * record that is not theirs when they see their own records only, is a document that is not there.
 * Nothing is written.
 */
final class VoucherDocuments
{
    private BaseConnection $db;
    private BranchGuard $guard;
    private RecordScope $scope;
    private DocumentNumbering $numbering;
    private AmountInWords $words;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $guard = null, ?RecordScope $scope = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->guard     = $guard ?? new BranchGuard($this->db);
        $this->scope     = $scope ?? new RecordScope($this->db);
        $this->numbering = new DocumentNumbering($this->db);
        $this->words     = new AmountInWords();
    }

    public function expense(int $expenseId, ?int $viewerId): ?VoucherDocument
    {
        $row = $this->db->table('expenses e')
            ->select("e.*, s.company_name AS supplier_name, c.category_name, t.name AS treasury_name, TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS employee_name", false)
            ->join('suppliers s', 's.person_id = e.supplier_id', 'left')
            ->join('expense_categories c', 'c.expense_category_id = e.expense_category_id', 'left')
            ->join('treasuries t', 't.treasury_id = e.treasury_id', 'left')
            ->join('people p', 'p.person_id = e.employee_id', 'left')
            ->where('e.expense_id', $expenseId)
            ->get()
            ->getRow();

        if ($row === null || (int) $row->deleted === 1 || bccomp((string) $row->amount, '0', 4) <= 0 || ! $this->visible($row->branch_id, $row->employee_id, $viewerId)) {
            return null;
        }

        $rows = [
            [lang('Documents.category'), (string) $row->category_name],
            [lang('Documents.method'), (string) $row->payment_type],
            [lang('Documents.treasury'), (string) $row->treasury_name],
            [lang('Documents.entered_by'), (string) $row->employee_name],
        ];

        return $this->build(
            VoucherDocument::PAYMENT_VOUCHER,
            lang('Documents.payment_voucher'),
            $this->numbering->label(DocumentType::Expense, 'expenses', $expenseId),
            to_date(strtotime((string) $row->date)),
            lang('Documents.pay_to'),
            (string) $row->supplier_name,
            (string) $row->amount,
            $this->currencyOf($row->currency_code),
            $this->withPurpose($rows, (string) $row->description),
            [lang('Documents.signature_receiver'), lang('Documents.signature_cashier')],
            (int) ($row->branch_id ?? 0),
        );
    }

    public function revenue(int $revenueId, ?int $viewerId): ?VoucherDocument
    {
        $row = (new RevenueVouchers($this->db))->find($revenueId);

        if ($row === null || (int) $row->deleted === 1 || bccomp((string) $row->amount, '0', 4) <= 0 || ! $this->visible($row->branch_id, $row->employee_id, $viewerId)) {
            return null;
        }

        $rows = [
            [lang('Documents.category'), (string) $row->category_name],
            [lang('Documents.method'), (string) $row->payment_type],
            [lang('Documents.treasury'), (string) $row->treasury_name],
            [lang('Documents.entered_by'), (string) $row->employee_name],
        ];

        if (bccomp((string) $row->tax_amount, '0', 4) > 0) {
            $rows[] = [lang('Documents.tax_included'), Money::format((string) $row->tax_amount, $this->decimalsOf($this->currencyOf($row->currency_code)))];
        }

        return $this->build(
            VoucherDocument::RECEIPT_VOUCHER,
            lang('Documents.receipt_voucher'),
            $this->numbering->label(DocumentType::Revenue, 'revenues', $revenueId),
            to_date(strtotime((string) $row->date)),
            lang('Documents.received_from_label'),
            '',
            (string) $row->amount,
            $this->currencyOf($row->currency_code),
            $this->withPurpose($rows, (string) $row->description),
            [lang('Documents.signature_payer'), lang('Documents.signature_cashier')],
            (int) $row->branch_id,
        );
    }

    public function customerPayment(int $paymentId, ?int $viewerId): ?VoucherDocument
    {
        $row = $this->db->table('sales_payments p')
            ->select("p.*, s.branch_id, s.invoice_number, s.sale_status, s.currency_code AS sale_currency, TRIM(CONCAT(COALESCE(cp.first_name, ''), ' ', COALESCE(cp.last_name, ''))) AS customer_name, c.company_name, t.name AS treasury_name, TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name", false)
            ->join('sales s', 's.sale_id = p.sale_id')
            ->join('customers c', 'c.person_id = s.customer_id', 'left')
            ->join('people cp', 'cp.person_id = s.customer_id', 'left')
            ->join('treasuries t', 't.treasury_id = p.treasury_id', 'left')
            ->join('people ep', 'ep.person_id = p.employee_id', 'left')
            ->where('p.payment_id', $paymentId)
            ->get()
            ->getRow();

        if ($row === null || (int) $row->sale_status !== COMPLETED || (int) $row->cash_adjustment !== 0 || ! PaymentStatus::fromStored($row->payment_status)->posts()) {
            return null;
        }

        $amount = bcsub((string) $row->payment_amount, (string) $row->cash_refund, 4);

        if (bccomp($amount, '0', 4) <= 0 || ! $this->visible($row->branch_id, $row->employee_id, $viewerId)) {
            return null;
        }

        $name = trim((string) $row->customer_name) !== '' ? trim((string) $row->customer_name) : (string) $row->company_name;
        $rows = [
            [lang('Documents.invoice'), (string) ($row->invoice_number ?: 'POS ' . (int) $row->sale_id)],
            [lang('Documents.method'), (string) $row->payment_type],
            [lang('Documents.treasury'), (string) $row->treasury_name],
        ];

        if ((string) $row->reference_code !== '') {
            $rows[] = [lang('Documents.reference'), (string) $row->reference_code];
        }

        $rows[] = [lang('Documents.received_by'), (string) $row->employee_name];

        return $this->build(
            VoucherDocument::CUSTOMER_RECEIPT,
            lang('Documents.customer_receipt'),
            $this->numbering->label(DocumentType::CustomerPayment, 'sales_payments', $paymentId),
            to_date(strtotime((string) $row->payment_time)),
            lang('Documents.received_from_label'),
            $name,
            $amount,
            $this->currencyOf($row->currency_code ?? $row->sale_currency),
            $rows,
            [lang('Documents.signature')],
            (int) ($row->branch_id ?? 0),
        );
    }

    public function supplierPayment(int $paymentId, ?int $viewerId): ?VoucherDocument
    {
        $row = $this->db->table('receivings_payments p')
            ->select("p.*, r.branch_id, r.supplier_id, s.company_name, TRIM(CONCAT(COALESCE(sp.first_name, ''), ' ', COALESCE(sp.last_name, ''))) AS supplier_person, t.name AS treasury_name, TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name", false)
            ->join('receivings r', 'r.receiving_id = p.receiving_id')
            ->join('suppliers s', 's.person_id = r.supplier_id', 'left')
            ->join('people sp', 'sp.person_id = r.supplier_id', 'left')
            ->join('treasuries t', 't.treasury_id = p.treasury_id', 'left')
            ->join('people ep', 'ep.person_id = p.employee_id', 'left')
            ->where('p.payment_id', $paymentId)
            ->get()
            ->getRow();

        if ($row === null || ! PaymentStatus::fromStored($row->payment_status)->posts() || bccomp((string) $row->payment_amount, '0', 4) <= 0) {
            return null;
        }

        if (! $this->visible($row->branch_id, $row->employee_id, $viewerId)) {
            return null;
        }

        $name = (string) ($row->company_name ?: $row->supplier_person);
        $rows = [
            [lang('Documents.purchase_invoice'), $this->numbering->label(DocumentType::Purchase, 'receivings', (int) $row->receiving_id)],
            [lang('Documents.method'), (string) $row->payment_type],
            [lang('Documents.treasury'), (string) $row->treasury_name],
        ];

        if ((string) $row->reference_code !== '') {
            $rows[] = [lang('Documents.reference'), (string) $row->reference_code];
        }

        $rows[] = [lang('Documents.paid_by'), (string) $row->employee_name];

        return $this->build(
            VoucherDocument::SUPPLIER_RECEIPT,
            lang('Documents.supplier_receipt'),
            $this->numbering->label(DocumentType::SupplierPayment, 'receivings_payments', $paymentId),
            to_date(strtotime((string) ($row->payment_date ?? $row->payment_time))),
            lang('Documents.pay_to'),
            $name,
            (string) $row->payment_amount,
            $this->currencyOf($row->currency_code),
            $rows,
            [lang('Documents.signature_receiver')],
            (int) ($row->branch_id ?? 0),
        );
    }

    private function visible(mixed $branchId, mixed $ownerId, ?int $viewerId): bool
    {
        if ($branchId !== null && ! $this->guard->canAccess((int) $branchId, $viewerId)) {
            return false;
        }

        return $this->scope->allows($viewerId, $ownerId);
    }

    /**
     * @param list<array{0: string, 1: string}> $rows
     *
     * @return list<array{0: string, 1: string}>
     */
    private function withPurpose(array $rows, string $purpose): array
    {
        return trim($purpose) === '' ? $rows : [[lang('Documents.purpose'), trim($purpose)], ...$rows];
    }

    private function currencyOf(mixed $documentCurrency): string
    {
        $code = is_string($documentCurrency) ? trim($documentCurrency) : '';

        if ($code !== '') {
            return $code;
        }

        $base = (new AccountingSettings($this->db))->baseCurrency();

        return $base !== '' ? $base : (string) (config(OSPOS::class)->settings['currency_code'] ?? '');
    }

    private function decimalsOf(string $currencyCode): int
    {
        $row = $this->db->table('accounting_currencies')->select('decimals')->where('currency_code', strtoupper($currencyCode))->get()->getRow();

        return $row === null ? (int) (config(OSPOS::class)->settings['currency_decimals'] ?? 2) : (int) $row->decimals;
    }

    /**
     * @param numeric-string                    $amount
     * @param list<array{0: string, 1: string}> $rows
     * @param list<string>                      $signatures
     */
    private function build(string $kind, string $title, string $number, string $date, string $partyLabel, string $partyName, string $amount, string $currencyCode, array $rows, array $signatures, int $branchId): VoucherDocument
    {
        $decimals = $this->decimalsOf($currencyCode);

        return new VoucherDocument(
            $kind,
            $title,
            $number,
            $date,
            $partyLabel,
            $partyName,
            Money::round($amount, $decimals),
            Money::format($amount, $decimals, $currencyCode),
            $currencyCode,
            array_values(array_filter($rows, static fn (array $row): bool => trim($row[1]) !== '')),
            $signatures,
            $this->spelled($amount, $currencyCode, $decimals),
            $branchId,
        );
    }

    private function spelled(string $amount, string $currencyCode, int $decimals): ?string
    {
        if (! AmountInWords::supports($currencyCode)) {
            return null;
        }

        try {
            return $this->words->spell($amount, $currencyCode, str_starts_with(current_language_code(), 'ar') ? 'ar' : 'en', $decimals);
        } catch (DocumentException) {
            return null;
        }
    }
}
