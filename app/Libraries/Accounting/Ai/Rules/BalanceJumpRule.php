<?php

namespace App\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\AnomalyContext;
use App\Libraries\Accounting\Ai\AnomalyRule;
use App\Libraries\Accounting\Ai\EvidenceRows;
use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalStatus;

/**
 * A customer who owes much more than a few days ago: the balance of the customer in a branch rose, in the last
 * days, by at least the amount of the threshold and by at least the percent of the threshold of what it was
 * (a customer who owed nothing, or was in credit, counts from zero). The journals of the days are the evidence.
 */
final class BalanceJumpRule implements AnomalyRule
{
    public const CODE   = 'AI-BALANCE-JUMP';
    private const SCALE = 4;
    private const SHOWN = 10;

    public function code(): string
    {
        return self::CODE;
    }

    public function detect(AnomalyContext $context): array
    {
        $days    = $context->settings->int('jump_days');
        $minimum = $context->settings->value('jump_min');
        $percent = (string) $context->settings->int('jump_percent');
        $before  = $context->today->modify('-' . $days . ' days')->format('Y-m-d');
        $today   = $context->today->format('Y-m-d');
        $quoted  = $context->db->escape($before);
        $net     = 'l.debit - l.credit';

        $builder = $context->db->table('accounting_journal_lines l')
            ->select("a.account_id, a.entity_id AS customer_id, a.name, j.branch_id, SUM(CASE WHEN j.journal_date <= {$quoted} THEN {$net} ELSE 0 END) AS prior, SUM({$net}) AS balance, MAX(CASE WHEN j.journal_date > {$quoted} THEN j.journal_id END) AS last_journal_id", false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('a.entity_type', EntityAccountService::CUSTOMER)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->where('j.journal_date <=', $today);

        if ($context->branchIds !== null) {
            if ($context->branchIds === []) {
                return [];
            }

            $builder->whereIn('j.branch_id', $context->branchIds);
        }

        $rows = $builder
            ->groupBy('a.account_id, a.entity_id, a.name, j.branch_id')
            ->having("SUM({$net}) - SUM(CASE WHEN j.journal_date <= {$quoted} THEN {$net} ELSE 0 END) >= " . $context->db->escape($minimum), null, false)
            ->orderBy('a.account_id', 'ASC')
            ->orderBy('j.branch_id', 'ASC')
            ->get()
            ->getResult();

        $findings = [];

        foreach ($rows as $row) {
            $prior    = bccomp((string) $row->prior, '0', self::SCALE) > 0 ? bcadd((string) $row->prior, '0', self::SCALE) : '0.0000';
            $balance  = bcadd((string) $row->balance, '0', self::SCALE);
            $increase = bcsub($balance, $prior, self::SCALE);

            if (bccomp($increase, $minimum, self::SCALE) < 0 || ($prior !== '0.0000' && bccomp(bcmul($increase, '100', self::SCALE), bcmul($prior, $percent, self::SCALE), self::SCALE) < 0)) {
                continue;
            }

            $evidence = [[
                'type'        => 'balance',
                'customer_id' => (int) $row->customer_id,
                'customer'    => (string) $row->name,
                'prior'       => $prior,
                'balance'     => $balance,
                'increase'    => $increase,
                'since'       => $before,
            ]];

            foreach ($this->movements((int) $row->account_id, (int) $row->branch_id, $before, $today, $context) as $movement) {
                $evidence[] = EvidenceRows::journal($movement, ['source' => (string) $movement->source_type, 'debit' => (string) $movement->debit, 'credit' => (string) $movement->credit]);
            }

            $findings[] = new Finding(
                rule: self::CODE,
                severity: Finding::MEDIUM,
                key: (int) $row->customer_id . '|' . (int) $row->branch_id . '|' . (int) $row->last_journal_id,
                branchId: (int) $row->branch_id,
                subjectType: 'customer',
                subjectId: (int) $row->customer_id,
                occurredOn: $today,
                templateArgs: ['customer' => (string) $row->name, 'prior' => $prior, 'balance' => $balance, 'increase' => $increase, 'days' => $days],
                evidence: $evidence,
            );
        }

        return $findings;
    }

    /**
     * @return list<object>
     */
    private function movements(int $accountId, int $branchId, string $before, string $today, AnomalyContext $context): array
    {
        return $context->db->table('accounting_journal_lines l')
            ->select('j.journal_id, j.number, j.series, j.journal_date, j.source_type, SUM(l.debit) AS debit, SUM(l.credit) AS credit')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('l.account_id', $accountId)
            ->where('j.branch_id', $branchId)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->where('j.journal_date >', $before)
            ->where('j.journal_date <=', $today)
            ->groupBy('j.journal_id, j.number, j.series, j.journal_date, j.source_type')
            ->orderBy('SUM(l.debit)', 'DESC', false)
            ->orderBy('j.journal_id', 'ASC')
            ->limit(self::SHOWN)
            ->get()
            ->getResult();
    }
}
