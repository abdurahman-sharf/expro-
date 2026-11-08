<?php

namespace App\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\AnomalyContext;
use App\Libraries\Accounting\Ai\AnomalyRule;
use App\Libraries\Accounting\Ai\EvidenceRows;
use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\RevenuePostingBuilder;

/**
 * A journal that somebody keyed, dated well before the day it was posted, and posted in the last days before a
 * period that holds its date was closed: an entry slipped into a period just before it was locked.
 */
final class BackdatedRule implements AnomalyRule
{
    public const CODE = 'AI-BACKDATED';

    public function code(): string
    {
        return self::CODE;
    }

    public function detect(AnomalyContext $context): array
    {
        $window  = $context->settings->int('lock_window_days');
        $gap     = $context->settings->int('backdate_days');
        $builder = $context->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.series, j.branch_id, j.journal_date, j.posted_at, j.total_debit, j.source_type, c.closed_period_id, c.date_from, c.date_to, c.created_at AS closed_at, DATEDIFF(DATE(j.posted_at), j.journal_date) AS days_back, DATEDIFF(c.created_at, j.posted_at) AS days_before_lock', false)
            ->join('accounting_closed_periods c', 'c.company_id = j.company_id AND (c.branch_id IS NULL OR c.branch_id = j.branch_id) AND j.journal_date <= c.date_to')
            ->whereIn('j.source_type', ['manual', ProposalService::SOURCE, ExpensePostingBuilder::SOURCE, RevenuePostingBuilder::SOURCE])
            ->where('j.status', JournalStatus::Posted->value)
            ->where('j.posted_at <= c.created_at', null, false)
            ->where("j.posted_at >= DATE_SUB(c.created_at, INTERVAL {$window} DAY)", null, false)
            ->where("DATEDIFF(DATE(j.posted_at), j.journal_date) >= {$gap}", null, false)
            ->where('DATE(c.created_at) >= ' . $context->db->escape($context->since), null, false);

        if ($context->branchIds !== null) {
            if ($context->branchIds === []) {
                return [];
            }

            $builder->whereIn('j.branch_id', $context->branchIds);
        }

        $findings = [];

        foreach ($builder->orderBy('j.journal_id', 'ASC')->orderBy('c.closed_period_id', 'ASC')->get()->getResult() as $row) {
            $number = EvidenceRows::number((string) $row->series, (string) $row->number);

            $findings[] = new Finding(
                rule: self::CODE,
                severity: Finding::HIGH,
                key: $row->journal_id . '|' . $row->closed_period_id,
                branchId: (int) $row->branch_id,
                subjectType: 'journal',
                subjectId: (int) $row->journal_id,
                occurredOn: (string) $row->journal_date,
                templateArgs: ['number' => $number, 'days_back' => (int) $row->days_back, 'lock_until' => (string) $row->date_to],
                evidence: [
                    EvidenceRows::journal($row, ['posted_at' => (string) $row->posted_at, 'amount' => (string) $row->total_debit, 'days_back' => (int) $row->days_back]),
                    ['type' => 'closing', 'closed_period_id' => (int) $row->closed_period_id, 'date_from' => (string) $row->date_from, 'date_to' => (string) $row->date_to, 'closed_at' => (string) $row->closed_at, 'days_before_lock' => (int) $row->days_before_lock],
                ],
            );
        }

        return $findings;
    }
}
