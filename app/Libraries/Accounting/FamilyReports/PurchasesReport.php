<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\SaleAmounts;

/**
 * What was bought in a period, by supplier, by the employee who entered it or by day, week, month or
 * year, as a summary or as the documents. The net purchases are the invoices less the returns and
 * debit notes, and every figure of purchases in the other reports is this one.
 */
final class PurchasesReport implements FamilyReport
{
    use BuildsReports;

    public const GROUPS   = ['supplier', 'user', 'day', 'week', 'month', 'year'];
    public const STATUSES = ['all', 'paid', 'partial', 'unpaid', 'returned'];

    public function __construct(private readonly PurchaseDocuments $documents = new PurchaseDocuments())
    {
    }

    public function build(ReportInput $input): ReportTable
    {
        $group  = $input->choice('group', self::GROUPS, 'supplier');
        $view   = $input->choice('view', ['summary', 'detail'], 'summary');
        $status = $input->choice('status', self::STATUSES, 'all');
        $unit   = PeriodUnit::tryFrom($group);
        $period = $input->period($unit?->defaultFrom($input->today));
        $filter = ['supplier_id' => $input->id('supplier_id'), 'employee_id' => $input->id('employee_id')];

        $all  = $this->documents->read($input->scope, $period, $input->viewerId, $filter);
        $docs = array_values(array_filter($all, fn (PurchaseDocument $document): bool => $this->wanted($document, $status)));

        $table = $view === 'detail' ? $this->detail($docs, $group, $unit, $period->from, $period->to) : $this->summary($docs, $group, $unit, $period->from, $period->to);

        $ties  = [new TieCheck($this->text('tie_net_purchases'), PurchaseDocuments::net($all), $this->documents->booked($input->scope, $period, $input->viewerId, $filter))];
        $notes = [];

        $this->noteIfAny($notes, PurchaseDocuments::unposted($all) > 0, 'note_unposted', PurchaseDocuments::unposted($all));
        $this->noteIfAny($notes, $status !== 'all', 'note_status_filter');
        $notes[] = $this->text('note_net_purchases');

        return new ReportTable($this->text('purchases_title'), $table['columns'], $table['blocks'], $table['grand'], $status === 'all' ? $ties : [], $notes);
    }

    private function wanted(PurchaseDocument $document, string $status): bool
    {
        return match ($status) {
            'returned' => $document->isReturn(),
            'paid'     => ! $document->isReturn() && ($document->state === PaymentState::Paid || $document->state === PaymentState::Excess),
            'partial'  => ! $document->isReturn() && $document->state === PaymentState::Partial,
            'unpaid'   => ! $document->isReturn() && $document->state === PaymentState::Unpaid,
            default    => true,
        };
    }

    /**
     * @param list<PurchaseDocument> $documents
     *
     * @return array{columns: list<ReportColumn>, blocks: list<ReportBlock>, grand: ReportRow}
     */
    private function summary(array $documents, string $group, ?PeriodUnit $unit, ?string $from, string $to): array
    {
        $groups  = $this->grouped($documents, $group, $unit, $from, $to);
        $columns = [$this->column('group', ColumnKind::Text, 'group_' . $group)];

        if ($group === 'supplier') {
            $columns[] = $this->column('tax_number');
        }

        array_push($columns, $this->column('invoices', ColumnKind::Amount), $this->column('returns', ColumnKind::Amount), $this->column('net', ColumnKind::Amount), $this->column('paid', ColumnKind::Amount), $this->column('open', ColumnKind::Amount), $this->column('count', ColumnKind::Plain));

        $rows = [];

        foreach ($groups as $label => $set) {
            $invoices = '0';
            $returns  = '0';
            $paid     = '0';
            $open     = '0';

            foreach ($set['documents'] as $document) {
                if ($document->isReturn()) {
                    $returns = bcadd($returns, $document->total, SaleAmounts::SCALE);

                    continue;
                }

                $invoices = bcadd($invoices, $document->total, SaleAmounts::SCALE);
                $paid     = bcadd($paid, $document->settled, SaleAmounts::SCALE);
                $open     = bcadd($open, $document->open(), SaleAmounts::SCALE);
            }

            $rows[] = new ReportRow([
                'group'      => (string) $set['label'],
                'tax_number' => $set['tax_number'],
                'invoices'   => $this->amount($invoices),
                'returns'    => $this->amount($returns),
                'net'        => $this->amount(bcsub($invoices, $returns, SaleAmounts::SCALE)),
                'paid'       => $this->amount($paid),
                'open'       => $this->amount($open),
                'count'      => (string) count($set['documents']),
            ]);
        }

        $keys = ['invoices', 'returns', 'net', 'paid', 'open', 'count'];

        return ['columns' => $columns, 'blocks' => [new ReportBlock(null, $rows)], 'grand' => $this->totalRow($rows, $keys, 'group', $this->text('total'))];
    }

