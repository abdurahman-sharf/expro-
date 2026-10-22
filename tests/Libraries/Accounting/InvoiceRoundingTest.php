<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\InvoiceRounding;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InvoiceRoundingTest extends CIUnitTestCase
{
    /**
     * Net, tax, then the total and revenue the reference system stored, from invoices
     * entered there. The first four are the cases this reproduces exactly; in the last two the
     * total fell exactly between two cents and the reference system rounded down.
     *
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function recordedInvoicesProvider(): array
    {
        return [
            'net 0.10'   => ['0.10', '0.015', '0.12', '0.105', true],
            'net 1.30'   => ['1.30', '0.195', '1.50', '1.305', true],
            'net 10.10'  => ['10.10', '1.515', '11.62', '10.105', true],
            'net 20.30'  => ['20.30', '3.045', '23.35', '20.305', true],
            'net 0.70'   => ['0.70', '0.105', '0.80', '0.695', false],
            'net 319.50' => ['319.50', '47.925', '367.42', '319.495', false],
        ];
    }

    #[DataProvider('recordedInvoicesProvider')]
    public function testSplit_StaysWithinOneCentOfTheReferenceSystem(string $net, string $tax, string $total, string $revenue, bool $exact): void
    {
        $split = InvoiceRounding::split($net, $tax);

        $this->assertEqualsWithDelta((float) $total, (float) $split['total'], $exact ? 0.00001 : 0.01001);
        $this->assertEqualsWithDelta((float) $revenue, (float) $split['revenue'], $exact ? 0.00001 : 0.01001);
    }

    #[DataProvider('recordedInvoicesProvider')]
    public function testSplit_KeepsTheTaxUnroundedAndBalancesTheJournal(string $net, string $tax): void
    {
        $split = InvoiceRounding::split($net, $tax);

        $this->assertSame(bcadd($tax, '0', 4), $split['tax']);
        $this->assertSame($split['total'], bcadd($split['revenue'], $split['tax'], 4));
    }

    public function testSplit_RoundsAnExactHalfCentUp(): void
    {
        $this->assertSame('0.8100', InvoiceRounding::split('0.70', '0.105')['total']);
        $this->assertSame('367.4300', InvoiceRounding::split('319.50', '47.925')['total']);
    }

    public function testSplit_LeavesAWholeCentAlone(): void
    {
        $split = InvoiceRounding::split('100.00', '15.00');

        $this->assertSame('115.0000', $split['total']);
        $this->assertSame('100.0000', $split['revenue']);
    }

    public function testRoundToCents_RoundsByTheThirdAndFourthDecimals(): void
    {
        $this->assertSame('11.6100', InvoiceRounding::roundToCents('11.6149'));
        $this->assertSame('11.6200', InvoiceRounding::roundToCents('11.6150'));
        $this->assertSame('0.0000', InvoiceRounding::roundToCents('0.0049'));
    }
}
