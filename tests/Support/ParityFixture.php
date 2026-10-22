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
}
