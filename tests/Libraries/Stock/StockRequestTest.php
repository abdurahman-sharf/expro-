<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\StockRequest;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;

/**
 * Stock requests between locations: the document that asks, its states, and that it posts nothing
 * itself and has the transfer permit move the stock when it is fulfilled, with every rule of a transfer.
 */
class StockRequestTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use StockDocumentFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockRequest $requests;
    private int $second;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->switchOnRequests();
        $this->switchOnTransferPermit();

        $this->requests = new StockRequest();
        $this->second   = $this->openLocation('Shop');
    }

    protected function tearDown(): void
    {
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * The shop asks the warehouse (location 1) for what the lines say.
     *
     * @param list<array{item_id: int, quantity: string}> $lines
     */
    private function ask(array $lines, array $more = [], ?int $employeeId = null): int
    {
        return $this->requests->create(['from_location_id' => self::LOCATION_ID, 'to_location_id' => $this->second, 'lines' => $lines, ...$more], $employeeId ?? $this->keeperId);
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

    public function testCreate_RecordsTheRequestAsRequestedAndPostsNothingAndMovesNothing(): void
    {
        $itemId  = $this->stocked('10', '50');
        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']], ['note' => 'for the weekend']);

        $row = $this->requests->find($requestId);

        $this->assertSame('requested', $row->status);
        $this->assertSame($this->second, (int) $row->to_location_id);
        $this->assertSame(self::LOCATION_ID, (int) $row->from_location_id);
        $this->assertSame('for the weekend', $row->note);
        $this->assertSame('4.000', bcadd((string) $this->requests->lines($requestId)[0]->quantity, '0', 3));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stock_request', 'entity_id' => $requestId, 'action' => 'stock_request_created'])->countAllResults());
    }

    public function testCreate_WorksOnlyWhileTheSettingIsOn(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->accountingDb()->table('app_config')->where('key', 'stock_requests_enabled')->delete();

        $this->refusedWith(AccountingError::StockDocumentOff, fn () => $this->ask([['item_id' => $itemId, 'quantity' => '4']]));
        $this->assertSame(0, $this->countRows('stock_requests'));
    }

    public function testCreate_RejectsWhatIsNotAValidRequest(): void
    {
        $itemId  = $this->stocked('10', '50');
        $service = $this->item('1.00', false);
        $line    = ['item_id' => $itemId, 'quantity' => '1'];

        $cases = [
            'no lines'          => [AccountingError::StockRequestLinesInvalid, ['lines' => []]],
            'zero quantity'     => [AccountingError::StockRequestLinesInvalid, ['lines' => [[...$line, 'quantity' => '0']]]],
            'four decimals'     => [AccountingError::StockRequestLinesInvalid, ['lines' => [[...$line, 'quantity' => '1.0001']]]],
            'item without stock' => [AccountingError::StockRequestLinesInvalid, ['lines' => [[...$line, 'item_id' => $service]]]],
            'unknown item'      => [AccountingError::StockRequestLinesInvalid, ['lines' => [[...$line, 'item_id' => 999999]]]],
            'same location'     => [AccountingError::StockRequestLocationsInvalid, ['to_location_id' => self::LOCATION_ID]],
            'unknown location'  => [AccountingError::StockPermitLocationInvalid, ['to_location_id' => 999999]],
            'bad date'          => [AccountingError::InvalidDate, ['date' => '2026-02-31']],
            'date to come'      => [AccountingError::InvalidDate, ['date' => date('Y-m-d', strtotime('+1 day'))]],
            'long note'         => [AccountingError::StockPermitNoteTooLong, ['note' => str_repeat('x', 501)]],
        ];

        foreach ($cases as [$error, $more]) {
            $this->refusedWith($error, fn () => $this->ask([$line], $more));
        }

        $this->assertSame(0, $this->countRows('stock_requests'));
    }

    public function testCreate_RequiresThePermissionAndTheBranchOfTheLocationThatAsks(): void
    {
        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $itemId = $this->stocked('10', '50');

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->ask([['item_id' => $itemId, 'quantity' => '1']], [], $this->keeper(null, [StockPermissions::MODULE])));

        $clerkOfOther = $this->keeper([$other]);

        $this->assertGreaterThan(0, $this->requests->create(['from_location_id' => self::LOCATION_ID, 'to_location_id' => $store, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]], $clerkOfOther), 'the branch that asks may ask a location of another branch');

        try {
            $this->requests->create(['from_location_id' => $store, 'to_location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]], $clerkOfOther);
            $this->fail('A request for a location of a branch the employee does not work in must be refused.');
        } catch (BranchException) {
            $this->assertSame(1, $this->countRows('stock_requests'));
        }
    }

    public function testApprove_MovesARequestedRequestToApprovedAndPostsNothing(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);
        $journals  = $this->countRows('accounting_journals');

        $this->requests->approve($requestId, $this->keeperId);

        $row = $this->requests->find($requestId);

        $this->assertSame('approved', $row->status);
        $this->assertSame($this->keeperId, (int) $row->approved_by);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->refusedWith(AccountingError::StockRequestStatusInvalid, fn () => $this->requests->approve($requestId, $this->keeperId));
    }

    public function testApprove_WorksOnlyWhileTheSettingIsOn(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);

        $this->accountingDb()->table('app_config')->where('key', 'stock_requests_enabled')->delete();

        $this->refusedWith(AccountingError::StockDocumentOff, fn () => $this->requests->approve($requestId, $this->keeperId));
        $this->assertSame('requested', $this->requests->find($requestId)->status);
    }

    public function testApprove_RequiresThePermissionToApproveAndTheBranchOfTheLocationThatGives(): void
    {
        $other     = $this->createBranch();
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->requests->approve($requestId, $this->keeper(null, [StockPermissions::REQUESTS_MANAGE])));

        try {
            $this->requests->approve($requestId, $this->keeper([$other]));
            $this->fail('An employee who does not work in the branch of the giving location must be refused.');
        } catch (BranchException) {
            $this->assertSame('requested', $this->requests->find($requestId)->status);
        }

        $this->refusedWith(AccountingError::StockRequestNotFound, fn () => $this->requests->approve(999999, $this->keeperId));
    }

    public function testFulfil_MakesTheTransferPermitThatMovesTheStockAndMarksTheRequestFulfilled(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);

        $this->requests->approve($requestId, $this->keeperId);

        $permitId = $this->requests->fulfil($requestId, $this->keeperId);

        $row    = $this->requests->find($requestId);
        $permit = (new StockPermit())->find($permitId);

        $this->assertSame('fulfilled', $row->status);
        $this->assertSame($permitId, (int) $row->permit_id);
        $this->assertSame('transfer', $permit->permit_type);
        $this->assertSame(self::LOCATION_ID, (int) $permit->location_id);
        $this->assertSame($this->second, (int) $permit->to_location_id);
        $this->assertSame($requestId, (int) $permit->request_id);
        $this->assertSame(date('Y-m-d'), $permit->permit_date);
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $this->second));

        $journal = $this->journalsOfSource(StockPermitSources::TRANSFER, $permitId)[0];

        $this->assertSame([$this->stockAccountOf($this->second) . ':200.00:0.00', $this->stockAccount() . ':0.00:200.00'], $this->linesOfJournal((int) $journal->journal_id));
        $this->assertBooksTie();
    }

    public function testFulfil_NeedsTheRequestToBeApprovedAndTheSettingsToBeOn(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);

        $this->refusedWith(AccountingError::StockRequestStatusInvalid, fn () => $this->requests->fulfil($requestId, $this->keeperId));

        $this->requests->approve($requestId, $this->keeperId);
        $this->accountingDb()->table('app_config')->where('key', 'stock_transfer_permit_enabled')->delete();

        $this->refusedWith(AccountingError::StockDocumentOff, fn () => $this->requests->fulfil($requestId, $this->keeperId));

        $this->assertSame('approved', $this->requests->find($requestId)->status, 'a refusal leaves the request approved');
        $this->assertSame(0, $this->countRows('stock_permits'));

        $this->accountingDb()->table('app_config')->where('key', 'stock_requests_enabled')->delete();
        $this->switchOnTransferPermit();

        $this->refusedWith(AccountingError::StockDocumentOff, fn () => $this->requests->fulfil($requestId, $this->keeperId));
    }

    public function testFulfil_RejectsWhenTheLocationThatGivesHoldsLessThanAskedAndLeavesTheRequestApproved(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '11']]);

        $this->requests->approve($requestId, $this->keeperId);

        $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->requests->fulfil($requestId, $this->keeperId));

        $row = $this->requests->find($requestId);

        $this->assertSame('approved', $row->status);
        $this->assertNull($row->permit_id);
        $this->assertSame(0, $this->countRows('stock_permits'));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testFulfil_RequiresThePermissionsOfARequestAndOfATransferPermitAndBothBranchesWhenTheyDiffer(): void
    {
        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $itemId = $this->stocked('10', '50');

        $requestId = $this->requests->create(['from_location_id' => self::LOCATION_ID, 'to_location_id' => $store, 'lines' => [['item_id' => $itemId, 'quantity' => '2']]], $this->keeperId);

        $this->requests->approve($requestId, $this->keeperId);

        $approverOnly = $this->keeper(null, [StockPermissions::REQUESTS_APPROVE, 'items_stock']);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->requests->fulfil($requestId, $approverOnly));
        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->requests->fulfil($requestId, $this->keeper(null, [StockPermissions::PERMITS_MANAGE])));

        $inOne = $this->keeper([1]);
        $this->allowLocation($store, $inOne);

        try {
            $this->requests->fulfil($requestId, $inOne);
            $this->fail('A transfer between branches needs the employee in both.');
        } catch (BranchException) {
            $this->assertSame('approved', $this->requests->find($requestId)->status);
            $this->assertSame(0, $this->countRows('stock_permits'));
        }

        $this->allowLocation($store, $this->keeperId);

        $permitId = $this->requests->fulfil($requestId, $this->keeperId);

        $this->assertCount(1, $this->journalsOfSource(StockPermitSources::TRANSFER_RECEIPT, $permitId));
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }

    public function testFulfil_MakesOnePermitWhenTwoFulfilmentsComeAtTheSameMoment(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);

        $this->requests->approve($requestId, $this->keeperId);

        $answers = $this->raceStockWorkers('fulfilRequest', [(string) $requestId, (string) $this->keeperId], [(string) $requestId, (string) $this->keeperId]);

        $this->assertSame(['done', AccountingError::StockRequestStatusInvalid->value], $answers);
        $this->assertSame(1, $this->countRows('stock_permits'));
        $this->assertSame('6.000', $this->onHand($itemId));
        $this->assertBooksTie();
    }

    public function testFulfil_LeavesTheRequestFulfilledWhenThePermitIsCancelledLater(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);

        $this->requests->approve($requestId, $this->keeperId);

        $permitId = $this->requests->fulfil($requestId, $this->keeperId);

        (new StockPermit())->cancel($permitId, 'sent back', $this->keeperId);

        $this->assertSame('fulfilled', $this->requests->find($requestId)->status);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->refusedWith(AccountingError::StockRequestStatusInvalid, fn () => $this->requests->cancel($requestId, 'too late', $this->keeperId));
    }

    public function testCancel_CancelsARequestedOrAnApprovedRequestWithAReasonAndNothingElse(): void
    {
        $itemId = $this->stocked('10', '50');

        $requested = $this->ask([['item_id' => $itemId, 'quantity' => '1']]);
        $approved  = $this->ask([['item_id' => $itemId, 'quantity' => '1']]);

        $this->requests->approve($approved, $this->keeperId);

        $this->refusedWith(AccountingError::ReasonRequired, fn () => $this->requests->cancel($requested, ' ', $this->keeperId));

        $this->requests->cancel($requested, 'not needed', $this->keeperId);
        $this->requests->cancel($approved, 'not needed either', $this->keeperId);

        $row = $this->requests->find($requested);

        $this->assertSame('cancelled', $row->status);
        $this->assertSame('not needed', $row->cancel_reason);
        $this->assertSame('cancelled', $this->requests->find($approved)->status);
        $this->refusedWith(AccountingError::StockRequestStatusInvalid, fn () => $this->requests->cancel($requested, 'again', $this->keeperId));
        $this->refusedWith(AccountingError::StockRequestStatusInvalid, fn () => $this->requests->approve($requested, $this->keeperId));
        $this->refusedWith(AccountingError::StockRequestNotFound, fn () => $this->requests->cancel(999999, 'none', $this->keeperId));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testCancel_RequiresAPermissionOfARequestAndABranchOfIt(): void
    {
        $other     = $this->createBranch();
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '1']]);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->requests->cancel($requestId, 'no', $this->keeper(null, [StockPermissions::MODULE])));

        try {
            $this->requests->cancel($requestId, 'no', $this->keeper([$other]));
            $this->fail('An employee of neither branch must be refused.');
        } catch (BranchException) {
            $this->assertSame('requested', $this->requests->find($requestId)->status);
        }

        $this->requests->cancel($requestId, 'yes', $this->keeper(null, [StockPermissions::REQUESTS_APPROVE]));

        $this->assertSame('cancelled', $this->requests->find($requestId)->status);
    }

    public function testNumberOf_NumbersARequestWithItsOwnSequenceAndShowsTheKindAndTheIdWhileItIsOff(): void
    {
        $itemId = $this->stocked('10', '50');

        $plain = $this->ask([['item_id' => $itemId, 'quantity' => '1']]);

        $this->assertSame('STK-REQ ' . $plain, $this->requests->numberOf($plain));
        $this->assertNull($this->requests->peekNumber());

        $this->numberWith(DocumentType::StockRequest, 'REQ-', 3);

        $numbered = $this->ask([['item_id' => $itemId, 'quantity' => '1']]);

        $this->assertSame('REQ-001', $this->requests->numberOf($numbered));
        $this->assertSame('REQ-002', $this->requests->peekNumber());
    }

    public function testSearch_ShowsARequestToTheBranchThatAsksAndTheBranchThatGivesAndFilters(): void
    {
        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $itemId = $this->stocked('10', '50');

        $local   = $this->ask([['item_id' => $itemId, 'quantity' => '1']], ['note' => 'weekend']);
        $across  = $this->requests->create(['from_location_id' => self::LOCATION_ID, 'to_location_id' => $store, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]], $this->keeperId);
        $this->requests->cancel($local, 'x', $this->keeperId);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->request_id, $result['rows']);

        $this->assertSame([$across, $local], $ids($this->requests->search([], $this->keeperId)));
        $this->assertSame([$local], $ids($this->requests->search(['status' => 'cancelled'], $this->keeperId)));
        $this->assertSame([$local], $ids($this->requests->search(['search' => 'weekend'], $this->keeperId)));
        $this->assertSame([$across, $local], $ids($this->requests->search([], $this->keeper([1]))), 'the giving branch sees both');
        $this->assertSame([$across], $ids($this->requests->search([], $this->keeper([$other]))), 'the asking branch sees its own');
        $this->assertSame([], $this->requests->search([], $this->keeper([]))['rows']);
    }

    public function testSearch_AcceptsTheLabelAndTheIdOfARequestWhileNumberingIsOff(): void
    {
        $itemId = $this->stocked('10', '50');

        $first  = $this->ask([['item_id' => $itemId, 'quantity' => '1']]);
        $second = $this->ask([['item_id' => $itemId, 'quantity' => '1']]);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->request_id, $result['rows']);

        $this->assertSame(['STK-REQ ' . $second, 'STK-REQ ' . $first], array_map(static fn (object $row): string => $row->label, $this->requests->search([], $this->keeperId)['rows']));
        $this->assertSame([$first], $ids($this->requests->search(['search' => 'STK-REQ ' . $first], $this->keeperId)));
        $this->assertSame([$second], $ids($this->requests->search(['search' => (string) $second], $this->keeperId)));
    }

    public function testApprove_WritesTheAuditLogOfApprovalFulfilmentAndCancellation(): void
    {
        $itemId = $this->stocked('10', '50');

        $fulfilled = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);
        $cancelled = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);

        $this->requests->approve($fulfilled, $this->keeperId);

        $permitId = $this->requests->fulfil($fulfilled, $this->keeperId);

        $this->requests->cancel($cancelled, 'not needed', $this->keeperId);

        $actions = static fn (array $rows): array => array_map(static fn (object $row): string => $row->action, $rows);
        $log     = fn (int $requestId): array => $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stock_request', 'entity_id' => $requestId])->orderBy('audit_id', 'ASC')->get()->getResult();

        $this->assertSame(['stock_request_created', 'stock_request_approved', 'stock_request_fulfilled'], $actions($log($fulfilled)));
        $this->assertSame(['stock_request_created', 'stock_request_cancelled'], $actions($log($cancelled)));
        $this->assertSame($permitId, json_decode((string) $log($fulfilled)[2]->details, true)['permit_id']);
        $this->assertSame('not needed', json_decode((string) $log($cancelled)[1]->details, true)['reason']);
    }

    public function testFulfil_TakesTheLockOfTheItemsBeforeItReadsTheRequest(): void
    {
        $itemId    = $this->stocked('10', '50');
        $requestId = $this->ask([['item_id' => $itemId, 'quantity' => '4']]);

        $this->requests->approve($requestId, $this->keeperId);

        $queries  = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = (string) $query->getQuery();
        };

        Events::on('DBQuery', $listener);

        try {
            $this->requests->fulfil($requestId, $this->keeperId);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $lock = null;
        $row  = null;

        foreach ($queries as $index => $sql) {
            if ($lock === null && str_contains($sql, 'ospos_items WHERE item_id IN') && str_contains($sql, 'FOR UPDATE')) {
                $lock = $index;
            }

            if ($row === null && str_contains($sql, 'ospos_stock_requests WHERE request_id') && str_contains($sql, 'FOR UPDATE')) {
                $row = $index;
            }
        }

        $this->assertNotNull($lock);
        $this->assertLessThan($row, $lock);
    }
}
