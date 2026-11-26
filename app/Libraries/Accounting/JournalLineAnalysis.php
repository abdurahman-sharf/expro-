<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The analysis of journal lines: where each line is charged, its tags and the tax it notes. It
 * cleans what a person typed, writes it beside the lines, reads it back, and copies it to the
 * reversal of a journal. It never changes a debit, a credit or an account, so no balance moves
 * with it. The cost center of a line is the representation older lines have: a line with no row
 * here is charged in full to that center, and it is never rewritten unless a person edits the
 * charge of that line.
 */
final class JournalLineAnalysis
{
    public const TAG_MAX  = 30;
    public const TAGS_MAX = 10;

    private BaseConnection $db;
    private CostCenterService $costCenters;
    private JournalSettings $settings;
    private ?int $decimals = null;

    public function __construct(?BaseConnection $db = null, ?CostCenterService $costCenters = null, ?JournalSettings $settings = null)
    {
        $this->db          = $db ?? Database::connect();
        $this->costCenters = $costCenters ?? new CostCenterService($this->db);
        $this->settings    = $settings ?? new JournalSettings($this->db);
    }

    /**
     * What a person asked for on a line, cleaned and checked. Nothing is written.
     *
     * @param array<string, mixed> $line     The input of the line: cost_center_id, distribution rows, tags, tax_code, tax_percent and tax_included.
     * @param numeric-string       $amount   The debit or the credit of the line.
     * @param bool                 $complete Whether a distribution must be whole, as it must be to post.
     * @param bool                 $stored   Whether the line is read back from a draft, whose tags and tax note were entered
     *                                       while their settings were on and are kept when the settings have been turned off since.
     *
     * @throws AccountingException
     */
    public function clean(array $line, int $lineNumber, string $amount, bool $complete, bool $stored = false): LineAnalysis
    {
        $rows = array_values(array_filter(
            is_array($line['distribution'] ?? null) ? $line['distribution'] : [],
            static fn (mixed $row): bool => is_array($row) && implode('', array_map(static fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : 'x', $row)) !== '',
        ));

        $single = isset($line['cost_center_id']) && is_scalar($line['cost_center_id']) && (int) $line['cost_center_id'] > 0 ? (int) $line['cost_center_id'] : null;

        if ($rows === [] && $single !== null) {
            $this->costCenters->requireActive([$single], $lineNumber);
        }

        $charge = $rows === [] ? new LineAnalysis($single) : LineAnalysis::charged($this->costCenters->distribute($rows, $amount, $lineNumber, $complete));

        return new LineAnalysis($charge->costCenterId, $charge->distribution, $this->tagsOf($line['tags'] ?? null, $lineNumber, $stored), $this->taxOf($line, $lineNumber, $amount, $stored));
    }

    /**
     * Writes the rows of the lines of a journal that has just been written, by the number of the line.
     *
     * @param array<int, LineAnalysis> $byLineNumber
     */
    public function store(int $journalId, array $byLineNumber): void
    {
        $byLineNumber = array_filter($byLineNumber, static fn (LineAnalysis $analysis): bool => $analysis->distribution !== [] || $analysis->tags !== [] || $analysis->tax !== null);

        if ($byLineNumber === []) {
            return;
        }

        $ids = $this->lineIds($journalId);

        foreach ($byLineNumber as $lineNumber => $analysis) {
            $this->write($ids[$lineNumber], $analysis);
        }
    }

    /**
     * Changes what a line carries beyond its amount: the cost center of the line, its distribution, its
     * tags and its tax note, replacing what was there.
     */
    public function apply(int $lineId, LineAnalysis $analysis): void
    {
        (new PostingService($this->db))->chargeLine($lineId, $analysis->costCenterId);
        $this->db->table('accounting_journal_line_distributions')->where('line_id', $lineId)->delete();
        $this->db->table('accounting_journal_line_meta')->where('line_id', $lineId)->delete();
        $this->write($lineId, $analysis);
    }

