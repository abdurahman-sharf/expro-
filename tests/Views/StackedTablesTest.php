<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The tables of the widest accounting screens turn into cards on a phone: each is marked with the class
 * that the style sheet stacks below the breakpoint and that the small script labels the cells of. The
 * browser pass of tools/ui-smoke/phone-pass.mjs measures the result; this checks the markings it relies on,
 * in the sources and in the files that are served.
 */
final class StackedTablesTest extends CIUnitTestCase
{
    private const STACKED_RULE = '/@media \(max-width: 767\.98px\) \{\s*\.acc-lines thead \{[^}]*\}\s*\.acc-lines,\s*\.acc-lines tbody,\s*\.acc-lines tr,\s*\.acc-lines td \{\s*display: block;\s*width: 100%;/';

    /**
     * @return array<string, array{string, int}> The view and how many of its tables are stacked.
     */
    public static function views(): array
    {
        return [
            'aged ledger'             => ['accounting_parties/aged_ledger.php', 1],
            'balances of the parties' => ['accounting_parties/balances.php', 1],
            'operational reports'     => ['accounting_operational/report.php', 2],
            'inventory valuation'     => ['accounting_reports/inventory_valuation.php', 2],
            'tax report'              => ['accounting_reports/tax_report.php', 2],
            'family reports'          => ['accounting_reports/family.php', 2],
            'account statement'       => ['accounting_reports/ledger.php', 1],
            'statement by accounts'   => ['accounting_reports/ledger_blocks.php', 1],
            'trial balance'           => ['accounting_reports/trial_balance.php', 1],
            'bank reconciliation'     => ['accounting_bank/report.php', 3],
            'year end closing'        => ['accounting_periods/year_end.php', 2],
            'cheque report'           => ['accounting_cheques/report.php', 2],
            'treasury statement'      => ['accounting_treasuries/statement.php', 2],
        ];
    }

    #[DataProvider('views')]
    public function testViews_MarkEveryDataTableOfTheScreenToBeStackedOnAPhone(string $view, int $tables): void
    {
        $this->assertSame($tables, preg_match_all('/<table[^>]*\bacc-lines\b/', (string) file_get_contents(APPPATH . 'Views/' . $view)), $view);
    }

    public function testStylesheets_StackTheRowsOfTheMarkedTablesIntoBlocksBelowTheBreakpointInTheSourceAndInTheServedFile(): void
    {
        foreach (['resources/assets/css/37-accounting.css', 'public/assets/css/app.css'] as $file) {
            $this->assertMatchesRegularExpression(self::STACKED_RULE, (string) file_get_contents(ROOTPATH . $file), $file);
        }
    }

    public function testScripts_LabelTheCellsOfTheMarkedTablesInTheSourceAndInTheServedFile(): void
    {
        foreach (['resources/assets/js/46-stacked-tables.js', 'public/assets/js/app.js'] as $file) {
            $script = (string) file_get_contents(ROOTPATH . $file);

            $this->assertStringContainsString("document.querySelectorAll('table.acc-lines').forEach(labelCells);", $script, $file);
            $this->assertStringContainsString("cell.setAttribute('data-label', labels[position]);", $script, $file);
        }
    }
}
