<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Reads the parity fixtures: inputs and outputs recorded in the reference system, each with the
 * ids of the evidence that holds them. A fixture carries numbers and ids only, never captured
 * pages or text copied from the reference system.
 */
final class ParityFixture
{
    public const EVIDENCE_PATTERN = '/^ev_[0-9a-f]{64}$/';
    public const DIRECTORY        = __DIR__ . '/Parity';
    public const OBSERVATIONS     = __DIR__ . '/../../docs/accounting/daftra-parity/observations';

    /**
     * Exact and one_halala: the application does what the reference system did. Differs: it does
     * something else, and a change that closes the gap is a gain. Deliberate_difference: it does
     * something else on purpose and the owner decided so. Not_implemented: the reference system has
     * the feature and the application does not, so the case asserts that it is still absent.
     */
    public const CLASSIFICATIONS = ['exact', 'one_halala', 'differs', 'deliberate_difference', 'not_implemented'];

    private const NOT_COMPARED = ['why', 'note', 'reason', 'detail'];
    private const SCALE        = 8;

    /**
     * @return array{id: string, title: string, recorded: string, evidence: array<string, array{id: ?string, note: string}>, cases: list<array<string, mixed>>}
     */
    public static function load(string $id): array
    {
        $path = self::DIRECTORY . "/{$id}.json";

        if (! is_file($path)) {
            throw new RuntimeException("The parity fixture {$id} does not exist.");
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string> The ids of every fixture file, in order.
     */
    public static function ids(): array
    {
        $ids = array_map(static fn (string $path): string => basename($path, '.json'), glob(self::DIRECTORY . '/*.json') ?: []);
        sort($ids);

        return $ids;
    }

    /**
     * The cases of one kind as a PHPUnit data set, keyed by case id.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cases(string $id, string $kind): array
    {
        $sets = [];

        foreach (self::load($id)['cases'] as $case) {
            if ($case['kind'] === $kind) {
                $sets[$case['id']] = [$case];
            }
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return list<string> The evidence ids the case cites, without the ones that were never kept.
     */
    public static function evidenceOf(string $id, array $case): array
    {
        $evidence = self::load($id)['evidence'];
        $ids      = [];

        foreach ($case['evidence'] as $key) {
            if (($evidence[$key]['id'] ?? null) !== null) {
                $ids[] = $evidence[$key]['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $reference What the reference system did.
     * @param array<string, mixed> $expected  What the application does.
     *
     * @return list<string> The paths, in the expected side, where the two do not say the same.
     */
    public static function differences(array $reference, array $expected, string $path = ''): array
    {
        $found = [];

        foreach ($expected as $key => $value) {
            if (in_array($key, self::NOT_COMPARED, true)) {
                continue;
            }

            $where = $path === '' ? (string) $key : $path . '.' . $key;

            if (! array_key_exists($key, $reference)) {
                $found[] = $where;

                continue;
            }

            $other = $reference[$key];

            if (is_array($value) && is_array($other)) {
                $other = self::sorted($other);
                $value = self::sorted($value);

                if (array_is_list($value) && array_is_list($other) && count($value) !== count($other)) {
                    $found[] = $where;

                    continue;
                }

                array_push($found, ...self::differences($other, $value, $where));

                continue;
            }

            if (! self::same($other, $value)) {
                $found[] = $where;
            }
        }

        return $found;
    }

    /**
     * A list of journal lines says the same in any order, so both sides are put in one.
     *
     * @param array<int|string, mixed> $values
     *
     * @return array<int|string, mixed>
     */
    private static function sorted(array $values): array
    {
        if (! array_is_list($values) || $values === [] || ! is_array($values[0]) || ! isset($values[0]['role'])) {
            return $values;
        }

        usort($values, static fn (array $a, array $b): int => [$a['role'], $a['debit'] ?? '0', $a['credit'] ?? '0'] <=> [$b['role'], $b['debit'] ?? '0', $b['credit'] ?? '0']);

        return $values;
    }

    private static function same(mixed $reference, mixed $expected): bool
    {
        if (is_numeric($reference) && is_numeric($expected) && ! is_bool($reference) && ! is_bool($expected)) {
            return bccomp((string) $reference, (string) $expected, self::SCALE) === 0;
        }

        return $reference === $expected;
    }

    /**
     * @return list<string> The ids of every fact the observation packages kept.
     */
    public static function observedFacts(): array
    {
        $ids = [];

        foreach (glob(self::OBSERVATIONS . '/*/facts.json') ?: [] as $path) {
            foreach (json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) as $fact) {
                $ids[] = $fact['id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<string> The ids of the scenarios of one observation package.
     */
    public static function observedScenarios(string $batch): array
    {
        $path = self::OBSERVATIONS . "/{$batch}/scenarios.json";

        return is_file($path) ? array_column(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), 'id') : [];
    }
}
