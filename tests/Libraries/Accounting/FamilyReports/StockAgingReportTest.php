<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\StockAgingReport;
use App\Libraries\Accounting\FamilyReports\StockEntries;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\StockLedger;
use Tests\Support\FamilyReportTestCase;
use Tests\Support\LegacyFamilyReports\LegacyStockAgingReport;
use Tests\Support\StockHistoryFixtureTrait;

/**
 * How long the stock on hand has been there: the quantity in four fixed stretches of age, with what
 * went out taking the oldest quantity in, a reversed document taking back what it brought in, stock sold
 * before it was bought used up first, and the average age.
 *
 * @internal
 */
final class StockAgingReportTest extends FamilyReportTestCase
{
    use StockHistoryFixtureTrait;

    private StockAgingReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyShelves();

        $this->report = new StockAgingReport();
    }

    protected function tearDown(): void
    {
        $this->restoreShelves();

        parent::tearDown();
    }

    /**
     * Two bought 100 days ago, four 40 days ago, three 5 days ago, and two sold yesterday.
     */
    private function lots(): int
    {
        $item = $this->named('Cream');

        $this->bookStock($this->ago(100), 1, $item, '2', '20.00');
        $this->bookStock($this->ago(40), 1, $item, '4', '40.00');
        $this->bookStock($this->ago(5), 1, $item, '3', '30.00');
        $this->bookStock($this->ago(1), 1, $item, '-2', '-20.00');

        return $item;
    }

    public function testBuild_PlacesTheRemainingQuantityInFixedBucketsByTheDateItWasReceived(): void
    {
        $this->lots();

        $table = $this->built($this->report);
        $row   = $this->rowWhere($table, 'name', 'Cream');

        $this->assertNotNull($row);
        $this->assertAmount('7', $row->cell('available'));
        $this->assertAmount('3', $row->cell('b0'));
        $this->assertAmount('4', $row->cell('b1'));
        $this->assertAmount('0', $row->cell('b2'));
        $this->assertAmount('0', $row->cell('b3'));
        $this->assertSame('25.0', $row->cell('age'));
    }

    public function testBuild_ReadsTheStockOfAPastDayAndKeepsTheOldQuantityInTheOldBucket(): void
    {
        $this->lots();

        $row = $this->rowWhere($this->built($this->report, ['to' => $this->ago(20)]), 'name', 'Cream');

        $this->assertAmount('2', $row->cell('b2'));
        $this->assertAmount('4', $row->cell('b0'));
        $this->assertAmount('6', $row->cell('available'));
    }

    public function testBuild_UsesUpWhatWasSoldBeforeItWasBoughtAndGivesNoneToANegativeItem(): void
    {
        $short = $this->named('Short');
        $below = $this->named('Below');

        $this->bookStock($this->ago(10), 1, $short, '-2', '-20.00');
        $this->bookStock($this->ago(3), 1, $short, '5', '50.00');
        $this->bookStock($this->ago(3), 1, $below, '-4', '-40.00');

        $table = $this->built($this->report);

        $this->assertAmount('3', $this->rowWhere($table, 'name', 'Short')->cell('available'));
        $this->assertSame('3.0', $this->rowWhere($table, 'name', 'Short')->cell('age'));
        $this->assertAmount('0', $this->rowWhere($table, 'name', 'Below')->cell('available'));
        $this->assertSame('0.0', $this->rowWhere($table, 'name', 'Below')->cell('age'));
        $this->assertReportTies($table);
    }

    public function testBuild_TakesBackWhatAReversedDocumentBroughtIn(): void
    {
        $item = $this->named('Taken back');

        $this->bookStock($this->ago(5), 1, $item, '4', '40.00');
        (new StockLedger())->reverseSource('purchase', $this->lastBookedSource(), JournalEntryType::General, $this->today(), null);

        $table = $this->built($this->report);

        $this->assertAmount('0', $this->rowWhere($table, 'name', 'Taken back')->cell('available'));
        $this->assertReportTies($table);
    }

    public function testBuild_TakesBackTheQuantityOfTheReversedDocumentAndNotTheOldestQuantity(): void
    {
        $item = $this->named('Newer lot reversed');

        $this->bookStock($this->ago(40), 1, $item, '5', '50.00');
        $this->bookStock($this->ago(3), 1, $item, '3', '30.00');
        (new StockLedger())->reverseSource('purchase', $this->lastBookedSource(), JournalEntryType::General, $this->today(), null);

        $row = $this->rowWhere($this->built($this->report), 'name', 'Newer lot reversed');

        $this->assertAmount('5', $row->cell('available'));
        $this->assertAmount('5', $row->cell('b1'));
        $this->assertAmount('0', $row->cell('b0'));
    }

    public function testBuild_GivesTheTotalAsThePlainAverageOfTheItemsThatHoldStock(): void
    {
        $this->lots();

        $short = $this->named('Short');
        $this->bookStock($this->ago(3), 1, $short, '5', '50.00');

        $table = $this->built($this->report);

        $this->assertSame('14.0', $this->grandOf($table, 'age'));
        $this->assertReportTies($table);
    }

    public function testBuild_SeparatesTheLocationsSearchesAndReadsOnlyTheBranchesOfTheScope(): void
    {
        $item   = $this->lots();
        $branch = $this->createBranch('AG3', 'Stock aging branch');
        $depot  = $this->locationOfBranch($branch, 'Aging depot');

        $this->bookStock($this->ago(2), $branch, $item, '5', '50.00', $depot);

        $joined = $this->built($this->report);
        $split  = $this->built($this->report, ['split' => '1']);
        $found  = $this->built($this->report, ['search' => 'zzz']);
        $depots = $this->built($this->report, [], $this->limitedTo($branch));

        $this->assertCount(1, $this->rowsOf($joined));
        $this->assertCount(2, $this->rowsOf($split));
        $this->assertSame([], $this->rowsOf($found));
        $this->assertAmount('5', $this->grandOf($depots, 'available'));
    }

    public function testBuild_GivesTheTableTheReportGaveWhenItReadEveryMovementAtOnceWhateverTheSizeOfTheChunks(): void
    {
        $history = $this->stockHistory();
        $legacy  = new LegacyStockAgingReport();
        $queries = [
            'today'    => [],
            'ten days' => ['to' => $this->ago(10)],
            'long ago' => ['to' => $this->ago(60)],
            'split'    => ['split' => '1'],
            'search'   => ['search' => 'SOAP'],
            'item'     => ['item_id' => (string) $history['items']['soap']],
            'category' => ['category' => 'Care'],
            'depot'    => ['location_id' => (string) $history['depot'], 'split' => '1'],
        ];

        foreach ([1, 2, 3, StockEntries::CHUNK] as $chunk) {
            $report = new StockAgingReport(new StockEntries(null, $chunk));

            foreach ($queries as $name => $query) {
                $input = $this->input($query);
                $old   = $legacy->build($input);

                $this->assertEquals($old, $report->build($input), "chunks of {$chunk}, {$name}");
            }
        }

        $this->assertGreaterThan(3, $legacy->build($this->input(['split' => '1']))->rowCount());
    }
}
