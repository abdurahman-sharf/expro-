<?php

namespace App\Commands;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Assets\DepreciationBranchPlan;
use App\Libraries\Assets\DepreciationPeriod;
use App\Libraries\Assets\DepreciationRun;
use App\Libraries\Organization\BranchException;
use App\Libraries\Recurring\GenerationRuntime;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingDepreciate extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:depreciate';
    protected $usage       = 'accounting:depreciate [--month YYYY-MM] [--branch ID] [--approve FINGERPRINT]';
    protected $description = 'Previews the depreciation of fixed assets for a month, which writes nothing and prints a fingerprint, or with --approve posts exactly what that preview showed: one journal for each branch with a line for every asset that is due, each asset once for a month, never past its cost less its salvage. A month that has not ended, a period that is closed, and a preview that changed since the fingerprint was taken are refused by name. Exits 0 when the preview was shown or the run was posted (and when accounting is off), 1 when it was refused, and 7 for an option it refuses.';
    protected $options     = [
        '--month'   => 'The month, YYYY-MM (default: the month that ended last).',
        '--branch'  => 'Only this branch (default: every branch).',
        '--approve' => 'The fingerprint of the preview to post.',
    ];

    public function run(array $params): int
    {
        GenerationRuntime::apply();

        if (! (new AccountingSettings())->isEnabled()) {
            CLI::write('Accounting is off, so there is no depreciation to run.');

            return EXIT_SUCCESS;
        }

        $month       = $this->option($params, 'month') ?? date('Y-m', strtotime('first day of last month'));
        $branch      = $this->option($params, 'branch');
        $fingerprint = $this->option($params, 'approve');

        if (DepreciationPeriod::fromMonth($month) === null || ($branch !== null && (! ctype_digit($branch) || (int) $branch < 1)) || ($fingerprint !== null && preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1)) {
            CLI::error('The month is written YYYY-MM, the branch is a number and the fingerprint is the 64 characters the preview printed.');

            return EXIT_USER_INPUT;
        }

        $run      = new DepreciationRun();
        $branchId = $branch === null ? null : (int) $branch;

        try {
            if ($fingerprint !== null) {
                $runs = $run->approve($branchId, $month, $fingerprint, null);

                CLI::write(sprintf('RESULT: %d run(s) posted for %s.', count($runs), $month));

                return EXIT_SUCCESS;
            }

            $preview = $run->preview($branchId, $month, null);
        } catch (AccountingException|BranchException|PostingException $refusal) {
            CLI::error(AccountingMessage::of($refusal));

            return EXIT_ERROR;
        }

        CLI::write('DEPRECIATION of ' . $month);

        foreach ($preview->branches as $plan) {
            $this->printBranch($plan);
        }

        foreach ($preview->blockers as $blocker) {
            CLI::write('STOPPED: ' . AccountingMessage::of($blocker), 'red');
        }

        CLI::write('FINGERPRINT: ' . $preview->fingerprint);
        CLI::write($preview->canApprove() ? 'Post it with --approve ' . $preview->fingerprint : 'Nothing can be posted.');

        return EXIT_SUCCESS;
    }

    private function printBranch(DepreciationBranchPlan $plan): void
    {
        CLI::write(sprintf('branch %s: %s, total %s', $plan->code, $plan->status->value, $plan->total));

        foreach ($plan->lines as $line) {
            CLI::write(sprintf('  %s %s: %s%s', $line->asset->code, $line->asset->name, $line->amount, $line->missedMonths === [] ? '' : ' (earlier months not run: ' . implode(', ', $line->missedMonths) . ')'));
        }
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function option(array $params, string $name): ?string
    {
        $value = $params[$name] ?? CLI::getOption($name);

        foreach (array_keys($params) as $key) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                $value = substr($key, strlen($name) + 1);
            }
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
