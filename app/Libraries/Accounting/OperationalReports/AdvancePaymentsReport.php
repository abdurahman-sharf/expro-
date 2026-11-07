<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The payments of customers that belong to no invoice: each advance with what the journal credited the
 * customer, what has been spent of it on invoices, what was paid back in cash, and what is left. The amounts
 * are the ones the account of the customer carries, read through the position of the customer, and the report
 * says whether their total is what the advances were saved with. The tax of an advance is not a column: an
 * advance of ours carries no tax of its own.
 */
final class AdvancePaymentsReport
{
    use BuildsBlocks;

    public const STATES = ['open', 'spent'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function build(ReportScope $scope, ?string $from, string $to, ?int $customerId = null, ?int $createdBy = null, string $methodKey = '', string $state = ''): ReportTable
    {
        $advances = $scope->isEmpty() ? [] : $this->advances($scope, $from, $to, $customerId, $createdBy);
        $numbers  = (new DocumentNumbering($this->db))->numbers(DocumentType::CustomerPayment, 'customer_advances', array_map(static fn (object $advance): int => (int) $advance->advance_id, $advances));
        $names    = $this->names($advances);
        $credits  = [];
        $rows     = [];
        $stored   = '0';
        $position = new CustomerLedger($this->db);

        foreach ($advances as $advance) {
            $type = PaymentType::fromLabel((string) $advance->payment_type);

            if ($methodKey !== '' && ($type?->value ?? (string) $advance->payment_type) !== $methodKey) {
                continue;
            }

            $customer = (int) $advance->customer_id;
            $credits[$customer] ??= $this->creditsOf($position, $customer);
            $source   = $credits[$customer][(int) $advance->advance_id] ?? null;
            $amount   = $source?->amount ?? Amounts::ZERO;
            $applied  = $source?->applied ?? Amounts::ZERO;
            $refunded = $source?->refunded ?? Amounts::ZERO;
            $left     = $source?->available() ?? Amounts::ZERO;

            if (($state === 'open' && Amounts::isZero($left)) || ($state === 'spent' && ($source === null || ! Amounts::isZero($left)))) {
                continue;
            }

            $stored = Amounts::add($stored, (string) $advance->amount);
            $rows[] = [
                'number'    => $numbers[(int) $advance->advance_id] ?? (string) $advance->advance_id,
                'date'      => (string) $advance->advance_date,
                'customer'  => $names[$customer] ?? '',
                'user'      => $advance->created_by === null ? $this->nobody() : ($names[(int) $advance->created_by] ?? $this->nobody()),
                'method'    => $type === null ? (string) $advance->payment_type : lang($type->languageKey()),
                'branch'    => (string) $advance->branch_code,
                'amount'    => $amount,
                'applied'   => $applied,
                'refunded'  => $refunded,
                'unsettled' => $left,
            ];
        }

        $total = $this->addUp($rows, ['amount', 'applied', 'refunded', 'unsettled']);

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: [
                new ReportColumn('number', 'Accounting_operational_reports.col_advance'),
                new ReportColumn('date', 'Accounting_operational_reports.col_date', ColumnKind::Date),
                new ReportColumn('customer', 'Accounting_operational_reports.col_customer'),
                new ReportColumn('user', 'Accounting_operational_reports.col_created_by'),
                new ReportColumn('method', 'Accounting_operational_reports.col_method'),
                ...($scope->choosable() ? [new ReportColumn('branch', 'Accounting_operational_reports.col_branch')] : []),
                new ReportColumn('amount', 'Accounting_operational_reports.col_amount', ColumnKind::Amount),
                new ReportColumn('applied', 'Accounting_operational_reports.col_settled', ColumnKind::Amount),
                new ReportColumn('refunded', 'Accounting_operational_reports.col_refunded', ColumnKind::Amount),
                new ReportColumn('unsettled', 'Accounting_operational_reports.col_unsettled', ColumnKind::Amount),
            ],
            blocks: [new ReportBlock([], $rows)],
            total: $total,
            ties: [new TieCheck('Accounting_operational_reports.tie_advances', $total['amount'], $stored)],
            notes: ['Accounting_operational_reports.note_advances'],
            documents: count($rows),
        );
    }

    /**
     * @return list<object>
     */
    private function advances(ReportScope $scope, ?string $from, string $to, ?int $customerId, ?int $createdBy): array
    {
        $builder = $this->db->table('customer_advances a')
            ->select('a.advance_id, a.customer_id, a.amount, a.advance_date, a.payment_type, a.created_by, b.code AS branch_code')
            ->join('branches b', 'b.branch_id = a.branch_id')
            ->where('a.status', 'completed')
            ->where('a.advance_date <=', $to)
            ->whereIn('a.branch_id', $scope->ids)
            ->orderBy('a.advance_date', 'ASC')
            ->orderBy('a.advance_id', 'ASC');

        if ($from !== null) {
            $builder->where('a.advance_date >=', $from);
        }

        if ($customerId !== null) {
            $builder->where('a.customer_id', $customerId);
        }

        if ($createdBy !== null) {
            $builder->where('a.created_by', $createdBy);
        }

        return $builder->get()->getResult();
    }

    /**
     * @return array<int, CreditSource>
     */
    private function creditsOf(CustomerLedger $ledger, int $customerId): array
    {
        $credits = [];

        foreach ($ledger->position($customerId)->credits as $credit) {
            if ($credit->type === CreditSource::CUSTOMER_ADVANCE) {
                $credits[$credit->sourceId] = $credit;
            }
        }

        return $credits;
    }

    /**
     * @param list<object> $advances
     *
     * @return array<int, string>
     */
    private function names(array $advances): array
    {
        $ids = [];

        foreach ($advances as $advance) {
            foreach ([$advance->customer_id, $advance->created_by] as $id) {
                if ($id !== null) {
                    $ids[(int) $id] = (int) $id;
                }
            }
        }

        $names = [];

        foreach (array_chunk(array_values($ids), 400) as $chunk) {
            foreach ($this->db->table('people')->select("person_id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name", false)->whereIn('person_id', $chunk)->get()->getResult() as $person) {
                $names[(int) $person->person_id] = (string) $person->name;
            }
        }

        return $names;
    }
}
