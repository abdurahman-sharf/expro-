<?php

namespace App\Libraries\Integrations;

use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\InvoiceFigures;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Looks at the books and the shelves for what each active rule asks about and keeps one open notification for
 * each subject: a subject that is new gets one, a subject that already has one gets none, so a second run
 * changes nothing, and a subject that no longer matches has its notification closed, so that it can be raised
 * again if it comes back. It reads what the screens read and writes nothing but notifications. No mail, no
 * message, no call to another server: a notification is a row an employee sees on the screen of notifications,
 * and sending one anywhere else is not built, because nothing may leave the shop without the owner's
 * separate permission.
 */
final class NotificationEvaluator
{
    private const PAGE = 200;

    private BaseConnection $db;
    private IntegrationOptions $options;
    private NotificationRules $rules;

    public function __construct(?BaseConnection $db = null, ?IntegrationOptions $options = null, ?NotificationRules $rules = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->options = $options ?? new IntegrationOptions($this->db);
        $this->rules   = $rules ?? new NotificationRules($this->db);
    }

    public function run(?string $today = null): NotificationRun
    {
        $today ??= date('Y-m-d');

        if (! $this->options->notificationsEnabled()) {
            return new NotificationRun(true, $today, []);
        }

        $lines = [];

        foreach ($this->rules->all() as $rule) {
            $subjects = $rule->active ? $this->subjects($rule, $today) : [];
            $lines[]  = ['rule' => $rule, ...$this->settle($rule, $subjects)];
        }

        return new NotificationRun(false, $today, $lines);
    }

    /**
     * @param list<array{key: string, branch: int|null, params: array<string, string>}> $subjects
     *
     * @return array{created: int, resolved: int, open: int}
     */
    private function settle(NotificationRule $rule, array $subjects): array
    {
        $created = 0;
        $now     = date('Y-m-d H:i:s');
        $current = [];

        foreach ($subjects as $subject) {
            $current[$subject['key']] = true;

            $this->db->table('notifications')->ignore(true)->insert([
                'company_id'  => CompanyContext::id(),
                'rule_id'     => $rule->id,
                'subject_key' => $subject['key'],
                'open_key'    => $subject['key'],
                'branch_id'   => $subject['branch'],
                'params'      => json_encode($subject['params'], JSON_UNESCAPED_UNICODE),
                'created_at'  => $now,
            ]);

            $created += $this->db->affectedRows() === 1 ? 1 : 0;
        }

        $resolved = 0;

        foreach ($this->db->table('notifications')->select('notification_id, subject_key')->where(['rule_id' => $rule->id, 'resolved_at' => null])->get()->getResult() as $open) {
            if (! isset($current[(string) $open->subject_key])) {
                $this->db->table('notifications')->where('notification_id', (int) $open->notification_id)->update(['resolved_at' => $now, 'open_key' => null]);
                $resolved++;
            }
        }

        return ['created' => $created, 'resolved' => $resolved, 'open' => count($current)];
    }

    /**
     * @return list<array{key: string, branch: int|null, params: array<string, string>}>
     */
    private function subjects(NotificationRule $rule, string $today): array
    {
        return match ($rule->kind) {
            NotificationRuleKind::InvoiceOverdue => $this->overdueInvoices($rule->threshold ?? 1, $today),
            NotificationRuleKind::LowStock       => $this->lowStock(),
            NotificationRuleKind::ChequeDue      => $this->chequesDue($rule->threshold ?? 0, $today),
        };
    }

