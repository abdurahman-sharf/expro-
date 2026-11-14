<?php

namespace App\Libraries\Accounting\Readiness;

use InvalidArgumentException;
use JsonException;

/**
 * Compares the report taken before the migrations with the one taken after, both from
 * accounting:readiness --json. A value of any check's data that differs is flagged, because a migration
 * may move what it moves but must not change what a party owes, what the books hold or what the checks
 * counted. Two things are expected to differ and are listed without being flagged: what the migrations
 * themselves change (the pending migrations; a recorded version that no file names is not one of them and
 * is flagged), and the purchase lines the discount scan named before the migration, which must equal the
 * ones the migration marked (the runbook says so, and a difference there is flagged). A check that was
 * skipped in one report cannot be compared, and one that was checked before and is skipped after is flagged.
 * Two reports that are one report, or a second one that still says pre-migration, cannot show what the
 * migrations changed: those are blockers, and the comparison is flagged however equal the checks are.
 */
final class ReadinessComparison
{
    public const ABSENT             = '(absent)';
    private const MIGRATIONS        = 'migrations';
    private const PURCHASE_DISCOUNT = 'purchase_discount';
    private const SKIPPED           = 'skipped';
    private const BLOCKING          = ['fail', 'error'];
    private const MARKED_AGAINST    = ['would_mark' => 'marked_lines', 'would_mark_discount' => 'marked_discount'];

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $json): array
    {
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('it is not valid JSON (' . $failure->getMessage() . '): take it with "php spark accounting:readiness --json --no-header"');
        }

        if (! is_array($document) || ! isset($document['checks']) || ! is_array($document['checks']) || $document['checks'] === []) {
            throw new InvalidArgumentException('it is not the output of "php spark accounting:readiness --json": it has no list of checks');
        }

        foreach ($document['checks'] as $check) {
            if (! is_array($check) || ! isset($check['id'], $check['status']) || ! is_string($check['id']) || ! is_string($check['status']) || (isset($check['data']) && ! is_array($check['data']))) {
                throw new InvalidArgumentException('a check in it has no id, no status or no data in the shape accounting:readiness gives');
            }
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function compare(array $before, array $after): ComparisonResult
    {
        $first  = self::byId($before['checks']);
        $second = self::byId($after['checks']);
        $ids    = array_values(array_unique([...array_keys($second), ...array_keys($first)]));

        return new ComparisonResult(
            array_map(static fn (string $id): CheckDifference => self::difference($id, $first[$id] ?? null, $second[$id] ?? null), $ids),
            self::notes($before, $after),
            self::blockers($before, $after),
        );
    }

    /**
     * @param list<array<string, mixed>> $checks
     *
     * @return array<string, array<string, mixed>>
     */
    private static function byId(array $checks): array
    {
        $byId = [];

        foreach ($checks as $check) {
            $byId[(string) $check['id']] = $check;
        }

        return $byId;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<string>
     */
    private static function notes(array $before, array $after): array
    {
        $notes = [];

        if (($before['database'] ?? null) !== ($after['database'] ?? null)) {
            $notes[] = sprintf('The reports name different databases (%s and %s): a rehearsal on a copy compared with the live database proves only that the two agree.', $before['database'] ?? '?', $after['database'] ?? '?');
        }

        if (($before['phase'] ?? null) === ReadinessResult::POST_MIGRATION) {
            $notes[] = 'The first report says post-migration: it was taken after the migrations, so the comparison shows nothing that the migrations did.';
        }

        return $notes;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<string>
     */
    private static function blockers(array $before, array $after): array
    {
        $blockers = [];

        if (($before['generated_at'] ?? null) !== null && ($before['generated_at'] ?? null) === ($after['generated_at'] ?? null) && ($before['database'] ?? null) === ($after['database'] ?? null)) {
            $blockers[] = 'The two reports carry the same database and the same moment: they are one report, and nothing can differ.';
        }

        if (($after['phase'] ?? null) === ReadinessResult::PRE_MIGRATION) {
            $blockers[] = 'The second report still says pre-migration: migrations are pending in the database it was taken from, so it cannot show what they changed. Run the migrations, take the report again and compare that one.';
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private static function difference(string $id, ?array $before, ?array $after): CheckDifference
    {
        $ref   = (string) (($after ?? $before)['ref'] ?? '');
        $title = (string) (($after ?? $before)['title'] ?? '');
        $was   = $before === null ? null : (string) $before['status'];
        $is    = $after === null ? null : (string) $after['status'];

        if ($before === null || $after === null) {
            return new CheckDifference($id, $ref, $title, $was, $is, notComparable: $before === null ? 'the check is only in the second report' : 'the check is only in the first report');
        }

        $problems = [];

        if ($is === self::SKIPPED || $was === self::SKIPPED) {
            if ($is === self::SKIPPED && $was !== self::SKIPPED) {
                $problems[] = "the check ran in the first report ({$was}) and is skipped in the second: the table or column it reads is gone";
            }

            return new CheckDifference($id, $ref, $title, $was, $is, problems: $problems, notComparable: $was === self::SKIPPED && $is === self::SKIPPED ? 'skipped in both reports' : ($was === self::SKIPPED ? 'skipped before: a migration it needs had not run, so there is nothing to compare it with' : 'skipped after'));
        }

        if (in_array($is, self::BLOCKING, true) && ! in_array($was, self::BLOCKING, true)) {
            $problems[] = "the check was {$was} in the first report and is " . strtoupper((string) $is) . ' in the second';
        }

        $beforeData = (array) ($before['data'] ?? []);
        $afterData  = (array) ($after['data'] ?? []);
        $changes    = [];
        $expected   = [];
        $scanned    = $id === self::PURCHASE_DISCOUNT && ($beforeData['migration_applied'] ?? null) === false && ($afterData['migration_applied'] ?? null) === true;

        if ($scanned) {
            foreach (self::MARKED_AGAINST as $counted => $marked) {
                if (array_key_exists($counted, $beforeData) && array_key_exists($marked, $afterData) && ! self::same($beforeData[$counted], $afterData[$marked])) {
                    $problems[] = sprintf('%s was %s before the migration and %s is %s after it: the lines the migration marked are not the ones the report named', $counted, self::show($beforeData[$counted]), $marked, self::show($afterData[$marked]));
                }
            }
        }

        $first  = self::flatten($beforeData);
        $second = self::flatten($afterData);

        foreach (array_values(array_unique([...array_keys($second), ...array_keys($first)])) as $path) {
            $has = ['before' => array_key_exists($path, $first), 'after' => array_key_exists($path, $second)];

            if ($has['before'] && $has['after'] && self::same($first[$path], $second[$path])) {
                continue;
            }

            $change = ['path' => $path, 'before' => $has['before'] ? $first[$path] : self::ABSENT, 'after' => $has['after'] ? $second[$path] : self::ABSENT];

            if ($id === self::MIGRATIONS && ! str_starts_with($path, 'data.unknown')) {
                $expected[] = [...$change, 'why' => 'the migrations run between the two reports'];
            } elseif ($scanned) {
                $expected[] = [...$change, 'why' => 'counted before the migration ran, and the migration marked them: compared above'];
            } else {
                $changes[] = $change;
            }
        }

        return new CheckDifference($id, $ref, $title, $was, $is, $changes, $expected, $problems);
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<string, mixed> Every value of the data by its path, a list or a map alike.
     */
    private static function flatten(array $data, string $prefix = 'data'): array
    {
        $leaves = [];

        foreach ($data as $key => $value) {
            $path = $prefix . '.' . $key;

            if (is_array($value) && $value !== []) {
                $leaves = [...$leaves, ...self::flatten($value, $path)];
            } else {
                $leaves[$path] = $value;
            }
        }

        return $leaves;
    }

    private static function same(mixed $before, mixed $after): bool
    {
        if (self::isDecimal($before) && self::isDecimal($after)) {
            return bccomp((string) $before, (string) $after, 8) === 0;
        }

        return json_encode($before) === json_encode($after);
    }

    private static function isDecimal(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value)) && preg_match('/^-?\d+(\.\d+)?$/', (string) $value) === 1;
    }

    private static function show(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) json_encode($value) : '[...]';
    }
}
