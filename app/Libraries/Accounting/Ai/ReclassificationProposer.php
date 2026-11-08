<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * A manual journal that put an amount on the account the books use for what has no account of its own (the
 * default of the expenses or of the revenues), with a text that earlier manual journals put on one account
 * again and again. The suggestion moves the amount to that account. The frequency table it rests on is the
 * evidence: for each account, how many times the same text went there.
 */
final class ReclassificationProposer implements Proposer
{
    public const KIND       = 'reclassify';
    public const NAME       = 'rules:reclassify';
    private const SUBJECTS  = 200;
    private const SOURCES   = ['manual', ProposalService::SOURCE];
    private const TABLE_TOP = 5;

    private BaseConnection $db;
    private AiSettings $settings;

    public function __construct(?BaseConnection $db = null, ?AiSettings $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->settings = $settings ?? new AiSettings($this->db);
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function propose(DateTimeImmutable $today, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $others = $this->defaultAccounts();

        if ($others === []) {
            return [];
        }

        $drafts = [];

        foreach ($this->subjectLines($today, $branchIds, array_keys($others)) as $subject) {
            $text = CounterpartText::normalize($subject->line_description !== null && trim((string) $subject->line_description) !== '' ? $subject->line_description : $subject->journal_description);

            if ($text === '') {
                continue;
            }

            $other = $others[(int) $subject->account_id];
            $table = $this->frequencyTable($subject, $text, $other['type'], array_keys($others));
            $draft = $this->draftOf($subject, $text, $other, $table, $today);

            if ($draft !== null) {
                $drafts[] = $draft;
            }
        }

        return $drafts;
    }

    /**
     * @return array<int, array{type: string, code: string, name: string}> The accounts of the two defaults, by id.
     */
    private function defaultAccounts(): array
    {
        $resolver = new RoutingResolver($this->db);
        $ids      = [];

        foreach ([RoutingKey::ExpensesDefault, RoutingKey::RevenuesDefault] as $key) {
            try {
                $id = $resolver->accountFor($key);
            } catch (PostingException) {
                continue;
            }

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $accounts = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, account_type, code, name')->whereIn('account_id', array_unique($ids))->orderBy('account_id', 'ASC')->get()->getResult() as $row) {
            $accounts[(int) $row->account_id] = ['type' => (string) $row->account_type, 'code' => (string) $row->code, 'name' => (string) $row->name];
        }

        return $accounts;
    }

    /**
     * @param list<int>|null $branchIds
     * @param list<int>      $accountIds
     *
     * @return list<object>
     */
    private function subjectLines(DateTimeImmutable $today, ?array $branchIds, array $accountIds): array
    {
        $since   = $today->modify('-' . $this->settings->int('lookback_days') . ' days')->format('Y-m-d');
        $builder = $this->db->table('accounting_journal_lines l')
            ->select('j.journal_id, j.number, j.series, j.journal_date, j.branch_id, j.description AS journal_description, l.line_no, l.account_id, l.description AS line_description, l.debit, l.credit, l.cost_center_id')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->whereIn('l.account_id', $accountIds)
            ->where('j.status', JournalStatus::Posted->value)
            ->whereIn('j.source_type', self::SOURCES)
            ->where('j.reverses_journal_id IS NULL', null, false)
            ->where('j.journal_date >=', $since)
            ->where('j.journal_date <=', $today->format('Y-m-d'));

        if ($branchIds !== null) {
            $builder->whereIn('j.branch_id', $branchIds);
        }

        return $builder->orderBy('j.journal_id', 'ASC')->orderBy('l.line_no', 'ASC')->limit(self::SUBJECTS)->get()->getResult();
    }

    /**
     * @param list<int> $otherIds
     *
     * @return list<array{account_id: int, code: string, name: string, uses: int, last_journal_id: int, last_date: string}> The accounts the same text went to before, the most used first.
     */
    private function frequencyTable(object $subject, string $text, string $type, array $otherIds): array
    {
        $isDebit = bccomp((string) $subject->debit, '0', 4) > 0;
        $builder = $this->db->table('accounting_journal_lines l')
            ->select('a.account_id, a.code, a.name, COUNT(*) AS uses, MAX(j.journal_id) AS last_journal_id, MAX(j.journal_date) AS last_date', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('j.status', JournalStatus::Posted->value)
            ->whereIn('j.source_type', self::SOURCES)
            ->where('j.reverses_journal_id IS NULL', null, false)
            ->where('j.journal_id <', (int) $subject->journal_id)
            ->where('a.account_type', $type)
            ->where('a.is_group', 0)
            ->where('a.active', 1)
            ->where('a.entity_type IS NULL', null, false)
            ->whereNotIn('a.account_id', $otherIds)
            ->where($isDebit ? 'l.debit >' : 'l.credit >', 0)
            ->where(CounterpartText::sql('l', 'j') . ' = ' . $this->db->escape($text), null, false)
            ->groupBy('a.account_id, a.code, a.name')
            ->orderBy('uses', 'DESC')
            ->orderBy('a.account_id', 'ASC');

        $table = [];

        foreach ($builder->get()->getResult() as $row) {
            $table[] = [
                'account_id'      => (int) $row->account_id,
                'code'            => (string) $row->code,
                'name'            => (string) $row->name,
                'uses'            => (int) $row->uses,
                'last_journal_id' => (int) $row->last_journal_id,
                'last_date'       => (string) $row->last_date,
            ];
        }

        return $table;
    }

    /**
     * @param array{type: string, code: string, name: string}                                                                           $other
     * @param list<array{account_id: int, code: string, name: string, uses: int, last_journal_id: int, last_date: string}> $table
     */
    private function draftOf(object $subject, string $text, array $other, array $table, DateTimeImmutable $today): ?ProposalDraft
    {
        if ($table === []) {
            return null;
        }

        $total = array_sum(array_column($table, 'uses'));
        $top   = $table[0];

        if ($top['uses'] < $this->settings->int('reclass_min_count') || $top['uses'] * 100 < $this->settings->int('reclass_min_share') * $total) {
            return null;
        }

        $isDebit = bccomp((string) $subject->debit, '0', 4) > 0;
        $amount  = $isDebit ? (string) $subject->debit : (string) $subject->credit;
        $label   = mb_substr((string) ($subject->line_description ?: $subject->journal_description), 0, 200);
        $center  = $subject->cost_center_id === null ? null : (int) $subject->cost_center_id;
        $share   = (int) round($top['uses'] * 100 / $total);
        $number  = $this->display((string) $subject->series, (string) $subject->number);

        $args = [
            'text'   => $label,
            'number' => $number,
            'from'   => $other['code'] . ' ' . $other['name'],
            'to'     => $top['code'] . ' ' . $top['name'],
            'amount' => $amount,
            'uses'   => $top['uses'],
            'total'  => $total,
            'share'  => $share,
        ];

        $lines = $isDebit
            ? [new JournalLineDraft($top['account_id'], debit: $amount, description: $label, costCenterId: $center), new JournalLineDraft((int) $subject->account_id, credit: $amount, description: $label, costCenterId: $center)]
            : [new JournalLineDraft((int) $subject->account_id, debit: $amount, description: $label, costCenterId: $center), new JournalLineDraft($top['account_id'], credit: $amount, description: $label, costCenterId: $center)];

        $evidence = [[
            'type'       => 'journal',
            'journal_id' => (int) $subject->journal_id,
            'number'     => $number,
            'date'       => (string) $subject->journal_date,
            'account'    => $other['code'],
            'amount'     => $amount,
            'text'       => $label,
        ]];

        foreach (array_slice($table, 0, self::TABLE_TOP) as $row) {
            $evidence[] = [
                'type'       => 'history',
                'journal_id' => $row['last_journal_id'],
                'account'    => $row['code'] . ' ' . $row['name'],
                'uses'       => $row['uses'],
                'share'      => (int) round($row['uses'] * 100 / $total),
                'last_date'  => $row['last_date'],
            ];
        }

        return new ProposalDraft(
            kind: self::KIND,
            proposer: self::NAME,
            dedupeKey: $subject->journal_id . '|' . $subject->line_no,
            date: $today->format('Y-m-d'),
            branchId: (int) $subject->branch_id,
            description: ProposalText::fill('journal', self::KIND, $args),
            lines: $lines,
            evidence: $evidence,
            templateArgs: $args,
            subjectJournals: [(int) $subject->journal_id],
        );
    }

    private function display(string $series, string $number): string
    {
        return $series === 'auto' ? 'A-' . $number : $number;
    }
}