    /**
     * What the customers owe and is late, read the way the receivables screens read it: an invoice, an opening
     * balance or a debit note of a customer who owes, still open and overdue by at least the days asked.
     *
     * @return list<array{key: string, branch: int|null, params: array<string, string>}>
     */
    private function overdueInvoices(int $days, string $today): array
    {
        $ledger   = new CustomerLedger($this->db);
        $subjects = [];
        $offset   = 0;

        do {
            $page = $ledger->customersWithBalance('', 'customer_name', 'asc', self::PAGE, $offset);

            foreach ($page['rows'] as $customer) {
                if (bccomp((string) $customer->balance, '0', 4) <= 0) {
                    continue;
                }

                $late  = array_values(array_filter($ledger->position((int) $customer->customer_id)->invoices, static fn (InvoiceFigures $invoice): bool => $invoice->daysOverdue($today) >= $days));
                $sales = $this->salesOf(array_map(static fn (InvoiceFigures $invoice): int => $invoice->saleId, array_filter($late, static fn (InvoiceFigures $invoice): bool => $invoice->saleId > 0 && ! $invoice->debitNote)));

                foreach ($late as $invoice) {
                    $sale = $sales[$invoice->saleId] ?? null;

                    $subjects[] = [
                        'key'    => 'invoice:' . $invoice->saleId,
                        'branch' => $sale === null ? null : (int) $sale->branch_id,
                        'params' => [
                            'customer' => (string) $customer->customer_name,
                            'document' => $sale === null ? '' : (trim((string) $sale->invoice_number) !== '' ? (string) $sale->invoice_number : '#' . $invoice->saleId),
                            'kind'     => $sale !== null ? 'invoice' : ($invoice->opening ? 'opening' : 'debit_note'),
                            'amount'   => $invoice->open(),
                            'days'     => (string) $invoice->daysOverdue($today),
                        ],
                    ];
                }
            }

            $offset += self::PAGE;
        } while ($offset < $page['total']);

        return $subjects;
    }

    /**
     * @param list<int> $saleIds
     *
     * @return array<int, object>
     */
    private function salesOf(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        $sales = [];

        foreach ($this->db->table('sales')->select('sale_id, invoice_number, branch_id')->whereIn('sale_id', $saleIds)->get()->getResult() as $sale) {
            $sales[(int) $sale->sale_id] = $sale;
        }

        return $sales;
    }

    /**
     * The items at or under their reorder level in a location, as the low stock report lists them.
     *
     * @return list<array{key: string, branch: int|null, params: array<string, string>}>
     */
    private function lowStock(): array
    {
        $rows = $this->db->table('items i')
            ->select('i.item_id, i.name, i.reorder_level, q.quantity, q.location_id, l.location_name, l.branch_id')
            ->join('item_quantities q', 'q.item_id = i.item_id')
            ->join('stock_locations l', 'l.location_id = q.location_id')
            ->where(['i.deleted' => 0, 'i.stock_type' => 0, 'l.deleted' => 0])
            ->where('q.quantity <= i.reorder_level', null, false)
            ->orderBy('i.item_id', 'ASC')
            ->orderBy('q.location_id', 'ASC')
            ->get()
            ->getResult();

        return array_map(static fn (object $row): array => [
            'key'    => 'stock:' . (int) $row->item_id . ':' . (int) $row->location_id,
            'branch' => $row->branch_id === null ? null : (int) $row->branch_id,
            'params' => ['item' => (string) $row->name, 'location' => (string) $row->location_name, 'quantity' => (string) (float) $row->quantity, 'reorder' => (string) (float) $row->reorder_level],
        ], $rows);
    }

    /**
     * The cheques that have not come to an end and fall due within the days asked, or are late.
     *
     * @return list<array{key: string, branch: int|null, params: array<string, string>}>
     */
    private function chequesDue(int $days, string $today): array
    {
        $until = date('Y-m-d', strtotime($today . " +{$days} days"));
        $rows  = $this->db->table('cheques c')
            ->select("c.cheque_id, c.branch_id, c.direction, c.cheque_number, c.amount, c.due_date, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS party", false)
            ->join('people p', 'p.person_id = c.party_id', 'left')
            ->where(['c.company_id' => CompanyContext::id(), 'c.deleted' => 0])
            ->whereIn('c.status', ['received', 'deposited', 'issued', 'delivered'])
            ->where('c.due_date <=', $until)
            ->orderBy('c.cheque_id', 'ASC')
            ->get()
            ->getResult();

        return array_map(static fn (object $row): array => [
            'key'    => 'cheque:' . (int) $row->cheque_id,
            'branch' => (int) $row->branch_id,
            'params' => ['number' => (string) $row->cheque_number, 'party' => (string) $row->party, 'amount' => bcadd((string) $row->amount, '0', 4), 'due' => (string) $row->due_date, 'direction' => (string) $row->direction],
        ], $rows);
    }
}
