<?php

namespace App\Libraries\Purchases;

use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\ReturnState;

/**
 * How the list of purchase invoices shows a document: the headers and the cells of the table, with the
 * options of the row, and the cells of the file. Every text that came from the database is escaped here, and
 * an amount is wrapped so that it keeps its direction in a right-to-left page.
 */
final class PurchaseInvoiceRows
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function headers(bool $withBranch): array
    {
        return [
            ['options' => '', 'sortable' => false, 'escape' => false],
            ['date'   => lang('Purchases.col_date'), 'escape' => false],
            ['number' => lang('Purchases.col_number'), 'escape' => false],
            ...($withBranch ? [['branch_code' => lang('Purchases.col_branch')]] : []),
            ['supplier_name'   => lang('Purchases.col_supplier')],
            ['purchase_status' => lang('Purchases.col_purchase_status'), 'sortable' => false, 'escape' => false],
            ['payment_status'  => lang('Purchases.col_payment_status'), 'sortable' => false, 'escape' => false],
            ['total'           => lang('Purchases.col_total'), 'escape' => false],
            ['settlement'      => lang('Purchases.col_settlement'), 'sortable' => false, 'escape' => false],
            ['employee_name'   => lang('Purchases.col_employee')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dataRow(PurchaseInvoiceRow $row, bool $canReceive): array
    {
        return [
            'receiving_id'    => $row->receivingId,
            'options'         => self::options($row, $canReceive),
            'date'            => esc(self::day($row->date)) . '<div class="small text-body-secondary"><bdi dir="ltr">' . esc(self::time($row->enteredAt)) . '</bdi></div>',
            'number'          => '<bdi dir="ltr">' . esc($row->number) . '</bdi>' . ($row->originNumber === null ? '' : '<div class="small text-body-secondary">' . esc(lang('Purchases.return_of', [$row->originNumber])) . '</div>'),
            'branch_code'     => $row->branchCode,
            'supplier_name'   => $row->supplierName,
            'purchase_status' => self::purchaseStatus($row),
            'payment_status'  => self::paymentStatus($row),
            'total'           => self::amount($row, $row->signedTotal(), true),
            'settlement'      => $row->isReturn() ? '' : '<div>' . esc(lang('Purchases.paid_label')) . ' ' . self::amount($row, $row->paid) . '</div><div>' . esc(lang('Purchases.due_label')) . ' ' . self::amount($row, $row->due) . '</div>',
            'employee_name'   => $row->employeeName,
        ];
    }

    /**
     * @return list<string>
     */
    public static function csvHeader(bool $withBranch): array
    {
        return [
            lang('Purchases.col_number'),
            lang('Purchases.col_date'),
            lang('Purchases.col_entered_at'),
            ...($withBranch ? [lang('Purchases.col_branch')] : []),
            lang('Purchases.col_supplier'),
            lang('Purchases.col_purchase_status'),
            lang('Purchases.col_payment_status'),
            lang('Purchases.col_currency'),
            lang('Purchases.col_total'),
            lang('Purchases.paid_label'),
            lang('Purchases.due_label'),
            lang('Purchases.col_reference'),
            lang('Purchases.col_employee'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function csvRow(PurchaseInvoiceRow $row, bool $withBranch): array
    {
        return [
            $row->number,
            $row->date,
            $row->enteredAt,
            ...($withBranch ? [$row->branchCode] : []),
            $row->supplierName,
            self::purchaseStatusText($row),
            self::paymentStatusText($row),
            $row->currencyCode ?? '',
            self::figure($row, $row->signedTotal()),
            $row->isReturn() ? '' : self::figure($row, $row->paid),
            $row->isReturn() ? '' : self::figure($row, $row->due),
            $row->reference,
            $row->employeeName,
        ];
    }

    public static function purchaseStatusText(PurchaseInvoiceRow $row): string
    {
        return lang($row->isReturn() ? 'Purchases.status_return' : 'Purchases.status_received');
    }

    public static function paymentStatusText(PurchaseInvoiceRow $row): string
    {
        if ($row->state === null) {
            return '';
        }

        return lang($row->state->languageKey()) . ($row->overdueDays > 0 ? ' - ' . lang('Purchases.payment_overdue_days', [$row->overdueDays]) : '');
    }

    /**
     * A number of the document as the file holds it: plain, to the decimals of its currency.
     */
    private static function figure(PurchaseInvoiceRow $row, string $amount): string
    {
        return number_format((float) $amount, $row->decimals, '.', '');
    }

    private static function amount(PurchaseInvoiceRow $row, string $amount, bool $strong = false): string
    {
        $text = $row->isForeign() ? number_format((float) $amount, $row->decimals, '.', ',') . ' ' . $row->currencyCode : to_currency_no_money($amount);
        $cell = '<bdi dir="ltr">' . esc($text) . '</bdi>';

        return $strong ? '<strong>' . $cell . '</strong>' . ($row->isForeign() ? '<div class="small text-body-secondary">' . esc(lang('Purchases.foreign_currency')) . '</div>' : '') : $cell;
    }

    private static function purchaseStatus(PurchaseInvoiceRow $row): string
    {
        if ($row->isReturn()) {
            return '<span class="badge text-bg-secondary">' . esc(lang('Purchases.status_return')) . '</span>';
        }

        $note = match ($row->returnState) {
            ReturnState::Partial => 'Purchases.status_partly_returned',
            ReturnState::Full    => 'Purchases.status_fully_returned',
            default              => null,
        };

        return '<span class="badge text-bg-success">' . esc(lang('Purchases.status_received')) . '</span>'
            . ($note === null ? '' : ' <span class="badge text-bg-warning">' . esc(lang($note)) . '</span>');
    }

    private static function paymentStatus(PurchaseInvoiceRow $row): string
    {
        if ($row->state === null) {
            return '';
        }

        $class = match ($row->state) {
            PaymentState::Paid, PaymentState::Excess => 'text-bg-success',
            PaymentState::Partial => 'text-bg-warning',
            PaymentState::Unpaid  => 'text-bg-warning',
        };

        return '<span class="badge ' . $class . '">' . esc(lang($row->state->languageKey())) . '</span>'
            . ($row->overdueDays > 0 ? ' <span class="badge text-bg-danger">' . esc(lang('Purchases.payment_overdue_days', [$row->overdueDays])) . '</span>' : '');
    }

    private static function options(PurchaseInvoiceRow $row, bool $canReceive): string
    {
        if (! $canReceive) {
            return '';
        }

        $id    = $row->receivingId;
        $items = [
            '<li><a class="dropdown-item" href="' . esc(site_url("receivings/receipt/{$id}"), 'attr') . '">' . esc(lang('Purchases.action_receipt')) . '</a></li>',
            '<li><a class="dropdown-item" href="' . esc(site_url("receivings/print/{$id}"), 'attr') . '" target="_blank" rel="noopener">' . esc(lang('Purchases.action_print')) . '</a></li>',
            '<li><a class="dropdown-item" href="' . esc(site_url("receivings/pdf/{$id}"), 'attr') . '">' . esc(lang('Purchases.action_pdf')) . '</a></li>',
            '<li><a class="dropdown-item modal-dlg" href="' . esc(site_url("receivings/edit/{$id}"), 'attr') . '" title="' . esc(lang('Purchases.action_edit'), 'attr') . '" data-btn-submit="' . esc(lang('Common.submit'), 'attr') . '">' . esc(lang('Purchases.action_edit')) . '</a></li>',
        ];

        if (! $row->isReturn() && $row->supplierId !== null && $row->returnState !== ReturnState::Full) {
            $items[] = '<li><button type="button" class="dropdown-item" data-purchase-action="debit_note" data-id="' . $id . '">' . esc(lang('Purchases.action_debit_note')) . '</button></li>';
        }

        $items[] = '<li><hr class="dropdown-divider"></li>';
        $items[] = '<li><button type="button" class="dropdown-item text-danger" data-purchase-action="delete" data-id="' . $id . '">' . esc(lang('Purchases.action_delete')) . '</button></li>';

        return '<div class="dropdown print_hide">'
            . '<button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle row-options" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="' . esc(lang('Purchases.row_options', [$row->number]), 'attr') . '">'
            . '<span class="bi bi-three-dots" aria-hidden="true"></span> ' . esc(lang('Purchases.col_options')) . '</button>'
            . '<ul class="dropdown-menu dropdown-menu-start">' . implode('', $items) . '</ul></div>';
    }

    private static function day(string $date): string
    {
        $time = strtotime($date);

        return $time === false ? '' : to_date($time);
    }

    private static function time(string $dateTime): string
    {
        $time = strtotime($dateTime);

        return $time === false ? '' : date('H:i', $time);
    }
}
