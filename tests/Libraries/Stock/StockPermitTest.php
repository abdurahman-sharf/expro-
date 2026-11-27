<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Stock\NegativeStockOption;
use App\Libraries\Stock\StockDocumentSettings;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\StockPermitType;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * The numbered stock permits: an add permit, an issue permit and a transfer between two stock
 * locations, with the journals and valued movements they post through the builders that already post
 * stock, the shelves they move, and how they are corrected and cancelled.
 */
class StockPermitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use ShelfFixtureTrait;
    use StockDocumentFixtureTrait;
    use StockUnitsFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockPermit $permits;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->permits = new StockPermit();
    }

    protected function tearDown(): void
    {
        $this->stopStockUnits();
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<array{item_id: int, quantity: string, unit_cost?: string}> $lines
     *
     * @return array<string, mixed>
     */
    private function input(StockPermitType $type, array $lines, array $more = []): array
    {
        return [
            'type'        => $type->value,
            'date'        => date('Y-m-d'),
            'location_id' => self::LOCATION_ID,
            'note'        => '',
            'lines'       => $lines,
            ...$more,
        ];
    }

    private function add(int $itemId, string $quantity, string $cost, array $more = []): int
    {
        return $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'quantity' => $quantity, 'unit_cost' => $cost]], $more), $this->keeperId);
    }

    private function issue(int $itemId, string $quantity, array $more = []): int
    {
        return $this->permits->create($this->input(StockPermitType::Issue, [['item_id' => $itemId, 'quantity' => $quantity]], $more), $this->keeperId);
    }

    private function refusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The change should have been refused with ' . $error->value . '.');
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    public function testCreate_PutsTheUnitsOfAnAddPermitInTheLocationAtTheCostTypedAndCreditsTheAutomaticAccount(): void
    {
        $itemId = $this->stocked('10', '50');

        $permitId = $this->add($itemId, '2', '150');

        $journals = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId);

        $this->assertCount(1, $journals);
        $this->assertSame([$this->stockAccount() . ':300.00:0.00', $this->accountId('other_payables') . ':0.00:300.00'], $this->linesOfJournal((int) $journals[0]->journal_id));
        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '12.000', 'value' => '800.00'], $this->stockOf($itemId));
        $this->assertSame('Stock add permit #STK-IN ' . $permitId, $journals[0]->description);
        $this->assertBooksTie();
    }

    public function testCreate_BringsTheUnitsOfAnAddPermitAtNoCostInWithoutAJournalAndLowersTheAverage(): void
    {
        $itemId = $this->stocked('10', '100');

        $permitId = $this->add($itemId, '4', '0');

        $this->assertSame([], $this->journalsOfSource(StockPermitSources::PERMIT, $permitId));
        $this->assertSame(['quantity' => '14.000', 'value' => '1000.00'], $this->stockOf($itemId), 'goods that cost nothing are still goods');
        $this->assertSame('14.000', $this->onHand($itemId));
        $this->assertSame('71.428571', $this->exactCostOf($itemId), 'the average falls from 100 to 71.428571 as the reference system showed');
        $this->assertCount(1, $this->movementsOfSource(StockPermitSources::PERMIT, $permitId));
        $this->assertBooksTie();
    }

    public function testCreate_WeighsTheCostOfTheItemWithWhatAnAddPermitBringsIn(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->add($itemId, '10', '70');

        $this->assertSame('60.000000', $this->exactCostOf($itemId));
    }

    public function testCreate_CreditsTheAccountChosenOnAnAddPermitInsteadOfTheAutomaticOne(): void
    {
        $itemId  = $this->stocked('10', '50');
        $account = $this->customAccount('211', '21199', 'Permit creditors');

        $permitId = $this->add($itemId, '2', '25', ['account_id' => $account]);

        $journal = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)[0];

        $this->assertSame([$this->stockAccount() . ':50.00:0.00', $account . ':0.00:50.00'], $this->linesOfJournal((int) $journal->journal_id));
        $this->assertSame($account, (int) $this->permits->find($permitId)->account_id);
    }

    public function testCreate_NamesTheSupplierOfAnAddPermitWithoutTouchingTheirAccount(): void
    {
        $itemId = $this->stocked('10', '50');
        $before = $this->balance($this->supplierAccount());

        $permitId = $this->add($itemId, '2', '25', ['supplier_id' => $this->supplierId]);

        $this->assertSame($this->supplierId, (int) $this->permits->find($permitId)->supplier_id);
        $this->assertSame($before, $this->balance($this->supplierAccount()), 'the permit posts to no party account');
    }

    public function testCreate_TakesTheUnitsOfAnIssuePermitOutAtTheAverageAndDebitsTheAutomaticAccount(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '70')]));

        $permitId = $this->issue($itemId, '3');

        $journal = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)[0];

        $this->assertSame([$this->accountId('other_receivables') . ':180.00:0.00', $this->stockAccount() . ':0.00:180.00'], $this->linesOfJournal((int) $journal->journal_id));
        $this->assertSame('17.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '17.000', 'value' => '1020.00'], $this->stockOf($itemId), 'the average does not move when units leave at the average');
        $this->assertSame('60.000000', bcadd((string) $this->permits->lines($permitId)[0]->unit_cost, '0', 6), 'the cost it left at is kept on the line');
        $this->assertBooksTie();
    }

    public function testCreate_DebitsTheAccountChosenOnAnIssuePermitAndNamesTheCustomer(): void
    {
        $itemId  = $this->stocked('10', '50');
        $account = $this->customAccount('12', '1299', 'Permit debtors');

        $permitId = $this->issue($itemId, '2', ['account_id' => $account, 'customer_id' => $this->customerId]);

        $journal = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)[0];

        $this->assertSame([$account . ':100.00:0.00', $this->stockAccount() . ':0.00:100.00'], $this->linesOfJournal((int) $journal->journal_id));
        $this->assertSame($this->customerId, (int) $this->permits->find($permitId)->customer_id);
    }

    public function testCreate_RejectsAnIssueOfMoreThanTheShelfHoldsAndLeavesNothingBehind(): void
    {
        $itemId  = $this->stocked('10', '50');
        $permits = $this->countRows('stock_permits');
        $entries = $this->countRows('accounting_stock_entries');

        $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->issue($itemId, '11'));

        $this->assertSame($permits, $this->countRows('stock_permits'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testCreate_AcceptsAnIssueOfTheWholeShelf(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->issue($itemId, '10');

        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '0.000', 'value' => '0.00'], $this->stockOf($itemId));
        $this->assertBooksTie();
    }

    public function testCreate_PostsNoJournalForAnIssueOfAnItemThatCostsNothing(): void
    {
        $itemId = $this->item('0.00');
        $this->putOnShelf($itemId, '5');

        $permitId = $this->issue($itemId, '2');

        $this->assertSame([], $this->journalsOfSource(StockPermitSources::PERMIT, $permitId));
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertCount(1, $this->movementsOfSource(StockPermitSources::PERMIT, $permitId));
    }

    public function testCreate_MovesTheStockOfATransferWithinABranchAtWhatItIsWorthWhereItLeaves(): void
    {
        $this->switchOnTransferPermit();

        $second = $this->openLocation('Second warehouse');
        $itemId = $this->stocked('10', '50');

        $permitId = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['to_location_id' => $second]), $this->keeperId);

        $journals = $this->journalsOfSource(StockPermitSources::TRANSFER, $permitId);

        $this->assertCount(1, $journals);
        $this->assertSame([$this->stockAccountOf($second) . ':200.00:0.00', $this->stockAccount() . ':0.00:200.00'], $this->linesOfJournal((int) $journals[0]->journal_id));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $second));
        $this->assertSame(['quantity' => '6.000', 'value' => '300.00'], $this->stockOf($itemId));
        $this->assertSame(['quantity' => '4.000', 'value' => '200.00'], $this->stockOf($itemId, $second));
        $this->assertCount(2, $this->movementsOfSource(StockPermitSources::TRANSFER, $permitId));
        $this->assertSame('Stock transfer permit #STK-TRF ' . $permitId, $journals[0]->description);
        $this->assertBooksTie();
    }

    public function testCreate_PostsAJournalInEachBranchOfATransferThatMeetInTheAccountsBetweenBranches(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');

        $permitId = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['to_location_id' => $store]), $this->keeperId);

        $sending   = $this->journalsOfSource(StockPermitSources::TRANSFER, $permitId);
        $receiving = $this->journalsOfSource(StockPermitSources::TRANSFER_RECEIPT, $permitId);

        $this->assertCount(1, $sending);
        $this->assertCount(1, $receiving);
        $this->assertSame(1, (int) $sending[0]->branch_id);
        $this->assertSame($other, (int) $receiving[0]->branch_id);
        $this->assertSame([$this->accountId('branches_receivable') . ':200.00:0.00', $this->stockAccount() . ':0.00:200.00'], $this->linesOfJournal((int) $sending[0]->journal_id));
        $this->assertSame([$this->stockAccountOf($store) . ':200.00:0.00', $this->accountId('branches_payable') . ':0.00:200.00'], $this->linesOfJournal((int) $receiving[0]->journal_id));
        $this->assertSame('0.0000', (new InterBranchPosition())->difference(), 'what one branch is owed is what the other owes');
        $this->assertSame(2, $this->countRows('accounting_interbranch_legs'));
        $this->assertSame($other, (int) $this->accountingDb()->table('accounting_interbranch_legs')->where('journal_id', $sending[0]->journal_id)->get()->getRow()->counterpart_branch_id);
        $this->assertBooksTie();
    }

    public function testCreate_MovesTheUnitsOfATransferOfAnItemThatCostsNothingWithoutAJournal(): void
    {
        $this->switchOnTransferPermit();

        $second = $this->openLocation();
        $itemId = $this->item('0.00');
        $this->putOnShelf($itemId, '5');

        $permitId = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '2']], ['to_location_id' => $second]), $this->keeperId);

        $this->assertSame([], $this->journalsOfSource(StockPermitSources::TRANSFER, $permitId));
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame('2.000', $this->onHand($itemId, $second));
    }

    public function testCreate_RefusesAnIssueAndATransferBelowNothingWhateverTheSettingForASaleSays(): void
    {
        $this->switchOnTransferPermit();

        $second = $this->openLocation();
        $itemId = $this->stocked('10', '50');

        foreach ([NegativeStockOption::Allow, NegativeStockOption::Refuse, NegativeStockOption::RefuseAvailable] as $option) {
            $this->setConfigValue(StockOptions::NEGATIVE_STOCK, $option->value);

            $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->issue($itemId, '11'));
            $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '11']], ['to_location_id' => $second]), $this->keeperId));
        }

        $this->assertSame(['10.000', '0.000'], [$this->onHand($itemId), $this->onHand($itemId, $second)]);
    }

    public function testCreate_RejectsATransferOfMoreThanTheSourceHoldsOrToTheSameLocation(): void
    {
        $this->switchOnTransferPermit();

        $second = $this->openLocation();
        $itemId = $this->stocked('10', '50');

        $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '11']], ['to_location_id' => $second]), $this->keeperId));
        $this->refusedWith(AccountingError::StockPermitLocationInvalid, fn () => $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => self::LOCATION_ID]), $this->keeperId));
        $this->refusedWith(AccountingError::StockPermitLocationInvalid, fn () => $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => 0]), $this->keeperId));
        $this->assertSame(0, $this->countRows('stock_permits'));
    }

    public function testCreate_RejectsAnInactiveLocationAsSourceOrAsDestinationAndAcceptsItWhenActive(): void
    {
        $this->switchOnTransferPermit();

        $second = $this->openLocation();
        $itemId = $this->stocked('10', '50');
        $db     = $this->accountingDb();

        $db->table('stock_locations')->where('location_id', $second)->update(['status' => 0]);

        $this->refusedWith(AccountingError::StockLocationNotActive, fn () => $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => $second]), $this->keeperId));
        $this->refusedWith(AccountingError::StockLocationNotActive, fn () => $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '1']], ['location_id' => $second]), $this->keeperId));
        $this->assertSame(0, $this->countRows('stock_permits'));
        $this->assertSame('10.000', $this->onHand($itemId));

        $db->table('stock_locations')->where('location_id', $second)->update(['status' => 1]);

        $this->assertGreaterThan(0, $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => $second]), $this->keeperId));
    }

    public function testCreate_AcceptsATransferOnlyWhileItsSettingIsOn(): void
    {
        $second = $this->openLocation();
        $itemId = $this->stocked('10', '50');

        $this->refusedWith(AccountingError::StockDocumentOff, fn () => $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => $second]), $this->keeperId));

        $this->assertFalse((new StockDocumentSettings())->transferPermitEnabled(), 'the setting is off until it is turned on');
        $this->assertSame(0, $this->countRows('stock_permits'));
    }

    public function testCreate_AcceptsAddAndIssueWithoutASettingAndRejectsASecondLocationOnAnAddPermit(): void
    {
        $second = $this->openLocation();
        $itemId = $this->stocked('10', '50');

        $this->assertGreaterThan(0, $this->add($itemId, '1', '1'));
        $this->assertGreaterThan(0, $this->issue($itemId, '1'));
        $this->refusedWith(AccountingError::StockPermitLocationInvalid, fn () => $this->add($itemId, '1', '1', ['to_location_id' => $second]));
    }

    public function testCreate_RequiresThePermissionToManagePermits(): void
    {
        $itemId = $this->stocked('10', '50');
        $clerk  = $this->keeper(null, [StockPermissions::MODULE, 'items_stock']);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '1']]), $clerk));
        $this->assertSame(0, $this->countRows('stock_permits'));
    }

    public function testCreate_RequiresTheItemsPermissionOfTheLocation(): void
    {
        $itemId = $this->stocked('10', '50');
        $clerk  = $this->keeper(null, [StockPermissions::PERMITS_MANAGE]);

        $this->refusedWith(AccountingError::StockLocationDenied, fn () => $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '1']]), $clerk));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testCreate_RequiresTheEmployeeToWorkInTheBranchOfTheLocation(): void
    {
        $other  = $this->createBranch();
        $itemId = $this->stocked('10', '50');
        $clerk  = $this->keeper([$other]);

        try {
            $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '1']]), $clerk);
            $this->fail('An employee of another branch must be refused.');
        } catch (BranchException) {
            $this->assertSame(0, $this->countRows('stock_permits'));
            $this->assertSame('10.000', $this->onHand($itemId));
        }
    }

    public function testCreate_RequiresTheEmployeeInBothBranchesAndBothLocationsOfATransfer(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $itemId = $this->stocked('10', '50');
        $line   = [['item_id' => $itemId, 'quantity' => '1']];

        $inOne = $this->keeper([1]);
        $this->allowLocation($store, $inOne);

        try {
            $this->permits->create($this->input(StockPermitType::Transfer, $line, ['to_location_id' => $store]), $inOne);
            $this->fail('An employee who works in one of the two branches must be refused.');
        } catch (BranchException) {
            $this->assertSame(0, $this->countRows('stock_permits'));
        }

        $noGrant = $this->keeper();
        $posted  = $this->countRows('accounting_journals');

        $this->refusedWith(AccountingError::StockLocationDenied, fn () => $this->permits->create($this->input(StockPermitType::Transfer, $line, ['to_location_id' => $store]), $noGrant));
        $this->assertSame($posted, $this->countRows('accounting_journals'));
    }

    public function testCreate_RollsBackThePermitTheShelvesAndTheMovementsWhenThePostingIsRefused(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->closeThrough(date('Y-m-d', strtotime('-1 day')));

        $before = $this->countRows('accounting_stock_entries');

        try {
            $this->add($itemId, '2', '150', ['date' => date('Y-m-d', strtotime('-1 day'))]);
            $this->fail('A permit in a closed period must be refused.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame(0, $this->countRows('stock_permits'));
        $this->assertSame($before, $this->countRows('accounting_stock_entries'));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('50.000000', $this->exactCostOf($itemId), 'the cost the item carries is not touched either');
        $this->assertSame(1, $this->countRows('accounting_posting_failures'), 'the refusal is written to the failure log');
    }

    public function testCreate_RejectsAPermitWorthNothingInAClosedPeriodToo(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->closeThrough(date('Y-m-d', strtotime('-1 day')));

        try {
            $this->add($itemId, '2', '0', ['date' => date('Y-m-d', strtotime('-1 day'))]);
            $this->fail('A permit in a closed period must be refused even when it posts no journal.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testCreate_RollsBackATransferWhenTheOtherBranchIsInAClosedPeriod(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');
        $this->closeThrough(date('Y-m-d', strtotime('-1 day')), $other);

        try {
            $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['to_location_id' => $store, 'date' => date('Y-m-d', strtotime('-1 day'))]), $this->keeperId);
            $this->fail('A transfer into a branch whose period is closed must be refused.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame(0, $this->countRows('stock_permits'));
        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));
    }

    public function testCreate_MovesTheShelvesAndPostsNothingForAPermitDatedBeforeTheBooksStart(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->setConfigValue('accounting_start_date', date('Y-m-d'));

        $permitId = $this->add($itemId, '2', '150', ['date' => date('Y-m-d', strtotime('-3 days'))]);

        $this->assertSame([], $this->journalsOfSource(StockPermitSources::PERMIT, $permitId));
        $this->assertSame([], $this->movementsOfSource(StockPermitSources::PERMIT, $permitId));
        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertSame(0, (int) $this->permits->find($permitId)->booked);
    }

    public function testCreate_MovesTheShelvesAndNumbersThePermitWithAccountingOffAndPostsNothing(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->numberWith(DocumentType::StockPermitAdd, 'ADD-', 4);
        $this->setConfigValue('accounting_enabled', '0');

        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        $permitId = $this->add($itemId, '2', '150');

        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame('ADD-0001', $this->permits->numberOf($permitId));
        $this->assertSame('50.000000', $this->exactCostOf($itemId), 'the cost is left as it is while the books and the setting are off');

        $this->permits->cancel($permitId, 'wrong', $this->keeperId);

        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testCreate_RejectsAnIssueBelowZeroWithAccountingOffToo(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->setConfigValue('accounting_enabled', '0');

        $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->issue($itemId, '11'));
    }

    public function testCreate_WritesTheInventoryRecordOfEveryShelfChange(): void
    {
        $itemId = $this->stocked('10', '50');

        $permitId = $this->add($itemId, '2', '150');

        $row = $this->accountingDb()->table('inventory')->where('trans_comment', 'STKP ' . $permitId)->get()->getRow();

        $this->assertSame('2.000', bcadd((string) $row->trans_inventory, '0', 3));
        $this->assertSame($itemId, (int) $row->trans_items);
        $this->assertSame($this->keeperId, (int) $row->trans_user);
    }

    public function testCreate_WritesTheAuditLogWithWhatWasMoved(): void
    {
        $itemId = $this->stocked('10', '50');

        $permitId = $this->add($itemId, '2', '150');

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stock_permit', 'entity_id' => $permitId])->get()->getRow();

        $this->assertSame('stock_permit_created', $entry->action);
        $this->assertSame($this->keeperId, (int) $entry->employee_id);
        $this->assertSame(1, (int) $entry->branch_id);
        $this->assertSame('add', json_decode((string) $entry->details, true)['type']);
    }

    public function testCreate_RejectsWhatIsNotAValidPermit(): void
    {
        $itemId  = $this->stocked('10', '50');
        $service = $this->item('1.00', false);
        $line    = ['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '1'];

        $cases = [
            'no lines'            => [AccountingError::StockPermitLinesInvalid, ['lines' => []]],
            'too many lines'      => [AccountingError::StockPermitLinesInvalid, ['lines' => array_fill(0, 201, $line)]],
            'a quantity too big'  => [AccountingError::StockPermitLinesInvalid, ['lines' => [[...$line, 'quantity' => '999999999.001']]]],
            'zero quantity'       => [AccountingError::StockPermitLinesInvalid, ['lines' => [[...$line, 'quantity' => '0']]]],
            'negative quantity'   => [AccountingError::StockPermitLinesInvalid, ['lines' => [[...$line, 'quantity' => '-1']]]],
            'four decimals'       => [AccountingError::StockPermitLinesInvalid, ['lines' => [[...$line, 'quantity' => '1.0001']]]],
            'text quantity'       => [AccountingError::StockPermitLinesInvalid, ['lines' => [[...$line, 'quantity' => 'abc']]]],
            'text cost'           => [AccountingError::StockPermitLinesInvalid, ['lines' => [[...$line, 'unit_cost' => '-5']]]],
            'unknown item'        => [AccountingError::StockPermitLinesInvalid, ['lines' => [[...$line, 'item_id' => 999999]]]],
            'item without stock'  => [AccountingError::StockPermitLinesInvalid, ['lines' => [[...$line, 'item_id' => $service]]]],
            'bad date'            => [AccountingError::InvalidDate, ['date' => '2026-02-31']],
            'a day to come'       => [AccountingError::InvalidDate, ['date' => date('Y-m-d', strtotime('+1 day'))]],
            'long note'           => [AccountingError::StockPermitNoteTooLong, ['note' => str_repeat('x', 501)]],
            'unknown type'        => [AccountingError::StockPermitTypeInvalid, ['type' => 'swap']],
            'unknown location'    => [AccountingError::StockPermitLocationInvalid, ['location_id' => 999999]],
            'customer on an add'  => [AccountingError::StockPermitPartyInvalid, ['customer_id' => $this->customerId]],
            'unknown supplier'    => [AccountingError::StockPermitPartyInvalid, ['supplier_id' => 999999]],
            'account of a party'  => [AccountingError::StockPermitAccountInvalid, ['account_id' => $this->supplierAccount()]],
            'a routed account'    => [AccountingError::StockPermitAccountInvalid, ['account_id' => $this->accountId('cash')]],
            'a group account'     => [AccountingError::StockPermitAccountInvalid, ['account_id' => $this->accountIdByCode('211')]],
        ];

        foreach ($cases as $name => [$error, $more]) {
            $this->refusedWith($error, fn () => $this->permits->create(array_merge($this->input(StockPermitType::Add, [$line]), $more), $this->keeperId));
        }

        $this->assertSame(0, $this->countRows('stock_permits'), 'no refusal left a permit behind');
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testCreate_RejectsASupplierOnAnIssueACustomerOnAnAddAndTheAccountOfAnotherSide(): void
    {
        $itemId  = $this->stocked('10', '50');
        $revenue = $this->customAccount('42', '4299', 'Some revenue');

        $this->refusedWith(AccountingError::StockPermitPartyInvalid, fn () => $this->issue($itemId, '1', ['supplier_id' => $this->supplierId]));
        $this->refusedWith(AccountingError::StockPermitAccountInvalid, fn () => $this->issue($itemId, '1', ['account_id' => $revenue]));
        $this->assertGreaterThan(0, $this->add($itemId, '1', '1', ['account_id' => $revenue]));
    }

    public function testAllowedAccounts_ListsOpenLeavesWithoutAPartyOfAKindThatFitsTheSideOfThePermit(): void
    {
        $liability = $this->customAccount('211', '21198', 'Fitting liability');
        $asset     = $this->customAccount('12', '1298', 'Fitting asset');

        $add   = array_map(static fn (object $account): int => (int) $account->account_id, $this->permits->allowedAccounts(StockPermitType::Add));
        $issue = array_map(static fn (object $account): int => (int) $account->account_id, $this->permits->allowedAccounts(StockPermitType::Issue));

        $this->assertContains($liability, $add);
        $this->assertNotContains($asset, $add);
        $this->assertContains($asset, $issue);
        $this->assertNotContains($liability, $issue);
        $this->assertContains($this->accountId('other_payables'), $add, 'the automatic account of add permits may be named');
        $this->assertNotContains($this->accountId('cash'), $issue);
        $this->assertNotContains($this->stockAccount(), $issue, 'the account of a stock location belongs to its location');
        $this->assertSame([], $this->permits->allowedAccounts(StockPermitType::Transfer));
    }

    /**
     * @return object|null The journal that reverses the journal given.
     */
    private function reversalOf(int $journalId): ?object
    {
        return $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journalId)->get()->getRow();
    }

    public function testCancel_ReversesTheJournalAndTheMovementsAndPutsTheShelvesAndTheCostBack(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '10', '70');

        $this->assertSame('60.000000', $this->exactCostOf($itemId));

        $this->permits->cancel($permitId, 'entered twice', $this->keeperId);

        $journal  = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)[0];
        $reversal = $this->reversalOf((int) $journal->journal_id);

        $this->assertSame('reversed', $journal->status);
        $this->assertNotNull($reversal);
        $this->assertSame([$this->stockAccount() . ':0.00:700.00', $this->accountId('other_payables') . ':700.00:0.00'], $this->linesOfJournal((int) $reversal->journal_id));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($itemId));
        $this->assertSame('50.000000', $this->exactCostOf($itemId));
        $this->assertSame(['posting', 'reversal'], array_map(static fn (object $entry): string => $entry->kind, $this->movementsOfSource(StockPermitSources::PERMIT, $permitId)));
        $this->assertSame('500.00', $this->balance($this->stockAccount()));

        $row = $this->permits->find($permitId);

        $this->assertSame('cancelled', $row->status);
        $this->assertSame('entered twice', $row->cancel_reason);
        $this->assertSame($this->keeperId, (int) $row->cancelled_by);
        $this->assertBooksTie();
    }

    public function testCancel_KeepsThePermitAndItsLinesAndWritesTheAuditLog(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '2', '10');

        $this->permits->cancel($permitId, 'wrong item', $this->keeperId);

        $this->assertCount(1, $this->permits->lines($permitId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stock_permit', 'entity_id' => $permitId, 'action' => 'stock_permit_cancelled'])->countAllResults());
        $this->assertSame('wrong item', json_decode((string) $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $permitId, 'action' => 'stock_permit_cancelled'])->get()->getRow()->details, true)['reason']);
    }

    public function testCancel_PutsAnIssuedUnitBackAtWhatItLeftAt(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '70')]));

        $permitId = $this->issue($itemId, '3');
        $this->permits->cancel($permitId, 'sent back', $this->keeperId);

        $this->assertSame('20.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '20.000', 'value' => '1200.00'], $this->stockOf($itemId));
        $this->assertBooksTie();
    }

    public function testCancel_ReversesBothJournalsOfATransferBetweenBranchesAndTheirMovements(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');

        $permitId = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['to_location_id' => $store]), $this->keeperId);

        $this->permits->cancel($permitId, 'wrong store', $this->keeperId);

        foreach ([StockPermitSources::TRANSFER, StockPermitSources::TRANSFER_RECEIPT] as $source) {
            $journal = $this->journalsOfSource($source, $permitId)[0];

            $this->assertSame('reversed', $journal->status, $source);
            $this->assertNotNull($this->reversalOf((int) $journal->journal_id), $source);
            $this->assertSame(['posting', 'reversal'], array_map(static fn (object $entry): string => $entry->kind, $this->movementsOfSource($source, $permitId)), $source);
        }

        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('0.000', $this->onHand($itemId, $store));
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
        $this->assertBooksTie();
    }

    public function testCancel_ReversesBothJournalsOfATransferSentFromTheHigherBranchToTheLowerOne(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50', $store);

        $permitId = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['location_id' => $store, 'to_location_id' => self::LOCATION_ID]), $this->keeperId);

        $sending   = $this->journalsOfSource(StockPermitSources::TRANSFER, $permitId)[0];
        $receiving = $this->journalsOfSource(StockPermitSources::TRANSFER_RECEIPT, $permitId)[0];

        $this->assertSame([$other, 1], [(int) $sending->branch_id, (int) $receiving->branch_id]);

        $this->permits->cancel($permitId, 'sent back by mistake', $this->keeperId);

        foreach ([StockPermitSources::TRANSFER, StockPermitSources::TRANSFER_RECEIPT] as $source) {
            $journal = $this->journalsOfSource($source, $permitId)[0];

            $this->assertSame('reversed', $journal->status, $source);
            $this->assertNotNull($this->reversalOf((int) $journal->journal_id), $source);
            $this->assertSame(['posting', 'reversal'], array_map(static fn (object $entry): string => $entry->kind, $this->movementsOfSource($source, $permitId)), $source);
        }

        $this->assertSame('10.000', $this->onHand($itemId, $store));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
        $this->assertBooksTie();
    }

    public function testCancel_PostsTheReversalTodayWhenThePeriodOfThePermitIsClosedAndTheJournalStaysWhereItWas(): void
    {
        $itemId   = $this->stocked('10', '50');
        $day      = date('Y-m-d', strtotime('-3 days'));
        $permitId = $this->add($itemId, '2', '150', ['date' => $day]);

        $this->closeThrough(date('Y-m-d', strtotime('-1 day')));
        $this->permits->cancel($permitId, 'late', $this->keeperId);

        $journal  = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)[0];
        $reversal = $this->reversalOf((int) $journal->journal_id);

        $this->assertSame($day, $journal->journal_date, 'the journal of the closed period is not rewritten');
        $this->assertSame(date('Y-m-d'), $reversal->journal_date);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame(date('Y-m-d'), $this->movementsOfSource(StockPermitSources::PERMIT, $permitId)[1]->entry_date);
        $this->assertBooksTie();
    }

    public function testCancel_RejectsAnAddPermitWhoseUnitsAreGoneAndChangesNothing(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '5', '50');

        $this->issue($itemId, '12');

        $journals = $this->countRows('accounting_journals');

        $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->permits->cancel($permitId, 'too late', $this->keeperId));

        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('posted', $this->permits->find($permitId)->status);
        $this->assertBooksTie();
    }

    public function testCancel_AcceptsAnAddPermitWhoseUnitsAreStillThereAfterOthersWereSold(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '5', '50');

        $this->issue($itemId, '4');
        $this->permits->cancel($permitId, 'fine', $this->keeperId);

        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertBooksTie();
    }

    public function testCancel_RejectsACancelledPermitAMissingOneAndAMissingReason(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '2', '10');

        $this->refusedWith(AccountingError::ReasonRequired, fn () => $this->permits->cancel($permitId, '  ', $this->keeperId));
        $this->refusedWith(AccountingError::ReasonRequired, fn () => $this->permits->cancel($permitId, str_repeat('x', 256), $this->keeperId));

        $this->permits->cancel($permitId, 'once', $this->keeperId);

        $this->refusedWith(AccountingError::StockPermitCancelled, fn () => $this->permits->cancel($permitId, 'twice', $this->keeperId));
        $this->refusedWith(AccountingError::StockPermitNotFound, fn () => $this->permits->cancel(999999, 'none', $this->keeperId));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testCancel_RequiresAReasonWhileTheBooksDoNotFollowThePermitToo(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->setConfigValue('accounting_enabled', '0');

        $permitId = $this->add($itemId, '2', '10');

        $this->refusedWith(AccountingError::ReasonRequired, fn () => $this->permits->cancel($permitId, '', $this->keeperId));
        $this->refusedWith(AccountingError::ReasonRequired, fn () => $this->permits->cancel($permitId, str_repeat('x', 256), $this->keeperId));

        $this->assertSame('posted', $this->permits->find($permitId)->status);
        $this->assertSame('12.000', $this->onHand($itemId));
    }

    public function testCancel_RequiresThePermissionTheLocationsAndTheBranchOfThePermit(): void
    {
        $other    = $this->createBranch();
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '2', '10');

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->permits->cancel($permitId, 'no', $this->keeper(null, [StockPermissions::MODULE, 'items_stock'])));
        $this->refusedWith(AccountingError::StockLocationDenied, fn () => $this->permits->cancel($permitId, 'no', $this->keeper(null, [StockPermissions::PERMITS_MANAGE])));

        try {
            $this->permits->cancel($permitId, 'no', $this->keeper([$other]));
            $this->fail('An employee of another branch must be refused.');
        } catch (BranchException) {
            $this->assertSame('posted', $this->permits->find($permitId)->status);
        }
    }

    public function testUpdate_ReversesWhatWasPostedAndPostsTheCorrectedLinesAsTheNextRevision(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '2', '150');

        $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '3', 'unit_cost' => '100']], 'note' => 'corrected'], $this->keeperId);

        $journals = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId);

        $this->assertCount(2, $journals);
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $journals));
        $this->assertSame([1, 2], array_map(static fn (object $journal): int => (int) $journal->revision, $journals));
        $this->assertSame([$this->stockAccount() . ':300.00:0.00', $this->accountId('other_payables') . ':0.00:300.00'], $this->linesOfJournal((int) $journals[1]->journal_id));
        $this->assertSame('13.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '13.000', 'value' => '800.00'], $this->stockOf($itemId));
        $this->assertSame(['posting', 'reversal', 'posting'], array_map(static fn (object $entry): string => $entry->kind, $this->movementsOfSource(StockPermitSources::PERMIT, $permitId)));

        $row = $this->permits->find($permitId);

        $this->assertSame('corrected', $row->note);
        $this->assertSame(2, (int) $row->revision);
        $this->assertSame('3.000', bcadd((string) $this->permits->lines($permitId)[0]->quantity, '0', 3));
        $this->assertBooksTie();
    }

    public function testUpdate_ChangesTheItemAndTheDateAndTheAccountAndKeepsTheNumber(): void
    {
        $first   = $this->stocked('10', '50');
        $second  = $this->stocked('10', '20');
        $account = $this->customAccount('211', '21197', 'Corrected creditors');
        $this->numberWith(DocumentType::StockPermitAdd, 'IN-', 3);

        $permitId = $this->add($first, '2', '150');
        $day      = date('Y-m-d', strtotime('-2 days'));

        $this->permits->update($permitId, ['date' => $day, 'account_id' => $account, 'lines' => [['item_id' => $second, 'quantity' => '1', 'unit_cost' => '40']]], $this->keeperId);

        $this->assertSame('IN-001', $this->permits->numberOf($permitId));
        $this->assertSame('10.000', $this->onHand($first));
        $this->assertSame('11.000', $this->onHand($second));
        $this->assertSame('50.000000', $this->exactCostOf($first));

        $standing = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)[1];

        $this->assertSame($day, $standing->journal_date);
        $this->assertSame([$this->stockAccount() . ':40.00:0.00', $account . ':0.00:40.00'], $this->linesOfJournal((int) $standing->journal_id));
        $this->assertBooksTie();
    }

    public function testUpdate_RepricesAnIssuePermitAtTheAverageOfTheMomentOfTheCorrection(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->issue($itemId, '2');

        $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '4']]], $this->keeperId);

        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '6.000', 'value' => '300.00'], $this->stockOf($itemId));
        $this->assertSame([$this->accountId('other_receivables') . ':200.00:0.00', $this->stockAccount() . ':0.00:200.00'], $this->linesOfJournal((int) $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)[1]->journal_id));
        $this->assertBooksTie();
    }

    public function testUpdate_CorrectsATransferBetweenBranchesInBothBranches(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');

        $permitId = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['to_location_id' => $store]), $this->keeperId);

        $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '6']]], $this->keeperId);

        $this->assertSame('4.000', $this->onHand($itemId));
        $this->assertSame('6.000', $this->onHand($itemId, $store));
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $this->journalsOfSource(StockPermitSources::TRANSFER, $permitId)));
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $this->journalsOfSource(StockPermitSources::TRANSFER_RECEIPT, $permitId)));
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
        $this->assertBooksTie();
    }

    public function testUpdate_CorrectsATransferSentFromTheHigherBranchToTheLowerOne(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50', $store);

        $permitId = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['location_id' => $store, 'to_location_id' => self::LOCATION_ID]), $this->keeperId);

        $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '6']]], $this->keeperId);

        $this->assertSame('4.000', $this->onHand($itemId, $store));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $this->journalsOfSource(StockPermitSources::TRANSFER, $permitId)));
        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $this->journalsOfSource(StockPermitSources::TRANSFER_RECEIPT, $permitId)));
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
        $this->assertBooksTie();
    }

    public function testUpdate_RejectsANetChangeThatWouldLeaveTheShelfBelowZero(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '5', '50');

        $this->issue($itemId, '12');

        $journals = $this->countRows('accounting_journals');

        $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '50']]], $this->keeperId));

        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(1, (int) $this->permits->find($permitId)->revision);
    }

    public function testUpdate_AcceptsAMoreThanTheShelfLostWhenTheNetChangeStaysAboveZero(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '5', '50');

        $this->issue($itemId, '12');
        $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '6', 'unit_cost' => '50']]], $this->keeperId);

        $this->assertSame('4.000', $this->onHand($itemId));
        $this->assertBooksTie();
    }

    public function testUpdate_RejectsAClosedPeriodAndLeavesTheOldPostingStanding(): void
    {
        $itemId   = $this->stocked('10', '50');
        $day      = date('Y-m-d', strtotime('-3 days'));
        $permitId = $this->add($itemId, '2', '150', ['date' => $day]);

        $this->closeThrough(date('Y-m-d', strtotime('-1 day')));

        try {
            $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '3', 'unit_cost' => '150']]], $this->keeperId);
            $this->fail('A permit of a closed period cannot be corrected where it stands.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertSame(['posted'], array_map(static fn (object $journal): string => $journal->status, $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)));
        $this->assertSame(1, (int) $this->permits->find($permitId)->revision);
    }

    public function testUpdate_MovesAPermitOfAClosedPeriodToAnOpenDayWithoutRewritingTheClosedOne(): void
    {
        $itemId   = $this->stocked('10', '50');
        $day      = date('Y-m-d', strtotime('-3 days'));
        $permitId = $this->add($itemId, '2', '150', ['date' => $day]);

        $this->closeThrough(date('Y-m-d', strtotime('-1 day')));
        $this->permits->update($permitId, ['date' => date('Y-m-d'), 'lines' => [['item_id' => $itemId, 'quantity' => '2', 'unit_cost' => '150']]], $this->keeperId);

        $journals = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId);

        $this->assertSame($day, $journals[0]->journal_date);
        $this->assertSame(date('Y-m-d'), $journals[1]->journal_date);
        $this->assertBooksTie();
    }

    public function testUpdate_RejectsACancelledPermitAndOneThatNeedsAnotherTypeOfLine(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '2', '10');

        $this->refusedWith(AccountingError::StockPermitLinesInvalid, fn () => $this->permits->update($permitId, ['lines' => []], $this->keeperId));

        $this->permits->cancel($permitId, 'gone', $this->keeperId);

        $this->refusedWith(AccountingError::StockPermitCancelled, fn () => $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '1']]], $this->keeperId));
        $this->refusedWith(AccountingError::StockPermitNotFound, fn () => $this->permits->update(999999, ['lines' => [['item_id' => $itemId, 'quantity' => '1']]], $this->keeperId));
    }

    public function testUpdate_RequiresThePermissionAndWritesTheAuditLogWithTheLinesBeforeAndAfter(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '2', '10');

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '1']]], $this->keeper(null, [StockPermissions::MODULE, 'items_stock'])));

        $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '3', 'unit_cost' => '10']]], $this->keeperId);

        $details = json_decode((string) $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $permitId, 'action' => 'stock_permit_edited'])->get()->getRow()->details, true);

        $this->assertSame('2.000', $details['before'][0]['quantity']);
        $this->assertSame('3.000', $details['after'][0]['quantity']);
    }

    public function testNumberOf_GivesEachKindOfPermitASequenceOfItsOwnAndKeepsTheNumberWhenThePermitIsCorrected(): void
    {
        $this->switchOnTransferPermit();

        $second = $this->openLocation();
        $itemId = $this->stocked('50', '10');
        $this->numberWith(DocumentType::StockPermitAdd, 'IN-', 3);
        $this->numberWith(DocumentType::StockPermitIssue, 'OUT-', 3);
        $this->numberWith(DocumentType::StockPermitTransfer, 'TRF-', 3);

        $first    = $this->add($itemId, '1', '10');
        $issued   = $this->issue($itemId, '1');
        $again    = $this->add($itemId, '1', '10');
        $transfer = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => $second]), $this->keeperId);

        $this->assertSame(['IN-001', 'OUT-001', 'IN-002', 'TRF-001'], [$this->permits->numberOf($first), $this->permits->numberOf($issued), $this->permits->numberOf($again), $this->permits->numberOf($transfer)]);
        $this->assertSame('IN-003', $this->permits->peekNumber(StockPermitType::Add));
        $this->assertSame('OUT-002', $this->permits->peekNumber(StockPermitType::Issue));

        $this->permits->update($first, ['lines' => [['item_id' => $itemId, 'quantity' => '2', 'unit_cost' => '10']]], $this->keeperId);
        $this->permits->cancel($again, 'gone', $this->keeperId);

        $this->assertSame('IN-001', $this->permits->numberOf($first));
        $this->assertSame('IN-002', $this->permits->numberOf($again), 'a cancelled permit keeps its number, which is never given again');
        $this->assertSame('IN-003', $this->permits->peekNumber(StockPermitType::Add));
        $this->assertSame('IN-001', $this->permits->find($first)->document_number);
    }

    public function testNumberOf_ShowsTheKindAndTheIdWhileTheSequenceIsOff(): void
    {
        $itemId = $this->stocked('10', '50');

        $permitId = $this->add($itemId, '1', '10');
        $issued   = $this->issue($itemId, '1');

        $this->assertSame('STK-IN ' . $permitId, $this->permits->numberOf($permitId));
        $this->assertSame('STK-OUT ' . $issued, $this->permits->numberOf($issued));
        $this->assertNull($this->permits->peekNumber(StockPermitType::Add));
        $this->assertNull($this->permits->find($permitId)->document_number);
    }

    public function testSearch_ListsTheNewestFirstAndFiltersByTypeStatusDateAndNumber(): void
    {
        $itemId = $this->stocked('20', '10');
        $this->numberWith(DocumentType::StockPermitAdd, 'IN-', 3);

        $old    = $this->add($itemId, '1', '1', ['date' => date('Y-m-d', strtotime('-5 days'))]);
        $added  = $this->add($itemId, '1', '1', ['note' => 'for the shop']);
        $issued = $this->issue($itemId, '1');
        $this->permits->cancel($issued, 'x', $this->keeperId);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->permit_id, $result['rows']);

        $this->assertSame([$issued, $added, $old], $ids($this->permits->search([], $this->keeperId)));
        $this->assertSame([$added, $old], $ids($this->permits->search(['type' => 'add'], $this->keeperId)));
        $this->assertSame([$issued], $ids($this->permits->search(['status' => 'cancelled'], $this->keeperId)));
        $this->assertSame([$old], $ids($this->permits->search(['to' => date('Y-m-d', strtotime('-4 days'))], $this->keeperId)));
        $this->assertSame([$added], $ids($this->permits->search(['search' => 'shop'], $this->keeperId)));
        $this->assertSame([$old], $ids($this->permits->search(['search' => 'IN-001'], $this->keeperId)));
        $this->assertSame(3, $this->permits->search([], $this->keeperId)['total']);
        $this->assertCount(1, $this->permits->search([], $this->keeperId, 1, 1)['rows']);
    }

    public function testSearch_AcceptsTheLabelAndTheIdOfAPermitWhileNumberingIsOffAndOnlyThen(): void
    {
        $itemId = $this->stocked('20', '10');

        $added  = $this->add($itemId, '1', '1');
        $issued = $this->issue($itemId, '1');

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->permit_id, $result['rows']);

        $this->assertSame(['STK-OUT ' . $issued, 'STK-IN ' . $added], array_map(static fn (object $row): string => $row->label, $this->permits->search([], $this->keeperId)['rows']), 'the list says what the page of the permit says');
        $this->assertSame([$added], $ids($this->permits->search(['search' => 'STK-IN ' . $added], $this->keeperId)));
        $this->assertSame([$added], $ids($this->permits->search(['search' => (string) $added], $this->keeperId)));
        $this->assertSame([], $ids($this->permits->search(['search' => 'STK-OUT ' . $added], $this->keeperId)), 'the kind of the label counts');

        $this->numberWith(DocumentType::StockPermitAdd, 'IN-', 3);

        $numbered = $this->add($itemId, '1', '1');

        $this->assertSame('IN-001', $this->permits->search(['search' => 'IN-001'], $this->keeperId)['rows'][0]->label);
        $this->assertSame([], $ids($this->permits->search(['search' => (string) $numbered], $this->keeperId)), 'a permit that has a number is found by it, not by its id');
    }

    public function testSearch_ShowsAPermitOnlyToTheBranchesItBelongsToAndATransferToBoth(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('20', '10');

        $local    = $this->add($itemId, '1', '1');
        $across   = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => $store]), $this->keeperId);
        $ofOther  = $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '1']], ['location_id' => $store]), $this->keeperId);
        $ids      = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->permit_id, $result['rows']);

        $this->assertEqualsCanonicalizing([$local, $across], $ids($this->permits->search([], $this->keeper([1]))));
        $this->assertEqualsCanonicalizing([$across, $ofOther], $ids($this->permits->search([], $this->keeper([$other]))));
        $this->assertEqualsCanonicalizing([$local, $across, $ofOther], $ids($this->permits->search([], $this->keeperId)));
        $this->assertSame([], $this->permits->search([], $this->keeper([]))['rows']);
    }

    public function testCreate_RejectsTheSecondOfTwoPermitsAtTheSameMomentThatTakeMoreThanTheShelfHolds(): void
    {
        $itemId = $this->stocked('10', '50');

        $answers = $this->raceStockWorkers('issuePermit', [(string) $itemId, '7', (string) $this->keeperId], [(string) $itemId, '7', (string) $this->keeperId]);

        $this->assertSame(['done', AccountingError::PurchaseStockWouldGoNegative->value], $answers, 'Of two issues of seven from ten, one is made and the other is refused for the shelf, not for a deadlock');
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame(1, $this->countRows('stock_permits'));
        $this->assertSame(['quantity' => '3.000', 'value' => '150.00'], $this->stockOf($itemId));
        $this->assertBooksTie();
    }

    public function testCancel_ReversesThePermitOnceWhenTwoCancellationsComeAtTheSameMoment(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '5', '50');

        $answers = $this->raceStockWorkers('cancelPermit', [(string) $permitId, (string) $this->keeperId], [(string) $permitId, (string) $this->keeperId]);

        $this->assertSame(['done', AccountingError::StockPermitCancelled->value], $answers);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());
        $this->assertBooksTie();
    }

    public function testCreate_LeavesALegacyTransferAndAQuantityAdjustmentAsTheyAre(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->add($itemId, '2', '50');

        $this->assertSame([], $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['stock_adjustment', 'stock_transfer', 'stock_transfer_receipt'])->get()->getResult());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'purchase')->countAllResults());
    }

    public function testCreate_PostsTheFiguresOfTheLegacyTransferBetweenBranchesThroughARequisitionReceiving(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch('B3', 'Third branch');
        $store  = $this->locationOfBranch($other, 'Branch warehouse');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '60.00']);

        $permitId    = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['to_location_id' => $store]), $this->keeperId);
        $receivingId = $this->buy([$this->purchaseLine($itemId, 0, '4', '0', '0', '0', PERCENT, $store), $this->purchaseLine($itemId, 1, '-4', '0', '0', '0', PERCENT, self::LOCATION_ID)], null, null);

        $this->assertGreaterThan(0, $receivingId);

        $legacy = [$this->standingPurchase($receivingId, 'stock_transfer')[0]['lines'], $this->standingPurchase($receivingId, 'stock_transfer_receipt')[0]['lines']];
        $permit = [$this->linesOfJournal((int) $this->journalsOfSource(StockPermitSources::TRANSFER, $permitId)[0]->journal_id), $this->linesOfJournal((int) $this->journalsOfSource(StockPermitSources::TRANSFER_RECEIPT, $permitId)[0]->journal_id)];

        $this->assertSame($legacy, $permit, 'the permit posts what the transfer through a receiving posts, at what the units are worth where they leave (50.00) and not what the item carries (60.00)');
        $this->assertSame([], $this->journalsOfSource('stock_transfer', $permitId), 'a permit never writes under the source of the receiving transfer');
        $this->assertBooksTie();
    }

    public function testCreate_TakesThePeriodLocksOfATransferInTheOrderOfTheBranchIds(): void
    {
        $this->switchOnTransferPermit();

        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');

        $this->assertGreaterThan(0, $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '4']], ['to_location_id' => $store]), $this->keeperId));

        $back = fn (): int => $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['location_id' => $store, 'to_location_id' => self::LOCATION_ID]), $this->keeperId);

        $this->assertSame([1, $other, 1, $other], $this->periodLocksTakenBy($back), 'the lower branch first, in the check of the periods and in the posting, when the stock comes back too');
        $this->assertSame([1, $other, 1, $other], $this->periodLocksTakenBy(fn (): int => $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => $store]), $this->keeperId)));
    }

    public function testCreate_TakesTheLockOfTheItemsBeforeItReadsWhatTheBooksSayAboutThem(): void
    {
        $itemId  = $this->stocked('10', '50');
        $queries = $this->queriesOf(fn () => $this->issue($itemId, '2'));

        $lock  = $this->firstQuery($queries, ['ospos_items WHERE item_id IN', 'FOR UPDATE']);
        $books = $this->firstQuery($queries, ['accounting_stock_entries', 'SELECT']);

        $this->assertNotNull($lock, 'the items are locked');
        $this->assertLessThan($books, $lock);
    }

    public function testCancel_TakesTheLockOfTheItemsBeforeItReadsThePermit(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->add($itemId, '5', '50');
        $queries  = $this->queriesOf(fn () => $this->permits->cancel($permitId, 'x', $this->keeperId));

        $lock = $this->firstQuery($queries, ['ospos_items WHERE item_id IN', 'FOR UPDATE']);
        $row  = $this->firstQuery($queries, ['ospos_stock_permits WHERE permit_id']);

        $this->assertNotNull($lock);
        $this->assertLessThan($row, $lock);
    }

    public function testUpdate_TakesTheLockOfTheItemsOfThePermitAndOfTheNewLinesBeforeItReadsThePermit(): void
    {
        $first    = $this->stocked('10', '50');
        $second   = $this->stocked('10', '50');
        $permitId = $this->add($first, '5', '50');
        $queries  = $this->queriesOf(fn () => $this->permits->update($permitId, ['lines' => [['item_id' => $second, 'quantity' => '4', 'unit_cost' => '50']]], $this->keeperId));

        $lock = $this->firstQuery($queries, ['ospos_items WHERE item_id IN (' . min($first, $second) . ',' . max($first, $second) . ')', 'ORDER BY item_id FOR UPDATE']);
        $row  = $this->firstQuery($queries, ['ospos_stock_permits WHERE permit_id']);

        $this->assertNotNull($lock, 'both items, in the order of their ids');
        $this->assertLessThan($row, $lock);
    }

    public function testCreate_RejectsAReversalByHandOfTheJournalsOfAPermit(): void
    {
        $this->switchOnTransferPermit();

        $other    = $this->createBranch();
        $store    = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $second   = $this->openLocation();
        $itemId   = $this->stocked('20', '50');
        $reverser = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL]);
        $service  = new JournalService();

        $permit = $this->add($itemId, '2', '10');
        $same   = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => $second]), $this->keeperId);
        $across = $this->permits->create($this->input(StockPermitType::Transfer, [['item_id' => $itemId, 'quantity' => '1']], ['to_location_id' => $store]), $this->keeperId);

        $cases = [
            [AccountingError::DocumentJournalLocked, (int) $this->journalsOfSource(StockPermitSources::PERMIT, $permit)[0]->journal_id],
            [AccountingError::TransferJournalLocked, (int) $this->journalsOfSource(StockPermitSources::TRANSFER, $same)[0]->journal_id],
            [AccountingError::TransferJournalLocked, (int) $this->journalsOfSource(StockPermitSources::TRANSFER, $across)[0]->journal_id],
            [AccountingError::TransferJournalLocked, (int) $this->journalsOfSource(StockPermitSources::TRANSFER_RECEIPT, $across)[0]->journal_id],
        ];

        foreach ($cases as [$error, $journalId]) {
            $this->refusedWith($error, fn () => $service->reverse($journalId, date('Y-m-d'), 'By hand', $reverser));
        }

        $this->assertSame(['posted'], array_unique(array_map(static fn (array $case): string => (string) (new JournalService())->find($case[1])->status, $cases)));
    }

    /**
     * Runs the action and, the moment it has locked its items, lets another request change what the
     * permit holds, over a second connection that commits at once.
     */
    private function whileLockedAnotherRequestChangesThePermit(int $permitId, int $toItem, callable $action): void
    {
        $link     = $this->openSecondConnection(false);
        $fired    = false;
        $listener = static function ($query) use (&$fired, $link, $toItem, $permitId): void {
            $sql = (string) $query->getQuery();

            if (! $fired && str_contains($sql, 'ospos_items WHERE item_id IN') && str_contains($sql, 'FOR UPDATE')) {
                $fired = true;
                $link->query('UPDATE ' . $link->prefixTable('stock_permit_lines') . ' SET item_id = ? WHERE permit_id = ?', [$toItem, $permitId]);
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
            $this->fail('A change prepared on what the permit held before must be asked to be made again.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::WriteFailed, $exception->error);
            $this->assertTrue($fired);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
    }

    public function testCancel_AsksToBeMadeAgainWhenAnotherRequestChangedTheItemsOfThePermit(): void
    {
        $first    = $this->stocked('10', '50');
        $second   = $this->stocked('10', '50');
        $permitId = $this->add($first, '5', '50');

        $this->whileLockedAnotherRequestChangesThePermit($permitId, $second, fn () => $this->permits->cancel($permitId, 'x', $this->keeperId));

        $this->assertSame('posted', $this->permits->find($permitId)->status);
        $this->assertSame('15.000', $this->onHand($first));
        $this->assertSame('10.000', $this->onHand($second));
    }

    public function testUpdate_AsksToBeMadeAgainWhenAnotherRequestChangedTheItemsOfThePermit(): void
    {
        $first    = $this->stocked('10', '50');
        $second   = $this->stocked('10', '50');
        $permitId = $this->add($first, '5', '50');

        $this->whileLockedAnotherRequestChangesThePermit($permitId, $second, fn () => $this->permits->update($permitId, ['lines' => [['item_id' => $first, 'quantity' => '4', 'unit_cost' => '50']]], $this->keeperId));

        $this->assertSame(1, (int) $this->permits->find($permitId)->revision);
        $this->assertSame('15.000', $this->onHand($first));
    }

    // ---------------------------------------------------------------------------------------
    // Lines entered in a larger unit
    // ---------------------------------------------------------------------------------------

    public function testCreate_PutsAnAddPermitEnteredInCartonsIntoTheLocationInBaseUnitsAtTheCostOfAPiece(): void
    {
        $setup  = $this->cartonItem('0.00');
        $itemId = $setup['item'];

        $permitId = $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'unit_id' => $setup['carton'], 'unit_quantity' => '2', 'unit_price' => '60']]), $this->keeperId);

        $line = $this->accountingDb()->table('stock_permit_lines')->where('permit_id', $permitId)->get()->getRow();

        $this->assertSame(['24.000', '5.000000', $setup['carton'], '12.0000', '2.000', '60.000000'], [$line->quantity, $line->unit_cost, (int) $line->unit_id, $line->unit_factor, $line->unit_quantity, $line->unit_cost_entered]);
        $this->assertSame('24.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '24.000', 'value' => '120.00'], $this->stockOf($itemId));
        $this->assertSame([$this->stockAccount() . ':120.00:0.00', $this->accountId('other_payables') . ':0.00:120.00'], $this->linesOfJournal((int) $this->journalsOfSource(StockPermitSources::PERMIT, $permitId)[0]->journal_id));
        $this->assertBooksTie();
    }

    public function testCreate_TakesAnIssuePermitEnteredInCartonsOutAtWhatABasePieceIsWorth(): void
    {
        $setup  = $this->cartonItem('0.00');
        $itemId = $setup['item'];

        $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'unit_id' => $setup['carton'], 'unit_quantity' => '3', 'unit_price' => '60']]), $this->keeperId);
        $this->permits->create($this->input(StockPermitType::Issue, [['item_id' => $itemId, 'unit_id' => $setup['carton'], 'unit_quantity' => '1']]), $this->keeperId);

        $this->assertSame('24.000', $this->onHand($itemId));
        $this->assertSame(['quantity' => '24.000', 'value' => '120.00'], $this->stockOf($itemId));
        $this->assertBooksTie();
    }

    public function testCreate_RejectsAPermitLineInAUnitThatIsNotOfTheTemplateOfItsItem(): void
    {
        $setup = $this->cartonItem('0.00');
        $other = $this->item('0.00');

        $this->refusedWith(AccountingError::UnitNotOfItem, fn (): int => $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $other, 'unit_id' => $setup['carton'], 'unit_quantity' => '1', 'unit_price' => '60']]), $this->keeperId));
        $this->assertSame(0, $this->accountingDb()->table('stock_permits')->countAllResults());
    }

    public function testCreate_RejectsACartonCostThatDoesNotDivideIntoACostPerPieceOfSixDecimals(): void
    {
        $setup = $this->cartonItem('0.00');

        $this->refusedWith(AccountingError::UnitPriceInexact, fn (): int => $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $setup['item'], 'unit_id' => $setup['carton'], 'unit_quantity' => '1', 'unit_price' => '1']]), $this->keeperId));
    }

    public function testCreate_RejectsAPermitLineInAUnitWhileTheUnitsSettingIsOff(): void
    {
        $setup = $this->cartonItem('0.00');

        $this->setConfigValue(StockOptions::UNITS, '0');

        $this->refusedWith(AccountingError::UnitsOff, fn (): int => $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $setup['item'], 'unit_id' => $setup['carton'], 'unit_quantity' => '1', 'unit_price' => '60']]), $this->keeperId));
    }

    public function testUpdate_ReplacesAUnitLineWithABaseLineAndTheShelfFollows(): void
    {
        $setup    = $this->cartonItem('0.00');
        $itemId   = $setup['item'];
        $permitId = $this->permits->create($this->input(StockPermitType::Add, [['item_id' => $itemId, 'unit_id' => $setup['carton'], 'unit_quantity' => '2', 'unit_price' => '60']]), $this->keeperId);

        $this->permits->update($permitId, ['lines' => [['item_id' => $itemId, 'quantity' => '10', 'unit_cost' => '5']]], $this->keeperId);

        $line = $this->accountingDb()->table('stock_permit_lines')->where('permit_id', $permitId)->get()->getRow();

        $this->assertSame(['10.000', null], [$line->quantity, $line->unit_id]);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertBooksTie();
    }

    private function itemAfterTheObservedPermits(int $permits): int
    {
        $itemId = $this->stocked('5', '100');

        foreach (array_slice(['0', '150'], 0, $permits) as $cost) {
            $this->add($itemId, '2', $cost);
        }

        return $itemId;
    }

    /**
     * @param array{units: string, average: string} $before
     */
    private function assertShelfIs(int $itemId, array $before): void
    {
        $this->assertSame(bcadd($before['units'], '0', 3), $this->onHand($itemId));
        $this->assertSame(0, bccomp($before['average'], $this->exactCostOf($itemId), 6));
    }

    public function testCreate_BringsAnAddPermitAtPrice0InWithoutAJournalAndLowersTheAverageAsTheReferenceSystemDoes(): void
    {
        $case   = $this->referenceCase('T-53', 'stock_permit_in_at_price_0', 'in-permit-at-price-0-posts-no-journal-and-lowers-the-average', 'exact');
        $itemId = $this->itemAfterTheObservedPermits(0);

        $this->assertShelfIs($itemId, $case['inputs']['before']);

        $permitId = $this->add($itemId, $case['inputs']['permit']['units'], $case['inputs']['permit']['price']);
        $journals = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId);

        $this->assertSameAsFixture($case['expected'], ['in_at_price_0' => ['journal' => $journals === [] ? 'none' : 'posted', 'average_after' => $this->exactCostOf($itemId)]], 'in-permit at price 0');
        $this->assertSame(['quantity' => '7.000', 'value' => '500.00'], $this->stockOf($itemId), 'the units are in and the value of the stock does not move');
        $this->assertBooksTie();
    }

    public function testCreate_PostsAnAddPermitAt150OnAccount2213AsTheReferenceSystemDoes(): void
    {
        $case   = $this->referenceCase('T-53', 'stock_permit_in_with_counter_account', 'in-permit-at-150-on-2213-debits-stock-and-credits-other-payables', 'exact');
        $itemId = $this->itemAfterTheObservedPermits(1);
        $permit = $case['inputs']['permit'];

        $this->assertShelfIs($itemId, $case['inputs']['before']);

        $this->assertSame('2213', $permit['counter_account'], 'The fixture names the other creditors by the code they had before codes followed their parent');

        $permitId = $this->add($itemId, $permit['units'], $permit['price'], ['account_id' => $this->accountId('other_payables')]);
        $journals = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId);

        $this->assertCount(1, $journals);
        $this->assertSameAsFixture($case['expected'], ['in_at_150' => ['journals' => ['permit' => $this->roleLines((int) $journals[0]->journal_id, ['inventory' => $this->stockAccount(), 'other_payables' => $this->accountId('other_payables')], 2)]]], 'journal of the in-permit at 150');
        $this->assertSame(['quantity' => '9.000', 'value' => '800.00'], $this->stockOf($itemId));
        $this->assertSame('88.888889', $this->exactCostOf($itemId));
        $this->assertBooksTie();
    }

    public function testCreate_PostsAnIssuePermitOf3UnitsAtTheAverageAgainstOtherReceivablesAsTheReferenceSystemDoes(): void
    {
        $case   = $this->referenceCase('T-53', 'stock_permit_out', 'out-permit-of-3-units-debits-other-receivables-at-the-average', 'exact');
        $itemId = $this->itemAfterTheObservedPermits(2);

        $this->assertShelfIs($itemId, $case['inputs']['before']);

        $permitId = $this->issue($itemId, $case['inputs']['permit']['units']);
        $journals = $this->journalsOfSource(StockPermitSources::PERMIT, $permitId);

        $this->assertCount(1, $journals);
        $this->assertSameAsFixture($case['expected'], ['out_of_3' => ['journals' => ['permit' => $this->roleLines((int) $journals[0]->journal_id, ['other_receivables' => $this->accountId('other_receivables'), 'inventory' => $this->stockAccount()], 2)]]], 'journal of the issue permit of 3 units');
        $this->assertSame(['quantity' => '6.000', 'value' => '533.33'], $this->stockOf($itemId));
        $this->assertSame('88.888889', $this->exactCostOf($itemId), 'the average does not move when units leave at the average');
        $this->assertBooksTie();
    }
}
