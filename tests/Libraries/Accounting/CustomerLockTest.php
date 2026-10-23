<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\CustomerLock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use mysqli;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * One change at a time to what a customer owes: the lock itself, and the reason it exists, that
 * two invoices issued at the same moment to a customer with credit must not spend it twice.
 */
class CustomerLockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
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
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    public function testRun_ReturnsWhatTheWorkReturnsAndLetsGoOfTheLock(): void
    {
        $lock = new CustomerLock();

        $this->assertSame('done', $lock->run($this->customerId, fn (): string => 'done'));
        $this->assertTrue($this->anotherConnectionCanTake($lock->nameOf($this->customerId)));
    }

    public function testRun_HoldsTheLockWhileTheWorkRuns(): void
    {
        $lock = new CustomerLock();
        $free = null;

        $lock->run($this->customerId, function () use ($lock, &$free): void {
            $free = $this->anotherConnectionCanTake($lock->nameOf($this->customerId));
        });

        $this->assertFalse($free);
    }

    public function testRun_LetsGoOfTheLockWhenTheWorkFails(): void
    {
        $lock = new CustomerLock();

        try {
            $lock->run($this->customerId, static function (): never {
                throw new RuntimeException('failed');
            });
            $this->fail('The failure of the work must come out.');
        } catch (RuntimeException $failure) {
            $this->assertSame('failed', $failure->getMessage());
        }

        $this->assertTrue($this->anotherConnectionCanTake($lock->nameOf($this->customerId)));
    }

    public function testRun_RefusesWhenAnotherChangeHoldsTheCustomerLongerThanItWaits(): void
    {
        $name = (new CustomerLock())->nameOf($this->customerId);
        $link = $this->otherConnection();

        mysqli_query($link, "SELECT GET_LOCK('" . mysqli_real_escape_string($link, $name) . "', 0)");

        try {
            (new CustomerLock(null, 0))->run($this->customerId, static fn (): string => 'ran');
            $this->fail('A customer held by another change must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::CustomerBusy, $exception->error);
        } finally {
            mysqli_close($link);
        }
    }

    public function testRun_TakesNoLockForASaleWithoutACustomer(): void
    {
        $this->assertSame('ran', (new CustomerLock())->run(null, static fn (): string => 'ran'));
        $this->assertSame('ran', (new CustomerLock())->run(-1, static fn (): string => 'ran'));
    }

    public function testNameOf_IsOneNameForOneCustomerInOneDatabase(): void
    {
        $lock = new CustomerLock();

        $this->assertSame($lock->nameOf(7), $lock->nameOf(7));
        $this->assertNotSame($lock->nameOf(7), $lock->nameOf(8));
        $this->assertLessThanOrEqual(64, strlen($lock->nameOf(2147483647)));
    }

    public function testRun_StopsTwoInvoicesIssuedAtTheSameMomentFromSpendingTheSameCreditTwice(): void
    {
        $first   = $this->invoiceOf('100.00');
        $service = $this->item('0', false);

        $this->creditNoteOf($first, '200.00');

        $this->assertSame('100.00', bcadd($this->position()->credit(), '0', 2));

        [$one, $two] = $this->raceTwoProcesses(
            'serviceInvoice',
            [$this->customerId, $service, '100.00', $this->employeeId],
            [$this->customerId, $service, '100.00', $this->employeeId],
        );

        $this->trackRaceSales();

        $this->assertTrue($one);
        $this->assertTrue($two);

        $position = $this->position();

        $this->assertSame('100.00', bcadd($position->open(), '0', 2));
        $this->assertSame('0.00', bcadd($position->credit(), '0', 2));
        $this->assertSame('100.00', bcadd($position->accountBalance, '0', 2));
        $this->assertSame('200.00', bcadd(array_reduce($this->accountingDb()->table('customer_credit_applications')->where('released_at', null)->get()->getResult(), static fn (string $sum, object $row): string => bcadd($sum, $row->amount, 4), '0'), '0', 2));
        $this->assertTies();
    }

    private function trackRaceSales(): void
    {
        foreach ($this->accountingDb()->table('sales')->select('sale_id')->where('comment', 'race')->get()->getResult() as $row) {
            $this->saleIds[] = (int) $row->sale_id;
        }
    }

    private function otherConnection(): mysqli
    {
        $config = config('Database')->tests;

        return mysqli_connect($config['hostname'], $config['username'], $config['password'], $config['database'], $config['port']);
    }

    private function anotherConnectionCanTake(string $name): bool
    {
        $link = $this->otherConnection();

        try {
            $row = mysqli_fetch_row(mysqli_query($link, "SELECT GET_LOCK('" . mysqli_real_escape_string($link, $name) . "', 0)"));

            return (int) $row[0] === 1;
        } finally {
            mysqli_close($link);
        }
    }
}
