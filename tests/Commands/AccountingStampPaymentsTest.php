<?php

namespace Tests\Commands;

use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class AccountingStampPaymentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        (new TreasuryService())->ensureMain();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function runCommand(string $arguments = ''): string
    {
        $this->resetStreamFilterBuffer();
        command(trim('accounting:stamp_payments ' . $arguments));

        return $this->getStreamFilterBuffer();
    }

    private function unstampedPayment(): int
    {
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);

        return (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
    }

    private function stampOf(int $paymentId): ?string
    {
        return $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id;
    }

    public function testRun_SaysThereIsNothingToStampWhenNoPaymentWaits(): void
    {
        $output = $this->runCommand();

        $this->assertStringContainsString('0 payment(s) have no treasury although their journal went into one.', $output);
    }

    public function testRun_ListsThePaymentsThatWaitAndStampsNone(): void
    {
        $paymentId = $this->unstampedPayment();

        $output = $this->runCommand();

        $this->assertStringContainsString("sales_payments #{$paymentId} would take treasury", $output);
        $this->assertNull($this->stampOf($paymentId));
    }

    public function testRun_StampsThePaymentsWhenAskedTo(): void
    {
        $paymentId = $this->unstampedPayment();

        $output = $this->runCommand('--apply');

        $this->assertMatchesRegularExpression("/sales_payments #{$paymentId}: treasury \\d+\\./", $output);
        $this->assertStringContainsString('0 payment(s) have no treasury although their journal went into one.', $output);
        $this->assertNotNull($this->stampOf($paymentId));
    }
}
