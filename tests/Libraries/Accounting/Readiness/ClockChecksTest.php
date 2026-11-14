<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\ClockChecks;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The clock of the database against the clock of the shop. The zone of the database session and the zone
 * of the shop are both set by each test, so that the answer does not depend on the machine or the hour the
 * test runs at, and payments are dated by hand the way a database with the wrong clock would have dated them.
 */
final class ClockChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ClockChecks $checks;
    private string $databaseZone;
    private string $processZone;
    private ?string $shopSetting;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->checks       = new ClockChecks($this->accountingDb());
        $this->databaseZone = (string) $this->accountingDb()->query('SELECT @@session.time_zone AS zone')->getRow()->zone;
        $this->processZone  = date_default_timezone_get();
        $stored             = $this->accountingDb()->table('app_config')->select('value')->where('key', 'timezone')->get()->getRow();
        $this->shopSetting  = $stored === null ? null : (string) $stored->value;

        $this->accountingDb()->table('app_config')->where('key', 'timezone')->delete();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        if (! $db->tableExists('ospos_sales_payments', false) && $db->tableExists('ospos_sales_payments_hold', false)) {
            $db->query('RENAME TABLE ospos_sales_payments_hold TO ospos_sales_payments');
        }

        $db->query("SET time_zone = '{$this->databaseZone}'");
        date_default_timezone_set($this->processZone);
        $db->table('app_config')->where('key', 'timezone')->delete();

        if ($this->shopSetting !== null) {
            $this->setConfigValue('timezone', $this->shopSetting);
        }

        $this->stopSelling();

        parent::tearDown();
    }

    private function check(): CheckResult
    {
        foreach ($this->checks->run() as $result) {
            if ($result->id === 'clock_zones') {
                return $result;
            }
        }

        $this->fail('No check clock_zones.');
    }

    private function databaseWorksAt(string $offset): void
    {
        $this->accountingDb()->query("SET time_zone = '{$offset}'");
    }

    private function shopWorksIn(string $zone): void
    {
        $this->setConfigValue('timezone', $zone);
    }

    private function offsetOf(string $zone): string
    {
        $seconds = (new DateTimeZone($zone))->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
    }

    private function paidSale(): int
    {
        $saleId = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);

        return (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
    }

    private function stampPayment(int $paymentId, string $stamp): void
    {
        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['payment_time' => $stamp]);
    }

    private function paymentsCopiedFrom(int $paymentId, int $count): void
    {
        $row = (array) $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow();

        unset($row['payment_id']);

        for ($copy = 1; $copy < $count; $copy++) {
            $this->accountingDb()->table('sales_payments')->insert($row);
        }
    }

    private function dayOfTheShop(string $zone, string $modifier = 'now'): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($zone)))->modify($modifier)->format('Y-m-d');
    }

    public function testRun_GivesTheCheckWithItsReference(): void
    {
        $results = $this->checks->run();

        $this->assertCount(1, $results);
        $this->assertSame('clock_zones', $results[0]->id);
        $this->assertSame('W93-CLOCK', $results[0]->ref);
    }

    public function testRun_PassesWhenTheDatabaseAndTheShopWorkAtTheSameOffset(): void
    {
        $this->databaseWorksAt($this->offsetOf('Asia/Riyadh'));
        $this->shopWorksIn('Asia/Riyadh');
        date_default_timezone_set('Asia/Riyadh');

        $result = $this->check();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('+03:00', $result->data['database_offset']);
        $this->assertSame('+03:00', $result->data['shop_offset']);
        $this->assertSame(0, $result->data['difference_minutes']);
        $this->assertSame('Asia/Riyadh', $result->data['shop_zone']);
        $this->assertTrue($result->data['shop_zone_from_settings']);
    }

    public function testRun_PassesForAZoneWithDaylightSavingWhenTheDatabaseFollowsItsOffsetToday(): void
    {
        $this->databaseWorksAt($this->offsetOf('America/New_York'));
        $this->shopWorksIn('America/New_York');
        date_default_timezone_set('America/New_York');

        $this->assertSame(CheckStatus::Pass, $this->check()->status);
    }

    public function testRun_ReviewsADatabaseThatIsBehindTheShop(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('Asia/Riyadh');
        date_default_timezone_set('Asia/Riyadh');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(-180, $result->data['difference_minutes']);
        $this->assertStringContainsString('differ by 3 hour(s)', $result->summary);
        $this->assertStringContainsString('The database stamps times in +00:00', $result->findings[0]);
        $this->assertStringContainsString('the shop works in Asia/Riyadh (+03:00, from the locale settings)', $result->findings[0]);
        $this->assertStringContainsString('sales_payments.payment_time is stamped by the database', $result->findings[1]);
        $this->assertStringContainsString('default-time-zone', $result->findings[2]);
        $this->assertStringStartsWith('To align them on a database that holds no sale or purchase yet', $result->findings[2]);
    }

    public function testRun_WarnsThatTheZoneOfTheDatabaseServerMovesTheTimeOfEverySavedRowOnADatabaseWithHistory(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('Asia/Riyadh');
        date_default_timezone_set('Asia/Riyadh');

        $warning = $this->check()->findings[3];

        $this->assertStringStartsWith('On a database that already holds history, do not change the zone of the database server on its own', $warning);
        $this->assertStringContainsString('sales.sale_time, receivings.receiving_time and sales_payments.payment_time are TIMESTAMP columns', $warning);
        $this->assertStringContainsString('a new server zone moves the time every saved row shows', $warning);
        $this->assertStringContainsString('CONVERT_TZ', $warning);
        $this->assertStringContainsString('the first hours of the shop day carry the day before', $warning);
    }

    public function testRun_SaysNothingOfTheHistoryOfTheDatabaseWhenTheClocksAgree(): void
    {
        $this->databaseWorksAt($this->offsetOf('Asia/Riyadh'));
        $this->shopWorksIn('Asia/Riyadh');
        date_default_timezone_set('Asia/Riyadh');

        $this->assertStringNotContainsString('CONVERT_TZ', implode("\n", $this->check()->findings));
    }

    public function testRun_ReviewsADatabaseThatIsAheadOfTheShopWhenNoPaymentIsStampedAhead(): void
    {
        $this->databaseWorksAt('+03:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(180, $result->data['difference_minutes']);
        $this->assertSame(0, $result->data['payments_dated_ahead']);
    }

    public function testRun_ReviewsAHalfHourOffsetWithoutRoundingItAway(): void
    {
        $this->databaseWorksAt('+05:30');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(330, $result->data['difference_minutes']);
        $this->assertSame('+05:30', $result->data['database_offset']);
        $this->assertStringContainsString('differ by 5.5 hour(s)', $result->summary);
    }

    public function testRun_WritesAnOffsetWestOfGreenwichWithItsSign(): void
    {
        $this->databaseWorksAt('-05:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame('-05:00', $result->data['database_offset']);
        $this->assertSame(-300, $result->data['difference_minutes']);
        $this->assertStringContainsString('The database stamps times in -05:00', $result->findings[0]);
    }

    public function testRun_FailsWhenTheDatabaseIsAheadAndAPaymentIsStampedOnADayThatHasNotCome(): void
    {
        $this->databaseWorksAt('+03:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');
        $paymentId = $this->paidSale();
        $this->stampPayment($paymentId, $this->dayOfTheShop('UTC', '+1 day') . ' 02:00:00');

        $result = $this->check();

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(1, $result->data['payments_dated_ahead']);
        $this->assertStringStartsWith('1 payment(s) on sales are stamped on a day that has not come', $result->summary);
        $this->assertStringContainsString("payment #{$paymentId} on sale #", implode("\n", $result->findings));
    }

    public function testRun_ReviewsAPaymentStampedAheadWhenTheClocksAgreeNow(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');
        $this->stampPayment($this->paidSale(), $this->dayOfTheShop('UTC', '+1 day') . ' 02:00:00');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(0, $result->data['difference_minutes']);
        $this->assertSame(1, $result->data['payments_dated_ahead']);
        $this->assertStringStartsWith('The clocks agree now, but payments are stamped', $result->summary);
    }

    public function testRun_LeavesAPaymentStampedLaterTodayAlone(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');
        $this->stampPayment($this->paidSale(), $this->dayOfTheShop('UTC') . ' 23:59:59');

        $result = $this->check();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['payments_dated_ahead']);
        $this->assertSame(0, $result->data['payments_before_sale'], 'A payment on the day of its sale is not before it.');
    }

    public function testRun_ReviewsAPaymentStampedOnTheDayBeforeItsSale(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('Asia/Riyadh');
        date_default_timezone_set('Asia/Riyadh');
        $paymentId = $this->paidSale();
        $this->stampPayment($paymentId, $this->dayOfTheShop('Asia/Riyadh', '-1 day') . ' 22:00:00');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(1, $result->data['payments_before_sale']);
        $this->assertStringContainsString("payment #{$paymentId} is stamped", implode("\n", $result->findings));
    }

    public function testRun_NamesOnlyThePaymentOnTheDayBeforeItsSaleAndNotTheOneOnTheSameDay(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('Asia/Riyadh');
        date_default_timezone_set('Asia/Riyadh');
        $early = $this->paidSale();
        $same  = $this->paidSale();
        $this->stampPayment($early, $this->dayOfTheShop('Asia/Riyadh', '-1 day') . ' 22:00:00');
        $this->stampPayment($same, $this->dayOfTheShop('Asia/Riyadh') . ' 00:00:01');

        $result = $this->check();
        $named  = implode("\n", $result->findings);

        $this->assertSame(1, $result->data['payments_before_sale']);
        $this->assertStringContainsString("payment #{$early} is stamped", $named);
        $this->assertStringNotContainsString("payment #{$same} is stamped", $named);
    }

    public function testRun_ReadsTheZoneOfTheShopFromTheLocaleSettingAndNotFromTheProcess(): void
    {
        $this->databaseWorksAt('+03:00');
        $this->shopWorksIn('Asia/Riyadh');
        date_default_timezone_set('America/New_York');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(0, $result->data['difference_minutes']);
        $this->assertSame('Asia/Riyadh', $result->data['shop_zone']);
        $this->assertSame('America/New_York', $result->data['process_zone']);
        $this->assertStringStartsWith('The command line does not work in the zone of the shop (Asia/Riyadh): it works in America/New_York', $result->summary);
        $this->assertStringContainsString('app.appTimezone', $result->findings[0]);
    }

    public function testRun_ReviewsAShopZoneThatPhpAcceptsButTheCommandsIgnore(): void
    {
        $this->databaseWorksAt($this->offsetOf('Etc/GMT-11'));
        $this->shopWorksIn('Etc/GMT-11');
        date_default_timezone_set('Etc/GMT-11');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertFalse($result->data['shop_zone_listed']);
        $this->assertTrue($result->data['shop_zone_from_settings']);
        $this->assertSame(0, $result->data['difference_minutes']);
        $this->assertStringContainsString('accounting:generate_recurring and accounting:depreciate ignore it', implode("\n", $result->findings));
        $this->assertSame('The zone Etc/GMT-11 of the shop is a name PHP accepts but does not list as a current zone: the commands ignore it and run in Etc/GMT-11.', $result->summary);
    }

    public function testRun_ReviewsAZoneOfTheSettingsScreenThatPhpDoesNotKnow(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('Asia/Katmandu');
        date_default_timezone_set('UTC');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame('Asia/Katmandu', $result->data['shop_zone_setting']);
        $this->assertFalse($result->data['shop_zone_from_settings']);
        $this->assertSame('UTC', $result->data['shop_zone']);
        $this->assertStringStartsWith('The locale settings name a zone that PHP does not know (Asia/Katmandu): the shop works in UTC.', $result->summary);
        $this->assertStringContainsString('The locale settings name the zone Asia/Katmandu, which the PHP of this server does not know', implode("\n", $result->findings));
    }

    public function testRun_PassesForAZoneThatPhpListsAsCurrent(): void
    {
        $this->databaseWorksAt($this->offsetOf('Asia/Kuwait'));
        $this->shopWorksIn('Asia/Kuwait');
        date_default_timezone_set('Asia/Kuwait');

        $result = $this->check();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertTrue($result->data['shop_zone_listed']);
    }

    public function testRun_FallsBackToTheZoneOfTheProcessWhenTheSettingNamesNone(): void
    {
        $this->databaseWorksAt($this->offsetOf('Asia/Riyadh'));
        date_default_timezone_set('Asia/Riyadh');

        $result = $this->check();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('Asia/Riyadh', $result->data['shop_zone']);
        $this->assertFalse($result->data['shop_zone_from_settings']);
    }

    public function testRun_SaysWhenTheZoneOfTheShopIsTheOneOfTheProcessBecauseTheSettingNamesNone(): void
    {
        $this->databaseWorksAt('+00:00');
        date_default_timezone_set('Asia/Riyadh');

        $result = $this->check();

        $this->assertStringContainsString('the zone of the application because the locale settings name none', $result->findings[0]);
    }

    public function testRun_FallsBackToTheZoneOfTheProcessForASettingThatNamesNoZoneAtAll(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('Mars/Olympus');
        date_default_timezone_set('UTC');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame('UTC', $result->data['shop_zone']);
        $this->assertSame('Mars/Olympus', $result->data['shop_zone_setting']);
        $this->assertFalse($result->data['shop_zone_from_settings']);
    }

    public function testRun_ReadsASettingWrittenWithSpacesAround(): void
    {
        $this->databaseWorksAt($this->offsetOf('Asia/Riyadh'));
        $this->shopWorksIn('  Asia/Riyadh ');
        date_default_timezone_set('Asia/Riyadh');

        $result = $this->check();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('Asia/Riyadh', $result->data['shop_zone_setting']);
        $this->assertTrue($result->data['shop_zone_from_settings']);
    }

    public function testRun_ReviewsAPaymentStampedOnTheDayBeforeItsSaleWhenTheClocksAgree(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');
        $this->stampPayment($this->paidSale(), $this->dayOfTheShop('UTC', '-1 day') . ' 22:00:00');

        $result = $this->check();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(0, $result->data['difference_minutes']);
        $this->assertSame(0, $result->data['payments_dated_ahead']);
        $this->assertSame(1, $result->data['payments_before_sale']);
        $this->assertStringStartsWith('The clocks agree now, but payments are stamped on days that do not fit their sale', $result->summary);
    }

    public function testRun_CountsAPaymentStampedAtTheFirstSecondOfTheNextDayOfTheShop(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');
        $this->stampPayment($this->paidSale(), $this->dayOfTheShop('UTC', '+1 day') . ' 00:00:00');

        $result = $this->check();

        $this->assertSame(1, $result->data['payments_dated_ahead']);
        $this->assertSame(CheckStatus::Review, $result->status);
    }

    public function testRun_ListsOnlyTheFirstTwentyPaymentsStampedAheadAndCountsThemAll(): void
    {
        $this->databaseWorksAt('+03:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');
        $paymentId = $this->paidSale();
        $this->paymentsCopiedFrom($paymentId, 25);
        $this->accountingDb()->table('sales_payments')->where('payment_id >=', $paymentId)->update(['payment_time' => $this->dayOfTheShop('UTC', '+1 day') . ' 02:00:00']);

        $result = $this->check();
        $listed = array_filter($result->findings, static fn (string $finding): bool => str_starts_with($finding, 'payment #'));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(25, $result->data['payments_dated_ahead']);
        $this->assertStringStartsWith('25 payment(s) on sales are stamped on a day that has not come', $result->summary);
        $this->assertCount(20, $listed);
        $this->assertStringStartsWith("payment #{$paymentId} on sale #", reset($listed));
    }

    public function testRun_ListsOnlyTheFirstTwentyPaymentsBeforeTheirSaleAndCountsThemAll(): void
    {
        $this->databaseWorksAt('+00:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');
        $paymentId = $this->paidSale();
        $this->paymentsCopiedFrom($paymentId, 25);
        $this->accountingDb()->table('sales_payments')->where('payment_id >=', $paymentId)->update(['payment_time' => $this->dayOfTheShop('UTC', '-1 day') . ' 22:00:00']);

        $result = $this->check();
        $listed = array_filter($result->findings, static fn (string $finding): bool => str_starts_with($finding, 'payment #'));

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(25, $result->data['payments_before_sale']);
        $this->assertCount(20, $listed);
        $this->assertStringContainsString(' on a day before sale #', reset($listed));
    }

    public function testRun_IsSkippedWhenTheDatabaseDoesNotReportTheOffsetOfItsClock(): void
    {
        $connection = $this->createMock(BaseConnection::class);
        $connection->method('tableExists')->willReturn(true);
        $connection->method('prefixTable')->willReturnArgument(0);
        $connection->method('query')->willReturnCallback(function (string $sql): ResultInterface {
            $result = $this->createMock(ResultInterface::class);
            $result->method('getNumRows')->willReturn(1);
            $result->method('getRow')->willReturn((object) ['session_zone' => 'SYSTEM', 'global_zone' => 'SYSTEM', 'system_zone' => 'UTC', 'offset_seconds' => null]);

            return $result;
        });

        $result = (new ClockChecks($connection))->run()[0];

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertStringContainsString('the database did not report the time zone of its clock', $result->summary);
    }

    public function testRun_IsSkippedBeforeThePaymentsExist(): void
    {
        $this->accountingDb()->query('RENAME TABLE ospos_sales_payments TO ospos_sales_payments_hold');

        $result = $this->check();

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertSame(['sales_payments.payment_time'], $result->data['missing']);
    }

    public function testRun_ChangesNoRowAndLeavesTheZoneOfTheSessionAsItWas(): void
    {
        $this->databaseWorksAt('+03:00');
        $this->shopWorksIn('UTC');
        date_default_timezone_set('UTC');
        $this->stampPayment($this->paidSale(), $this->dayOfTheShop('UTC', '+1 day') . ' 02:00:00');
        $before = $this->databaseFingerprint();

        $this->check();

        $this->assertDatabaseUnchanged($before);
        $this->assertSame('+03:00', (string) $this->accountingDb()->query('SELECT @@session.time_zone AS zone')->getRow()->zone);
    }
}
