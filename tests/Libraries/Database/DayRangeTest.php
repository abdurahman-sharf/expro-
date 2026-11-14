<?php

namespace Tests\Libraries\Database;

use App\Libraries\Database\DayRange;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The range on the raw column keeps the rows DATE() of the column keeps, at the edges of a day and in the
 * time zones whose clocks move at midnight, and is the form the index of the column can serve.
 *
 * @internal
 */
final class DayRangeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const TABLE = 'day_range_probe_temp';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private string $zone = '';

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db         = Database::connect();
        $this->zone = (string) $db->query('SELECT @@session.time_zone AS zone')->getRow()->zone;

        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->probe());
        $db->query('CREATE TEMPORARY TABLE ' . $this->probe() . ' (id INT AUTO_INCREMENT PRIMARY KEY, moment TIMESTAMP NOT NULL, KEY (moment))');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->query('SET time_zone = ' . $db->escape($this->zone));
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->probe());

        parent::tearDown();
    }

    private function probe(): string
    {
        return Database::connect()->prefixTable(self::TABLE);
    }

    /**
     * @param list<string> $instantsInUtc
     */
    private function store(array $instantsInUtc): void
    {
        $db = Database::connect();

        $db->query("SET time_zone = '+00:00'");

        foreach ($instantsInUtc as $instant) {
            $db->query('INSERT INTO ' . $this->probe() . ' (moment) VALUES (?)', [$instant]);
        }
    }

    /**
     * @return list<int>
     */
    private function keptBy(string $condition): array
    {
        return array_map('intval', array_column(Database::connect()->query('SELECT id FROM ' . $this->probe() . ' WHERE ' . $condition . ' ORDER BY id')->getResultArray(), 'id'));
    }

    /**
     * @return array{0: list<int>, 1: list<int>} What DATE() keeps and what the range keeps.
     */
    private function bothKeep(string $firstDay, string $lastDay): array
    {
        $db = Database::connect();

        return [
            $this->keptBy('DATE(moment) BETWEEN ' . $db->escape($firstDay) . ' AND ' . $db->escape($lastDay)),
            $this->keptBy(DayRange::condition($db, 'moment', $firstDay, $lastDay)),
        ];
    }

    public function testCondition_KeepsTheFirstAndTheLastSecondOfTheDaysAndNothingOutsideThem(): void
    {
        $db = Database::connect();

        $this->store(['2026-10-19 23:59:59', '2026-10-20 00:00:00', '2026-10-20 12:00:00', '2026-10-20 23:59:59', '2026-10-21 00:00:00', '2026-10-21 23:59:59', '2026-10-22 00:00:00']);
        $db->query("SET time_zone = '+00:00'");

        $this->assertSame([2, 3, 4], $this->keptBy(DayRange::condition($db, 'moment', '2026-10-20', '2026-10-20')));
        $this->assertSame([2, 3, 4, 5, 6], $this->keptBy(DayRange::condition($db, 'moment', '2026-10-20', '2026-10-21')));
        $this->assertSame([], $this->keptBy(DayRange::condition($db, 'moment', '2026-10-21', '2026-10-20')));
        $this->assertSame($this->bothKeep('2026-10-20', '2026-10-21')[0], $this->bothKeep('2026-10-20', '2026-10-21')[1]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function zonesWhoseClocksMove(): array
    {
        return [
            'New York, the hour of the spring is skipped'     => ['America/New_York', '2026-03-08 03:00:00', '2026-03-07', '2026-03-09'],
            'New York, the hour of the autumn is lived twice' => ['America/New_York', '2026-11-01 04:00:00', '2026-10-31', '2026-11-02'],
            'Cairo, midnight of the spring is skipped'        => ['Africa/Cairo', '2024-04-25 20:00:00', '2024-04-24', '2024-04-27'],
            'Cairo, the last hour of the autumn is lived twice' => ['Africa/Cairo', '2024-10-24 19:00:00', '2024-10-23', '2024-10-26'],
            'Beirut, midnight of the spring is skipped'       => ['Asia/Beirut', '2024-03-30 20:00:00', '2024-03-29', '2024-04-01'],
            'Beirut, the last hour of the autumn is lived twice' => ['Asia/Beirut', '2024-10-26 19:00:00', '2024-10-25', '2024-10-28'],
            'Riyadh, whose clocks never move'                 => ['Asia/Riyadh', '2024-06-01 00:00:00', '2024-05-31', '2024-06-02'],
        ];
    }

    #[DataProvider('zonesWhoseClocksMove')]
    public function testCondition_KeepsTheRowsOfEveryDayDATEKeepsInAZoneWhoseClocksMove(string $zone, string $from, string $firstDay, string $lastDay): void
    {
        $instants = [];

        for ($minutes = 0; $minutes <= 4 * 24 * 60; $minutes += 15) {
            $instants[] = gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + $minutes * 60 - 24 * 3600);
        }

        $this->store($instants);
        Database::connect()->query('SET time_zone = ' . Database::connect()->escape($zone));

        $day = $firstDay;

        while ($day <= $lastDay) {
            [$byDate, $byRange] = $this->bothKeep($day, $day);

            $this->assertNotSame([], $byDate, $zone . ' ' . $day);
            $this->assertSame($byDate, $byRange, $zone . ' ' . $day);

            $day = gmdate('Y-m-d', strtotime($day . ' UTC') + 86400);
        }

        [$byDate, $byRange] = $this->bothKeep($firstDay, $lastDay);

        $this->assertSame($byDate, $byRange, $zone . ' across the days');
    }

    public function testCondition_KeepsTheFormOfDATEWhenABoundIsNotADay(): void
    {
        $db = Database::connect();

        foreach ([['2026-10-20 10:00:00', '2026-10-21'], ['2026-10-20', '2026-10-21 10:00:00'], ['20-10-2026', '2026-10-21'], ['2026-2-3', '2026-10-21'], ['2026-02-30', '2026-10-21'], ['', '']] as [$first, $last]) {
            $this->assertStringStartsWith('DATE(moment) BETWEEN', DayRange::condition($db, 'moment', $first, $last));
        }
    }

    public function testCondition_ReadsTheEndOfTheCalendarWithoutOverflowing(): void
    {
        $db = Database::connect();

        $this->store(['2026-10-20 12:00:00', '2037-12-31 23:59:59']);
        $db->query("SET time_zone = '+00:00'");

        $this->assertSame([1, 2], $this->keptBy(DayRange::condition($db, 'moment', '2026-01-01', '2099-12-31')));
        $this->assertSame([1, 2], $this->keptBy(DayRange::condition($db, 'moment', '2026-01-01', '9999-12-31')));
    }

    public function testCondition_IsServedByTheIndexOfTheColumnWhileDATEReadsTheWholeTable(): void
    {
        $db = Database::connect();

        $db->query("SET time_zone = '+00:00'");
        $db->query('INSERT INTO ' . $this->probe() . " (moment) SELECT TIMESTAMP('2026-01-01 00:00:00') + INTERVAL (a.n + 10 * b.n + 100 * c.n + 1000 * d.n) * 3600 SECOND FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a, (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b, (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c, (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d");

        $explain = static fn (string $condition): array => $db->query('EXPLAIN SELECT id FROM ' . $db->prefixTable(self::TABLE) . ' WHERE ' . $condition)->getRowArray();

        $byRange = $explain(DayRange::condition($db, 'moment', '2026-03-10', '2026-03-12'));
        $byDate  = $explain("DATE(moment) BETWEEN '2026-03-10' AND '2026-03-12'");

        $this->assertSame('range', $byRange['type']);
        $this->assertLessThan(500, (int) $byRange['rows']);
        $this->assertGreaterThan(5000, (int) $byDate['rows']);
    }
}
