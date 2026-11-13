<?php

namespace App\Libraries\Accounting\Ai\Rules;

use App\Libraries\Accounting\Ai\AnomalyContext;
use App\Libraries\Accounting\Ai\AnomalyRule;
use App\Libraries\Accounting\Ai\EvidenceRows;
use App\Libraries\Accounting\Ai\Finding;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\JournalStatus;
use DateTimeImmutable;

/**
 * A manual journal posted on a weekend or at night in a branch whose manual journals are nearly all posted on
 * working days in the daytime. The usual pattern of the branch is the share of its manual journals of the
 * last year that were posted outside working hours, and a branch with too few of them has no pattern to depart
 * from.
 */
final class OffHoursRule implements AnomalyRule
{
    public const CODE  = 'AI-OFFHOURS';
    private const YEAR = 365;

    public function code(): string
    {
        return self::CODE;
    }

    public function detect(AnomalyContext $context): array
    {
        $hours = [
            'weekend' => $context->settings->weekendDays(),
            'start'   => $context->settings->int('night_start'),
            'end'     => $context->settings->int('night_end'),
        ];
        $history   = $context->settings->int('offhours_history');
        $threshold = $context->settings->int('offhours_share');
        $builder   = $context->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.series, j.branch_id, j.journal_date, j.posted_at, j.total_debit')
            ->whereIn('j.source_type', ['manual', ProposalService::SOURCE])
            ->where('j.status', JournalStatus::Posted->value)
            ->where('j.posted_at >=', $context->today->modify('-' . self::YEAR . ' days')->format('Y-m-d'));

        if ($context->branchIds !== null) {
            if ($context->branchIds === []) {
                return [];
            }

            $builder->whereIn('j.branch_id', $context->branchIds);
        }

        $rows   = $builder->orderBy('j.journal_id', 'ASC')->get()->getResult();
        $totals = [];
        $off    = [];

        foreach ($rows as $row) {
            $branch          = (int) $row->branch_id;
            $totals[$branch] = ($totals[$branch] ?? 0) + 1;
            $off[$branch]    = ($off[$branch] ?? 0) + ($this->isOffHours((string) $row->posted_at, $hours) ? 1 : 0);
        }

        $findings = [];

        foreach ($rows as $row) {
            $branch = (int) $row->branch_id;

            if (substr((string) $row->posted_at, 0, 10) < $context->since || ! $this->isOffHours((string) $row->posted_at, $hours)) {
                continue;
            }

            $others  = $totals[$branch] - 1;
            $offLeft = $off[$branch] - 1;

            if ($others < $history || $offLeft * 100 >= $threshold * $others) {
                continue;
            }

            $share = number_format($offLeft * 100 / $others, 1, '.', '');

            $findings[] = new Finding(
                rule: self::CODE,
                severity: Finding::LOW,
                key: (string) $row->journal_id,
                branchId: $branch,
                subjectType: 'journal',
                subjectId: (int) $row->journal_id,
                occurredOn: (string) $row->journal_date,
                templateArgs: ['number' => EvidenceRows::number((string) $row->series, (string) $row->number), 'posted_at' => (string) $row->posted_at, 'share' => $share, 'history' => $others],
                evidence: [
                    EvidenceRows::journal($row, ['posted_at' => (string) $row->posted_at, 'amount' => (string) $row->total_debit]),
                    ['type' => 'pattern', 'branch_id' => $branch, 'journals' => $others, 'off_hours' => $offLeft, 'share_percent' => $share],
                ],
            );
        }

        return $findings;
    }

    /**
     * @param array{weekend: list<int>, start: int, end: int} $hours
     */
    private function isOffHours(string $postedAt, array $hours): bool
    {
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $postedAt);

        if ($moment === false) {
            return false;
        }

        if (in_array((int) $moment->format('w'), $hours['weekend'], true)) {
            return true;
        }

        $hour  = (int) $moment->format('G');
        $start = $hours['start'];
        $end   = $hours['end'];

        return $start > $end ? ($hour >= $start || $hour < $end) : ($hour >= $start && $hour < $end);
    }
}