    /**
     * What the lines carry, by the id of the line. A line with no row of its own is charged to its
     * cost center in full, or to none.
     *
     * @param list<object> $lines Lines with their line_id and cost_center_id.
     *
     * @return array<int, LineAnalysis>
     */
    public function load(array $lines): array
    {
        $ids = array_map(static fn (object $line): int => (int) $line->line_id, $lines);

        if ($ids === []) {
            return [];
        }

        $shares = [];
        $meta   = [];

        foreach ($this->db->table('accounting_journal_line_distributions')->select('line_id, cost_center_id, percent, amount')->whereIn('line_id', $ids)->orderBy('distribution_id', 'ASC')->get()->getResult() as $row) {
            $shares[(int) $row->line_id][] = ['cost_center_id' => (int) $row->cost_center_id, 'percent' => bcadd($row->percent, '0', 4), 'amount' => bcadd($row->amount, '0', 4)];
        }

        foreach ($this->db->table('accounting_journal_line_meta')->whereIn('line_id', $ids)->get()->getResult() as $row) {
            $meta[(int) $row->line_id] = $row;
        }

        $loaded = [];

        foreach ($lines as $line) {
            $lineId = (int) $line->line_id;
            $stored = $meta[$lineId] ?? null;
            $tags   = $stored === null || $stored->tags === null ? [] : (array) json_decode($stored->tags, true);
            $tax    = $stored === null || $stored->tax_code === null ? null : [
                'code'     => (string) $stored->tax_code,
                'percent'  => bcadd((string) $stored->tax_percent, '0', 4),
                'included' => (int) $stored->tax_included === 1,
                'amount'   => bcadd((string) $stored->tax_amount, '0', 4),
            ];

            $rows = $shares[$lineId] ?? [];

            $loaded[$lineId] = new LineAnalysis($rows === [] && $line->cost_center_id !== null ? (int) $line->cost_center_id : null, $rows, array_values(array_filter($tags, 'is_string')), $tax);
        }

        return $loaded;
    }

    /**
     * Gives the lines of a reversal the analysis of the lines they mirror, row for row, in the order of
     * the lines. A cost center that has been switched off since stays as it was, because the reversal
     * must take back exactly what the journal charged.
     */
    public function copy(int $fromJournalId, int $toJournalId): void
    {
        $from = array_values($this->lineIds($fromJournalId));
        $to   = array_values($this->lineIds($toJournalId));

        $sources = array_map(static fn (int $id): object => (object) ['line_id' => $id, 'cost_center_id' => null], $from);

        foreach ($this->load($sources) as $lineId => $analysis) {
            $position = array_search($lineId, $from, true);

            if ($position !== false && isset($to[$position]) && ($analysis->distribution !== [] || $analysis->tags !== [] || $analysis->tax !== null)) {
                $this->write($to[$position], $analysis);
            }
        }
    }

    /**
     * Gives the lines of an automatic journal the default distribution of their account, where the
     * account has one and the line has no cost center. A default that names a center that is off now is
     * left out, so that an automatic journal is never stopped by it.
     */
    public function applyDefaults(int $journalId): void
    {
        $lines = $this->db->table('accounting_journal_lines l')
            ->select('l.line_id, l.account_id, l.debit, l.credit')
            ->join('accounting_account_cost_centers d', 'd.account_id = l.account_id')
            ->where('l.journal_id', $journalId)
            ->where('l.cost_center_id IS NULL', null, false)
            ->groupBy('l.line_id, l.account_id, l.debit, l.credit')
            ->get()
            ->getResult();

        foreach ($lines as $line) {
            $rows = array_map(static fn (object $row): array => ['cost_center_id' => $row->cost_center_id, 'percent' => $row->percent], $this->costCenters->defaultsOf((int) $line->account_id));

            try {
                $charge = LineAnalysis::charged($this->costCenters->distribute($rows, bccomp($line->debit, '0', 4) > 0 ? $line->debit : $line->credit, 0, true, false));
            } catch (AccountingException $refusal) {
                log_message('notice', 'The default cost center distribution of account ' . $line->account_id . ' was not applied: ' . $refusal->getMessage());

                continue;
            }

            $this->apply((int) $line->line_id, $charge);
        }
    }

    /**
     * @return array<int, int> The id of each line of a journal by its number.
     */
    private function lineIds(int $journalId): array
    {
        $ids = [];

        foreach ($this->db->table('accounting_journal_lines')->select('line_id, line_no')->where('journal_id', $journalId)->orderBy('line_no', 'ASC')->get()->getResult() as $row) {
            $ids[(int) $row->line_no] = (int) $row->line_id;
        }

        return $ids;
    }

