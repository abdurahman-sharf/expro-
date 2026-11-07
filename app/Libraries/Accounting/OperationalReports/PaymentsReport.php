<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Database\BaseConnection;

/**
 * What customers paid, by customer, invoice employee, collector, method, treasury or period, or payment by
 * payment: the completed payments whose journals stand, a refund counting against what was paid. The total
 * is what the payments put on the accounts of the customers, and the report says whether it is what the same
 * journals put on the accounts the money went into, which is what the treasuries hold of them. The credit
 * applied to an invoice is not a payment and is not here, which is why the paid column of the revenue report,
 * which counts it, is larger.
 */
final class PaymentsReport
{
    use BuildsBlocks;

    private PaymentRows $payments;

    public function __construct(?BaseConnection $db = null, ?PaymentRows $payments = null)
    {
        $this->payments = $payments ?? new PaymentRows($db);
    }

    public function build(ReportScope $scope, PaymentGroup $group, bool $details, ?string $from, string $to, ?int $customerId = null, ?int $userId = null, ?int $collectorId = null, string $method = '', ?int $moneyAccountId = null, bool $allDates = false): ReportTable
    {
        $period = $group->period();
        $from ??= $allDates ? null : $period?->defaultFrom($to);

        $payments = $this->payments->read($scope, $from, $to, $customerId, $userId, $collectorId, $method, $moneyAccountId);
        $groups   = $this->grouped($payments, $group, $from, $to);
        $blocks   = [];
        $summary  = [];
        $all      = [];

        foreach ($groups as $key => $members) {
            $rows  = array_map(fn (PaymentRow $payment): array => $this->row($payment), $members);
            $all   = [...$all, ...$rows];
            $sums  = $this->addUp($rows, ['amount']);
            $label = $this->label($group, $key, $members);

            if ($details) {
                $blocks[] = new ReportBlock($this->heading($label['text'], $label['kind']), $rows, $sums);
            } else {
                $summary[] = [...$label['cells'], ...$sums];
            }
        }

        $total = $this->addUp($all, ['amount']);

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: $this->columns($group, $details, $scope),
            blocks: $details ? $blocks : [new ReportBlock([], $summary)],
            total: $total,
            ties: [new TieCheck('Accounting_operational_reports.tie_payments_treasury', $total['amount'], Amounts::sum(array_map(static fn (PaymentRow $payment): string => $payment->ledgerMoney, $payments)))],
            notes: ['Accounting_operational_reports.note_payments'],
            documents: count($payments),
        );
    }

    /**
     * @param list<PaymentRow> $payments
     *
     * @return array<string, list<PaymentRow>>
     */
    private function grouped(array $payments, PaymentGroup $group, ?string $from, string $to): array
    {
        $period = $group->period();
        $groups = [];

        if ($period !== null) {
            $start = $from ?? ($payments === [] ? null : $payments[0]->date);

            foreach ($start === null ? [] : $period->buckets($start, $to) as $bucket) {
                $groups[$bucket->key] = [];
            }

            foreach ($payments as $payment) {
                $groups[$period->bucketOf($payment->date)->key][] = $payment;
            }

            ksort($groups);

            return $groups;
        }

        foreach ($payments as $payment) {
            $groups[$this->keyOf($group, $payment)][] = $payment;
        }

        uasort($groups, fn (array $a, array $b): int => mb_strtolower($this->nameOf($group, $a[0])) <=> mb_strtolower($this->nameOf($group, $b[0])));

        return $groups;
    }

    private function keyOf(PaymentGroup $group, PaymentRow $payment): string
    {
        return match ($group) {
            PaymentGroup::Customer  => (string) ($payment->customerId ?? 0),
            PaymentGroup::User      => (string) ($payment->userId ?? 0),
            PaymentGroup::Collector => (string) ($payment->collectorId ?? 0),
            PaymentGroup::Method    => $payment->methodKey,
            default                 => (string) $payment->moneyAccountId,
        };
    }

    private function nameOf(PaymentGroup $group, PaymentRow $payment): string
    {
        return match ($group) {
            PaymentGroup::Customer  => $payment->customerName !== '' ? $payment->customerName : $this->walkIn(),
            PaymentGroup::User      => $payment->userName !== '' ? $payment->userName : $this->nobody(),
            PaymentGroup::Collector => $payment->collectorName !== '' ? $payment->collectorName : $this->nobody(),
            PaymentGroup::Method    => $payment->method,
            default                 => $payment->moneyAccount,
        };
    }

    /**
     * @param list<PaymentRow> $members
     *
     * @return array{text: string, kind: string, cells: array<string, string>}
     */
    private function label(PaymentGroup $group, string $key, array $members): array
    {
        $period = $group->period();

        if ($period !== null) {
            $bucket = $period->bucketOf($key);

            return match ($period) {
                PeriodGroup::Day   => ['text' => $bucket->key, 'kind' => 'date', 'cells' => ['period' => $bucket->key]],
                PeriodGroup::Week  => ['text' => $bucket->from . '|' . $bucket->to, 'kind' => 'range', 'cells' => ['period_from' => $bucket->from, 'period_to' => $bucket->to]],
                PeriodGroup::Month => ['text' => substr($bucket->key, 0, 7), 'kind' => 'text', 'cells' => ['period' => substr($bucket->key, 0, 7)]],
                PeriodGroup::Year  => ['text' => substr($bucket->key, 0, 4), 'kind' => 'text', 'cells' => ['period' => substr($bucket->key, 0, 4)]],
            };
        }

        $name = $this->nameOf($group, $members[0]);

        return ['text' => $name, 'kind' => 'text', 'cells' => [$group->value => $name]];
    }

    /**
     * @return array<string, string>
     */
    private function row(PaymentRow $payment): array
    {
        return [
            'payment'   => (string) $payment->paymentId,
            'invoice'   => $payment->invoiceNumber,
            'date'      => $payment->date,
            'customer'  => $payment->customerName !== '' ? $payment->customerName : $this->walkIn(),
            'user'      => $payment->userName !== '' ? $payment->userName : $this->nobody(),
            'collector' => $payment->collectorName !== '' ? $payment->collectorName : $this->nobody(),
            'method'    => $payment->method,
            'reference' => $payment->reference,
            'treasury'  => $payment->moneyAccount,
            'branch'    => $payment->branchCode,
            'amount'    => $payment->amount,
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(PaymentGroup $group, bool $details, ReportScope $scope): array
    {
        $amount = new ReportColumn('amount', 'Accounting_operational_reports.col_amount', ColumnKind::Amount);

        if (! $details) {
            return [match ($group) {
                PaymentGroup::Customer  => new ReportColumn('customer', 'Accounting_operational_reports.col_customer'),
                PaymentGroup::User      => new ReportColumn('user', 'Accounting_operational_reports.col_invoice_user'),
                PaymentGroup::Collector => new ReportColumn('collector', 'Accounting_operational_reports.col_collector'),
                PaymentGroup::Method    => new ReportColumn('method', 'Accounting_operational_reports.col_method'),
                PaymentGroup::Treasury  => new ReportColumn('treasury', 'Accounting_operational_reports.col_treasury'),
                PaymentGroup::Day       => new ReportColumn('period', 'Accounting_operational_reports.col_date', ColumnKind::Date),
                PaymentGroup::Week      => new ReportColumn('period_from', 'Accounting_operational_reports.col_week_start', ColumnKind::Date),
                PaymentGroup::Month     => new ReportColumn('period', 'Accounting_operational_reports.col_month'),
                PaymentGroup::Year      => new ReportColumn('period', 'Accounting_operational_reports.col_year'),
            }, ...($group === PaymentGroup::Week ? [new ReportColumn('period_to', 'Accounting_operational_reports.col_week_end', ColumnKind::Date)] : []), $amount];
        }

        return [
            new ReportColumn('payment', 'Accounting_operational_reports.col_payment'),
            new ReportColumn('invoice', 'Accounting_operational_reports.col_invoice'),
            new ReportColumn('date', 'Accounting_operational_reports.col_date', ColumnKind::Date),
            ...($group === PaymentGroup::Customer ? [] : [new ReportColumn('customer', 'Accounting_operational_reports.col_customer')]),
            ...($group === PaymentGroup::User ? [] : [new ReportColumn('user', 'Accounting_operational_reports.col_invoice_user')]),
            ...($group === PaymentGroup::Collector ? [] : [new ReportColumn('collector', 'Accounting_operational_reports.col_collector')]),
            ...($group === PaymentGroup::Method ? [] : [new ReportColumn('method', 'Accounting_operational_reports.col_method')]),
            new ReportColumn('reference', 'Accounting_operational_reports.col_reference'),
            ...($group === PaymentGroup::Treasury ? [] : [new ReportColumn('treasury', 'Accounting_operational_reports.col_treasury')]),
            ...($scope->choosable() ? [new ReportColumn('branch', 'Accounting_operational_reports.col_branch')] : []),
            $amount,
        ];
    }
}
