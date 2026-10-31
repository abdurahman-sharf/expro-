<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ClosedOutYears;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * Which branches have closed which years: the closings are written straight into their table, so
 * what is read back is judged without the service that makes them.
 *
 * @internal
 */
final class ClosedOutYearsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private ClosedOutYears $years;
    private int $second;

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

        $this->second = $this->createBranch('T2', 'Second branch');
        $this->years  = new ClosedOutYears();
    }

    protected function tearDown(): void
    {
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function yearId(string $name): int
    {
        return (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', $name)->get()->getRow()->fiscal_year_id;
    }

    private function insertClosing(int $branchId, string $year, bool $active = true, int $revision = 1): int
    {
        $row = $this->accountingDb()->table('accounting_fiscal_years')->where('name', $year)->get()->getRow();

        $this->accountingDb()->table('accounting_year_end_closings')->insert([
            'company_id'          => 1,
            'branch_id'           => $branchId,
            'fiscal_year_id'      => (int) $row->fiscal_year_id,
            'revision'            => $revision,
            'period_from'         => $row->start_date,
            'period_to'           => $row->end_date,
            'retained_account_id' => $this->accountIdByCode('32'),
            'fingerprint'         => str_repeat('a', 64),
            'status'              => $active ? 'active' : 'reversed',
            'active_key'          => $active ? 1 : null,
        ]);

        return (int) $this->accountingDb()->insertID();
    }

    /**
     * @param list<object> $closings
     *
     * @return list<array{int, int}> The branch and the year of each, in the order given.
     */
    private function branchesAndYears(array $closings): array
    {
        return array_map(static fn (object $closing): array => [(int) $closing->branch_id, (int) $closing->fiscal_year_id], $closings);
    }

    public function testActiveOf_ReturnsTheActiveClosingOfTheBranchAndYear(): void
    {
        $closingId = $this->insertClosing(1, '2025');

        $found = $this->years->activeOf(1, $this->yearId('2025'));

        $this->assertNotNull($found);
        $this->assertSame($closingId, (int) $found->closing_id);
    }

    public function testActiveOf_IgnoresAnotherBranchAndAnotherYear(): void
    {
        $this->insertClosing(1, '2025');

        $this->assertNull($this->years->activeOf($this->second, $this->yearId('2025')));
        $this->assertNull($this->years->activeOf(1, $this->yearId('2024')));
    }

    public function testActiveOf_IgnoresAClosingThatWasTakenBack(): void
    {
        $this->insertClosing(1, '2025', false);

        $this->assertNull($this->years->activeOf(1, $this->yearId('2025')));
    }

    public function testActiveOf_ReturnsTheActiveOneWhenAnEarlierRevisionWasTakenBack(): void
    {
        $this->insertClosing(1, '2025', false);
        $active = $this->insertClosing(1, '2025', true, 2);

        $found = $this->years->activeOf(1, $this->yearId('2025'));

        $this->assertNotNull($found);
        $this->assertSame($active, (int) $found->closing_id);
    }

    public function testHasActiveIn_ReturnsTrueForAYearOneBranchHasClosed(): void
    {
        $this->insertClosing($this->second, '2025');

        $this->assertTrue($this->years->hasActiveIn($this->yearId('2025')));
    }

    public function testHasActiveIn_ReturnsFalseForAYearWithNoActiveClosing(): void
    {
        $this->insertClosing(1, '2025');
        $this->insertClosing(1, '2024', false);

        $this->assertFalse($this->years->hasActiveIn($this->yearId('2024')));
        $this->assertFalse($this->years->hasActiveIn($this->yearId('2023')));
    }

    public function testCovering_ReturnsTheClosingWithTheNameOfItsYearOnTheFirstAndLastDay(): void
    {
        $closingId = $this->insertClosing(1, '2025');

        foreach (['2025-01-01', '2025-07-15', '2025-12-31'] as $date) {
            $found = $this->years->covering(1, $date);

            $this->assertNotNull($found, $date);
            $this->assertSame($closingId, (int) $found->closing_id, $date);
            $this->assertSame('2025', $found->year_name, $date);
        }
    }

    public function testCovering_IgnoresTheDaysAroundTheYear(): void
    {
        $this->insertClosing(1, '2025');

        $this->assertNull($this->years->covering(1, '2024-12-31'));
        $this->assertNull($this->years->covering(1, '2026-01-01'));
    }

    public function testCovering_IgnoresAnotherBranchAndAClosingThatWasTakenBack(): void
    {
        $this->insertClosing(1, '2025');
        $this->insertClosing($this->second, '2024', false);

        $this->assertNull($this->years->covering($this->second, '2025-06-01'));
        $this->assertNull($this->years->covering($this->second, '2024-06-01'));
    }

    public function testLeftOpenBy_ForTheCompanyListsEveryBranchWhoseYearEndsAfterTheCutoff(): void
    {
        $this->insertClosing(1, '2024');
        $this->insertClosing(1, '2025');
        $this->insertClosing($this->second, '2025');

        $left = $this->years->leftOpenBy(null, '2024-12-31');

        $this->assertSame(
            [[1, $this->yearId('2025')], [$this->second, $this->yearId('2025')]],
            $this->branchesAndYears($left),
        );
        $this->assertSame('2025', $left[0]->year_name);
        $this->assertSame('2025-12-31', $left[0]->period_to);
    }

    public function testLeftOpenBy_LeavesAYearWhoseLastDayIsTheCutoff(): void
    {
        $this->insertClosing(1, '2025');

        $this->assertSame([], $this->years->leftOpenBy(null, '2025-12-31'));
        $this->assertCount(1, $this->years->leftOpenBy(null, '2025-12-30'));
    }

    public function testLeftOpenBy_ListsEveryActiveClosingWhenNothingIsClosedAnymore(): void
    {
        $this->insertClosing(1, '2024');
        $this->insertClosing(1, '2025');
        $this->insertClosing($this->second, '2025', false);

        $this->assertSame(
            [[1, $this->yearId('2024')], [1, $this->yearId('2025')]],
            $this->branchesAndYears($this->years->leftOpenBy(null, null)),
        );
    }

    public function testLeftOpenBy_ForABranchListsOnlyItsOwnClosings(): void
    {
        $this->insertClosing(1, '2025');
        $this->insertClosing($this->second, '2025');

        $this->assertSame(
            [[$this->second, $this->yearId('2025')]],
            $this->branchesAndYears($this->years->leftOpenBy($this->second, null, null)),
        );
    }

    public function testLeftOpenBy_ForABranchTakesTheLaterOfTheTwoCutoffs(): void
    {
        $this->insertClosing(1, '2024');
        $this->insertClosing(1, '2025');

        $this->assertSame(
            [[1, $this->yearId('2025')]],
            $this->branchesAndYears($this->years->leftOpenBy(1, '2024-12-31', '2025-06-30')),
            'the branch closing is the later one',
        );
        $this->assertSame(
            [],
            $this->branchesAndYears($this->years->leftOpenBy(1, '2025-12-31', '2024-06-30')),
            'the company closing is the later one',
        );
        $this->assertCount(2, $this->years->leftOpenBy(1, null, null));
    }

    public function testAssertNotClosedOut_RejectsADateInsideAYearTheBranchClosed(): void
    {
        $this->insertClosing(1, '2025');

        try {
            $this->years->assertNotClosedOut(1, '2025-06-30');
            $this->fail('A date inside a closed out year must be refused.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::YearEndClosedOut, $refusal->error);
            $this->assertSame('2025', $refusal->context['name']);
            $this->assertSame('2025-12-31', $refusal->context['date']);
        }
    }

    public function testAssertNotClosedOut_AcceptsADateOutsideTheYearAndOneOfAnotherBranch(): void
    {
        $this->insertClosing(1, '2025');
        $this->insertClosing($this->second, '2024', false);

        $this->expectNotToPerformAssertions();

        $this->years->assertNotClosedOut(1, '2026-01-01');
        $this->years->assertNotClosedOut($this->second, '2025-06-30');
        $this->years->assertNotClosedOut($this->second, '2024-06-30');
    }

    public function testAssertNotClosedOut_SeesAClosingThatCommittedAfterTheSnapshotOfTheCaller(): void
    {
        $other = $this->openSecondConnection();
        $this->startWithSnapshot($other);
        $this->insertClosing(1, '2025');

        try {
            (new ClosedOutYears($other))->assertNotClosedOut(1, '2025-06-30');
            $this->fail('The closing was written after the snapshot of the caller, and the year is closed out all the same.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::YearEndClosedOut, $refusal->error);
        } finally {
            $other->transRollback();
            $other->close();
        }
    }

    public function testCovering_ReadsWhatTheSnapshotOfTheCallerHoldsUnlessItIsAskedToLock(): void
    {
        $other = $this->openSecondConnection();
        $this->startWithSnapshot($other);
        $this->insertClosing(1, '2025');

        try {
            $this->assertNull((new ClosedOutYears($other))->covering(1, '2025-06-30'));
            $this->assertNotNull((new ClosedOutYears($other))->covering(1, '2025-06-30', true));
        } finally {
            $other->transRollback();
            $other->close();
        }
    }
}
