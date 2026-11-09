<?php

namespace Tests\Support;

use App\Libraries\Accounting\Ai\AiSettings;
use App\Libraries\Accounting\Ai\AnomalyContext;
use App\Libraries\Accounting\Ai\ProposalDraft;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use Config\Services;
use DateTimeImmutable;

/**
 * The books a test of the agent layer starts from: the default chart in the base currency, the master switch
 * on and every threshold at its default, and the people who may use the agent, approve and post.
 */
trait AiFixtureTrait
{
    use AccountingFixtureTrait;

    protected function startAgent(bool $enabled = true): void
    {
        Services::language()->setLocale('en');
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue('accounting_line_description_required', '0');
        $this->resetJournalSettings();
        $this->resetAgentThresholds();
        $this->setConfigValue(AiSettings::ENABLED_KEY, $enabled ? '1' : '0');
        $this->accountingDb()->table('accounting_audit_log')->truncate();
    }

    protected function stopAgent(): void
    {
        $this->releaseOpenTransactions();
        $this->resetAgentThresholds();
        $this->setConfigValue(AiSettings::ENABLED_KEY, '0');
        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_line_description_required', '0');
        $this->resetJournalSettings();
    }

    protected function resetAgentThresholds(): void
    {
        foreach (array_keys(AiSettings::THRESHOLDS) as $name) {
            $this->accountingDb()->table('app_config')->where('key', 'accounting_ai_' . $name)->delete();
        }
    }

    protected function setThreshold(string $name, string $value): void
    {
        $this->setConfigValue('accounting_ai_' . $name, $value);
    }

    /**
     * @param list<string>   $extra
     * @param list<int>|null $branchIds
     */
    protected function agentEmployee(array $extra = [], ?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::AI_USE, AccountingGuard::AI_APPROVE, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_DRAFT, ...$extra], $branchIds);
    }

    /**
     * Posts a manual journal of two lines through the service and puts the date and the moment of posting where a
     * test needs them.
     */
    protected function manualJournal(string $date, int $debitAccount, int $creditAccount, string $amount, string $description = 'Manual', ?string $postedAt = null, ?int $branchId = null, ?string $lineText = null): int
    {
        $input = [
            'date'        => $date,
            'description' => $description,
            'branch_id'   => $branchId,
            'lines'       => [
                ['account_id' => $debitAccount, 'debit' => $amount, 'credit' => '', 'description' => $lineText ?? ''],
                ['account_id' => $creditAccount, 'debit' => '', 'credit' => $amount, 'description' => $lineText ?? ''],
            ],
        ];

        $journalId = (new JournalService())->postNew($input, null);

        if ($postedAt !== null) {
            $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['posted_at' => $postedAt]);
        }

        return $journalId;
    }

    protected function otherExpenses(): int
    {
        return $this->accountIdByCode('541');
    }

    protected function rentExpense(): int
    {
        return $this->accountIdByCode('5201');
    }

    protected function electricityExpense(): int
    {
        return $this->accountIdByCode('5202');
    }

    protected function cashAccount(): int
    {
        return $this->accountId('cash');
    }

    protected function customerLedgerAccount(int $customerId): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::CUSTOMER, $customerId);
    }

    /**
     * A valid proposal: the rent from the cash, for the amount.
     *
     * @param array<string, scalar>                 $args     Put over the figures its title and its rationale are filled with.
     * @param list<array<string, scalar|null>>|null $evidence
     */
    protected function proposalDraft(string $amount = '500.00', string $key = 'rent-1', string $date = '2026-10-21', ?int $branchId = 1, string $kind = 'recurring', array $args = [], string $description = 'Rent proposed', ?array $evidence = null, string $lineText = 'Rent'): ProposalDraft
    {
        return new ProposalDraft(
            kind: $kind,
            proposer: 'rules:test',
            dedupeKey: $key,
            date: $date,
            branchId: $branchId,
            description: $description,
            lines: [
                new JournalLineDraft($this->rentExpense(), debit: $amount, description: $lineText),
                new JournalLineDraft($this->cashAccount(), credit: $amount, description: $lineText),
            ],
            evidence: $evidence ?? [['type' => 'journal', 'journal_id' => 1, 'number' => '000001', 'date' => '2026-09-21', 'amount' => $amount]],
            templateArgs: $args + ['amount' => $amount, 'account' => '5201 Rent', 'month' => '2026-10', 'months' => 3, 'text' => 'Rent', 'number' => '000001'],
        );
    }

    /**
     * The account of a treasury that only the given employee may withdraw from.
     */
    protected function treasuryAccountOnlyFor(int $employeeId): int
    {
        $treasuries = new TreasuryService();
        $treasuryId = $treasuries->create(['name' => 'Drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $scopes     = new TreasuryScopes();

        $scopes->apply($treasuryId, $scopes->validated(['withdraw_scope' => TreasuryScopes::STAFF, 'withdraw_members' => [$employeeId]]), null);

        return $treasuries->accountOf($treasuryId);
    }

    /**
     * @param list<int>|null $branchIds
     */
    protected function ruleContext(?array $branchIds = null, string $today = '2026-11-08', string $since = '2026-08-01'): AnomalyContext
    {
        return new AnomalyContext($this->accountingDb(), new AiSettings(), new DateTimeImmutable($today), $since, $branchIds);
    }

    protected function postedAt(int $journalId, string $postedAt): void
    {
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['posted_at' => $postedAt]);
    }

    protected function closedAt(string $dateTo, string $createdAt, ?int $branchId = null, string $dateFrom = '2020-01-01'): int
    {
        $this->accountingDb()->table('accounting_closed_periods')->insert([
            'company_id' => 1,
            'branch_id'  => $branchId,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'active'     => 1,
            'created_at' => $createdAt,
        ]);

        return (int) $this->accountingDb()->insertID();
    }

    protected function journalCount(): int
    {
        return (int) $this->accountingDb()->table('accounting_journals')->countAllResults();
    }
}
