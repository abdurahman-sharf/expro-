<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Takes the accounts of the bank accounts that were opened under the main treasury account to the
 * main account of the banks. Only the place of the account in the chart changes (its parent and
 * its code), so no journal line moves and no balance changes, and that is checked before the move
 * is kept: the lines of the accounts moved add up to what they did before, or nothing is moved.
 */
final class BankAccountPlacement
{
    use RunsInTransaction;

    /**
     * The tables and columns the placement reads, which a database that the migrations have not reached does not have.
     */
    public const REQUIRES = ['treasuries.bank_name', 'treasuries.account_number', 'accounting_accounts', 'accounting_routing', 'accounting_journal_lines'];

    private BaseConnection $db;
    private EntityAccountService $accounts;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->accounts = new EntityAccountService($this->db);
    }

    /**
     * Makes the main account of the banks usable for routing: the account coded 1202 becomes the
     * "banks" system account when it is a group nobody else holds that key for, and the routing row
     * of the banks points at it. A chart without such an account is left alone, and the routing
     * screen then asks for one.
     *
     * @return bool Whether the routing row points at an account.
     */
    public function openRouting(): bool
    {
        $opened = false;

        foreach ($this->db->table('companies')->select('company_id')->get()->getResult() as $company) {
            $companyId = (int) $company->company_id;

            $row = $this->db->table('accounting_routing')->select('account_id')->where(['company_id' => $companyId, 'routing_key' => RoutingKey::Banks->value])->get()->getRow();

            if ($row !== null && $row->account_id !== null) {
                $opened = true;

                continue;
            }

            $account = $this->db->table('accounting_accounts')
                ->select('account_id, system_key')
                ->where('company_id', $companyId)
                ->where('is_group', 1)
                ->where('active', 1)
                ->groupStart()->where('system_key', 'banks')->orWhere('code', '1202')->groupEnd()
                ->orderBy('system_key', 'DESC')
                ->limit(1)
                ->get()
                ->getRow();

            if ($account === null) {
                continue;
            }

            if ($account->system_key === null && $this->db->table('accounting_accounts')->where(['company_id' => $companyId, 'system_key' => 'banks'])->countAllResults() === 0) {
                $this->db->table('accounting_accounts')->where('account_id', $account->account_id)->update(['system_key' => 'banks', 'is_system' => 1]);
            }

            $this->db->query(
                'INSERT INTO `' . $this->db->prefixTable('accounting_routing') . '` (`company_id`, `routing_key`, `routing_type`, `account_id`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `account_id` = VALUES(`account_id`)',
                [$companyId, RoutingKey::Banks->value, RoutingType::Auto->value, (int) $account->account_id],
            );

            $opened = true;
        }

        return $opened;
    }

    /**
     * The bank accounts whose account is not yet under the main account of the banks.
     *
     * @return list<object{treasury_id: int, name: string, account_id: int, code: string}>
     */
    public function pending(): array
    {
        $parent = $this->parentOfBanks();

        if ($parent === null) {
            return [];
        }

        return $this->db->table('treasuries t')
            ->select('t.treasury_id, t.name, a.account_id, a.code')
            ->join('accounting_accounts a', "a.entity_type = 'treasury' AND a.entity_id = t.treasury_id AND a.company_id = t.company_id")
            ->where('t.company_id', CompanyContext::id())
            ->where('t.kind', TreasuryService::BANK)
            ->groupStart()->where('a.parent_id IS NULL', null, false)->orWhere('a.parent_id !=', $parent)->groupEnd()
            ->orderBy('t.treasury_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The bank accounts that lack the name of the bank or the number of the account.
     *
     * @return list<object>
     */
    public function incomplete(): array
    {
        return $this->db->table('treasuries')
            ->where('company_id', CompanyContext::id())
            ->where('kind', TreasuryService::BANK)
            ->groupStart()
            ->where('bank_name IS NULL', null, false)->orWhere('bank_name', '')
            ->orWhere('account_number IS NULL', null, false)->orWhere('account_number', '')
            ->groupEnd()
            ->orderBy('treasury_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @return list<array{treasury_id: int, from: string, to: string}>
     *
     * @throws AccountingException|RuntimeException
     */
    public function move(?int $employeeId = null): array
    {
        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        return $this->transactional(function () use ($pending, $employeeId): array {
            $ids    = array_map(static fn (object $row): int => (int) $row->account_id, $pending);
            $before = $this->fingerprint($ids);
            $moved  = [];

            foreach ($pending as $row) {
                $this->accounts->relocate(EntityAccountService::TREASURY, (int) $row->treasury_id, RoutingKey::Banks, $employeeId);

                $moved[] = [
                    'treasury_id' => (int) $row->treasury_id,
                    'from'        => (string) $row->code,
                    'to'          => (string) $this->db->table('accounting_accounts')->select('code')->where('account_id', $row->account_id)->get()->getRow()->code,
                ];
            }

            if ($this->fingerprint($ids) !== $before) {
                throw new RuntimeException('The lines of the bank accounts changed while their accounts were moved, so nothing was moved.');
            }

            return $moved;
        });
    }

    private function parentOfBanks(): ?int
    {
        $row = $this->db->table('accounting_routing')->select('account_id')->where(['company_id' => CompanyContext::id(), 'routing_key' => RoutingKey::Banks->value])->get()->getRow();

        return $row === null || $row->account_id === null ? null : (int) $row->account_id;
    }

    /**
     * @param list<int> $accountIds
     *
     * @return array<int, array{int, string, string}> Lines, debit and credit of each account.
     */
    private function fingerprint(array $accountIds): array
    {
        $print = [];

        foreach ($this->db->table('accounting_journal_lines')->select('account_id, COUNT(*) AS line_count, COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit', false)->whereIn('account_id', $accountIds)->groupBy('account_id')->get()->getResult() as $row) {
            $print[(int) $row->account_id] = [(int) $row->line_count, bcadd((string) $row->debit, '0', SaleAmounts::SCALE), bcadd((string) $row->credit, '0', SaleAmounts::SCALE)];
        }

        ksort($print);

        return $print;
    }
}
