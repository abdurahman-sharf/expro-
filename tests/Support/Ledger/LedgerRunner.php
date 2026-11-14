<?php

namespace Tests\Support\Ledger;

/**
 * Runs one seeded scenario with the invariants asked after every batch, and shrinks a failing run to the
 * fewest operations that still fail the same way.
 */
final class LedgerRunner
{
    public LedgerScenario $scenario;

    public LedgerInvariants $invariants;

    /**
     * @param list<int> $skip
     */
    public function __construct(private readonly int $seed, private readonly array $skip = [])
    {
    }

    /**
     * @return string|null What broke, none when the run held.
     */
    public function run(int $operations, int $batch = 20): ?string
    {
        $this->scenario   = new LedgerScenario($this->seed, $this->skip);
        $this->invariants = new LedgerInvariants();

        $this->scenario->prepare();

        try {
            $done = 0;

            while ($done < $operations) {
                $count = min($batch, $operations - $done);

                $this->scenario->run($count);
                $done += $count;

                $problems = $this->invariants->all(true, $this->scenario->world->boss);

                if ($problems !== []) {
                    return 'after operation ' . ($done - 1) . ': ' . implode("\n  ", $problems);
                }
            }
        } catch (LedgerViolation $violation) {
            return $violation->getMessage();
        }

        return null;
    }

    /**
     * Drops operations while the run still breaks with the same sentence once the numbers are taken out of it.
     *
     * @return list<int> The positions that were left out.
     */
    public function minimize(int $operations, string $message): array
    {
        $signature = self::signature($message);
        $failing   = $operations;
        $keep      = range(0, $operations - 1);
        $size      = max(2, intdiv(count($keep), 2));

        while ($size >= 1 && count($keep) > 1) {
            $reduced = false;

            for ($start = 0; $start < count($keep); $start += $size) {
                $removable = array_slice($keep, $start, $size);
                $removable = array_values(array_filter($removable, static fn (int $position): bool => $position < $failing - 1));

                if ($removable === []) {
                    continue;
                }

                $left = array_values(array_diff($keep, $removable));
                $skip = array_values(array_diff(range(0, $operations - 1), $left));
                $try  = new self($this->seed, $skip);
                $text = $try->run($operations, $operations);

                if ($text !== null && self::signature($text) === $signature) {
                    $keep    = $left;
                    $reduced = true;
                    $start  -= $size;
                }
            }

            if (! $reduced) {
                $size = intdiv($size, 2);
            }
        }

        return array_values(array_diff(range(0, $operations - 1), $keep));
    }

    public static function signature(string $message): string
    {
        $first = explode("\n", $message)[0];

        return (string) preg_replace('/[0-9]+(\.[0-9]+)?/', '#', $first);
    }
}
