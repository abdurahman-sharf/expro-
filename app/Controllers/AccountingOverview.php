<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingOverview as OverviewFigures;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Navigation\QuickActions;
use App\Libraries\Navigation\SidebarMenu;

/**
 * A light page that opens the general accounts: the figures a person looks at first, each shown only to whoever may open
 * the page it comes from, by the rule the sidebar applies. It reads and writes nothing.
 */
class AccountingOverview extends AccountingController
{
    private const SHORTCUTS = ['accounting_chart', 'trial_balance', 'ledger', 'balance_sheet', 'income_statement'];

    public function __construct()
    {
        parent::__construct('accounting_reports');
    }

    public function getIndex(): string
    {
        helper('navigation');

        $personId             = $this->employeeId();
        $modules              = array_map(static fn (object $module): string => $module->module_id, $this->global_view_data['allowed_modules'] ?? []);
        [$held, $permissions] = navigationGrants($personId);
        $opens                = static fn (string $id): bool => ($page = SidebarMenu::page($id)) !== null && SidebarMenu::opens($page, $modules, $held, $permissions);
        $figures              = new OverviewFigures(null, $this->branchGuard());
        $branchIds            = $this->branchIds();

        $shortcuts = [];

        foreach (self::SHORTCUTS as $id) {
            if ($opens($id)) {
                $page        = SidebarMenu::page($id);
                $shortcuts[] = ['label' => lang($page['label']), 'url' => site_url($page['path']), 'icon' => $this->iconOf($id)];
            }
        }

        foreach (QuickActions::visible($modules, $held, $permissions, true, false) as $action) {
            if ($action['id'] === 'add_journal') {
                array_unshift($shortcuts, ['label' => lang('Common.shortcut_add_journal'), 'url' => site_url($action['url']), 'icon' => 'bi-journal-plus']);
            }
        }

        return view('accounting_reports/overview', [
            'treasuries'   => $opens('accounting_treasuries') ? $figures->treasuries($branchIds) : null,
            'receivables'  => $opens('accounting_receivables') ? $figures->partyTotal(EntityAccountService::CUSTOMER, $branchIds) : null,
            'payables'     => $opens('accounting_payables') ? $figures->partyTotal(EntityAccountService::SUPPLIER, $branchIds) : null,
            'period'       => $opens('accounting_periods') ? $figures->period() : null,
            'journals'     => $opens('accounting_journals') ? $this->journalRows($figures->latestJournals($personId)) : null,
            'shortcuts'    => $shortcuts,
            'scope_notice' => $this->scopeNoticeNeeded(),
            'amount'       => fn (string $value): string => $this->formatAmount($value),
            'date'         => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    private function iconOf(string $id): string
    {
        return match ($id) {
            'accounting_chart' => 'bi-diagram-3',
            'trial_balance'    => 'bi-list-check',
            'ledger'           => 'bi-journal-text',
            'balance_sheet'    => 'bi-bar-chart-steps',
            default            => 'bi-graph-up-arrow',
        };
    }

    /**
     * @param list<object> $journals
     *
     * @return list<array<string, string>>
     */
    private function journalRows(array $journals): array
    {
        return array_map(fn (object $journal): array => [
            'url'         => site_url('accounting_journals/view/' . (int) $journal->journal_id),
            'number'      => $this->displayNumber($journal->series, $journal->number),
            'date'        => $this->formatDate((string) $journal->journal_date),
            'description' => (string) $journal->description,
            'status'      => $this->badge((string) $journal->status),
            'amount'      => $this->formatAmount((string) $journal->total_debit),
        ], $journals);
    }
}
