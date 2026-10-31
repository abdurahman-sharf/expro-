<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ReceivingLineEdit;
use App\Libraries\Accounting\SupplierLock;
use App\Libraries\Accounting\LockOutcome;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use mysqli;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PooledConnection;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * One change at a time to what is owed to a supplier: the lock itself, and the reason it exists,
 * that two purchases saved at the same moment for a supplier with credit must not spend it twice,
 * and two payments made at the same moment must not both be counted against what was owed.
 */
class SupplierLockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

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

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->trackRacePurchases();
        $this->stopBuying();

        parent::tearDown();
    }

    public function testRun_ReturnsWhatTheWorkReturnsAndLetsGoOfTheLock(): void
    {
        $lock = new SupplierLock();

        $this->assertSame('done', $lock->run($this->supplierId, fn (): string => 'done'));
        $this->assertTrue($this->anotherConnectionCanTake($lock->nameOf($this->supplierId)));
    }

    public function testRun_HoldsTheLockWhileTheWorkRuns(): void
    {
        $lock = new SupplierLock();
        $free = null;

        $lock->run($this->supplierId, function () use ($lock, &$free): void {
            $free = $this->anotherConnectionCanTake($lock->nameOf($this->supplierId));
        });

        $this->assertFalse($free);
    }

    public function testRun_LetsGoOfTheLockWhenTheWorkFails(): void
    {
        $lock = new SupplierLock();

        try {
            $lock->run($this->supplierId, static function (): never {
                throw new RuntimeException('failed');
            });
            $this->fail('The failure of the work must come out.');
        } catch (RuntimeException $failure) {
            $this->assertSame('failed', $failure->getMessage());
        }

        $this->assertTrue($this->anotherConnectionCanTake($lock->nameOf($this->supplierId)));
    }

    public function testRun_RefusesWhenAnotherChangeHoldsTheSupplierLongerThanItWaits(): void
    {
        $name = (new SupplierLock())->nameOf($this->supplierId);
        $link = $this->otherConnection();

        mysqli_query($link, "SELECT GET_LOCK('" . mysqli_real_escape_string($link, $name) . "', 0)");

        try {
            (new SupplierLock(null, 0))->run($this->supplierId, static fn (): string => 'ran');
            $this->fail('A supplier held by another change must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::SupplierBusy, $exception->error);
        } finally {
            mysqli_close($link);
        }
    }

    private function connectionBehindAPooler(): PooledConnection
    {
        $pooled = new PooledConnection(config('Database')->tests);

        $pooled->sendStatementsMentioning('IS_USED_LOCK', Database::connect('tests', false));

        return $pooled;
    }

    public function testRun_RejectsAndDoesNotRunTheWorkWhenTheServerDoesNotHoldTheLockOnTheConnectionThatRunsIt(): void
    {
        $lock = new SupplierLock($this->connectionBehindAPooler());
        $ran  = false;

        try {
            $lock->run($this->supplierId, static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('A change must be refused when the lock is not held on the connection that makes it.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::SupplierBusy, $exception->error);
            $this->assertSame(LockOutcome::NotHeld->value, $exception->context['lock']);
            $this->assertSame($this->supplierId, $exception->context['supplier_id']);
        }

        $this->assertFalse($ran);
        $this->assertTrue($this->anotherConnectionCanTake($lock->nameOf($this->supplierId)), 'A refused change leaves no lock behind');
    }

    public function testRun_RejectsAndLeavesTheLockToItsHolderWhenTheConnectionAlreadyHeldItForSomebodyElse(): void
    {
        $db   = Database::connect();
        $lock = new SupplierLock($db);
        $name = $lock->nameOf($this->supplierId);
        $ran  = false;

        $db->query('SELECT GET_LOCK(?, 0)', [$name]);

        try {
            $lock->run($this->supplierId, static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('A lock the connection already held for somebody else cannot protect the change.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::SupplierBusy, $exception->error);
            $this->assertSame(LockOutcome::Occupied->value, $exception->context['lock']);
        }

        $this->assertFalse($ran);
        $this->assertSame(1, (int) $db->query('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS mine', [$name])->getRow()->mine, 'The holder keeps what it holds');
        $this->assertFalse($this->anotherConnectionCanTake($name));

        $db->query('SELECT RELEASE_LOCK(?)', [$name]);
    }

    public function testRun_LetsTheSameChangeTakeTheLockOfTheSameSupplierAgainAndLetsGoOnlyWhenItEnds(): void
    {
        $lock  = new SupplierLock();
        $inner = null;
        $after = null;

        $lock->run($this->supplierId, function () use ($lock, &$inner, &$after): void {
            $inner = $lock->run($this->supplierId, fn (): string => 'inner');
            $after = $this->anotherConnectionCanTake($lock->nameOf($this->supplierId));
        });

        $this->assertSame('inner', $inner);
        $this->assertFalse($after, 'The outer change still holds the lock when the inner one ends');
        $this->assertTrue($this->anotherConnectionCanTake($lock->nameOf($this->supplierId)));
    }

    public function testRun_TakesNoLockForAPurchaseWithoutASupplier(): void
    {
        $this->assertSame('ran', (new SupplierLock())->run(null, static fn (): string => 'ran'));
        $this->assertSame('ran', (new SupplierLock())->run(-1, static fn (): string => 'ran'));
    }

    public function testNameOf_IsOneNameForOneSupplierInOneDatabaseAndNotTheNameOfACustomer(): void
    {
        $lock = new SupplierLock();

        $this->assertSame($lock->nameOf(7), $lock->nameOf(7));
        $this->assertNotSame($lock->nameOf(7), $lock->nameOf(8));
        $this->assertNotSame($lock->nameOf(7), (new \App\Libraries\Accounting\CustomerLock())->nameOf(7));
        $this->assertLessThanOrEqual(64, strlen($lock->nameOf(2147483647)));
    }

    public function testSaveValue_IsRefusedAndSavesNothingWhenAnotherChangeToTheSupplierDoesNotFinish(): void
    {
        $link = $this->holdTheSupplier();
        $item = $this->item('0.00');
        $rows = $this->countRows('receivings');

        try {
            $result = $this->purchases->setSupplierLockWait(0)->save_value([$this->purchaseLine($item, 0, '1', '10.00')], $this->supplierId, $this->employeeId, 'busy', 'REF', lang('Sales.due'));
        } finally {
            mysqli_close($link);
        }

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(AccountingError::SupplierBusy, $this->purchases->accountingFailure()->error);
        $this->assertSame($rows, $this->countRows('receivings'));
        $this->assertSame('0.000', $this->onHand($item));
    }

    public function testSaveValue_RejectsTheSaveWhenTheLockOfTheSupplierIsNotHeldByTheConnectionThatSavesAndSavesNothing(): void
    {
        $db   = Database::connect();
        $name = (new SupplierLock($db))->nameOf($this->supplierId);
        $item = $this->item('0.00');
        $rows = $this->countRows('receivings');

        $db->query('SELECT GET_LOCK(?, 0)', [$name]);

        try {
            $result = $this->purchases->save_value([$this->purchaseLine($item, 0, '1', '10.00')], $this->supplierId, $this->employeeId, 'shared', 'REF', lang('Sales.due'));
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$name]);
        }

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(AccountingError::SupplierBusy, $this->purchases->accountingFailure()->error);
        $this->assertSame(LockOutcome::Occupied->value, $this->purchases->accountingFailure()->context['lock']);
        $this->assertSame($rows, $this->countRows('receivings'));
        $this->assertSame('0.000', $this->onHand($item));
    }

    public function testUpdate_IsRefusedAndChangesNothingWhenAnotherChangeToTheSupplierDoesNotFinish(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $link     = $this->holdTheSupplier();

        try {
            $paid = $this->purchases->setSupplierLockWait(0)->update($purchase, ['payments' => [
                ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '40.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''],
            ]]);
        } finally {
            mysqli_close($link);
        }

        $this->assertFalse($paid);
        $this->assertSame(AccountingError::SupplierBusy, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->accountingDb()->table('receivings_payments')->where('receiving_id', $purchase)->countAllResults());
        $this->assertSame('100.00', $this->owed());
    }

    public function testUpdateLines_IsRefusedAndChangesNothingWhenAnotherChangeToTheSupplierDoesNotFinish(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $line     = $this->accountingDb()->table('receivings_items')->where('receiving_id', $purchase)->get()->getRow();
        $edit     = ReceivingLineEdit::changing((int) $line->item_id, (int) $line->line, ['quantity' => '1']);
        $before   = bcadd((string) $line->quantity_purchased, '0', 3);
        $link     = $this->holdTheSupplier();

        try {
            $corrected = $this->purchases->setSupplierLockWait(0)->updateLines($purchase, [$edit], $this->employeeId);
        } finally {
            mysqli_close($link);
        }

        $this->assertFalse($corrected);
        $this->assertSame(AccountingError::SupplierBusy, $this->purchases->accountingFailure()->error);
        $this->assertSame($before, bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $purchase)->get()->getRow()->quantity_purchased, '0', 3));
        $this->assertSame('100.00', $this->owed());
    }

    public function testDeleteValue_IsRefusedAndDeletesNothingWhenAnotherChangeToTheSupplierDoesNotFinish(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $link     = $this->holdTheSupplier();

        try {
            $deleted = $this->purchases->setSupplierLockWait(0)->delete_value($purchase, $this->employeeId);
            $listed  = $this->purchases->setSupplierLockWait(0)->delete_list([$purchase], $this->employeeId);
        } finally {
            mysqli_close($link);
        }

        $this->assertFalse($deleted);
        $this->assertFalse($listed);
        $this->assertSame(AccountingError::SupplierBusy, $this->purchases->accountingFailure()->error);
        $this->assertSame(1, $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->countAllResults());
        $this->assertSame('100.00', $this->owed());
    }

    public function testRun_StopsTwoPurchasesSavedAtTheSameMomentFromSpendingTheSameCreditTwice(): void
    {
        $first = $this->purchaseOf('200.00', null, lang('Sales.cash'));

        $this->debitNoteOf($first, '100.00');

        $this->assertSame('100.00', bcadd($this->supplierPosition()->credit(), '0', 2));

        [$one, $two] = $this->raceTwoProcesses(
            'purchase',
            [$this->supplierId, $this->item('0.00'), '1', '100.00', $this->employeeId],
            [$this->supplierId, $this->item('0.00'), '1', '100.00', $this->employeeId],
        );

        $this->assertTrue($one);
        $this->assertTrue($two);

        $position = $this->supplierPosition();

        $this->assertSame('100.00', bcadd($position->open(), '0', 2), 'One of the two purchases is paid by the credit and the other stays open');
        $this->assertSame('0.00', bcadd($position->credit(), '0', 2));
        $this->assertSame('100.00', $this->owed());
        $this->assertSame('100.00', bcadd(array_reduce($this->accountingDb()->table('supplier_credit_applications')->where('released_at', null)->get()->getResult(), static fn (string $sum, object $row): string => bcadd($sum, $row->amount, 4), '0'), '0', 2), 'The credit of 100 was applied once');
        $this->assertSupplierTies();
    }

    public function testRun_MakesAPurchaseWaitForTheSupplierWhoseLockIsHeldAndThenSettleItFromTheCreditThatChangeLeft(): void
    {
        $first = $this->purchaseOf('200.00', null, lang('Sales.cash'));

        $this->debitNoteOf($first, '100.00');

        $held   = $this->holdTheSupplier();
        $worker = $this->startRaceWorker('purchase', [$this->supplierId, $this->item('0.00'), '1', '100.00', $this->employeeId]);

        usleep(700_000);

        $this->assertTrue($this->raceWorkerIsRunning($worker), 'The purchase waits while the lock of the supplier is held');
        $this->assertSame(0, $this->accountingDb()->table('receivings')->where('comment', 'race')->countAllResults());

        mysqli_query($held, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($held, (new SupplierLock())->nameOf($this->supplierId)) . "')");
        mysqli_close($held);

        $this->assertSame('1', $this->finishRaceWorker($worker));
        $this->assertSame('0.00', bcadd($this->supplierPosition()->open(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testRun_CountsTwoPaymentsMadeAtTheSameMomentAgainstWhatWasOwedOnce(): void
    {
        $purchase = $this->purchaseOf('100.00');

        [$one, $two] = $this->raceTwoProcesses(
            'payPurchase',
            [$purchase, '100.00', $this->employeeId],
            [$purchase, '100.00', $this->employeeId],
        );

        $this->assertTrue($one);
        $this->assertTrue($two);

        $position = $this->supplierPosition();
        $figures  = $position->purchase($purchase);

        $this->assertSame('200.00', bcadd($figures->paid, '0', 2));
        $this->assertSame('100.00', bcadd($figures->excess(), '0', 2));
        $this->assertSame('100.00', bcadd($position->credit(), '0', 2));
        $this->assertSame('-100.00', $this->owed());
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'supplier_payment')->countAllResults());
        $this->assertSupplierTies();
    }

    public function testRun_LetsTwoDeletionsOfTheSamePurchaseRunOneAfterTheOtherAndReverseItOnce(): void
    {
        $purchase = $this->purchaseOf('100.00');

        $this->payPurchase($purchase, '40.00');

        [$one, $two] = $this->raceTwoProcesses(
            'deletePurchase',
            [$purchase, $this->employeeId],
            [$purchase, $this->employeeId],
        );

        $this->assertTrue($one);
        $this->assertTrue($two);
        $this->assertSame(0, $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('receivings_payments')->where('receiving_id', $purchase)->countAllResults());
        $this->assertSame('0.00', $this->owed());
        $this->assertSupplierTies();
    }

    private function holdTheSupplier(): mysqli
    {
        $link = $this->otherConnection();

        mysqli_query($link, "SELECT GET_LOCK('" . mysqli_real_escape_string($link, (new SupplierLock())->nameOf($this->supplierId)) . "', 0)");

        return $link;
    }

    private function trackRacePurchases(): void
    {
        foreach ($this->accountingDb()->table('receivings')->select('receiving_id')->where('comment', 'race')->get()->getResult() as $row) {
            $this->receivingIds[] = (int) $row->receiving_id;
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
