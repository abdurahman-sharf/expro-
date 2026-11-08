<?php

namespace App\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\AnomalyContext;
use App\Libraries\Accounting\Ai\AnomalyRule;
use App\Libraries\Accounting\Ai\EvidenceRows;
use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\JournalStatus;

/**
 * A manual journal whose total is at least the threshold and an exact multiple of the round unit: a figure
 * somebody chose rather than one a document gave.
 */
final class RoundNumberRule implements AnomalyRule
{
    public const CODE = 'AI-ROUND';

    public function code(): string
    {
        return self::CODE;
    }

    public function detect(AnomalyContext $context): array
    {
        $unit    = $context->settings->value('round_unit');
        $minimum = $context->settings->value('round_min');
        $builder = $context->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.series, j.branch_id, j.journal_date, j.posted_at, j.total_debit')
            ->whereIn('j.source_type', ['manual', ProposalService::SOURCE])
            ->where('j.status', JournalStatus::Posted->value)
            ->where('j.reverses_journal_id IS NULL', null, false)
            ->where('j.posted_at IS NOT NULL', null, false)
            ->where('DATE(j.posted_at) >= ' . $context->db->escape($context->since), null, false)
            ->where('j.total_debit >=', $minimum)
            ->where('MOD(j.total_debit, ' . $context->db->escape($unit) . ') = 0', null, false);

        if ($context->branchIds !== null) {
            if ($context->branchIds === []) {
                return [];
            }

            $builder->whereIn('j.branch_id', $context->branchIds);
        }

        $findings = [];

        foreach ($builder->orderBy('j.journal_id', 'ASC')->get()->getResult() as $row) {
            $number = EvidenceRows::number((string) $row->series, (string) $row->number);

            $findings[] = new Finding(
                rule: self::CODE,
                severity: Finding::LOW,
                key: (string) $row->journal_id,
                branchId: (int) $row->branch_id,
                subjectType: 'journal',
                subjectId: (int) $row->journal_id,
                occurredOn: (string) $row->journal_date,
                templateArgs: ['number' => $number, 'amount' => (string) $row->total_debit, 'unit' => $unit],
                evidence: [EvidenceRows::journal($row, ['amount' => (string) $row->total_debit, 'round_unit' => $unit])],
            );
        }

        return $findings;
    }
}
