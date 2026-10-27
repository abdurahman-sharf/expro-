<?php

namespace App\Libraries\Numbering;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One sequence of numbers per company and kind of document, with the prefix and the digits the
 * owner chose. A sequence is switched off until the owner switches it on, and a document that has
 * no number shows what it has always shown, so nothing that exists is renumbered. A number is
 * taken under the lock of the row of its sequence, inside the transaction that saves the document,
 * so two requests never get the same one, and it is never given again, whether or not the
 * document it went to survives. The next number can be raised and never lowered, and never below
 * a number that was given. A sequence that starts, or changes its prefix, also starts above the
 * numbers the documents already carry or show; one that is running steps over them as it goes.
 */
final class DocumentNumbering
{
    use RunsInTransaction;

    public const PERMISSION        = 'config';
    public const MAX_PREFIX_LENGTH = 16;
    public const MAX_PADDING       = 10;
    public const MAX_NEXT          = 2_000_000_000;
    private const SEARCH_LIMIT     = 1000;
    private const PREFIX_PATTERN   = '/^[\p{L}\p{N}._\/#-]*$/u';

    private BaseConnection $db;
    private AccountingGuard $guard;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->guard = $guard ?? new AccountingGuard();
    }

    public function sequence(DocumentType $type, ?int $companyId = null): NumberingSequence
    {
        $row = $this->db->table('document_sequences')->where(['company_id' => $companyId ?? CompanyContext::id(), 'doc_type' => $type->value])->get()->getRow();

        return $row === null ? NumberingSequence::defaultFor($type) : $this->fromRow($type, $row);
    }

    public function isEnabled(DocumentType $type, ?int $companyId = null): bool
    {
        return $this->sequence($type, $companyId)->enabled;
    }

    /**
     * Every kind of document with its settings and the lowest number it may give next.
     *
     * @return list<NumberingSequence>
     */
    public function overview(?int $companyId = null): array
    {
        $companyId ??= CompanyContext::id();
        $rows = [];

        foreach (DocumentType::cases() as $type) {
            $sequence = $this->sequence($type, $companyId);

            $rows[] = new NumberingSequence(
                $type,
                $sequence->enabled,
                $sequence->prefix,
                $sequence->padding,
                $this->lowestNext($sequence, $sequence->prefix, $companyId),
                $sequence->preventDuplicates,
            );
        }

        return $rows;
    }

    /**
     * The lowest number a sequence with this prefix can give next: above every number it gave, and
     * above every number of the same shape that the documents already carry or show.
     */
    public function floor(DocumentType $type, string $prefix, ?int $companyId = null): int
    {
        $companyId ??= CompanyContext::id();
        $floor  = $this->aboveIssued($type, $companyId);
        $column = $type->numberColumn();

        if ($column !== null) {
            $floor = max($floor, $this->aboveNumbersOf(array_key_first($type->sources()), $column, $prefix));
        }

        if ($prefix === '') {
            foreach ($type->bareIdSources() as $table => $idColumn) {
                $floor = max($floor, $this->aboveIdsShownBare($type, $table, $idColumn));
            }
        }

        return $floor;
    }

    /**
     * Changes the settings of a sequence. The next number may only go up, and only to a number no
     * document has or shows, unless the sequence is running with the prefix it has.
     *
     * @param array{enabled?: bool, prefix?: string, padding?: int|string, next_number?: int|string, prevent_duplicates?: bool} $input
     *
     * @throws AccountingException
     */
    public function configure(DocumentType $type, array $input, ?int $employeeId = null, ?int $companyId = null): NumberingSequence
    {
        $this->guard->require(self::PERMISSION, $employeeId);

        $companyId ??= CompanyContext::id();
        $prefix  = array_key_exists('prefix', $input) ? $this->validPrefix($input['prefix']) : null;
        $padding = array_key_exists('padding', $input) ? $this->validPadding($input['padding']) : null;
        $next    = array_key_exists('next_number', $input) ? $this->validNext($input['next_number']) : null;

        return $this->transactional(function () use ($type, $input, $employeeId, $companyId, $prefix, $padding, $next): NumberingSequence {
            $table = $this->db->prefixTable('document_sequences');

            $this->db->query("INSERT IGNORE INTO {$table} (company_id, doc_type) VALUES (?, ?)", [$companyId, $type->value]);

            $row = LockedRead::row($this->db, "SELECT enabled, prefix, padding, next_number, prevent_duplicates FROM {$table} WHERE company_id = ? AND doc_type = ? FOR UPDATE", [$companyId, $type->value]);

            if ($row === null) {
                throw new AccountingException(AccountingError::WriteFailed, 'The numbering of the document could not be read.');
            }

            $before = $this->fromRow($type, $row);
            $after  = new NumberingSequence(
                $type,
                array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $before->enabled,
                $prefix ?? $before->prefix,
                $padding ?? $before->padding,
                $before->nextNumber,
                array_key_exists('prevent_duplicates', $input) ? (bool) $input['prevent_duplicates'] : $before->preventDuplicates,
            );

            $minimum = $this->lowestNext($before, $after->prefix, $companyId);
            $target  = $next ?? $minimum;

            if ($target < $minimum) {
                throw new AccountingException(AccountingError::NumberingNextTooLow, 'The next number cannot be lower than a number that exists.', ['min' => $minimum]);
            }

            $after = new NumberingSequence($type, $after->enabled, $after->prefix, $after->padding, $target, $after->preventDuplicates);

            if ($this->same($before, $after)) {
                return $before;
            }

            $this->db->table('document_sequences')->where(['company_id' => $companyId, 'doc_type' => $type->value])->update([
                'enabled'            => $after->enabled ? 1 : 0,
                'prefix'             => $after->prefix,
                'padding'            => $after->padding,
                'next_number'        => $after->nextNumber,
                'prevent_duplicates' => $after->preventDuplicates ? 1 : 0,
                'updated_by'         => $employeeId,
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

            (new AuditLog($this->db))->write(
                'document_numbering',
                null,
                'numbering_configured',
                $employeeId,
                $employeeId === null ? JournalOrigin::System : JournalOrigin::User,
                ['doc_type' => $type->value, 'before' => $this->describe($before), 'after' => $this->describe($after)],
            );

            return $after;
        });
    }

    /**
     * Gives the document its number, or returns the one it already has. Nothing is given while
     * the sequence is off. Call it inside the transaction that saves the document.
     *
     * @throws AccountingException
     */
    public function assign(DocumentType $type, string $sourceTable, int $sourceId, ?int $branchId = null): ?DocumentNumber
    {
        $idColumn = $type->sources()[$sourceTable] ?? null;

        if ($idColumn === null || $sourceId <= 0) {
            throw new AccountingException(AccountingError::NumberingSourceInvalid, 'A document of this kind is not stored there.', ['name' => $sourceTable]);
        }

        $companyId = $this->companyOf($branchId);

        if (! $this->sequence($type, $companyId)->enabled) {
            return null;
        }

        return $this->transactional(fn (): ?DocumentNumber => $this->assignLocked($type, $sourceTable, $idColumn, $sourceId, $companyId));
    }

    /**
     * The number the next document of the kind would get, or null while the sequence is off. It
     * takes nothing, so a screen can show it before the document is saved.
     */
    public function peek(DocumentType $type, ?int $companyId = null): ?string
    {
        $companyId ??= CompanyContext::id();
        $sequence = $this->sequence($type, $companyId);

        if (! $sequence->enabled) {
            return null;
        }

        try {
            return $this->nextFree($sequence, $companyId, (string) array_key_first($type->sources()))[1];
        } catch (AccountingException) {
            return null;
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> The numbers of the documents that have one, by id.
     */
    public function numbers(DocumentType $type, string $sourceTable, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === [] || ! isset($type->sources()[$sourceTable])) {
            return [];
        }

        $numbers = [];

        foreach ($this->db->table('document_numbers')->select('source_id, number')->where(['doc_type' => $type->value, 'source_table' => $sourceTable])->whereIn('source_id', $ids)->get()->getResult() as $row) {
            $numbers[(int) $row->source_id] = (string) $row->number;
        }

        return $numbers;
    }

    public function label(DocumentType $type, string $sourceTable, int $id): string
    {
        return $this->numbers($type, $sourceTable, [$id])[$id] ?? $type->fallback($id);
    }

    private function assignLocked(DocumentType $type, string $sourceTable, string $idColumn, int $sourceId, int $companyId): ?DocumentNumber
    {
        $sequences = $this->db->prefixTable('document_sequences');
        $row       = LockedRead::row($this->db, "SELECT enabled, prefix, padding, next_number, prevent_duplicates FROM {$sequences} WHERE company_id = ? AND doc_type = ? FOR UPDATE", [$companyId, $type->value]);

        if ($row === null || (int) $row->enabled !== 1) {
            return null;
        }

        $known = $this->db->table('document_numbers')->select('sequence_number, number')->where(['doc_type' => $type->value, 'source_table' => $sourceTable, 'source_id' => $sourceId])->get()->getRow();

        if ($known !== null) {
            $this->writeColumn($type, $sourceTable, $idColumn, $sourceId, (string) $known->number);

            return new DocumentNumber($type, (int) $known->sequence_number, (string) $known->number);
        }

        [$candidate, $number] = $this->nextFree($this->fromRow($type, $row), $companyId, $sourceTable);

        $this->db->table('document_numbers')->insert([
            'company_id'      => $companyId,
            'doc_type'        => $type->value,
            'source_table'    => $sourceTable,
            'source_id'       => $sourceId,
            'sequence_number' => $candidate,
            'number'          => $number,
        ]);

        $this->db->table('document_sequences')->where(['company_id' => $companyId, 'doc_type' => $type->value])->update(['next_number' => $candidate + 1]);
        $this->writeColumn($type, $sourceTable, $idColumn, $sourceId, $number);

        return new DocumentNumber($type, $candidate, $number);
    }

    /**
     * The first number from the next one on that no document of the kind has.
     *
     * @return array{0: int, 1: string}
     *
     * @throws AccountingException
     */
    private function nextFree(NumberingSequence $sequence, int $companyId, string $sourceTable): array
    {
        $candidate = $sequence->nextNumber;
        $number    = $sequence->format($candidate);

        for ($attempt = 0; $this->isTaken($sequence->type, $companyId, $sourceTable, $number); $attempt++) {
            if ($attempt >= self::SEARCH_LIMIT || $candidate >= 4_294_967_294) {
                throw new AccountingException(AccountingError::NumberingExhausted, 'No free number was found after the next one.', ['count' => self::SEARCH_LIMIT]);
            }

            $number = $sequence->format(++$candidate);
        }

        return [$candidate, $number];
    }

    private function isTaken(DocumentType $type, int $companyId, string $sourceTable, string $number): bool
    {
        if ($this->db->table('document_numbers')->where(['company_id' => $companyId, 'doc_type' => $type->value, 'number' => $number])->countAllResults() > 0) {
            return true;
        }

        $column = $type->numberColumn();

        return $column !== null && $this->db->table($sourceTable)->where($column, $number)->countAllResults() > 0;
    }

    private function writeColumn(DocumentType $type, string $sourceTable, string $idColumn, int $sourceId, string $number): void
    {
        $column = $type->numberColumn();

        if ($column !== null) {
            $this->db->table($sourceTable)->where($idColumn, $sourceId)->update([$column => $number]);
        }
    }

    private function lowestNext(NumberingSequence $current, string $prefix, int $companyId): int
    {
        $runningAsItIs = $current->enabled && $current->prefix === $prefix;

        return max($current->nextNumber, $runningAsItIs ? $this->aboveIssued($current->type, $companyId) : $this->floor($current->type, $prefix, $companyId));
    }

    private function aboveIssued(DocumentType $type, int $companyId): int
    {
        $issued = $this->db->table('document_numbers')->selectMax('sequence_number', 'top')->where(['company_id' => $companyId, 'doc_type' => $type->value])->get()->getRow();

        return (int) ($issued->top ?? 0) + 1;
    }

    private function aboveNumbersOf(string $table, string $column, string $prefix): int
    {
        $length = mb_strlen($prefix);
        $row    = $this->db->query(
            'SELECT MAX(CAST(SUBSTRING(`' . $column . '`, ?) AS UNSIGNED)) AS top FROM ' . $this->db->prefixTable($table)
            . ' WHERE `' . $column . '` IS NOT NULL AND LEFT(`' . $column . '`, ?) = ? AND SUBSTRING(`' . $column . "`, ?) REGEXP '^[0-9]{1,9}\$'",
            [$length + 1, $length, $prefix, $length + 1],
        )->getRow();

        return (int) ($row->top ?? 0) + 1;
    }

    private function aboveIdsShownBare(DocumentType $type, string $table, string $idColumn): int
    {
        $row = $this->db->query(
            'SELECT s.`' . $idColumn . '` AS top FROM ' . $this->db->prefixTable($table) . ' s WHERE NOT EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('document_numbers')
            . ' n WHERE n.doc_type = ? AND n.source_table = ? AND n.source_id = s.`' . $idColumn . '`) ORDER BY s.`' . $idColumn . '` DESC LIMIT 1',
            [$type->value, $table],
        )->getRow();

        return (int) ($row->top ?? 0) + 1;
    }

    private function companyOf(?int $branchId): int
    {
        if ($branchId === null) {
            return CompanyContext::id();
        }

        $row = $this->db->table('branches')->select('company_id')->where('branch_id', $branchId)->get()->getRow();

        return $row === null ? CompanyContext::id() : (int) $row->company_id;
    }

    private function fromRow(DocumentType $type, object $row): NumberingSequence
    {
        return new NumberingSequence(
            $type,
            (int) $row->enabled === 1,
            (string) $row->prefix,
            (int) $row->padding,
            (int) $row->next_number,
            (int) $row->prevent_duplicates === 1,
        );
    }

    private function same(NumberingSequence $before, NumberingSequence $after): bool
    {
        return $this->describe($before) === $this->describe($after);
    }

    /**
     * @return array<string, bool|int|string>
     */
    private function describe(NumberingSequence $sequence): array
    {
        return [
            'enabled'            => $sequence->enabled,
            'prefix'             => $sequence->prefix,
            'padding'            => $sequence->padding,
            'next_number'        => $sequence->nextNumber,
            'prevent_duplicates' => $sequence->preventDuplicates,
        ];
    }

    /**
     * @throws AccountingException
     */
    private function validPrefix(mixed $prefix): string
    {
        $prefix = is_string($prefix) ? trim($prefix) : null;

        if ($prefix === null || mb_strlen($prefix) > self::MAX_PREFIX_LENGTH || preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new AccountingException(AccountingError::NumberingPrefixInvalid, 'The prefix takes letters, digits and . _ / # - only.', ['max' => self::MAX_PREFIX_LENGTH]);
        }

        return $prefix;
    }

    /**
     * @throws AccountingException
     */
    private function validPadding(mixed $padding): int
    {
        if (! is_int($padding) && ! (is_string($padding) && preg_match('/^\d{1,2}$/', trim($padding)) === 1)) {
            throw new AccountingException(AccountingError::NumberingPaddingInvalid, 'The digits must be a whole number.', ['max' => self::MAX_PADDING]);
        }

        $padding = (int) $padding;

        if ($padding < 0 || $padding > self::MAX_PADDING) {
            throw new AccountingException(AccountingError::NumberingPaddingInvalid, 'The digits must be between 0 and ' . self::MAX_PADDING . '.', ['max' => self::MAX_PADDING]);
        }

        return $padding;
    }

    /**
     * @throws AccountingException
     */
    private function validNext(mixed $next): int
    {
        if (! is_int($next) && ! (is_string($next) && preg_match('/^\d{1,10}$/', trim($next)) === 1)) {
            throw new AccountingException(AccountingError::NumberingNextInvalid, 'The next number must be a whole number.', ['max' => self::MAX_NEXT]);
        }

        $next = (int) $next;

        if ($next < 1 || $next > self::MAX_NEXT) {
            throw new AccountingException(AccountingError::NumberingNextInvalid, 'The next number must be between 1 and ' . self::MAX_NEXT . '.', ['max' => self::MAX_NEXT]);
        }

        return $next;
    }
}
