<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\ProductBalanceReport;
use CodeIgniter\Events\Events;
use Tests\Support\FamilyReportTestCase;
use Tests\Support\LegacyFamilyReports\LegacyProductBalanceReport;
use Tests\Support\StockHistoryFixtureTrait;

/**
 * For each item what it held before a period, what came in, what went out and what it holds after, in
 * quantity and in value, the value after beside the accounts of the stock locations.
 *
 * @internal
 */
final class ProductBalanceReportTest extends FamilyReportTestCase
{
    use StockHistoryFixtureTrait;

    private ProductBalanceReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyShelves();

        $this->report = new ProductBalanceReport();
    }

    protected function tearDown(): void
    {
        $this->restoreShelves();

        parent::tearDown();
    }

    /**
     * Ten worth 100 held before the period, four worth 40 in during it, three worth 30 out.
     */
    private function cream(): int
    {
        $item = $this->named('Cream', 'Care');

        $this->bookStock($this->ago(20), 1, $item, '10', '100.00');
        $this->bookStock($this->ago(5), 1, $item, '4', '40.00');
        $this->bookStock($this->ago(3), 1, $item, '-3', '-30.00');

        return $item;
    }

    private function period(): array
    {
        return ['from' => $this->ago(10), 'to' => $this->today()];
    }

    public function testBuild_ComputesBeforeInOutAndNetFromTheMovementsAndCarriesTheBalanceBeforeTheStart(): void
    {
        $this->cream();

        $table = $this->built($this->report, $this->period());
        $row   = $this->rowWhere($table, 'name', 'Cream');

        $this->assertNotNull($row);
        $this->assertAmount('10', $row->cell('before_quantity'));
        $this->assertAmount('100.00', $row->cell('before_value'));
        $this->assertAmount('4', $row->cell('in_quantity'));
        $this->assertAmount('40.00', $row->cell('in_value'));
        $this->assertAmount('3', $row->cell('out_quantity'));
        $this->assertAmount('30.00', $row->cell('out_value'));
        $this->assertAmount('11', $row->cell('net_quantity'));
        $this->assertAmount('110.00', $row->cell('net_value'));
    }

    public function testBuild_TiesTheValueAfterToTheAccountsOfTheLocationsForNowAndForAPastDay(): void
    {
        $this->cream();

        $this->assertReportTies($this->built($this->report, $this->period()));
        $this->assertReportTies($this->built($this->report, ['from' => $this->ago(30), 'to' => $this->ago(4)]));
        $this->assertAmount('140.00', $this->grandOf($this->built($this->report, ['from' => $this->ago(30), 'to' => $this->ago(4)]), 'net_value'));
    }

    public function testBuild_SaysSoWhenTheAccountOfALocationHoldsWhatNoMovementDoes(): void
    {
        $this->cream();
        $this->manualToStock('25.00', 1, 1, $this->ago(2));

        $table = $this->built($this->report, $this->period());

        $this->assertFalse($table->ties());
        $this->assertAmount('-25.00', $table->ties[0]->difference());
    }

    public function testBuild_HidesAnItemWithNothingUnlessAskedAndCutsDownToACategory(): void
    {
        $this->cream();
        $soap = $this->named('Soap', 'Home');

        $this->bookStock($this->ago(2), 1, $soap, '2', '20.00');

        $this->assertSame(['Cream', 'Soap'], $this->columnOf($this->built($this->report, $this->period()), 'name'));
        $this->assertSame(['Soap'], $this->columnOf($this->built($this->report, [...$this->period(), 'category' => 'Home']), 'name'));
        $this->assertSame([], $this->built($this->report, [...$this->period(), 'category' => 'Home'])->ties);
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $this->cream();

        $branch = $this->createBranch('PB2', 'Balance branch');
        $depot  = $this->locationOfBranch($branch, 'Balance depot');
        $other  = $this->named('Depot item');

        $this->bookStock($this->ago(2), $branch, $other, '6', '60.00', $depot);

        $limited = $this->built($this->report, $this->period(), $this->limitedTo($branch));

        $this->assertSame(['Depot item'], $this->columnOf($limited, 'name'));
        $this->assertAmount('60.00', $this->grandOf($limited, 'net_value'));
        $this->assertReportTies($limited);
    }

    /**
     * @return list<string>
     */
    private function queriesOfBuild(string $to, ?array $branches = null): array
    {
        $queries  = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = (string) $query->getQuery();
        };

        Events::on('DBQuery', $listener);

        try {
            $this->built($this->report, ['from' => $this->ago(10), 'to' => $to], $branches === null ? null : $this->limitedTo(...$branches));
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $queries;
    }

    public function testBuild_ReadsTheStockAccountsThroughTheReconciliationOfTheLedgerForTheWholeCompanyNowAndNotOtherwise(): void
    {
        $this->cream();
        $this->createBranch('PB2', 'Second balance branch');

        $reconciles = static fn (array $queries): bool => array_filter($queries, static fn (string $query): bool => str_contains($query, 'SUM(value), 0) AS value') && str_contains($query, 'accounting_stock_entries')) !== [];

        $this->assertTrue($reconciles($this->queriesOfBuild($this->today())), 'The whole company now');
        $this->assertFalse($reconciles($this->queriesOfBuild($this->today(), [1])), 'A branch of the company');
        $this->assertFalse($reconciles($this->queriesOfBuild($this->ago(2))), 'A day of the past');
    }

    public function testBuild_GivesTheTableTheReportGaveWhenItReadEveryMovementAtOnce(): void
    {
        $history = $this->stockHistory();
        $legacy  = new LegacyProductBalanceReport();
        $periods = [
            'default' => [],
            'whole'   => ['from' => $history['from'], 'to' => $history['to']],
            'month'   => ['from' => $this->ago(30), 'to' => $history['to']],
            'middle'  => ['from' => $this->ago(50), 'to' => $this->ago(10)],
            'empty'   => ['from' => $this->ago(300), 'to' => $this->ago(200)],
            'day'     => $this->thisDay(),
        ];
        $filters = [
            'none'     => [],
            'location' => ['location_id' => (string) $history['depot']],
            'item'     => ['item_id' => (string) $history['items']['soap']],
            'category' => ['category' => 'Care'],
            'all rows' => ['empty' => '1'],
        ];

        foreach ($periods as $periodName => $period) {
            foreach ($filters as $filterName => $filter) {
                $input = $this->input([...$period, ...$filter]);

                $this->assertEquals($legacy->build($input), $this->report->build($input), "{$periodName}, {$filterName}");
            }
        }

        $this->assertGreaterThan(3, $legacy->build($this->input($periods['whole']))->rowCount());
    }
}
