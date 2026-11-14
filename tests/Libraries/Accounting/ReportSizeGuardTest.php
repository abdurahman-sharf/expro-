<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\ReportSizeGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * What a report may hold on a screen: what the memory of the request leaves at the cost of a row, or what a
 * scan of rows may take, and the words that refuse a report that holds more.
 *
 * @internal
 */
final class ReportSizeGuardTest extends CIUnitTestCase
{
    public function testMemoryRows_LeavesWhatTheMemoryHoldsAfterTheBaseAtTheCostOfARow(): void
    {
        $this->assertSame(intdiv(256 * 1_048_576 - ReportSizeGuard::BASE_BYTES, 3000), ReportSizeGuard::memoryRows(3000, 256 * 1_048_576));
    }

    public function testMemoryRows_AllowsMoreRowsWithMoreMemoryAndNeverFewerThanTheFloor(): void
    {
        $small = ReportSizeGuard::memoryRows(3000, 128 * 1_048_576);
        $large = ReportSizeGuard::memoryRows(3000, 512 * 1_048_576);

        $this->assertGreaterThan($small, $large);
        $this->assertSame(ReportSizeGuard::MIN_ROWS, ReportSizeGuard::memoryRows(3000, 1_048_576));
        $this->assertSame(PHP_INT_MAX, ReportSizeGuard::memoryRows(3000, PHP_INT_MAX));
    }

    public function testScreenRows_IsTheScanLimitForAReportThatDoesNotHoldItsRows(): void
    {
        $this->assertSame(ReportSizeGuard::SCAN_ROWS, ReportSizeGuard::screenRows());
        $this->assertSame(ReportSizeGuard::SCAN_ROWS, ReportSizeGuard::screenRows(0, 64 * 1_048_576));
        $this->assertLessThan(ReportSizeGuard::SCAN_ROWS, ReportSizeGuard::screenRows(3000, 128 * 1_048_576));
    }

    public function testMemoryLimit_ReadsTheSettingOfTheRequest(): void
    {
        $before = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '300M');
            $this->assertSame(300 * 1_048_576, ReportSizeGuard::memoryLimit());

            ini_set('memory_limit', '-1');
            $this->assertSame(PHP_INT_MAX, ReportSizeGuard::memoryLimit());
        } finally {
            ini_set('memory_limit', (string) $before);
        }
    }

    public function testRequire_AcceptsTheRowsOfTheLimitAndRefusesMoreByNameWithTheCountAndTheLimit(): void
    {
        ReportSizeGuard::require(1000, 1000);

        try {
            ReportSizeGuard::require(1001, 1000);
            $this->fail('A report of more rows than the limit was accepted.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::ReportRowsExceedScreen, $refusal->error);
            $this->assertSame(['count' => 1001, 'max' => 1000], $refusal->context);
            $this->assertStringContainsString('1001', AccountingMessage::of($refusal));
            $this->assertStringContainsString('1000', AccountingMessage::of($refusal));
            $this->assertStringContainsString('CSV', AccountingMessage::of($refusal));
        }
    }

    public function testRequire_RefusesInMemoryWithoutSendingToTheCsvFileWhenTheFileHoldsTheRowsToo(): void
    {
        try {
            ReportSizeGuard::require(5000, 1000, AccountingError::ReportRowsExceedMemory);
            $this->fail('A report of more rows than the limit was accepted.');
        } catch (AccountingException $refusal) {
            $message = AccountingMessage::of($refusal);

            $this->assertStringContainsString('5000', $message);
            $this->assertStringNotContainsString('CSV', $message);
        }
    }
}
