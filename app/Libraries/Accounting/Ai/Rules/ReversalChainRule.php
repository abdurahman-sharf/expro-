<?php

namespace App\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\AnomalyContext;
use App\Libraries\Accounting\Ai\AnomalyRule;
use App\Libraries\Accounting\Ai\EvidenceRows;
use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalStatus;

/**
 * A document whose journal was reversed again and again: the journals of one sale, purchase or other document
 * that were taken back at least as many times as the threshold, which is a document changed over and over after
 * it was posted. The journals of the chain are the evidence.
 */
final class ReversalChainRule implements AnomalyRule
{
    public const CODE   = 'AI-REVERSAL-CHAIN';
    private const SHOWN = 20;

    public function code(): string
    {
        return self::CODE;
    }

    public function detect(AnomalyContext $context): array
    {
        $minimum = $context->settings->int('reversal_chain');
        $builder = $context->db->table('accounting_journals r')
            ->select('o.source_type, o.source_id, MAX(o.branch_id) AS branch_id, COUNT(r.journal_id) AS reversals, MAX(r.journal_date) AS last_date', false)
            ->join('accounting_journals o', 'o.journal_id = r.reverses_journal_id')
            ->where('r.status', JournalStatus::Posted->value)
            ->where('o.source_id IS NOT NULL', null, false)
            ->where('o.source_type !=', 'reversal')
            ->where('o.entry_type !=', JournalEntryType::YearEnd->value);

        if ($context->branchIds !== null) {
            if ($context->branchIds === []) {
                return [];
            }

            $builder->whereIn('o.branch_id', $context->branchIds);
        }

        $rows = $builder
            ->groupBy('o.source_type, o.source_id')
            ->having('COUNT(r.journal_id) >= ' . $minimum, null, false)
            ->having('MAX(DATE(r.posted_at)) >= ' . $context->db->escape($context->since), null, false)
            ->orderBy('o.source_type', 'ASC')
            ->orderBy('o.source_id', 'ASC')
            ->get()
            ->getResult();

        $findings = [];

        foreach ($rows as $row) {
            $chain = $context->db->table('accounting_journals j')
                ->select('j.journal_id, j.number, j.series, j.journal_date, j.status, j.revision, j.reverses_journal_id, j.source_type')
                ->groupStart()
                ->groupStart()->where('j.source_type', $row->source_type)->where('j.source_id', (int) $row->source_id)->groupEnd()
                ->orWhere('j.reverses_journal_id IN (SELECT o.journal_id FROM ' . $context->db->prefixTable('accounting_journals') . ' o WHERE o.source_type = ' . $context->db->escape($row->source_type) . ' AND o.source_id = ' . (int) $row->source_id . ')', null, false)
                ->groupEnd()
                ->orderBy('j.journal_id', 'ASC')
                ->limit(self::SHOWN)
                ->get()
                ->getResult();

            $evidence = array_map(static fn (object $journal): array => EvidenceRows::journal($journal, ['source' => (string) $journal->source_type, 'revision' => (int) $journal->revision, 'status' => (string) $journal->status, 'reverses' => $journal->reverses_journal_id === null ? null : (int) $journal->reverses_journal_id]), $chain);

            $findings[] = new Finding(
                rule: self::CODE,
                severity: Finding::MEDIUM,
                key: $row->source_type . '|' . (int) $row->source_id . '|' . (int) $row->reversals,
                branchId: (int) $row->branch_id,
                subjectType: (string) $row->source_type,
                subjectId: (int) $row->source_id,
                occurredOn: (string) $row->last_date,
                templateArgs: ['source' => (string) $row->source_type, 'document' => (int) $row->source_id, 'reversals' => (int) $row->reversals],
                evidence: $evidence,
            );
        }

        return $findings;
    }
}
