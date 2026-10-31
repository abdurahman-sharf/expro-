<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\BalanceRow;
use App\Libraries\Accounting\BalanceTable;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The rows of a balance report and the totals of their columns.
 */
class BalanceTableTest extends CIUnitTestCase
{
    private function row(int $id, string $before, string $sales, string $returns, string $payments, string $settlements, string $other, string $balance): BalanceRow
    {
        return new BalanceRow($id, 'Party ' . $id, 100 + $id, $before, $sales, $returns, $payments, $settlements, $other, $balance);
    }

    public function testNet_IsWhatWasSoldLessWhatCameBack(): void
    {
        $this->assertSame('1563.5000', $this->row(1, '0.0000', '2541.5000', '978.0000', '0.0000', '0.0000', '0.0000', '0.0000')->net());
    }

    public function testAdds_IsTrueWhenBeforePlusNetLessPaymentsPlusSettlementsPlusOtherIsTheBalance(): void
    {
        $this->assertTrue($this->row(1, '0.0000', '2541.5000', '0.0000', '1563.5000', '-253.0000', '0.0000', '725.0000')->adds());
        $this->assertTrue($this->row(2, '100.0000', '50.0000', '10.0000', '40.0000', '-5.0000', '25.0000', '120.0000')->adds());
        $this->assertFalse($this->row(3, '0.0000', '100.0000', '0.0000', '40.0000', '0.0000', '0.0000', '70.0000')->adds());
    }

    public function testIsEmpty_IsTrueOnlyWhenNothingMovedAndNothingIsHeld(): void
    {
        $this->assertTrue($this->row(1, '0.0000', '0.0000', '0.0000', '0.0000', '0.0000', '0.0000', '0.0000')->isEmpty());
        $this->assertFalse($this->row(2, '0.0000', '0.0000', '0.0000', '0.0000', '0.0000', '0.0000', '5.0000')->isEmpty());
        $this->assertFalse($this->row(3, '0.0000', '0.0000', '0.0000', '9.0000', '0.0000', '0.0000', '0.0000')->isEmpty());
    }

    public function testTotal_AddsTheColumnOverTheRowsAndTheNetColumnIsTheNetOfTheTotals(): void
    {
        $table = new BalanceTable('2026-10-01', '2026-10-27', [
            $this->row(1, '0.0000', '2541.5000', '0.0000', '1563.5000', '-253.0000', '0.0000', '725.0000'),
            $this->row(2, '10.0000', '100.0000', '25.0000', '60.0000', '0.0000', '5.0000', '30.0000'),
        ]);

        $this->assertSame('10.0000', $table->total('before'));
        $this->assertSame('2641.5000', $table->total('sales'));
        $this->assertSame('25.0000', $table->total('returns'));
        $this->assertSame('2616.5000', $table->total('net_sales'));
        $this->assertSame('1623.5000', $table->total('payments'));
        $this->assertSame('-253.0000', $table->total('settlements'));
        $this->assertSame('5.0000', $table->total('other'));
        $this->assertSame('755.0000', $table->total('balance'));
        $this->assertTrue($table->hasOther());
    }

    public function testHasOther_IsFalseWhenEveryColumnExplainsItsBalance(): void
    {
        $table = new BalanceTable('', '2026-10-27', [$this->row(1, '0.0000', '10.0000', '0.0000', '0.0000', '0.0000', '0.0000', '10.0000')]);

        $this->assertFalse($table->hasOther());
        $this->assertSame('0.0000', (new BalanceTable('', '2026-10-27', []))->total('balance'));
    }
}