    private function write(int $lineId, LineAnalysis $analysis): void
    {
        if ($analysis->distribution !== []) {
            $this->db->table('accounting_journal_line_distributions')->insertBatch(array_map(
                static fn (array $row): array => ['line_id' => $lineId, 'cost_center_id' => $row['cost_center_id'], 'percent' => $row['percent'], 'amount' => $row['amount']],
                $analysis->distribution,
            ));
        }

        if ($analysis->tags === [] && $analysis->tax === null) {
            return;
        }

        $this->db->table('accounting_journal_line_meta')->insert([
            'line_id'      => $lineId,
            'tags'         => $analysis->tags === [] ? null : json_encode($analysis->tags, JSON_UNESCAPED_UNICODE),
            'tax_code'     => $analysis->tax['code'] ?? null,
            'tax_percent'  => $analysis->tax['percent'] ?? null,
            'tax_included' => $analysis->tax === null ? null : (int) $analysis->tax['included'],
            'tax_amount'   => $analysis->tax['amount'] ?? null,
        ]);
    }

    /**
     * @return list<string>
     *
     * @throws AccountingException
     */
    private function tagsOf(mixed $raw, int $lineNumber, bool $stored): array
    {
        $parts = is_array($raw) ? $raw : (is_string($raw) ? preg_split('/[,\x{060C}\x{061B};\n]+/u', $raw) : []);
        $tags  = [];

        foreach ((array) $parts as $part) {
            if (! is_string($part)) {
                throw new AccountingException(AccountingError::LineTagInvalid, 'A tag is text.', ['line' => $lineNumber, 'max' => self::TAG_MAX]);
            }

            $tag = trim((string) preg_replace('/\s+/u', ' ', $part));

            if ($tag === '') {
                continue;
            }

            if (mb_strlen($tag) > self::TAG_MAX || preg_match('/[\p{C}]/u', $tag) === 1) {
                throw new AccountingException(AccountingError::LineTagInvalid, 'A tag is text of up to 30 characters.', ['line' => $lineNumber, 'max' => self::TAG_MAX]);
            }

            $tags[mb_strtolower($tag)] ??= $tag;
        }

        if ($tags === []) {
            return [];
        }

        if (! $stored && ! $this->settings->lineTags()) {
            throw new AccountingException(AccountingError::LineTagsOff, 'Tags on journal lines are not turned on.', ['line' => $lineNumber]);
        }

        if (count($tags) > self::TAGS_MAX) {
            throw new AccountingException(AccountingError::LineTagInvalid, 'A line takes up to ten tags.', ['line' => $lineNumber, 'max' => self::TAGS_MAX]);
        }

        return array_values($tags);
    }

    /**
     * @param array<string, mixed> $line
     * @param numeric-string       $amount
     *
     * @return array{code: string, percent: numeric-string, included: bool, amount: numeric-string}|null
     *
     * @throws AccountingException
     */
    private function taxOf(array $line, int $lineNumber, string $amount, bool $stored): ?array
    {
        $code     = isset($line['tax_code']) && is_scalar($line['tax_code']) ? trim((string) $line['tax_code']) : '';
        $percent  = isset($line['tax_percent']) && is_scalar($line['tax_percent']) ? trim((string) $line['tax_percent']) : '';
        $included = isset($line['tax_included']) && in_array($line['tax_included'], ['1', 1, true], true);

        if ($code === '' && $percent === '') {
            return null;
        }

        if (! $stored && ! $this->settings->lineTax()) {
            throw new AccountingException(AccountingError::LineTaxOff, 'The tax note on journal lines is not turned on.', ['line' => $lineNumber]);
        }

        if (! JournalLineTax::isCode($code) || ! JournalLineTax::isPercent($percent)) {
            throw new AccountingException(AccountingError::LineTaxInvalid, 'A tax note needs a code of up to 16 letters, digits or dashes and a rate from 0 to 100.', ['line' => $lineNumber]);
        }

        $this->decimals ??= (new Currencies($this->db))->baseDecimals();

        return ['code' => $code, 'percent' => bcadd($percent, '0', 4), 'included' => $included, 'amount' => bcadd(JournalLineTax::figures($amount, $percent, $included, $this->decimals)['tax'], '0', 4)];
    }
}
