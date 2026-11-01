<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Bank\BankReconciliation;
use App\Libraries\Accounting\Bank\ImportResult;
use App\Libraries\Accounting\Bank\StatementMapping;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\TreasuryService;

/**
 * What a test needs to reconcile a bank account through the real services: accounting on, an employee
 * who reconciles and manages treasuries, a bank account, lines posted on its account by the books, and
 * the text of a statement. A class that uses it also uses AccountingFixtureTrait.
 */
trait BankFixtureTrait
{
    protected BankReconciliation $bank;
    protected TreasuryService $bankTreasuries;
    protected int $bankClerk;
    protected int $bankId;

    private int $bankSerial = 0;

    protected function startBanking(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->setConfigValue('accounting_enabled', '1');
        (new EntityAccountService())->fill();

        $this->bank           = new BankReconciliation();
        $this->bankTreasuries = new TreasuryService();
        $this->bankClerk      = $this->employeeWithGrants([AccountingGuard::BANK_RECONCILE, AccountingGuard::TREASURIES_MANAGE]);
        $this->bankSerial     = 0;
        $this->bankId         = $this->openBank();
    }

    protected function stopBanking(): void
    {
        $this->clearJournals();
        $this->setConfigValue('accounting_enabled', '0');
    }

    protected function openBank(string $name = '', ?int $branchId = null, string $bankCode = 'SA_RIYAD'): int
    {
        $this->bankSerial++;

        return $this->bankTreasuries->create([
            'name'           => $name !== '' ? $name : 'Bank ' . $this->bankSerial,
            'kind'           => 'bank',
            'bank_code'      => $bankCode,
            'account_number' => 'SA' . str_pad((string) (1000000 + $this->bankSerial + random_int(0, 899999)), 18, '0', STR_PAD_LEFT),
            'branch_id'      => $branchId ?? 0,
        ], $this->bankClerk);
    }

    /**
     * Posts a journal that puts the amount on the bank account (a deposit, or a withdrawal when it is
     * negative) against the opening balances, and returns the id of the line on the account.
     */
    protected function bookLine(string $amount, string $date, string $description = 'Bank movement', ?int $treasuryId = null): int
    {
        $treasuryId ??= $this->bankId;
        $accountId   = (int) $this->bankTreasuries->accountOf($treasuryId);
        $deposit     = bccomp($amount, '0', 4) > 0;
        $absolute    = ltrim($amount, '-');

        $journalId = (new JournalService())->postNew([
            'date'        => $date,
            'description' => $description,
            'lines'       => [
                ['account_id' => $accountId, 'debit' => $deposit ? $absolute : '', 'credit' => $deposit ? '' : $absolute],
                ['account_id' => $this->accountId('opening_balance'), 'debit' => $deposit ? '' : $absolute, 'credit' => $deposit ? $absolute : ''],
            ],
        ]);

        return $this->lineOf($journalId, $accountId);
    }

    protected function lineOf(int $journalId, int $accountId): int
    {
        return (int) $this->accountingDb()->table('accounting_journal_lines')->where(['journal_id' => $journalId, 'account_id' => $accountId])->get()->getRow()->line_id;
    }

    protected function journalOfLine(int $lineId): int
    {
        return (int) $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $lineId)->get()->getRow()->journal_id;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows Date, description, deposit, withdrawal and reference.
     */
    protected function statementText(array $rows, string $delimiter = ',', bool $heading = true): string
    {
        $lines = $heading ? [implode($delimiter, ['Date', 'Description', 'Deposit', 'Withdrawal', 'Reference'])] : [];

        foreach ($rows as $row) {
            $lines[] = implode($delimiter, array_map(static fn (string $cell): string => str_contains($cell, $delimiter) ? '"' . $cell . '"' : $cell, $row));
        }

        return implode("\n", $lines) . "\n";
    }

    protected function importStatement(string $text, ?int $treasuryId = null, string $name = 'statement.csv', ?StatementMapping $mapping = null): ImportResult
    {
        return $this->bank->import($treasuryId ?? $this->bankId, $name, $text, $mapping ?? StatementMapping::standard(), $this->bankClerk);
    }

    protected function statementLineId(string $description, ?int $treasuryId = null): int
    {
        return (int) $this->accountingDb()->table('bank_statement_lines')->where(['treasury_id' => $treasuryId ?? $this->bankId, 'description' => $description])->get()->getRow()->line_id;
    }

    /**
     * Runs the action while a second request, a process of its own, holds the row of the bank account
     * and then commits the statements. The action starts at once and so waits for the lock, which is
     * what two requests that overlap do.
     *
     * @param list<string> $statements What the other request commits before it lets go.
     */
    protected function whileAnotherRequestCommits(array $statements, callable $action, ?int $treasuryId = null, int $holdMilliseconds = 1200): void
    {
        $db  = $this->accountingDb();
        $job = json_encode([
            'hostname'   => $db->hostname,
            'username'   => $db->username,
            'password'   => $db->password,
            'database'   => $db->database,
            'port'       => (int) ($db->port ?: 3306),
            'lock'       => 'SELECT treasury_id FROM ' . $db->prefixTable('treasuries') . ' WHERE treasury_id = ' . ($treasuryId ?? $this->bankId) . ' FOR UPDATE',
            'hold'       => $holdMilliseconds,
            'statements' => $statements,
        ], JSON_THROW_ON_ERROR);

        $process = proc_open([PHP_BINARY, __DIR__ . '/bank_lock_holder.php', $job], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        $this->assertIsResource($process);

        try {
            $read   = [$pipes[1]];
            $write  = null;
            $except = null;

            $this->assertSame(1, stream_select($read, $write, $except, 10), 'The other request did not take the bank account.');
            $this->assertSame("locked\n", fgets($pipes[1]));

            $action();
        } finally {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exit = proc_close($process);
        }

        $this->assertSame([0, "committed\n"], [$exit, $output], $errors);
    }
}
