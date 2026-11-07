<?php

namespace Tests\Support;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\SaleAmounts;

/**
 * What a test needs to run a case of a parity fixture and to compare what the application did with
 * what the fixture expects. A class that uses it also uses AccountingFixtureTrait.
 */
trait ParityCaseTrait
{
    /**
     * A case of a fixture, after checking that its classification is the one the test was written
     * for and that the two sides of the case say what that classification claims.
     *
     * @return array<string, mixed>
     */
    protected function referenceCase(string $fixture, string $kind, string $id, string $classification): array
    {
        $case = ParityFixture::cases($fixture, $kind)[$id][0] ?? null;

        $this->assertNotNull($case, "{$fixture} has no case {$id} of kind {$kind}");
        $this->assertSame($classification, $case['classification'], "{$fixture} {$id}");

        $differences = ParityFixture::differences($case['reference'], $case['expected']);

        match ($classification) {
            'exact' => $this->assertSame([], $differences, "{$id} is recorded as exact and must expect what the reference system did"),
            'differs', 'deliberate_difference' => $this->assertNotSame([], $differences, "{$id} is recorded as a difference and cannot expect what the reference system does"),
            'not_implemented' => $this->assertSame('not_implemented', $case['expected']['status'], $id),
            default           => null,
        };

        return $case;
    }

    /**
     * What the application did must be what the fixture expects, with nothing more and nothing less.
     *
     * @param array<int|string, mixed> $fixture
     * @param array<int|string, mixed> $actual
     */
    protected function assertSameAsFixture(array $fixture, array $actual, string $message = ''): void
    {
        $this->assertSame([], ParityFixture::differences(['x' => $fixture], ['x' => $actual]), "{$message}: the application does something the fixture does not say");
        $this->assertSame([], ParityFixture::differences(['x' => $actual], ['x' => $fixture]), "{$message}: the fixture says something the application does not do");
    }

    /**
     * The lines of the roles given say the same on both sides, whatever the other lines say.
     *
     * @param list<array{role: string, debit: string, credit: string}> $reference
     * @param list<array{role: string, debit: string, credit: string}> $actual
     * @param list<string>                                             $roles
     */
    protected function assertRolesAgree(array $reference, array $actual, array $roles, string $message): void
    {
        $pick = static fn (array $lines): array => array_values(array_map(
            static fn (array $line): array => ['role' => $line['role'], 'debit' => $line['debit'], 'credit' => $line['credit']],
            array_filter($lines, static fn (array $line): bool => in_array($line['role'], $roles, true)),
        ));

        $this->assertSameAsFixture($pick($reference), $pick($actual), $message);
    }

    /**
     * The lines of a journal as the fixture writes them: the role of the account, then what it was
     * debited and credited, to every decimal the journal keeps.
     *
     * @param array<string, int> $accounts The account of each role.
     * @param int|null           $decimals Round every amount, half up, to the decimals the reference system showed.
     *
     * @return list<array{role: string, debit: string, credit: string}>
     */
    protected function roleLines(int $journalId, array $accounts, ?int $decimals = null): array
    {
        $roles = array_flip($accounts);
        $lines = [];

        foreach ($this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResult() as $line) {
            $lines[] = [
                'role'   => $roles[(int) $line->account_id] ?? 'account ' . $line->account_id,
                'debit'  => $decimals === null ? (string) $line->debit : SaleAmounts::round((string) $line->debit, $decimals),
                'credit' => $decimals === null ? (string) $line->credit : SaleAmounts::round((string) $line->credit, $decimals),
            ];
        }

        return $lines;
    }

    /**
     * The lines of a journal that is not posted yet as the fixture writes them. A line that has an amount
     * in a foreign currency says it in that currency and the base apart.
     *
     * @param array<string, int> $accounts The account of each role.
     *
     * @return list<array<string, string>>
     */
    protected function foreignRoleLines(JournalDraft $draft, array $accounts): array
    {
        $roles = array_flip($accounts);
        $lines = [];

        foreach ($draft->lines as $line) {
            $role = $roles[$line->accountId] ?? 'account ' . $line->accountId;

            if ($line->foreignAmount === null) {
                $lines[] = ['role' => $role, 'debit' => $line->debit, 'credit' => $line->credit];
            } elseif (bccomp($line->debit, '0', 4) > 0) {
                $lines[] = ['role' => $role, 'debit' => $line->foreignAmount, 'credit' => '0', 'debit_base' => $line->debit];
            } else {
                $lines[] = ['role' => $role, 'debit' => '0', 'credit' => $line->foreignAmount, 'credit_base' => $line->credit];
            }
        }

        return $lines;
    }
}
