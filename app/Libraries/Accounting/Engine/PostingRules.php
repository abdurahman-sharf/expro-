<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use JsonException;

/**
 * The posting rules of each operation, kept as data. A change is a new version that applies from a
 * day on: the versions before it stay, so a document is always posted by the version that applied on
 * its day, and no journal already posted is touched. Every new version is written in the audit log
 * with the employee who published it.
 */
final class PostingRules
{
    use RunsInTransaction;

    private const SIDES        = ['debit', 'credit'];
    private const SOURCE_KEYS  = ['when', 'field', 'role', 'entity', 'system', 'payment', 'no_journal'];
    private const ELSE_ERRORS  = ['payment_type_unknown', 'routing_row_cancelled', 'fx_accounts_missing', 'entity_account_missing', 'supplier_required'];
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    private const BALANCING    = ['first', 'largest_debit'];

    private BaseConnection $db;

    /**
     * @var array<string, ?RuleSet>
     */
    private array $cache = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * The version of the rules of the operation that applies on the day.
     *
     * @throws PostingException
     */
    public function current(string $operation, string $date): RuleSet
    {
        $key = $operation . '@' . $date;

        if (! array_key_exists($key, $this->cache)) {
            $row = $this->db->table('accounting_posting_rule_sets')
                ->where('company_id', CompanyContext::id())
                ->where('operation', $operation)
                ->where('effective_from <=', $date)
                ->orderBy('effective_from', 'DESC')
                ->orderBy('version', 'DESC')
                ->limit(1)
                ->get()
                ->getRow();

            $this->cache[$key] = $row === null ? null : $this->ruleSet($row);
        }

        if ($this->cache[$key] === null) {
            throw new PostingException(PostingError::RuleMissing, "No posting rule covers {$operation} on {$date}.", ['code' => $operation, 'date' => $date]);
        }

        return $this->cache[$key];
    }

    /**
     * Every version of the rules, newest first, of one operation or of all of them.
     *
     * @return list<RuleSet>
     */
    public function versions(?string $operation = null): array
    {
        $builder = $this->db->table('accounting_posting_rule_sets')->where('company_id', CompanyContext::id());

        if ($operation !== null) {
            $builder->where('operation', $operation);
        }

        return array_map(fn (object $row): RuleSet => $this->ruleSet($row), $builder->orderBy('operation', 'ASC')->orderBy('version', 'DESC')->get()->getResult());
    }

    /**
     * Writes a new version of the rules of an operation, which applies to documents dated on the day
     * given or later. Every line is checked before anything is written.
     *
     * @param array{source_type: string, entry_type?: string, description?: string, cross_branch?: bool, foreign_rounding?: ?array{balancing: string, except?: list<int>}, note?: string}                                          $header
     * @param list<array{line_no: int, side: string, account: array<string, mixed>, amount: string, repeat_over?: ?string, foreign_amount?: ?string, cost_center?: ?array<string, string>, auto_tax?: bool, matrix_ref?: ?string}> $lines
     *
     * @throws PostingException
     */
    public function publish(string $operation, string $effectiveFrom, array $header, array $lines, int $employeeId): int
    {
        $this->assertName($operation, 'operation');
        $this->assertName($header['source_type'], 'source_type');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1 || strtotime($effectiveFrom) === false) {
            throw new PostingException(PostingError::RuleInvalid, 'The first day of the rules is not a date.', ['code' => $effectiveFrom]);
        }

        $entryType = JournalEntryType::tryFrom($header['entry_type'] ?? JournalEntryType::General->value)
            ?? throw new PostingException(PostingError::RuleInvalid, 'The kind of journal of the rules is not known.', ['code' => (string) $header['entry_type']]);

        if ($lines === []) {
            throw new PostingException(PostingError::RuleInvalid, 'A version of the rules needs at least one line.', ['code' => $operation]);
        }

        if (isset($header['foreign_rounding'])) {
            self::assertForeignRounding($header['foreign_rounding']);
        }

        $numbers = [];

        foreach ($lines as $line) {
            $this->assertLine($line);
            $numbers[] = $line['line_no'];
        }

        if (count(array_unique($numbers)) !== count($numbers)) {
            throw new PostingException(PostingError::RuleInvalid, 'Two lines of the rules have the same number.', ['code' => $operation]);
        }

