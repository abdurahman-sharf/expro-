<?php

namespace Tests\Database;

use App\Database\Migrations\YearEndClosings;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class YearEndClosingsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
    }

    protected function tearDown(): void
    {
        (new YearEndClosings())->up();
        $this->clearJournals();

        parent::tearDown();
    }

    private function hasTable(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable('accounting_year_end_closings')],
        )->getRow()->found > 0;
    }

    private function permission(): ?object
    {
        return $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::YEAR_END_CLOSE)->get()->getRow();
    }

    private function rejects(callable $action): bool
    {
        try {
            $action();
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function closing(array $changes = []): array
    {
        return $changes + [
            'company_id'          => 1,
            'branch_id'           => 1,
            'fiscal_year_id'      => (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2025')->get()->getRow()->fiscal_year_id,
            'revision'            => 1,
            'period_from'         => '2025-01-01',
            'period_to'           => '2025-12-31',
            'retained_account_id' => $this->accountId('retained_earnings'),
            'result_amount'       => '10.0000',
            'fingerprint'         => str_repeat('a', 64),
            'status'              => 'active',
            'active_key'          => 1,
        ];
    }

    private function inserts(array $row): bool
    {
        return ! $this->rejects(fn () => $this->accountingDb()->table('accounting_year_end_closings')->insert($row));
    }

    public function testUp_CreatesTheTableOfClosings(): void
    {
        $this->assertTrue($this->hasTable());
    }

    public function testUp_RegistersThePermissionToCloseTheYearUnderThePeriodsScreenAndGrantsItToNobody(): void
    {
        $permission = $this->permission();

        $this->assertNotNull($permission);
        $this->assertSame('accounting_periods', $permission->module_id);
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::YEAR_END_CLOSE)->countAllResults());
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new YearEndClosings())->up();
        (new YearEndClosings())->up();

        $this->assertTrue($this->hasTable());
        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::YEAR_END_CLOSE)->countAllResults());
    }

    public function testUp_TouchesNoJournalAndNoClosedPeriod(): void
    {
        (new JournalService())->postNew(['date' => '2025-05-10'] + $this->journalInput());
        $this->closeThrough('2025-12-31');
        $journals = $this->countRows('accounting_journals');
        $periods  = $this->countRows('accounting_closed_periods');

        (new YearEndClosings())->down();
        (new YearEndClosings())->up();

        $this->assertSame([$journals, $periods], [$this->countRows('accounting_journals'), $this->countRows('accounting_closed_periods')]);
        $this->assertSame(0, $this->countRows('accounting_year_end_closings'));
    }

    public function testDown_RemovesTheTableThePermissionAndItsGrants(): void
    {
        $employee = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);

        (new YearEndClosings())->down();

        $this->assertFalse($this->hasTable());
        $this->assertNull($this->permission());
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('person_id', $employee)->where('permission_id', AccountingGuard::YEAR_END_CLOSE)->countAllResults());
    }

    public function testTable_AllowsOneActiveClosingForABranchAndAYear(): void
    {
        $this->assertTrue($this->inserts($this->closing()));
        $this->assertFalse($this->inserts($this->closing(['revision' => 2])), 'A second active closing of the same branch and year is refused by the key');
    }

    public function testTable_AllowsAnyNumberOfClosingsThatWereTakenBack(): void
    {
        $reversed = ['status' => 'reversed', 'active_key' => null];

        $this->assertTrue($this->inserts($this->closing($reversed)));
        $this->assertTrue($this->inserts($this->closing($reversed + ['revision' => 2])));
        $this->assertTrue($this->inserts($this->closing(['revision' => 3])), 'A year can be closed again after it was taken back');
        $this->assertFalse($this->inserts($this->closing(['revision' => 4])));
    }

    public function testTable_KeepsTheClosingsOfOtherBranchesAndYearsApart(): void
    {
        $second = $this->createBranch('YE2');
        $other  = (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2024')->get()->getRow()->fiscal_year_id;

        $this->assertTrue($this->inserts($this->closing()));
        $this->assertTrue($this->inserts($this->closing(['branch_id' => $second])));
        $this->assertTrue($this->inserts($this->closing(['fiscal_year_id' => $other])));
    }

    public function testTable_RefusesTwoClosingsWithTheSameRevision(): void
    {
        $reversed = ['status' => 'reversed', 'active_key' => null];

        $this->assertTrue($this->inserts($this->closing($reversed)));
        $this->assertFalse($this->inserts($this->closing($reversed)));
    }

    public function testTable_RefusesAnActiveClosingWithoutItsKeyAndAReversedOneWithIt(): void
    {
        $this->assertFalse($this->inserts($this->closing(['active_key' => null])));
        $this->assertFalse($this->inserts($this->closing(['status' => 'reversed', 'active_key' => 1])));
        $this->assertFalse($this->inserts($this->closing(['status' => 'open'])));
    }

    public function testTable_RefusesABranchAYearOrAnAccountThatDoNotExist(): void
    {
        $this->assertFalse($this->inserts($this->closing(['branch_id' => 9999])));
        $this->assertFalse($this->inserts($this->closing(['fiscal_year_id' => 9999])));
        $this->assertFalse($this->inserts($this->closing(['retained_account_id' => 999999])));
    }

    public function testTable_RefusesToLoseTheYearOrTheBranchOfAClosing(): void
    {
        $this->assertTrue($this->inserts($this->closing()));

        $this->assertTrue($this->rejects(fn () => $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2025')->delete()));
        $this->assertTrue($this->rejects(fn () => $this->accountingDb()->table('branches')->where('branch_id', 1)->delete()));
    }
}
