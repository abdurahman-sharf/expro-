<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ChequeCredits;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ChequeFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the cheques of a customer or a supplier hold in credit: the net of the journals of the
 * cheque that stand on the account of the party, so a status that takes the cheque back takes its
 * credit back, and a cheque is held from the day of its journal.
 */
class ChequeCreditsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ChequeFixtureTrait;
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
        $this->startCheques();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopBuying();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();

        parent::tearDown();
    }

    /**
     * @return list<array{int, string}> The cheque and what it holds, oldest issue date first.
     */
    private function heldByCustomer(?int $customerId = null, ?int $accountOf = null, ?string $asOf = null): array
    {
        $rows = ChequeCredits::ofCustomer($this->accountingDb(), $customerId ?? $this->customerId, $this->customerAccount($accountOf ?? $customerId), $asOf);

        return array_map(static fn (object $row): array => [(int) $row->cheque_id, bcadd((string) $row->amount, '0', 2)], $rows);
    }

    /**
     * @return list<array{int, string}>
     */
    private function heldForSupplier(?string $asOf = null): array
    {
        $rows = ChequeCredits::ofSupplier($this->accountingDb(), $this->supplierId, $this->supplierAccount(), $asOf);

        return array_map(static fn (object $row): array => [(int) $row->cheque_id, bcadd((string) $row->amount, '0', 2)], $rows);
    }

    public function testOfCustomer_HoldsWhatAReceivedChequeCreditedTheCustomerWithItsIssueDate(): void
    {
        $chequeId = $this->receiveCheque('100.00', $this->daysAgo(10));
        $rows     = ChequeCredits::ofCustomer($this->accountingDb(), $this->customerId, $this->customerAccount(), null);

        $this->assertCount(1, $rows);
        $this->assertSame($chequeId, (int) $rows[0]->cheque_id);
        $this->assertSame($this->daysAgo(10), (string) $rows[0]->issue_date);
        $this->assertSame('100.00', bcadd((string) $rows[0]->amount, '0', 2));
    }

    public function testOfCustomer_ListsTheChequesOldestIssueDateFirstAndEachOnceWhateverItsSteps(): void
    {
        $newer = $this->receiveCheque('30.00', $this->daysAgo(2));
        $older = $this->receiveCheque('70.00', $this->daysAgo(9));

        $this->chequeService->deposit($older, $this->daysAgo(8), $this->chequeClerk);

        $this->assertSame([[$older, '70.00'], [$newer, '30.00']], $this->heldByCustomer());
    }

    public function testOfCustomer_LeavesOutTheChequesOfAnotherCustomerEvenOnTheSameAccount(): void
    {
        $other = $this->anotherCustomer();

        $mine   = $this->receiveCheque('100.00', $this->daysAgo(3));
        $theirs = $this->receiveCheque('40.00', $this->daysAgo(2), null, $other);

        $this->assertSame([[$mine, '100.00']], $this->heldByCustomer());
        $this->assertSame([[$theirs, '40.00']], $this->heldByCustomer($other));
        $this->assertSame([], $this->heldByCustomer($other, $this->customerId));
        $this->assertSame([], $this->heldByCustomer($this->customerId, $other));
    }

    public function testOfCustomer_HoldsNothingForAChequeThatWasRejectedAndTakesItBackWithTheUndo(): void
    {
        $chequeId = $this->receiveCheque('100.00', $this->daysAgo(6));

        $this->chequeService->reject($chequeId, $this->daysAgo(1), $this->chequeClerk);

        $this->assertSame([], $this->heldByCustomer());

        $this->chequeService->undo($chequeId, $this->chequeClerk);

        $this->assertSame([[$chequeId, '100.00']], $this->heldByCustomer());
    }

    public function testOfCustomer_HoldsAChequeFromTheDayOfItsJournalUntilTheDayItIsRejected(): void
    {
        $chequeId = $this->receiveCheque('100.00', $this->daysAgo(10));

        $this->chequeService->reject($chequeId, $this->daysAgo(4), $this->chequeClerk);

        $this->assertSame([], $this->heldByCustomer(null, null, $this->daysAgo(11)));
        $this->assertSame([[$chequeId, '100.00']], $this->heldByCustomer(null, null, $this->daysAgo(10)));
        $this->assertSame([[$chequeId, '100.00']], $this->heldByCustomer(null, null, $this->daysAgo(5)));
        $this->assertSame([], $this->heldByCustomer(null, null, $this->daysAgo(4)));
    }

    public function testOfCustomer_IgnoresTheJournalsOfOtherSourcesOnTheAccountOfTheCustomer(): void
    {
        $this->invoiceOf('250.00');

        $this->assertSame([], $this->heldByCustomer());
    }

    public function testOfSupplier_HoldsWhatADeliveredChequeDebitedTheSupplierAndNothingBeforeDelivery(): void
    {
        $chequeId = $this->issueCheque('200.00', $this->daysAgo(7));

        $this->assertSame([], $this->heldForSupplier());

        $this->chequeService->deliver($chequeId, $this->daysAgo(6), $this->chequeClerk);

        $this->assertSame([[$chequeId, '200.00']], $this->heldForSupplier());
        $this->assertSame([], $this->heldForSupplier($this->daysAgo(7)));
    }

    public function testOfSupplier_HoldsNothingForAChequeThatWasRejectedOrIsOnlyIssued(): void
    {
        $delivered = $this->issueCheque('200.00', $this->daysAgo(7));

        $this->issueCheque('90.00', $this->daysAgo(5));

        $this->chequeService->deliver($delivered, $this->daysAgo(6), $this->chequeClerk);
        $this->chequeService->reject($delivered, $this->daysAgo(2), $this->chequeClerk);

        $this->assertSame([], $this->heldForSupplier());
    }
}