    /**
     * @param list<PurchaseDocument> $documents
     *
     * @return array{columns: list<ReportColumn>, blocks: list<ReportBlock>, grand: ReportRow}
     */
    private function detail(array $documents, string $group, ?PeriodUnit $unit, ?string $from, string $to): array
    {
        $columns = [
            $this->column('number'), $this->column('date', ColumnKind::Date), $this->column('user'), $this->column('supplier'), $this->column('kind'),
            $this->column('total', ColumnKind::Amount), $this->column('paid', ColumnKind::Amount), $this->column('open', ColumnKind::Amount),
        ];

        $blocks = [];
        $all    = [];

        foreach ($this->grouped($documents, $group, $unit, $from, $to) as $set) {
            if ($set['documents'] === []) {
                continue;
            }

            $rows = [];

            foreach ($set['documents'] as $document) {
                $rows[] = new ReportRow([
                    'number'   => $document->number,
                    'date'     => $document->date,
                    'user'     => $document->employeeName,
                    'supplier' => $document->supplierName,
                    'kind'     => $this->text($document->isReturn() ? 'kind_return' : 'kind_invoice'),
                    'total'    => $document->signed($document->total),
                    'paid'     => $document->settled,
                    'open'     => $document->open(),
                ]);
            }

            $all    = [...$all, ...$rows];
            $blocks[] = new ReportBlock((string) $set['label'], $rows, $this->totalRow($rows, ['total', 'paid', 'open'], 'number', $this->text('total')));
        }

        return ['columns' => $columns, 'blocks' => $blocks, 'grand' => $this->totalRow($all, ['total', 'paid', 'open'], 'number', $this->text('grand_total'))];
    }

    /**
     * @param list<PurchaseDocument> $documents
     *
     * @return array<string, array{label: string, tax_number: string, documents: list<PurchaseDocument>}>
     */
    private function grouped(array $documents, string $group, ?PeriodUnit $unit, ?string $from, string $to): array
    {
        $sets = [];

        if ($unit !== null) {
            $first = $from ?? ($documents === [] ? $to : $documents[0]->date);

            foreach ($unit->buckets($first, $to) as $start) {
                $sets[$start] = ['label' => $unit->label($start), 'tax_number' => '', 'documents' => []];
            }
        }

        foreach ($documents as $document) {
            if ($unit !== null) {
                $key = $unit->start($document->date);
                $sets[$key]['label'] ??= $unit->label($key);
                $sets[$key]['tax_number'] ??= '';
            } elseif ($group === 'supplier') {
                $key = 's' . ($document->supplierId ?? 0);
                $sets[$key] ??= ['label' => $document->supplierName === '' ? $this->text('no_supplier') : $document->supplierName, 'tax_number' => $document->taxNumber, 'documents' => []];
            } else {
                $key = 'u' . ($document->employeeId ?? 0);
                $sets[$key] ??= ['label' => $document->employeeName === '' ? $this->text('no_user') : $document->employeeName, 'tax_number' => '', 'documents' => []];
            }

            $sets[$key]['documents'][] = $document;
        }

        if ($unit === null) {
            uasort($sets, static fn (array $first, array $second): int => mb_strtolower($first['label']) <=> mb_strtolower($second['label']));
        } else {
            ksort($sets);
        }

        return $sets;
    }
}
