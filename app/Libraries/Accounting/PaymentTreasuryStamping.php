<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A payment that names no treasury lands on the account its way of payment is routed to, and
 * lands there again, wherever the routing points by then, every time the payments of its document
 * are brought in line with the books. Stamping it with the treasury its journal went into makes
 * it stay where it was posted. Only the treasury of the payment changes: its journal, its amount
 * and every balance stay as they are, and a payment is stamped only when posting it again with the
 * stamp gives the account its journal already has.
 */
final class PaymentTreasuryStamping
{
    use RunsInTransaction;

    private const TABLES = [
        'sales_payments'     => SalePostingBuilder::SOURCE_PAYMENT,
        'receivings_payments' => ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT,
    ];

    private BaseConnection $db;
    private RoutingResolver $resolver;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->resolver = new RoutingResolver($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * The payments that have no treasury and a journal that went into one, as the stamp they
     * would take, and the ones with a journal into a treasury that cannot be stamped.
     *
     * @param list<PaymentType>|null $types Only the payments of these ways of payment.
     *
     * @return array{stamp: list<array{table: string, payment_id: int, treasury_id: int, payment_type: string}>, skipped: list<array{table: string, payment_id: int, reason: string}>}
     */
    public function plan(?array $types = null): array
    {
        $stamp   = [];
        $skipped = [];
        $account = [];

        foreach (self::TABLES as $table => $source) {
            foreach ($this->unstamped($table, $source) as $row) {
                $type = PaymentType::fromLabel((string) $row->payment_type);

                if ($type === null || ! $type->usesTreasury() || ($types !== null && ! in_array($type, $types, true))) {
                    continue;
                }

                $treasuryId = (int) $row->treasury_id;

                if ((int) $row->owners !== 1) {
                    $skipped[] = ['table' => $table, 'payment_id' => (int) $row->payment_id, 'reason' => 'more_than_one_treasury'];

                    continue;
                }

                if (! array_key_exists($treasuryId, $account)) {
                    $account[$treasuryId] = $this->accountOf($treasuryId);
                }

                if ($account[$treasuryId] !== (int) $row->account_id) {
                    $skipped[] = ['table' => $table, 'payment_id' => (int) $row->payment_id, 'reason' => 'account_differs'];

                    continue;
                }

                $stamp[] = ['table' => $table, 'payment_id' => (int) $row->payment_id, 'treasury_id' => $treasuryId, 'payment_type' => (string) $row->payment_type];
            }
        }

        return ['stamp' => $stamp, 'skipped' => $skipped];
    }

    /**
     * Stamps what plan() lists, in one transaction, and writes down how many. The payments that
     * have no stamp are locked before they are read, so an edit that is changing one of them
     * finishes first and one that starts after waits.
     *
     * @param list<PaymentType>|null $types Only the payments of these ways of payment.
     *
     * @return list<array{table: string, payment_id: int, treasury_id: int, payment_type: string}>
     *
     * @throws AccountingException|PostingException
     */
    public function stamp(?array $types = null, ?int $employeeId = null): array
    {
        return $this->transactional(function () use ($types, $employeeId): array {
            foreach (array_keys(self::TABLES) as $table) {
                $this->db->query('SELECT payment_id FROM ' . $this->db->prefixTable($table) . ' WHERE treasury_id IS NULL FOR UPDATE');
            }

            $stamped = $this->apply($this->plan($types)['stamp']);

            if ($stamped !== []) {
                $this->audit->write('payment', null, 'treasury_stamped', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, [
                    'sales'     => count(array_filter($stamped, static fn (array $row): bool => $row['table'] === 'sales_payments')),
                    'purchases' => count(array_filter($stamped, static fn (array $row): bool => $row['table'] === 'receivings_payments')),
                ]);
            }

            return $stamped;
        });
    }

    /**
     * Stamps the rows of a plan. A payment is stamped only while it still has no treasury and
     * still the way of payment the plan saw, so a plan that an edit has outdated stamps nothing.
     *
     * @param list<array{table: string, payment_id: int, treasury_id: int, payment_type: string}> $rows
     *
     * @return list<array{table: string, payment_id: int, treasury_id: int, payment_type: string}> The rows that were stamped.
     */
    public function apply(array $rows): array
    {
        $stamped = [];

        foreach ($rows as $row) {
            $this->db->table($row['table'])->where(['payment_id' => $row['payment_id'], 'treasury_id' => null, 'payment_type' => $row['payment_type']])->update(['treasury_id' => $row['treasury_id']]);

            if ($this->db->affectedRows() === 1) {
                $stamped[] = $row;
            }
        }

        return $stamped;
    }

    /**
     * The account posting would use for a payment stamped with the treasury, or null when the
     * routing of the treasuries cannot name one.
     */
    private function accountOf(int $treasuryId): ?int
    {
        try {
            return $this->resolver->accountFor(RoutingKey::Treasuries, $treasuryId);
        } catch (PostingException) {
            return null;
        }
    }

    /**
     * @return list<object{payment_id: string, payment_type: string, treasury_id: string, account_id: string, owners: string}>
     */
    private function unstamped(string $table, string $source): array
    {
        $builder = $this->db->table($table . ' p')
            ->select('p.payment_id, p.payment_type, MIN(t.treasury_id) AS treasury_id, MIN(l.account_id) AS account_id, COUNT(DISTINCT t.treasury_id) AS owners', false)
            ->join('accounting_journals j', 'j.source_type = ' . $this->db->escape($source) . ' AND j.source_id = p.payment_id AND j.status = ' . $this->db->escape(JournalStatus::Posted->value) . ' AND j.entry_type = ' . $this->db->escape(JournalEntryType::General->value) . ' AND j.company_id = ' . CompanyContext::id())
            ->join('accounting_journal_lines l', 'l.journal_id = j.journal_id')
            ->join('accounting_accounts a', 'a.account_id = l.account_id AND a.entity_type = ' . $this->db->escape(EntityAccountService::TREASURY))
            ->join('treasuries t', 't.treasury_id = a.entity_id AND t.company_id = a.company_id')
            ->where('p.treasury_id IS NULL', null, false);

        if ($table === 'sales_payments') {
            $builder->where('p.cash_adjustment', 0);
        }

        return $builder->groupBy('p.payment_id, p.payment_type')->orderBy('p.payment_id', 'ASC')->get()->getResult();
    }
}