        return $this->transactional(function () use ($operation, $effectiveFrom, $header, $lines, $employeeId, $entryType): int {
            $last = (int) ($this->db->table('accounting_posting_rule_sets')->selectMax('version', 'last')->where(['company_id' => CompanyContext::id(), 'operation' => $operation])->get()->getRow()->last ?? 0);

            $this->db->table('accounting_posting_rule_sets')->insert([
                'company_id'       => CompanyContext::id(),
                'operation'        => $operation,
                'version'          => $last + 1,
                'effective_from'   => $effectiveFrom,
                'source_type'      => $header['source_type'],
                'entry_type'       => $entryType->value,
                'description'      => mb_substr($header['description'] ?? '', 0, 255),
                'cross_branch'     => (int) ($header['cross_branch'] ?? false),
                'foreign_rounding' => isset($header['foreign_rounding']) ? json_encode($header['foreign_rounding'], JSON_THROW_ON_ERROR) : null,
                'note'             => isset($header['note']) ? mb_substr($header['note'], 0, 500) : null,
                'created_by'       => $employeeId,
            ]);

            $setId = (int) $this->db->insertID();

            foreach ($lines as $line) {
                $this->db->table('accounting_posting_rules')->insert([
                    'rule_set_id'    => $setId,
                    'line_no'        => $line['line_no'],
                    'side'           => $line['side'],
                    'account'        => json_encode($line['account'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'amount'         => $line['amount'],
                    'repeat_over'    => $line['repeat_over'] ?? null,
                    'foreign_amount' => $line['foreign_amount'] ?? null,
                    'cost_center'    => isset($line['cost_center']) ? json_encode($line['cost_center'], JSON_THROW_ON_ERROR) : null,
                    'auto_tax'       => (int) ($line['auto_tax'] ?? false),
                    'matrix_ref'     => $line['matrix_ref'] ?? null,
                ]);
            }

            (new AuditLog($this->db))->write('posting_rules', $setId, 'published', $employeeId, JournalOrigin::User, [
                'operation'      => $operation,
                'version'        => $last + 1,
                'effective_from' => $effectiveFrom,
                'lines'          => count($lines),
            ]);

            $this->cache = [];

            return $setId;
        });
    }

    /**
     * @param array<string, mixed> $line
     *
     * @throws PostingException
     */
    private function assertLine(array $line): void
    {
        if (! is_int($line['line_no'] ?? null) || $line['line_no'] < 1 || ! in_array($line['side'] ?? null, self::SIDES, true)) {
            throw new PostingException(PostingError::RuleInvalid, 'A line of the rules needs a number and a side.', ['code' => (string) json_encode($line)]);
        }

        PostingExpression::assertValid((string) ($line['amount'] ?? ''));

        if (($line['foreign_amount'] ?? null) !== null) {
            PostingExpression::assertValid((string) $line['foreign_amount']);
        }

        foreach (['repeat_over'] as $name) {
            if (($line[$name] ?? null) !== null) {
                $this->assertName((string) $line[$name], $name);
            }
        }

        if (isset($line['auto_tax']) && ! is_bool($line['auto_tax'])) {
            throw new PostingException(PostingError::RuleInvalid, 'Whether a line is the tax worked out of another is yes or no.', ['code' => (string) json_encode($line['auto_tax'])]);
        }

        if (($line['cost_center'] ?? null) !== null && (array_keys($line['cost_center']) !== ['field'] || preg_match(self::NAME_PATTERN, (string) $line['cost_center']['field']) !== 1)) {
            throw new PostingException(PostingError::RuleInvalid, 'The cost center of a line names a field of the document and nothing else.', ['code' => (string) json_encode($line['cost_center'])]);
        }

        self::assertAccount($line['account'] ?? null);
    }

    /**
     * @throws PostingException
     */
    public static function assertAccount(mixed $account): void
    {
        $refuse = static fn (string $why): never => throw new PostingException(PostingError::RuleInvalid, "The account of a line of the rules is not valid: {$why}.", ['code' => (string) json_encode($account)]);

        if (! is_array($account) || ! is_array($account['first'] ?? null) || $account['first'] === [] || array_diff(array_keys($account), ['first', 'else']) !== []) {
            $refuse('it is a list of the places to take it from, in order');
        }

        if (isset($account['else']) && ! in_array($account['else'], self::ELSE_ERRORS, true)) {
            $refuse('the failure when no place gives an account is not known');
        }

        foreach ($account['first'] as $source) {
            if (! is_array($source) || array_diff(array_keys($source), self::SOURCE_KEYS) !== []) {
                $refuse('a place has a key that is not known');
            }

            if (array_key_exists('no_journal', $source)) {
                if ($source['no_journal'] !== true || array_diff(['when', 'no_journal'], array_keys($source)) !== [] || count($source) !== 2 || preg_match(self::NAME_PATTERN, (string) $source['when']) !== 1) {
                    $refuse('a place that posts no journal names the condition it holds on and nothing else');
                }

                continue;
            }

            $kinds = array_intersect(array_keys($source), ['field', 'role', 'system', 'payment']);

            if (count($kinds) !== 1) {
                $refuse('each place is one field, one role, one system account or one way of payment');
            }

            foreach ($source as $key => $value) {
                if (! is_string($value) || ($key !== 'role' && preg_match(self::NAME_PATTERN, $value) !== 1)) {
                    $refuse("{$key} is not a name");
                }
            }

            if (isset($source['role']) && RoutingKey::tryFrom($source['role']) === null) {
                $refuse("{$source['role']} is not a row of the routing");
            }

            if (isset($source['entity']) && ! isset($source['role'])) {
                $refuse('only a row of the routing takes an entity');
            }
        }
    }

    /**
     * How a journal in a foreign currency is brought to the base: every line converted on its own at the
     * rate of the document, and the rounding put on one line, the first (first) or the largest debit
     * that no line of the rules named in except made (largest_debit).
     *
     * @throws PostingException
     */
    public static function assertForeignRounding(mixed $rounding): void
    {
        $valid = is_array($rounding)
            && in_array($rounding['balancing'] ?? null, self::BALANCING, true)
            && array_diff(array_keys($rounding), ['balancing', 'except']) === []
            && (! isset($rounding['except']) || (is_array($rounding['except']) && array_is_list($rounding['except']) && array_filter($rounding['except'], static fn (mixed $line): bool => ! is_int($line) || $line < 1) === []));

        if (! $valid) {
            throw new PostingException(PostingError::RuleInvalid, 'The rounding of a foreign journal names the line that takes it and nothing else.', ['code' => (string) json_encode($rounding)]);
        }
    }

    /**
     * @throws PostingException
     */
    private function assertName(string $name, string $what): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new PostingException(PostingError::RuleInvalid, "The {$what} of the rules is not a name.", ['code' => $name]);
        }
    }

    private function ruleSet(object $row): RuleSet
    {
        $lines = [];

        foreach ($this->db->table('accounting_posting_rules')->where('rule_set_id', $row->rule_set_id)->orderBy('line_no', 'ASC')->get()->getResult() as $line) {
            try {
                $account    = json_decode((string) $line->account, true, 8, JSON_THROW_ON_ERROR);
                $costCenter = $line->cost_center === null ? null : json_decode((string) $line->cost_center, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new PostingException(PostingError::RuleInvalid, "Line {$line->line_no} of the rules of {$row->operation} cannot be read.", ['code' => (string) $row->operation]);
            }

            $lines[] = new RuleLine((int) $line->line_no, (string) $line->side, $account, (string) $line->amount, $line->repeat_over, $line->foreign_amount, $costCenter, $line->matrix_ref, (int) $line->auto_tax === 1);
        }

        return new RuleSet(
            (int) $row->rule_set_id,
            (string) $row->operation,
            (int) $row->version,
            (string) $row->effective_from,
            (string) $row->source_type,
            JournalEntryType::from((string) $row->entry_type),
            (string) $row->description,
            (int) $row->cross_branch === 1,
            $lines,
            $this->foreignRounding($row),
        );
    }

    /**
     * @return array{balancing: string, except?: list<int>}|null
     *
     * @throws PostingException
     */
    private function foreignRounding(object $row): ?array
    {
        if ($row->foreign_rounding === null) {
            return null;
        }

        try {
            $rounding = json_decode((string) $row->foreign_rounding, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PostingException(PostingError::RuleInvalid, "The rounding of the rules of {$row->operation} cannot be read.", ['code' => (string) $row->operation]);
        }

        self::assertForeignRounding($rounding);

        return $rounding;
    }
}
