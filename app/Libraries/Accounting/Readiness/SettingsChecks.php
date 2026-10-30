<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\OpeningStockPlan;
use App\Libraries\Accounting\OpeningStockStatus;
use App\Libraries\Accounting\ReadinessIssue;
use App\Libraries\Accounting\SensitivePermissions;
use App\Libraries\Organization\CompanyContext;

/**
 * What the owner decides: who holds the permissions that cannot be undone, whether accounting is on
 * and what is missing before it can be, the opening stock that waits to be posted (E26) and the
 * opening balances of every branch. These are listed for review: they never stop the database
 * being ready, except that accounting turned on while its settings are incomplete does.
 */
final class SettingsChecks extends CheckGroup
{
    private const ACTIVATION_NEEDS = ['accounting_accounts', 'accounting_routing', 'accounting_fiscal_years', 'accounting_journals', 'branches', 'treasuries', 'expenses.branch_id', 'cash_up.branch_id'];

    public function run(): array
    {
        return [
            $this->check('sensitive_grants', 'OWNER-GRANTS', 'Who holds the sensitive accounting permissions', ['grants', 'permissions', 'employees', 'people', 'employee_branches', 'branches'], fn (): CheckResult => $this->grants()),
            $this->check('activation', 'E26-ACTIVATION', 'Accounting activation state', [], fn (): CheckResult => $this->activation()),
            $this->check('opening_stock', 'E26-ACTIVATION', 'Opening stock waiting to be posted', ['branches', 'stock_locations.branch_id', 'accounting_stock_entries', 'accounting_accounts.entity_type', 'item_quantities'], fn (): CheckResult => $this->openingStock()),
            $this->check('opening_balances', 'E26-ACTIVATION', 'Opening balances of the branches', ['branches', 'accounting_opening_drafts', 'accounting_journals'], fn (): CheckResult => $this->openingBalances()),
        ];
    }

    private function grants(): CheckResult
    {
        $holders = (new SensitivePermissions($this->db))->holders();
        $known   = array_column($this->db->table('permissions')->select('permission_id')->whereIn('permission_id', AccountingGuard::SENSITIVE)->get()->getResultArray(), 'permission_id');
        $by      = [];

        foreach ($holders as $holder) {
            foreach ($holder['permissions'] as $permissionId) {
                $by[$permissionId][] = sprintf('%s (%s), %s', $holder['name'], $holder['username'], $holder['all_branches'] ? 'every branch' : ($holder['branches'] === [] ? 'no branch' : 'branches ' . implode(', ', $holder['branches'])));
            }
        }

        $findings = [];

        foreach (AccountingGuard::SENSITIVE as $permissionId) {
            $findings[] = $permissionId . ': ' . (isset($by[$permissionId]) ? implode('; ', $by[$permissionId]) : 'nobody') . (in_array($permissionId, $known, true) ? '' : ' [not registered yet]');
        }

        $data = ['holders' => $holders, 'registered' => array_values($known)];

        if ($holders === []) {
            return CheckResult::pass('Nobody holds a sensitive accounting permission: the administrator grants them by hand when they are needed.', $findings, $data);
        }

        return CheckResult::review(count($holders) . ' employee(s) hold sensitive accounting permissions: the owner confirms each grant.', $findings, $data);
    }

