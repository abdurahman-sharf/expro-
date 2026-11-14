<?php

namespace Tests\Support\Ledger;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The random numbers of one operation of a scenario. Each operation draws from a generator seeded by the
 * seed of the scenario and its own position, so that dropping an operation from a replay leaves every
 * other operation drawing what it drew before.
 */
final class LedgerRandom
{
    private const PRICES = ['0.01', '0.02', '0.05', '0.33', '0.99', '1.00', '1.01', '2.67', '3.33', '9.99', '10.05', '12.50', '19.99', '33.33', '33.34', '49.95', '100.00', '250.75', '999.99', '1000000.01'];

    private const QUANTITIES = ['1', '1', '1', '2', '3', '5', '0.5', '0.125', '1.333', '2.5', '0.001', '7.250', '12', '0.333', '3.333', '10', '100'];

    private Randomizer $generator;

    public function __construct(int $seed, int $position)
    {
        $this->generator = new Randomizer(new Mt19937(($seed * 1000003 + $position * 7919) & 0x7fffffff));
    }

    public function int(int $min, int $max): int
    {
        return $this->generator->getInt($min, $max);
    }

    public function chance(int $percent): bool
    {
        return $this->generator->getInt(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->generator->getInt(0, count($items) - 1)];
    }

    /**
     * @param array<string, int> $weights
     */
    public function weighted(array $weights): string
    {
        $roll = $this->generator->getInt(1, array_sum($weights));

        foreach ($weights as $name => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $name;
            }
        }

        return (string) array_key_last($weights);
    }

    /**
     * A price with an awkward number of cents, as the register keeps it: two decimals.
     */
    public function price(): string
    {
        if ($this->chance(70)) {
            return $this->pick(self::PRICES);
        }

        return number_format($this->int(1, 300000) / 100, 2, '.', '');
    }

    public function quantity(): string
    {
        return $this->chance(75) ? (string) $this->int(1, 6) : $this->pick(self::QUANTITIES);
    }

    public function percent(): string
    {
        return $this->pick(['5', '10', '12.5', '15', '33.33', '7.5', '0.5', '50', '100', '2', '17.25']);
    }

    public function money(int $maxCents = 100000): string
    {
        return number_format($this->int(1, $maxCents) / 100, 2, '.', '');
    }
}
