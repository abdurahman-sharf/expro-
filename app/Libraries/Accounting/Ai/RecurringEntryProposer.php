<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalStatus;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * An expense that was posted in each of the last months, for about the same amount, and has not been posted
 * this month although the day it usually comes on has passed. The suggestion repeats the journal of the last
 * month, dated today. The journals of the months it looked at are the evidence.
 */
final class RecurringEntryProposer implements Proposer
{
    public const KIND      = 'recurring';
    public const NAME      = 'rules:recurring';
    private const SOURCES  = ['manual', ProposalService::SOURCE, ExpensePostingBuilder::SOURCE];
    private const ROW_CAP  = 20000;
    private const AMOUNT_S = 4;

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

        $months  = $this->settings->int('recurring_months');
        $current = $today->modify('first day of this month')->setTime(0, 0);
        $first   = $current->modify('-' . $months . ' months');
        $groups  = $this->groups($first, $today, $branchIds);
        $drafts  = [];

        foreach ($groups as $group) {
            $draft = $this->draftOf($group, $current, $months, $today);

            if ($draft !== null) {
                $drafts[] = $draft;
            }
        }

        return $drafts;
    }

    /**
     * The expense lines of the window, by branch, account and text, each with its occurrences by month.
     *
     * @param list<int>|null $branchIds
     *
     * @return list<array{branch_id: int, account_id: int, text: string, occurrences: array<string, list<object>>}>
     */
    private function groups(DateTimeImmutable $first, DateTimeImmutable $today, ?array $branchIds): array
    {
        $builder = $this->db->table('accounting_journal_lines l')
            ->select('j.journal_id, j.number, j.series, j.branch_id, j.journal_date, j.source_type, j.description AS journal_description, l.line_no, l.account_id, l.description AS line_description, l.debit')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('a.account_type', 'expense')
            ->where('a.is_group', 0)
            ->where('a.entity_type IS NULL', null, false)
            ->where('l.debit >', 0)
            ->where('j.status', JournalStatus::Posted->value)
            ->where('j.entry_type', 'general')
            ->where('j.reverses_journal_id IS NULL', null, false)
            ->whereIn('j.source_type', self::SOURCES)
            ->where('j.journal_date >=', $first->format('Y-m-d'))
            ->where('j.journal_date <=', $today->format('Y-m-d'));

        if ($branchIds !== null) {
            $builder->whereIn('j.branch_id', $branchIds);
        }

        $groups = [];

        foreach ($builder->orderBy('j.journal_id', 'ASC')->orderBy('l.line_no', 'ASC')->limit(self::ROW_CAP)->get()->getResult() as $row) {
            $text = $this->textOf($row);
            $key  = (int) $row->branch_id . '|' . (int) $row->account_id . '|' . $text;
            $mon  = substr((string) $row->journal_date, 0, 7);

            $groups[$key] ??= ['branch_id' => (int) $row->branch_id, 'account_id' => (int) $row->account_id, 'text' => $text, 'occurrences' => []];
            $groups[$key]['occurrences'][$mon][] = $row;
        }

        ksort($groups);

        return array_values($groups);
    }

    /**
     * What names the expense: the text of the line, else the text of the journal for a manual one. An expense
     * document and a journal the agent made from one have no text, so they are one expense of their account, and
     * a journal the agent made from a manual one carries its text on every line.
     */
    private function textOf(object $row): string
    {
        $line = trim((string) $row->line_description);

        if ($row->source_type === ExpensePostingBuilder::SOURCE) {
            return '';
        }

        if ($row->source_type === ProposalService::SOURCE) {
            return CounterpartText::normalize($line);
        }

        return CounterpartText::normalize($line !== '' ? $line : $row->journal_description);
    }

    /**
     * @param array{branch_id: int, account_id: int, text: string, occurrences: array<string, list<object>>} $group
     */
    private function draftOf(array $group, DateTimeImmutable $current, int $months, DateTimeImmutable $today): ?ProposalDraft
    {
        if (isset($group['occurrences'][$current->format('Y-m')])) {
            return null;
        }

        $seen    = [];
        $lastDay = 0;

        for ($back = 1; $back <= $months; $back++) {
            $month = $current->modify('-' . $back . ' months')->format('Y-m');
            $rows  = $group['occurrences'][$month] ?? [];

            if ($rows === []) {
                return null;
            }

            $latest = $rows[array_key_last($rows)];
            $seen[] = $latest;

            foreach ($rows as $row) {
                $lastDay = max($lastDay, (int) substr((string) $row->journal_date, 8, 2));
            }
        }

        if ((int) $today->format('j') < min($lastDay, (int) $today->format('t'))) {
            return null;
        }

        $amounts = array_map(static fn (object $row): string => (string) $row->debit, $seen);
        $largest = array_reduce($amounts, static fn (string $carry, string $amount): string => bccomp($amount, $carry, self::AMOUNT_S) > 0 ? $amount : $carry, '0');
        $least   = array_reduce($amounts, static fn (string $carry, string $amount): string => bccomp($amount, $carry, self::AMOUNT_S) < 0 ? $amount : $carry, $largest);
        $spread  = bcsub($largest, $least, self::AMOUNT_S);

        if (bccomp(bcmul($spread, '100', self::AMOUNT_S), bcmul($largest, (string) $this->settings->int('recurring_tolerance'), self::AMOUNT_S), self::AMOUNT_S) > 0) {
            return null;
        }

        $template = $seen[0];
        $account  = $this->db->table('accounting_accounts')->select('code, name')->where('account_id', $group['account_id'])->get()->getRow();
        $label    = $group['text'] !== '' ? mb_substr((string) ($template->line_description ?: $template->journal_description), 0, 200) : ProposalText::fill('label', self::KIND, ['account' => $account->code . ' ' . $account->name]);
        $lines    = $this->templateLines((int) $template->journal_id, $group['text'] !== '' ? $label : null);

        if ($lines === null) {
            return null;
        }

        $number = $template->series === 'auto' ? 'A-' . $template->number : (string) $template->number;
        $amount = (string) $template->debit;

        $args = [
            'text'    => $label,
            'account' => $account->code . ' ' . $account->name,
            'amount'  => $amount,
            'months'  => $months,
            'month'   => $current->format('Y-m'),
            'number'  => $number,
        ];

        $evidence = [];

        foreach (array_reverse($seen) as $row) {
            $evidence[] = [
                'type'       => 'journal',
                'journal_id' => (int) $row->journal_id,
                'number'     => $row->series === 'auto' ? 'A-' . $row->number : (string) $row->number,
                'date'       => (string) $row->journal_date,
                'account'    => $account->code,
                'amount'     => (string) $row->debit,
            ];
        }

        $evidence[] = ['type' => 'absent', 'month' => $current->format('Y-m'), 'account' => $account->code, 'found' => 0];

        return new ProposalDraft(
            kind: self::KIND,
            proposer: self::NAME,
            dedupeKey: $group['branch_id'] . '|' . $group['account_id'] . '|' . hash('sha256', $group['text']) . '|' . $current->format('Y-m'),
            date: $today->format('Y-m-d'),
            branchId: $group['branch_id'],
            description: ProposalText::fill('journal', self::KIND, $args),
            lines: $lines,
            evidence: $evidence,
            templateArgs: $args,
            subjectJournals: [(int) $template->journal_id],
        );
    }

    /**
     * The lines of the journal of the last month, to be repeated, with the text of the expense on the lines that
     * have none, or null when it is not one the agent may repeat: it has more than one expense line, or a line on
     * the account of a customer, a supplier or a stock location.
     *
     * @return list<JournalLineDraft>|null
     */
    private function templateLines(int $journalId, ?string $label): ?array
    {
        $rows = $this->db->table('accounting_journal_lines l')
            ->select('l.account_id, l.debit, l.credit, l.description, l.cost_center_id, a.account_type, a.entity_type')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('l.journal_id', $journalId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();

        $expenseLines = 0;

        foreach ($rows as $row) {
            if (in_array($row->entity_type, [EntityAccountService::CUSTOMER, EntityAccountService::SUPPLIER, EntityAccountService::STOCK_LOCATION], true)) {
                return null;
            }

            if ($row->account_type === 'expense' && bccomp((string) $row->debit, '0', self::AMOUNT_S) > 0) {
                $expenseLines++;
            }
        }

        if ($expenseLines !== 1 || count($rows) < 2) {
            return null;
        }

        return array_map(static fn (object $row): JournalLineDraft => new JournalLineDraft(
            accountId: (int) $row->account_id,
            debit: (string) $row->debit,
            credit: (string) $row->credit,
            description: $label !== null && trim((string) $row->description) === '' ? $label : $row->description,
            costCenterId: $row->cost_center_id === null ? null : (int) $row->cost_center_id,
        ), $rows);
    }
}
