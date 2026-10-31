<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AgedLedgerRow;
use App\Libraries\Accounting\AgedLedgerTable;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The rows of an aged ledger and the totals of every bucket.
 */
class AgedLedgerTableTest extends CIUnitTestCase
{
    /**
     * @param list<numeric-string> $buckets
     */
    private function row(int $id, array $buckets, string $credit = '0.0000'): AgedLedgerRow
    {
        return new AgedLedgerRow($id, 'Party ' . $id, 100 + $id, $buckets, $credit);
    }

    private function table(): AgedLedgerTable
    {
        return new AgedLedgerTable('2026-10-30', 30, [
            $this->row(1, ['50.0000', '120.0000', '0.0000', '180.0000', '0.0000', '1.5000'], '10.0000'),
            $this->row(2, ['0.0000', '30.0000', '5.2500', '0.0000', '7.0000', '0.0000'], '2.2500'),
        ]);
    }

    public function testBucket_AddsTheBucketOverTheRows(): void
    {
        $table = $this->table();

        $this->assertSame('50.0000', $table->bucket(0));
        $this->assertSame('150.0000', $table->bucket(1));
        $this->assertSame('5.2500', $table->bucket(2));
        $this->assertSame('180.0000', $table->bucket(3));
        $this->assertSame('7.0000', $table->bucket(4));
        $this->assertSame('1.5000', $table->bucket(5));
    }

    public function testTotal_IsWhatTheBucketsHoldAndTheTotalsOfTheBucketsAddUpToIt(): void
    {
        $table = $this->table();
        $sum   = '0';

        foreach (range(0, 5) as $index) {
            $sum = bcadd($sum, $table->bucket($index), 4);
        }

        $this->assertSame('393.7500', $table->total());
        $this->assertSame($table->total(), bcadd($sum, '0', 4));
    }

    public function testBalance_IsWhatIsOwedLessTheCreditNotApplied(): void
    {
        $table = $this->table();

        $this->assertSame('12.2500', $table->credit());
        $this->assertSame('381.5000', $table->balance());
        $this->assertSame($table->balance(), bcsub($table->total(), $table->credit(), 4));
    }

    public function testTotals_AreNothingForAnAgedLedgerWithNoRow(): void
    {
        $table = new AgedLedgerTable('2026-10-30', 30, []);

        $this->assertSame('0.0000', $table->total());
        $this->assertSame('0.0000', $table->credit());
        $this->assertSame('0.0000', $table->balance());
        $this->assertSame('0.0000', $table->bucket(3));
    }
}
