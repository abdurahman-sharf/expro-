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
use DateTimeImmutable;

/**
 * Two journals that somebody keyed, a manual journal, an expense or a revenue, with the same amounts on the same
 * accounts in the same branch, dated within a few days of each other: the same thing entered twice. A journal that
 * a sale or a purchase made is left out, because two sales of the same goods are not a mistake.
 */
final class DuplicateRule implements AnomalyRule
{
    public const CODE = 'AI-DUPLICATE';
    private const CHUNK = 500;

    public function code(): string
    {
        return self::CODE;
    }

    public function detect(AnomalyContext $context): array
    {
        $days    = $context->settings->int('duplicate_days');
        $from    = (new DateTimeImmutable($context->since))->modify('-' . $days . ' days')->format('Y-m-d');
        $builder = $context->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.series, j.branch_id, j.journal_date, j.total_debit, j.source_type')
            ->whereIn('j.source_type', ['manual', ProposalService::SOURCE, ExpensePostingBuilder::SOURCE, RevenuePostingBuilder::SOURCE])
            ->where('j.status', JournalStatus::Posted->value)
            ->where('j.reverses_journal_id IS NULL', null, false)
            ->where('j.journal_date >=', $from);

        if ($context->branchIds !== null) {
            if ($context->branchIds === []) {
                return [];
            }

            $builder->whereIn('j.branch_id', $context->branchIds);
        }

        $journals = $builder->orderBy('j.journal_date', 'ASC')->orderBy('j.journal_id', 'ASC')->get()->getResult();
        $shapes   = $this->shapes(array_map(static fn (object $row): int => (int) $row->journal_id, $journals), $context);
        $groups   = [];

        foreach ($journals as $row) {
            $groups[(int) $row->branch_id . '|' . ($shapes[(int) $row->journal_id] ?? '')][] = $row;
        }

        ksort($groups);

        $findings = [];

        foreach ($groups as $rows) {
            for ($index = 1; $index < count($rows); $index++) {
                $earlier = $rows[$index - 1];
                $later   = $rows[$index];
                $gap     = (int) (new DateTimeImmutable((string) $earlier->journal_date))->diff(new DateTimeImmutable((string) $later->journal_date))->days;

                if ($gap > $days || (string) $later->journal_date < $context->since) {
                    continue;
                }

                $findings[] = new Finding(
                    rule: self::CODE,
                    severity: Finding::MEDIUM,
                    key: $earlier->journal_id . '|' . $later->journal_id,
                    branchId: (int) $later->branch_id,
                    subjectType: 'journal',
                    subjectId: (int) $later->journal_id,
                    occurredOn: (string) $later->journal_date,
                    templateArgs: [
                        'number'  => EvidenceRows::number((string) $later->series, (string) $later->number),
                        'earlier' => EvidenceRows::number((string) $earlier->series, (string) $earlier->number),
                        'amount'  => (string) $later->total_debit,
                        'days'    => $gap,
                    ],
                    evidence: [
                        EvidenceRows::journal($earlier, ['source' => (string) $earlier->source_type, 'amount' => (string) $earlier->total_debit]),
                        EvidenceRows::journal($later, ['source' => (string) $later->source_type, 'amount' => (string) $later->total_debit, 'days_after' => $gap]),
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * What a journal is made of: each account and side with its amount, so that two journals with the same shape
     * have the same text.
     *
     * @param list<int> $journalIds
     *
     * @return array<int, string>
     */
    private function shapes(array $journalIds, AnomalyContext $context): array
    {
        $lines = [];

        foreach (array_chunk($journalIds, self::CHUNK) as $chunk) {
            foreach ($context->db->table('accounting_journal_lines')->select('journal_id, account_id, debit, credit')->whereIn('journal_id', $chunk)->orderBy('journal_id', 'ASC')->orderBy('account_id', 'ASC')->orderBy('line_no', 'ASC')->get()->getResult() as $line) {
                $debit                                = bccomp((string) $line->debit, '0', 4) > 0;
                $lines[(int) $line->journal_id][] = $line->account_id . ':' . ($debit ? 'D' : 'C') . ':' . bcadd($debit ? (string) $line->debit : (string) $line->credit, '0', 4);
            }
        }

        return array_map(static fn (array $parts): string => implode('|', $parts), $lines);
    }
}