    private function activation(): CheckResult
    {
        $settings = new AccountingSettings($this->db);
        $enabled  = $settings->isEnabled();
        $state    = $enabled ? 'enabled' : 'disabled';
        $data     = ['enabled' => $enabled, 'base_currency' => $settings->baseCurrency(), 'start_date' => $settings->startDate()];

        if ($this->schema->missing(self::ACTIVATION_NEEDS) !== []) {
            return CheckResult::skipped("Accounting is {$state}. What it needs before it can be enabled is checked once the migrations have run.", $data);
        }

        $describe = static fn (ReadinessIssue $issue): string => $issue->code->value . ($issue->subject === null ? '' : " ({$issue->subject})");
        $issues   = array_map($describe, $settings->readiness());
        $notices  = array_map($describe, $settings->notices());
        $data     = [...$data, 'missing' => $issues, 'notices' => $notices];

        if (! $enabled) {
            return CheckResult::review(
                $issues === []
                    ? 'Accounting is disabled and its settings are complete: it stays disabled until the owner orders the activation.'
                    : 'Accounting is disabled, as it stays until the owner orders the activation. Before it can be enabled: ' . count($issues) . ' item(s).',
                [...$issues, ...$notices],
                $data,
            );
        }

        if ($issues !== []) {
            return CheckResult::fail('Accounting is enabled while ' . count($issues) . ' of its settings are incomplete.', [...$issues, ...$notices], $data);
        }

        return $notices === []
            ? CheckResult::pass('Accounting is enabled and its settings are complete.', [], $data)
            : CheckResult::review('Accounting is enabled and its settings are complete; ' . count($notices) . ' notice(s) are worth reading.', $notices, $data);
    }

    private function openingStock(): CheckResult
    {
        $stock    = new OpeningStock($this->db);
        $findings = [];
        $waiting  = 0;
        $rows     = [];

        foreach ($this->branches() as $branch) {
            foreach ($stock->plans((int) $branch->branch_id, null) as $plan) {
                $rows[] = ['branch' => (string) $branch->code, 'location_id' => $plan->locationId, 'status' => $plan->status->value];

                if (in_array($plan->status, [OpeningStockStatus::NothingToPost, OpeningStockStatus::AlreadyPosted], true)) {
                    continue;
                }

                $waiting++;
                $findings[] = $this->describePlan((string) $branch->code, $plan);
            }
        }

        $data = ['locations' => $rows, 'waiting' => $waiting];

        if ($waiting === 0) {
            return CheckResult::pass('No stock location has opening stock waiting to be posted.', [], $data);
        }

        return CheckResult::review($waiting . ' stock location(s) have opening stock that is waiting, partly posted or blocked: posting it is the owner\'s order (E26).', $findings, $data);
    }

    private function describePlan(string $branch, OpeningStockPlan $plan): string
    {
        $line = sprintf('branch %s, %s (location %d): %s, %s unit(s) worth %s', $branch, $plan->locationName, $plan->locationId, $plan->status->value, $plan->units(), $plan->value());

        if ($plan->findings !== []) {
            $line .= '; ' . implode(', ', array_unique(array_map(static fn ($finding): string => $finding->problem->value, $plan->findings)));
        }

        return $line;
    }

    private function openingBalances(): CheckResult
    {
        $service  = new OpeningBalanceService($this->db);
        $findings = [];
        $drafts   = 0;
        $rows     = [];

        foreach ($this->branches() as $branch) {
            $state = $service->state((int) $branch->branch_id, null);

            if ($state['posted'] !== null) {
                $rows[]     = ['branch' => (string) $branch->code, 'state' => 'posted'];
                $findings[] = "branch {$branch->code}: posted as journal #{$state['posted']->journal_id} dated {$state['posted']->journal_date}";
            } elseif ($state['draft'] !== null) {
                $drafts++;
                $rows[]     = ['branch' => (string) $branch->code, 'state' => 'draft'];
                $findings[] = "branch {$branch->code}: a draft of " . count($state['draft']) . ' line(s) is saved and not posted';
            } else {
                $rows[]     = ['branch' => (string) $branch->code, 'state' => 'none'];
                $findings[] = "branch {$branch->code}: nothing entered";
            }
        }

        $data = ['branches' => $rows, 'drafts' => $drafts];

        if ($drafts > 0) {
            return CheckResult::review($drafts . ' branch(es) have opening balances saved as a draft and not posted.', $findings, $data);
        }

        return CheckResult::pass('No opening balance is left as a draft.', $findings, $data);
    }

    /**
     * @return list<object>
     */
    private function branches(): array
    {
        return $this->db->table('branches')->select('branch_id, code')->where('company_id', CompanyContext::id())->orderBy('branch_id', 'ASC')->get()->getResult();
    }
}
