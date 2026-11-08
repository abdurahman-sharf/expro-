<?php

namespace App\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\AnomalyContext;
use App\Libraries\Accounting\Ai\AnomalyRule;
use App\Libraries\Accounting\Ai\EvidenceRows;
use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalStatus;

/**
 * A manual journal with a line on the account of a customer, a supplier or a stock location, which the
 * documents own: the account no longer ties to them until somebody has looked at the journal.
 */
final class ControlAccountRule implements AnomalyRule
{
    public const CODE = 'AI-CONTROL-MANUAL';

    public function code(): string
    {
        return self::CODE;
    }

    public function detect(AnomalyContext $context): array
    {
        $builder = $context->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.series, j.branch_id, j.journal_date, j.total_debit, l.line_no, l.debit, l.credit, a.code, a.name, a.entity_type')
            ->join('accounting_journal_lines l', 'l.journal_id = j.journal_id')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->whereIn('a.entity_type', [EntityAccountService::CUSTOMER, EntityAccountService::SUPPLIER, EntityAccountService::STOCK_LOCATION])
            ->whereIn('j.source_type', ['manual', ProposalService::SOURCE])
            ->where('j.status', JournalStatus::Posted->value)
            ->where('j.reverses_journal_id IS NULL', null, false)
            ->where('j.posted_at IS NOT NULL', null, false)
            ->where('DATE(j.posted_at) >= ' . $context->db->escape($context->since), null, false);

        if ($context->branchIds !== null) {
            if ($context->branchIds === []) {
                return [];
            }

            $builder->whereIn('j.branch_id', $context->branchIds);
        }

        $byJournal = [];

        foreach ($builder->orderBy('j.journal_id', 'ASC')->orderBy('l.line_no', 'ASC')->get()->getResult() as $row) {
            $byJournal[(int) $row->journal_id][] = $row;
        }

        $findings = [];

        foreach ($byJournal as $journalId => $rows) {
            $first  = $rows[0];
            $number = EvidenceRows::number((string) $first->series, (string) $first->number);

            $evidence = [EvidenceRows::journal($first, ['amount' => (string) $first->total_debit])];

            foreach ($rows as $row) {
                $evidence[] = [
                    'type'        => 'line',
                    'journal_id'  => $journalId,
                    'line_no'     => (int) $row->line_no,
                    'account'     => $row->code . ' ' . $row->name,
                    'entity_type' => (string) $row->entity_type,
                    'debit'       => (string) $row->debit,
                    'credit'      => (string) $row->credit,
                ];
            }

            $findings[] = new Finding(
                rule: self::CODE,
                severity: Finding::HIGH,
                key: (string) $journalId,
                branchId: (int) $first->branch_id,
                subjectType: 'journal',
                subjectId: $journalId,
                occurredOn: (string) $first->journal_date,
                templateArgs: ['number' => $number, 'accounts' => count($rows), 'account' => $first->code . ' ' . $first->name],
                evidence: $evidence,
            );
        }

        return $findings;
    }
}
