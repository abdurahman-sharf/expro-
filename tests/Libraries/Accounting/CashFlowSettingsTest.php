<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CashFlowFormula;
use App\Libraries\Accounting\CashFlowLineConfig;
use App\Libraries\Accounting\CashFlowSection;
use App\Libraries\Accounting\CashFlowSettings;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * How the lines of the cash flow report are set up and saved: the lines it starts with, renaming,
 * hiding and pointing them elsewhere, lines added and removed, what is refused, who may save, and
 * what the audit log keeps.
 */
final class CashFlowSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CashFlowSettings $settings;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->reinstallChart();
        $this->setConfigValue(CashFlowSettings::KEY, '');
        $this->accountingDb()->table('accounting_audit_log')->truncate();

        $this->settings = new CashFlowSettings();
        $this->manager  = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->setConfigValue(CashFlowSettings::KEY, '');
        $this->accountingDb()->table('accounting_audit_log')->truncate();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function saveRow(array $row, ?int $employee = null): void
    {
        $this->settings->save(['lines' => [$row]], $employee ?? $this->manager);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRefused(AccountingError $error, array $row): void
    {
        try {
            $this->saveRow($row);
            $this->fail('A line that is not valid was saved.');
        } catch (AccountingException $refusal) {
            $this->assertSame($error, $refusal->error);
        }

        $this->assertSame('', model(\App\Models\Appconfig::class)->get_value(CashFlowSettings::KEY), 'Nothing was kept');
        $this->assertSame(0, $this->savesWritten());
    }

    private function savesWritten(): int
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('action', 'cash_flow_lines_updated')->countAllResults();
    }

    private function line(string $key): CashFlowLineConfig
    {
        foreach ($this->settings->lines() as $line) {
            if ($line->key === $key) {
                return $line;
            }
        }

        $this->fail("There is no line {$key}.");
    }

    public function testLines_StartsWithTheEightLinesOfTheReferenceSystemInFiveTwoAndOne(): void
    {
        $lines = $this->settings->lines();

        $this->assertSame(['net_income', 'depreciation_expense', 'account_receivable', 'account_payable', 'inventory', 'assets_selling', 'assets_purchases', 'common_shares'], array_map(static fn (CashFlowLineConfig $line): string => $line->key, $lines));
        $this->assertSame([5, 2, 1], array_values(array_count_values(array_map(static fn (CashFlowLineConfig $line): string => $line->section->value, $lines))));
        $this->assertSame([], array_filter($lines, static fn (CashFlowLineConfig $line): bool => $line->custom || ! $line->visible || $line->title !== '' || $line->accountIds !== []));
        $this->assertSame(CashFlowFormula::DebitInPeriod, $this->line('depreciation_expense')->formula);
    }

    public function testLines_ReadsALineThatCannotBeReadAsTheLinesTheReportStartsWith(): void
    {
        foreach (['not json', '{"lines":"x"}', '[]', '{"lines":[{"key":"UPPER bad"},{"key":"custom_1","custom":true}]}'] as $stored) {
            $this->setConfigValue(CashFlowSettings::KEY, $stored);

            $this->assertCount(8 + ($stored === '{"lines":[{"key":"UPPER bad"},{"key":"custom_1","custom":true}]}' ? 1 : 0), $this->settings->lines(), $stored);
        }
    }

    public function testSave_RenamesAHidesAndChangesTheFormulaOfALineTheReportStartsWith(): void
    {
        $this->saveRow(['key' => 'inventory', 'title' => '  Stock  ', 'visible' => '0', 'formula' => 'debit_in_period']);

        $line = $this->line('inventory');

        $this->assertSame(['Stock', false, CashFlowFormula::DebitInPeriod], [$line->title, $line->visible, $line->formula]);
        $this->assertCount(8, $this->settings->lines(), 'A line the report starts with is never added or removed');
    }

    public function testSave_KeepsALineThatTheRequestDoesNotName(): void
    {
        $this->saveRow(['key' => 'inventory', 'title' => 'Stock']);
        $this->saveRow(['key' => 'account_payable', 'visible' => '0']);

        $this->assertSame('Stock', $this->line('inventory')->title);
        $this->assertFalse($this->line('account_payable')->visible);
        $this->assertTrue($this->line('account_receivable')->visible);
    }

    public function testSave_PointsALineAtOtherAccountsAndBackAtItsOwnWithNone(): void
    {
        $account = $this->customAccount('1203', '120301', 'Stock of the warehouse');

        $this->saveRow(['key' => 'inventory', 'accounts' => [(string) $account]]);

        $this->assertSame([$account], $this->line('inventory')->accountIds);

        $this->saveRow(['key' => 'inventory', 'accounts' => []]);

        $this->assertSame([], $this->line('inventory')->accountIds);
    }

    public function testSave_AddsALineAndGivesItTheNextNumberAndRemovesItByName(): void
    {
        $loans = $this->customAccount('2', '2999', 'Loans');

        $this->saveRow(['key' => '', 'title' => 'Loans', 'accounts' => [$loans], 'formula' => 'credit_in_period', 'section' => 'financing']);
        $this->saveRow(['key' => 'new', 'title' => 'Second', 'accounts' => [$loans], 'section' => 'investing']);

        $lines = array_values(array_filter($this->settings->lines(), static fn (CashFlowLineConfig $line): bool => $line->custom));

        $this->assertSame(['custom_1', 'custom_2'], array_map(static fn (CashFlowLineConfig $line): string => $line->key, $lines));
        $this->assertSame([CashFlowSection::Financing, CashFlowSection::Investing], array_map(static fn (CashFlowLineConfig $line): CashFlowSection => $line->section, $lines));

        $this->saveRow(['key' => 'custom_1', 'remove' => '1']);

        $this->assertSame(['custom_2'], array_map(static fn (CashFlowLineConfig $line): string => $line->key, array_values(array_filter($this->settings->lines(), static fn (CashFlowLineConfig $line): bool => $line->custom))));

        $this->saveRow(['key' => '', 'title' => 'Third', 'accounts' => [$loans]]);

        $this->assertContains('custom_3', array_map(static fn (CashFlowLineConfig $line): string => $line->key, $this->settings->lines()), 'A number that was used is not used again');
    }

    public function testSave_IgnoresABlankRowOfTheFormForANewLine(): void
    {
        $this->saveRow(['key' => '', 'title' => ' ', 'accounts' => []]);

        $this->assertCount(8, $this->settings->lines());
        $this->assertSame(0, $this->savesWritten());
    }

    public function testSave_WritesWhatTheSettingWasAndWhatItBecameToTheAuditLogAndNothingWhenNothingChanged(): void
    {
        $this->saveRow(['key' => 'inventory', 'title' => 'Stock']);
        $this->saveRow(['key' => 'inventory', 'title' => 'Stock']);

        $this->assertSame(1, $this->savesWritten(), 'A save that changes nothing is not written down');

        $row     = $this->accountingDb()->table('accounting_audit_log')->where('action', 'cash_flow_lines_updated')->get()->getRow();
        $details = json_decode((string) $row->details, true);

        $this->assertSame('cash_flow_lines_updated', $row->action);
        $this->assertSame((string) $this->manager, (string) $row->employee_id);
        $this->assertSame('', array_values(array_filter($details['before'], static fn (array $line): bool => $line['key'] === 'inventory'))[0]['title']);
        $this->assertSame('Stock', array_values(array_filter($details['after'], static fn (array $line): bool => $line['key'] === 'inventory'))[0]['title']);
    }

    public function testSave_RequiresThePermissionToManageTheSettings(): void
    {
        foreach ([$this->employeeWithGrants([]), $this->employeeWithGrants([AccountingGuard::CHART_MANAGE])] as $employee) {
            try {
                $this->saveRow(['key' => 'inventory', 'title' => 'Stock'], $employee);
                $this->fail('Someone who may not manage the settings changed them.');
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::PermissionDenied, $refusal->error);
            }
        }

        $this->assertSame('', $this->line('inventory')->title);
        $this->assertSame(0, $this->savesWritten());
    }

    public function testSave_RequiresEveryBranchBecauseTheSettingIsTheCompanys(): void
    {
        $limited = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE], [1]);

        try {
            $this->saveRow(['key' => 'inventory', 'title' => 'Stock'], $limited);
            $this->fail('Someone limited to a branch changed the setting of the company.');
        } catch (BranchException $refusal) {
            $this->assertSame(BranchError::CompanyScopeNeeded, $refusal->error);
        }

        $this->assertSame('', $this->line('inventory')->title);
    }

    public function testSave_RejectsALineThatIsNotInTheReport(): void
    {
        $this->assertRefused(AccountingError::CashFlowLineInvalid, ['key' => 'nonsense', 'title' => 'x']);
    }

    public function testSave_RejectsATitleThatIsTooLongAndATitleOfAnAddedLineThatIsEmpty(): void
    {
        $this->assertRefused(AccountingError::CashFlowLineInvalid, ['key' => 'inventory', 'title' => str_repeat('ب', CashFlowSettings::TITLE_MAX + 1)]);
        $this->assertRefused(AccountingError::CashFlowLineInvalid, ['key' => '', 'title' => '', 'accounts' => [$this->customAccount('2', '2999', 'Loans')]]);

        $this->saveRow(['key' => 'inventory', 'title' => str_repeat('ب', CashFlowSettings::TITLE_MAX)]);

        $this->assertSame(CashFlowSettings::TITLE_MAX, mb_strlen($this->line('inventory')->title), 'A title of the longest length is kept');
    }

    public function testSave_RejectsAFormulaThatIsNotOneTheReportKnows(): void
    {
        $this->assertRefused(AccountingError::CashFlowLineInvalid, ['key' => 'inventory', 'formula' => 'sum_everything']);
        $this->assertRefused(AccountingError::CashFlowLineInvalid, ['key' => 'inventory', 'formula' => ['credit_in_period']]);
        $this->assertRefused(AccountingError::CashFlowLineInvalid, ['key' => 'net_income', 'formula' => 'debit_in_period']);
    }

    public function testSave_RejectsAPartThatIsNotOneTheReportDraws(): void
    {
        $this->assertRefused(AccountingError::CashFlowLineInvalid, ['key' => '', 'title' => 'Loans', 'accounts' => [$this->customAccount('2', '2999', 'Loans')], 'section' => 'elsewhere']);
    }

    public function testSave_RejectsAnAddedLineWithNoAccount(): void
    {
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => '', 'title' => 'Loans', 'accounts' => []]);
    }

    public function testSave_RejectsTheAccountOfATreasuryOrABankAndAnAccountThatIsNotInTheChart(): void
    {
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => 'inventory', 'accounts' => [$this->accountId('cash')]]);
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => 'inventory', 'accounts' => [$this->accountIdByCode('1202')]]);
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => 'inventory', 'accounts' => [$this->accountIdByCode('1201')]]);
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => 'inventory', 'accounts' => [987654]]);
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => 'inventory', 'accounts' => ['abc']]);
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => 'inventory', 'accounts' => 'not a list']);
    }

    public function testSave_RejectsAnAccountChosenTogetherWithAnAccountAboveItBecauseItWouldBeCountedTwice(): void
    {
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => 'inventory', 'accounts' => [$this->accountIdByCode('12'), $this->accountIdByCode('1203')]]);

        $this->saveRow(['key' => 'inventory', 'accounts' => [$this->accountIdByCode('1203'), $this->accountIdByCode('2211')]]);

        $this->assertCount(2, $this->line('inventory')->accountIds, 'Two accounts that are not one above the other are kept');
    }

    public function testSave_RejectsAccountsOfTheNetIncomeWhichTakesFromEveryRevenueAndExpenseAccount(): void
    {
        $this->assertRefused(AccountingError::CashFlowAccountInvalid, ['key' => 'net_income', 'accounts' => [$this->accountIdByCode('4201')]]);

        $this->saveRow(['key' => 'net_income', 'title' => 'Result', 'visible' => '0']);

        $this->assertSame(['Result', false], [$this->line('net_income')->title, $this->line('net_income')->visible]);
    }

    public function testSave_RejectsDeletingALineTheReportStartsWith(): void
    {
        $this->assertRefused(AccountingError::CashFlowLineInvalid, ['key' => 'inventory', 'remove' => '1']);
    }

    public function testSave_RejectsMoreAddedLinesThanTheReportTakes(): void
    {
        $loans = $this->customAccount('2', '2999', 'Loans');
        $rows  = [];

        for ($index = 1; $index <= CashFlowSettings::MAX_CUSTOM_LINES + 1; $index++) {
            $rows[] = ['key' => '', 'title' => "Line {$index}", 'accounts' => [$loans]];
        }

        try {
            $this->settings->save(['lines' => array_slice($rows, 0, CashFlowSettings::MAX_CUSTOM_LINES)], $this->manager);
            $this->assertCount(8 + CashFlowSettings::MAX_CUSTOM_LINES, $this->settings->lines(), 'The most that the report takes are kept');

            $this->settings->save(['lines' => [$rows[CashFlowSettings::MAX_CUSTOM_LINES]]], $this->manager);
            $this->fail('A line over the limit was added.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CashFlowLineInvalid, $refusal->error);
            $this->assertSame(CashFlowSettings::MAX_CUSTOM_LINES, $refusal->context['max']);
        }

        $this->assertCount(8 + CashFlowSettings::MAX_CUSTOM_LINES, $this->settings->lines());
    }

    public function testSave_RejectsLinesThatAreNotAListOfSetsOfValues(): void
    {
        foreach ([['lines' => 'x'], ['lines' => ['x']]] as $input) {
            try {
                $this->settings->save($input, $this->manager);
                $this->fail('Lines that are not a list of sets were saved.');
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::CashFlowLineInvalid, $refusal->error);
            }
        }
    }

    public function testSave_KeepsNothingOfARequestThatIsRefusedPartWay(): void
    {
        try {
            $this->settings->save(['lines' => [['key' => 'inventory', 'title' => 'Stock'], ['key' => 'nonsense']]], $this->manager);
            $this->fail('A request with a line that is not valid was saved.');
        } catch (AccountingException) {
            $this->assertSame('', $this->line('inventory')->title, 'The line before the one that was refused is not kept either');
        }
    }
}
