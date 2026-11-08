<?php

namespace Tests\Libraries\PurchaseCycle;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocuments;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\PurchaseCycleFixtureTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The four documents of the purchase cycle: the lifecycle of each, the statuses set by hand, the conversions
 * that copy the lines forward and post nothing, the numbering, the branches and the permissions.
 */
class PurchaseDocumentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use PurchaseCycleFixtureTrait;
    use RoleFixtureTrait;
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

        $this->startBuying();
        $this->startCycle();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopCycle();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: PurchaseDocumentKind}>
     */
    public static function kindProvider(): array
    {
        $cases = [];

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $cases[$kind->value] = [$kind];
        }

        return $cases;
    }

    private function refusal(callable $action): AccountingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|BranchException $refusal) {
            return $refusal;
        }

        $this->fail('The action should have been refused.');
    }

    private function assertRefused(AccountingError $error, callable $action): void
    {
        $refusal = $this->refusal($action);

        $this->assertInstanceOf(AccountingException::class, $refusal);
        $this->assertSame($error, $refusal->error);
    }

    private function row(PurchaseDocumentKind $kind, int $documentId): object
    {
        return $this->accountingDb()->table($kind->table())->where($kind->idColumn(), $documentId)->get()->getRow();
    }

    private function turnOnManualStatuses(): void
    {
        foreach (PurchaseDocumentKind::cases() as $kind) {
            $this->setConfigValue($kind->manualStatusSetting(), '1');
        }
    }

    #[DataProvider('kindProvider')]
    public function testCreate_StoresTheDocumentOpenWithItsLinesAndWritesAnAuditEntry(PurchaseDocumentKind $kind): void
    {
        $documentId = $this->makeDocument($kind, ['lines' => [
            ['item_id' => $this->goods, 'quantity' => '10', 'unit_price' => '4.00', 'description' => 'first'],
            ['item_id' => $this->goods, 'quantity' => '2.5', 'unit_price' => '0', 'description' => ''],
        ]]);

        $service  = $this->documentsOf($kind);
        $document = $service->find($documentId, $this->clerk);

        $this->assertSame(PurchaseDocumentStatus::Open, $document->status_object);
        $this->assertSame('2026-10-20', $document->document_date);
        $this->assertSame('2026-10-30', $document->due_date);
        $this->assertSame('cycle document', $document->note);
        $this->assertSame($this->clerk, (int) $document->created_by);
        $this->assertSame(self::LOCATION_ID, (int) $document->location_id);

        $lines = $service->lines($documentId);

        $this->assertCount(2, $lines);
        $this->assertSame('10.000', $lines[0]->quantity);
        $this->assertSame('4.00', $lines[0]->unit_price);
        $this->assertSame('2.500', $lines[1]->quantity);
        $this->assertSame('0.00', $lines[1]->unit_price);
        $this->assertSame(['created'], $this->auditActions($kind, $documentId));
    }

    public function testCreate_StoresTheSupplierOfAQuotationAndAnOrderAndNoneOnARequest(): void
    {
        $this->assertSame($this->supplierId, (int) $this->row(PurchaseDocumentKind::SupplierQuotation, $this->makeDocument(PurchaseDocumentKind::SupplierQuotation))->supplier_id);
        $this->assertSame($this->supplierId, (int) $this->row(PurchaseDocumentKind::Order, $this->makeDocument(PurchaseDocumentKind::Order))->supplier_id);

        $request = $this->makeDocument(PurchaseDocumentKind::Request);

        $this->assertObjectNotHasProperty('supplier_id', $this->row(PurchaseDocumentKind::Request, $request));
    }

    public function testCreate_AcceptsAQuotationRequestWithoutASupplier(): void
    {
        $documentId = $this->makeDocument(PurchaseDocumentKind::QuotationRequest, ['supplier_id' => 0]);

        $this->assertNull($this->row(PurchaseDocumentKind::QuotationRequest, $documentId)->supplier_id);
    }

    #[DataProvider('kindProvider')]
    public function testCreate_RequiresTheAddPermissionOfTheFamily(PurchaseDocumentKind $kind): void
    {
        $others = $this->employeeWithGrants(array_values(array_filter($this->cycleGrants(), static fn (string $permission): bool => $permission !== $kind->permission(PurchaseDocuments::ADD))));

        $this->assertRefused(AccountingError::PermissionDenied, fn (): int => $this->makeDocument($kind, [], $others));
        $this->assertSame(0, $this->countRows($kind->table()));
    }

    #[DataProvider('kindProvider')]
    public function testCreate_RefusesWhileThePurchaseCycleIsOff(PurchaseDocumentKind $kind): void
    {
        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->assertRefused(AccountingError::PurchaseDocumentModuleOff, fn (): int => $this->makeDocument($kind));
        $this->assertSame(0, $this->countRows($kind->table()));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: AccountingError}>
     */
    public static function invalidInputProvider(): array
    {
        $line = ['item_id' => 1, 'quantity' => '1', 'unit_price' => '1.00'];

        return [
            'no lines'                    => [['lines' => []], AccountingError::PurchaseDocumentNoLines],
            'lines that are not a list'   => [['lines' => 'x'], AccountingError::PurchaseDocumentNoLines],
            'a line that is not a line'   => [['lines' => ['x']], AccountingError::PurchaseDocumentLineInvalid],
            'quantity zero'               => [['lines' => [[...$line, 'quantity' => '0']]], AccountingError::PurchaseDocumentLineInvalid],
            'negative quantity'           => [['lines' => [[...$line, 'quantity' => '-1']]], AccountingError::PurchaseDocumentLineInvalid],
            'four decimals of quantity'   => [['lines' => [[...$line, 'quantity' => '1.0001']]], AccountingError::PurchaseDocumentLineInvalid],
            'negative price'              => [['lines' => [[...$line, 'unit_price' => '-0.01']]], AccountingError::PurchaseDocumentLineInvalid],
            'three decimals of price'     => [['lines' => [[...$line, 'unit_price' => '1.001']]], AccountingError::PurchaseDocumentLineInvalid],
            'text for a quantity'         => [['lines' => [[...$line, 'quantity' => 'ten']]], AccountingError::PurchaseDocumentLineInvalid],
            'an item that does not exist' => [['lines' => [[...$line, 'item_id' => 999999]]], AccountingError::PurchaseDocumentLineInvalid],
            'no item'                     => [['lines' => [[...$line, 'item_id' => 0]]], AccountingError::PurchaseDocumentLineInvalid],
            'a note that is a list'       => [['lines' => [[...$line, 'description' => ['x']]]], AccountingError::PurchaseDocumentLineInvalid],
            'a note over 255 characters'  => [['lines' => [[...$line, 'description' => str_repeat('x', 256)]]], AccountingError::PurchaseDocumentLineInvalid],
            'a date that is not a day'    => [['document_date' => '2026-02-30'], AccountingError::PurchaseDocumentDateInvalid],
            'a due date before the date'  => [['due_date' => '2026-10-19'], AccountingError::PurchaseDocumentDateInvalid],
            'a due date that is no day'   => [['due_date' => 'soon'], AccountingError::PurchaseDocumentDateInvalid],
            'a stock location that is not' => [['location_id' => 999999], AccountingError::PurchaseDocumentLocationInvalid],
            'a note over 500 characters'  => [['note' => str_repeat('x', 501)], AccountingError::DescriptionTooLong],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidInputProvider')]
    public function testCreate_RejectsWhatIsNotAValidDocument(array $override, AccountingError $error): void
    {
        $input = $this->documentInput($override);

        if (isset($override['lines']) && is_array($override['lines'])) {
            $input['lines'] = array_map(fn (mixed $line): mixed => is_array($line) && ($line['item_id'] ?? 0) === 1 ? [...$line, 'item_id' => $this->goods] : $line, $override['lines']);
        }

        $this->assertRefused($error, fn (): int => $this->documentsOf(PurchaseDocumentKind::Order)->create($input, $this->clerk));
        $this->assertSame(0, $this->countRows('purchase_orders'));
    }

    public function testCreate_AcceptsTwoHundredLinesAndRejectsTwoHundredAndOne(): void
    {
        $line = ['item_id' => $this->goods, 'quantity' => '1', 'unit_price' => '1.00'];

        $documentId = $this->documentsOf(PurchaseDocumentKind::Request)->create($this->documentInput(['lines' => array_fill(0, 200, $line)]), $this->clerk);

        $this->assertCount(200, $this->documentsOf(PurchaseDocumentKind::Request)->lines($documentId));
        $this->assertRefused(AccountingError::PurchaseDocumentLineInvalid, fn (): int => $this->documentsOf(PurchaseDocumentKind::Request)->create($this->documentInput(['lines' => array_fill(0, 201, $line)]), $this->clerk));
    }

    #[DataProvider('kindProvider')]
    public function testCreate_RequiresASupplierOnlyWhereTheDocumentNeedsOne(PurchaseDocumentKind $kind): void
    {
        $withNone = fn (): int => $this->makeDocument($kind, ['supplier_id' => 0]);

        if ($kind->needsSupplier()) {
            $this->assertRefused(AccountingError::PurchaseDocumentSupplierNeeded, $withNone);
            $this->assertRefused(AccountingError::PurchaseDocumentSupplierInvalid, fn (): int => $this->makeDocument($kind, ['supplier_id' => 999999]));
        } else {
            $this->assertGreaterThan(0, $withNone());
        }
    }

    public function testCreate_RejectsASupplierThatWasDeleted(): void
    {
        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['deleted' => 1]);

        $this->assertRefused(AccountingError::PurchaseDocumentSupplierInvalid, fn (): int => $this->makeDocument(PurchaseDocumentKind::Order));
    }

    #[DataProvider('kindProvider')]
    public function testCreate_NumbersTheDocumentFromItsOwnSequenceInFiveDigits(PurchaseDocumentKind $kind): void
    {
        $numbering = new DocumentNumbering($this->accountingDb());
        $numbering->configure($kind->documentType(), ['enabled' => true, 'padding' => 5], null);

        $service = $this->documentsOf($kind);

        $this->assertSame('00001', $service->peekNumber());

        $first  = $this->makeDocument($kind);
        $second = $this->makeDocument($kind);

        $this->assertSame('00001', $service->numberOf($first));
        $this->assertSame('00002', $service->numberOf($second));
        $this->assertSame('00003', $service->peekNumber());
        $this->assertSame(5, $kind->documentType()->defaultPadding());
    }

    public function testCreate_KeepsASequenceForEachKindSoOneKindNeverUsesTheNumbersOfAnother(): void
    {
        $numbering = new DocumentNumbering($this->accountingDb());

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $numbering->configure($kind->documentType(), ['enabled' => true, 'padding' => 5], null);
        }

        $this->makeDocument(PurchaseDocumentKind::Request);
        $this->makeDocument(PurchaseDocumentKind::Request);
        $order = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertSame('00001', $this->documentsOf(PurchaseDocumentKind::Order)->numberOf($order));
    }

    public function testCreate_NumbersWithoutAGapWhenTheSaveIsTakenBack(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::PurchaseOrder, ['enabled' => true, 'padding' => 5], null);

        $db = $this->accountingDb();
        $db->transStart();
        $lost = $this->makeDocument(PurchaseDocumentKind::Order);
        $db->transRollback();
        $db->resetTransStatus();

        $this->assertSame(0, $this->countRows('purchase_orders'), 'the document went with the transaction');

        $kept = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertSame('00001', $this->documentsOf(PurchaseDocumentKind::Order)->numberOf($kept), 'the number went with it and is given again');
        $this->assertGreaterThan(0, $lost);
    }

    public function testCreate_NumbersWithoutAGapWhenARefusedSaveCameFirst(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::PurchaseOrder, ['enabled' => true, 'padding' => 5], null);

        $this->assertRefused(AccountingError::PurchaseDocumentNoLines, fn (): int => $this->makeDocument(PurchaseDocumentKind::Order, ['lines' => []]));

        $kept = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertSame('00001', $this->documentsOf(PurchaseDocumentKind::Order)->numberOf($kept));
    }

    public function testCreate_ShowsTheIdInFiveDigitsWhileTheSequenceIsOff(): void
    {
        $documentId = $this->makeDocument(PurchaseDocumentKind::SupplierQuotation);

        $this->assertSame(str_pad((string) $documentId, 5, '0', STR_PAD_LEFT), $this->documentsOf(PurchaseDocumentKind::SupplierQuotation)->numberOf($documentId));
        $this->assertNull($this->documentsOf(PurchaseDocumentKind::SupplierQuotation)->peekNumber());
    }

    #[DataProvider('kindProvider')]
    public function testUpdate_ChangesAnOpenDocumentAndItsLinesAndWritesAnAuditEntry(PurchaseDocumentKind $kind): void
    {
        $documentId = $this->makeDocument($kind);
        $other      = $this->item('9.00');

        $this->documentsOf($kind)->update($documentId, $this->documentInput(['note' => 'changed', 'due_date' => '', 'lines' => [['item_id' => $other, 'quantity' => '3', 'unit_price' => '9.50']]]), $this->clerk);

        $service  = $this->documentsOf($kind);
        $document = $service->find($documentId, $this->clerk);
        $lines    = $service->lines($documentId);

        $this->assertSame('changed', $document->note);
        $this->assertNull($document->due_date);
        $this->assertCount(1, $lines);
        $this->assertSame($other, (int) $lines[0]->item_id);
        $this->assertSame(['created', 'updated'], $this->auditActions($kind, $documentId));
    }

    #[DataProvider('kindProvider')]
    public function testUpdate_RequiresTheEditPermissionOfTheFamily(PurchaseDocumentKind $kind): void
    {
        $documentId = $this->makeDocument($kind);
        $others     = $this->employeeWithGrants(array_values(array_filter($this->cycleGrants(), static fn (string $permission): bool => $permission !== $kind->permission(PurchaseDocuments::EDIT))));

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->documentsOf($kind)->update($documentId, $this->documentInput(['note' => 'x']), $others));
        $this->assertSame('cycle document', $this->row($kind, $documentId)->note);
    }

    public function testUpdate_RefusesADocumentThatWasCancelledOrConvertedAndKeepsItAsItWas(): void
    {
        $request   = $this->makeDocument(PurchaseDocumentKind::Request);
        $cancelled = $this->makeDocument(PurchaseDocumentKind::Request);
        $service   = $this->documentsOf(PurchaseDocumentKind::Request);

        $service->convert($request, $this->clerk);
        $service->cancel($cancelled, 'not needed', $this->clerk);

        foreach ([$request, $cancelled] as $documentId) {
            $this->assertRefused(AccountingError::PurchaseDocumentLocked, fn () => $service->update($documentId, $this->documentInput(['note' => 'changed']), $this->clerk));
            $this->assertSame('cycle document', $this->row(PurchaseDocumentKind::Request, $documentId)->note);
        }
    }

    public function testUpdate_ChangesAConvertedDocumentAgainOnceTheDocumentMadeFromItIsCancelled(): void
    {
        $request = $this->makeDocument(PurchaseDocumentKind::Request);
        $service = $this->documentsOf(PurchaseDocumentKind::Request);
        $rfq     = $service->convert($request, $this->clerk);

        $this->documentsOf(PurchaseDocumentKind::QuotationRequest)->cancel($rfq, 'wrong supplier', $this->clerk);
        $service->update($request, $this->documentInput(['note' => 'again']), $this->clerk);

        $this->assertSame('again', $this->row(PurchaseDocumentKind::Request, $request)->note);
    }

    public function testUpdate_KeepsTheSupplierOfARequestOutAndTheRestOfTheDocumentWhenOnlyTheNoteChanges(): void
    {
        $documentId = $this->makeDocument(PurchaseDocumentKind::QuotationRequest);
        $service    = $this->documentsOf(PurchaseDocumentKind::QuotationRequest);

        $service->update($documentId, $this->documentInput(['supplier_id' => 0]), $this->clerk);

        $this->assertNull($this->row(PurchaseDocumentKind::QuotationRequest, $documentId)->supplier_id);
    }

    #[DataProvider('kindProvider')]
    public function testChangeStatus_MovesBetweenTheThreeLabelsInEveryDirectionWithAnAuditEntryForEach(PurchaseDocumentKind $kind): void
    {
        $this->turnOnManualStatuses();

        $documentId = $this->makeDocument($kind);
        $service    = $this->documentsOf($kind);
        $labels     = [PurchaseDocumentStatus::Open, PurchaseDocumentStatus::Sent, PurchaseDocumentStatus::Approved];
        $changes    = 0;

        foreach ($labels as $from) {
            foreach ($labels as $to) {
                if ($from === $to) {
                    continue;
                }

                $this->accountingDb()->table($kind->table())->where($kind->idColumn(), $documentId)->update(['status' => $from->value]);
                $this->assertSame($to, $service->changeStatus($documentId, $to, $this->clerk));
                $this->assertSame($to, $service->statusOf($documentId));
                $changes++;
            }
        }

        $this->assertSame(6, $changes);
        $this->assertSame(array_merge(['created'], array_fill(0, 6, 'status_changed')), $this->auditActions($kind, $documentId));
    }

    public function testChangeStatus_WritesNothingWhenTheStatusIsTheSame(): void
    {
        $this->turnOnManualStatuses();

        $documentId = $this->makeDocument(PurchaseDocumentKind::Request);

        $this->documentsOf(PurchaseDocumentKind::Request)->changeStatus($documentId, PurchaseDocumentStatus::Open, $this->clerk);

        $this->assertSame(['created'], $this->auditActions(PurchaseDocumentKind::Request, $documentId));
    }

    #[DataProvider('kindProvider')]
    public function testChangeStatus_RefusesWhileTheSwitchOfTheFamilyIsOff(PurchaseDocumentKind $kind): void
    {
        $this->turnOnManualStatuses();
        $this->setConfigValue($kind->manualStatusSetting(), '0');

        $documentId = $this->makeDocument($kind);

        $this->assertRefused(AccountingError::PurchaseDocumentManualStatusOff, fn () => $this->documentsOf($kind)->changeStatus($documentId, PurchaseDocumentStatus::Sent, $this->clerk));
        $this->assertSame('open', $this->row($kind, $documentId)->status);
    }

    public function testChangeStatus_IsOnlyForTheFamilyWhoseSwitchIsOn(): void
    {
        $this->setConfigValue(PurchaseDocumentKind::Order->manualStatusSetting(), '1');

        $order   = $this->makeDocument(PurchaseDocumentKind::Order);
        $request = $this->makeDocument(PurchaseDocumentKind::Request);

        $this->documentsOf(PurchaseDocumentKind::Order)->changeStatus($order, PurchaseDocumentStatus::Approved, $this->clerk);

        $this->assertRefused(AccountingError::PurchaseDocumentManualStatusOff, fn () => $this->documentsOf(PurchaseDocumentKind::Request)->changeStatus($request, PurchaseDocumentStatus::Approved, $this->clerk));
    }

    /**
     * @return array<string, array{0: PurchaseDocumentStatus}>
     */
    public static function statusesNotSetByHandProvider(): array
    {
        return [
            'cancelled'       => [PurchaseDocumentStatus::Cancelled],
            'converted'       => [PurchaseDocumentStatus::Converted],
            'partly received' => [PurchaseDocumentStatus::PartlyReceived],
            'received'        => [PurchaseDocumentStatus::Received],
        ];
    }

    #[DataProvider('statusesNotSetByHandProvider')]
    public function testChangeStatus_RefusesAStatusThatTheDocumentsAndTheReceivingsSetAndNotTheHand(PurchaseDocumentStatus $to): void
    {
        $this->turnOnManualStatuses();

        $documentId = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertRefused(AccountingError::PurchaseDocumentStatusNotAllowed, fn () => $this->documentsOf(PurchaseDocumentKind::Order)->changeStatus($documentId, $to, $this->clerk));
        $this->assertSame('open', $this->row(PurchaseDocumentKind::Order, $documentId)->status);
    }

    public function testChangeStatus_RefusesToLeaveCancelledOrConverted(): void
    {
        $this->turnOnManualStatuses();

        $service   = $this->documentsOf(PurchaseDocumentKind::Request);
        $cancelled = $this->makeDocument(PurchaseDocumentKind::Request);
        $converted = $this->makeDocument(PurchaseDocumentKind::Request);

        $service->cancel($cancelled, 'no', $this->clerk);
        $service->convert($converted, $this->clerk);

        foreach ([$cancelled, $converted] as $documentId) {
            $this->assertRefused(AccountingError::PurchaseDocumentStatusNotAllowed, fn () => $service->changeStatus($documentId, PurchaseDocumentStatus::Open, $this->clerk));
        }

        $this->assertSame('cancelled', $this->row(PurchaseDocumentKind::Request, $cancelled)->status);
    }

    #[DataProvider('kindProvider')]
    public function testChangeStatus_RequiresTheEditPermissionOfTheFamily(PurchaseDocumentKind $kind): void
    {
        $this->turnOnManualStatuses();

        $documentId = $this->makeDocument($kind);
        $others     = $this->employeeWithGrants([$kind->permission(PurchaseDocuments::VIEW_ALL)]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->documentsOf($kind)->changeStatus($documentId, PurchaseDocumentStatus::Sent, $others));
    }

    #[DataProvider('kindProvider')]
    public function testCancel_KeepsTheDocumentWithTheReasonTheEmployeeAndTheAuditEntry(PurchaseDocumentKind $kind): void
    {
        $documentId = $this->makeDocument($kind);

        $this->documentsOf($kind)->cancel($documentId, '  not needed any more  ', $this->clerk);

        $row = $this->row($kind, $documentId);

        $this->assertSame('cancelled', $row->status);
        $this->assertSame('not needed any more', $row->cancel_reason);
        $this->assertSame($this->clerk, (int) $row->cancelled_by);
        $this->assertNotNull($row->cancelled_at);
        $this->assertSame(PurchaseDocumentStatus::Cancelled, $this->documentsOf($kind)->statusOf($documentId));
        $this->assertSame(['created', 'cancelled'], $this->auditActions($kind, $documentId));
    }

    public function testCancel_RequiresAReasonOfAtMost255Characters(): void
    {
        $documentId = $this->makeDocument(PurchaseDocumentKind::Request);
        $service    = $this->documentsOf(PurchaseDocumentKind::Request);

        $this->assertRefused(AccountingError::ReasonRequired, fn () => $service->cancel($documentId, '   ', $this->clerk));
        $this->assertRefused(AccountingError::ReasonRequired, fn () => $service->cancel($documentId, str_repeat('x', 256), $this->clerk));
        $this->assertSame('open', $this->row(PurchaseDocumentKind::Request, $documentId)->status);

        $service->cancel($documentId, str_repeat('x', 255), $this->clerk);

        $this->assertSame('cancelled', $this->row(PurchaseDocumentKind::Request, $documentId)->status);
    }

    #[DataProvider('kindProvider')]
    public function testCancel_RequiresTheDeletePermissionOfTheFamily(PurchaseDocumentKind $kind): void
    {
        $documentId = $this->makeDocument($kind);
        $others     = $this->employeeWithGrants(array_values(array_filter($this->cycleGrants(), static fn (string $permission): bool => $permission !== $kind->permission(PurchaseDocuments::DELETE))));

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->documentsOf($kind)->cancel($documentId, 'no', $others));
        $this->assertSame('open', $this->row($kind, $documentId)->status);
    }

    public function testCancel_RefusesADocumentThatIsCancelledOrWhoseNextDocumentStands(): void
    {
        $service   = $this->documentsOf(PurchaseDocumentKind::Request);
        $cancelled = $this->makeDocument(PurchaseDocumentKind::Request);
        $converted = $this->makeDocument(PurchaseDocumentKind::Request);

        $service->cancel($cancelled, 'no', $this->clerk);
        $rfq = $service->convert($converted, $this->clerk);

        $this->assertRefused(AccountingError::PurchaseDocumentStatusNotAllowed, fn () => $service->cancel($cancelled, 'again', $this->clerk));
        $this->assertRefused(AccountingError::PurchaseDocumentStatusNotAllowed, fn () => $service->cancel($converted, 'too late', $this->clerk));

        $this->documentsOf(PurchaseDocumentKind::QuotationRequest)->cancel($rfq, 'first the next one', $this->clerk);
        $service->cancel($converted, 'now', $this->clerk);

        $this->assertSame('cancelled', $this->row(PurchaseDocumentKind::Request, $converted)->status);
    }

    /**
     * @return array<string, array{0: PurchaseDocumentKind}>
     */
    public static function stepProvider(): array
    {
        return [
            'purchase request to quotation request'     => [PurchaseDocumentKind::Request],
            'quotation request to supplier quotation'   => [PurchaseDocumentKind::QuotationRequest],
            'supplier quotation to purchase order'      => [PurchaseDocumentKind::SupplierQuotation],
        ];
    }

    #[DataProvider('stepProvider')]
    public function testConvert_MakesTheNextDocumentWithTheLinesCopiedForward(PurchaseDocumentKind $kind): void
    {
        $other    = $this->item('7.00');
        $sourceId = $this->makeDocument($kind, ['note' => 'for the shop', 'lines' => [
            ['item_id' => $this->goods, 'quantity' => '10', 'unit_price' => '4.00', 'description' => 'first'],
            ['item_id' => $other, 'quantity' => '2.5', 'unit_price' => '7.25', 'description' => 'second'],
        ]]);

        $next     = $kind->next();
        $newId    = $this->documentsOf($kind)->convert($sourceId, $this->clerk, '2026-10-25');
        $target   = $this->documentsOf($next);
        $document = $target->find($newId, $this->clerk);

        $this->assertSame(PurchaseDocumentStatus::Open, $document->status_object);
        $this->assertSame('2026-10-25', $document->document_date);
        $this->assertNull($document->due_date);
        $this->assertSame('for the shop', $document->note);
        $this->assertSame(self::LOCATION_ID, (int) $document->location_id);
        $this->assertSame($sourceId, (int) $this->row($next, $newId)->{$next->sourceColumn()});

        if ($next->hasSupplier() && $kind->hasSupplier()) {
            $this->assertSame($this->supplierId, (int) $document->supplier_id);
        }

        $lines = $target->lines($newId);

        $this->assertCount(2, $lines);
        $this->assertSame([$this->goods, $other], array_map(static fn (object $line): int => (int) $line->item_id, $lines));
        $this->assertSame(['10.000', '2.500'], array_map(static fn (object $line): string => $line->quantity, $lines));
        $this->assertSame(['4.00', '7.25'], array_map(static fn (object $line): string => $line->unit_price, $lines));
        $this->assertSame(['first', 'second'], array_map(static fn (object $line): string => $line->description, $lines));

        $this->assertSame(PurchaseDocumentStatus::Converted, $this->documentsOf($kind)->statusOf($sourceId));
        $this->assertSame(['created', 'converted'], $this->auditActions($kind, $sourceId));
        $this->assertSame(['created'], $this->auditActions($next, $newId));
    }

    #[DataProvider('stepProvider')]
    public function testConvert_PostsNothingAndMovesNoStock(PurchaseDocumentKind $kind): void
    {
        $sourceId = $this->makeDocument($kind);
        $before   = $this->bookCounts();

        $this->documentsOf($kind)->convert($sourceId, $this->clerk);

        $this->assertSame($before, $this->bookCounts());
        $this->assertStockTies();
    }

    public function testCreate_NeverMakesTheNextDocumentByItself(): void
    {
        $this->makeDocument(PurchaseDocumentKind::Request);
        $this->makeDocument(PurchaseDocumentKind::QuotationRequest);
        $this->makeDocument(PurchaseDocumentKind::SupplierQuotation);

        $this->assertSame(1, $this->countRows('quotation_requests'));
        $this->assertSame(1, $this->countRows('supplier_quotations'));
        $this->assertSame(0, $this->countRows('purchase_orders'));
    }

    #[DataProvider('stepProvider')]
    public function testConvert_RefusesASecondConversionWhileTheFirstStandsAndAllowsOneOnceItIsCancelled(PurchaseDocumentKind $kind): void
    {
        $sourceId = $this->makeDocument($kind);
        $service  = $this->documentsOf($kind);
        $first    = $service->convert($sourceId, $this->clerk);

        $this->assertRefused(AccountingError::PurchaseDocumentNotConvertible, fn () => $service->convert($sourceId, $this->clerk));
        $this->assertSame(1, $this->countRows($kind->next()->table()));

        $this->documentsOf($kind->next())->cancel($first, 'made by mistake', $this->clerk);

        $this->assertSame(PurchaseDocumentStatus::Open, $service->statusOf($sourceId));
        $this->assertGreaterThan($first, $service->convert($sourceId, $this->clerk));
        $this->assertSame(2, $this->countRows($kind->next()->table()));
    }

    #[DataProvider('stepProvider')]
    public function testConvert_RefusesACancelledDocument(PurchaseDocumentKind $kind): void
    {
        $sourceId = $this->makeDocument($kind);
        $service  = $this->documentsOf($kind);

        $service->cancel($sourceId, 'no', $this->clerk);

        $this->assertRefused(AccountingError::PurchaseDocumentNotConvertible, fn () => $service->convert($sourceId, $this->clerk));
        $this->assertSame(0, $this->countRows($kind->next()->table()));
    }

    #[DataProvider('stepProvider')]
    public function testConvert_ConvertsAnApprovedAndASentDocumentToo(PurchaseDocumentKind $kind): void
    {
        $this->turnOnManualStatuses();

        $service = $this->documentsOf($kind);

        foreach ([PurchaseDocumentStatus::Sent, PurchaseDocumentStatus::Approved] as $label) {
            $sourceId = $this->makeDocument($kind);
            $service->changeStatus($sourceId, $label, $this->clerk);

            $this->assertGreaterThan(0, $service->convert($sourceId, $this->clerk));
        }
    }

    #[DataProvider('stepProvider')]
    public function testConvert_RequiresTheConvertPermissionOfTheSourceAndTheAddPermissionOfTheNextDocument(PurchaseDocumentKind $kind): void
    {
        $sourceId   = $this->makeDocument($kind);
        $withoutOne = $this->employeeWithGrants(array_values(array_filter($this->cycleGrants(), static fn (string $permission): bool => $permission !== $kind->permission(PurchaseDocuments::CONVERT))));
        $withoutAdd = $this->employeeWithGrants(array_values(array_filter($this->cycleGrants(), static fn (string $permission): bool => $permission !== $kind->next()->permission(PurchaseDocuments::ADD))));

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->documentsOf($kind)->convert($sourceId, $withoutOne));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $this->documentsOf($kind)->convert($sourceId, $withoutAdd));
        $this->assertSame(0, $this->countRows($kind->next()->table()));
        $this->assertSame('open', $this->row($kind, $sourceId)->status);
    }

    public function testConvert_RefusesAQuotationRequestWithoutASupplierAndLeavesItAsItWas(): void
    {
        $rfq = $this->makeDocument(PurchaseDocumentKind::QuotationRequest, ['supplier_id' => 0]);

        $this->assertRefused(AccountingError::PurchaseDocumentSupplierNeeded, fn () => $this->documentsOf(PurchaseDocumentKind::QuotationRequest)->convert($rfq, $this->clerk));
        $this->assertSame(0, $this->countRows('supplier_quotations'));
        $this->assertSame(PurchaseDocumentStatus::Open, $this->documentsOf(PurchaseDocumentKind::QuotationRequest)->statusOf($rfq));
    }

    public function testConvert_RefusesAQuotationWhoseSupplierWasDeletedSinceAndMakesNothing(): void
    {
        $rfq = $this->makeDocument(PurchaseDocumentKind::QuotationRequest);

        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['deleted' => 1]);

        $this->assertRefused(AccountingError::PurchaseDocumentSupplierInvalid, fn () => $this->documentsOf(PurchaseDocumentKind::QuotationRequest)->convert($rfq, $this->clerk));
        $this->assertSame(0, $this->countRows('supplier_quotations'));
    }

    public function testConvert_RefusesAPurchaseOrderBecauseItIsReceivedAndNotConverted(): void
    {
        $order = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertRefused(AccountingError::PurchaseDocumentNotConvertible, fn () => $this->documentsOf(PurchaseDocumentKind::Order)->convert($order, $this->clerk));
    }

    public function testConvert_TakesTheNextDocumentBackWithTheTransactionWhenItFails(): void
    {
        $request = $this->makeDocument(PurchaseDocumentKind::Request);
        $service = $this->documentsOf(PurchaseDocumentKind::Request);
        $limited = $this->employeeWithGrants([PurchaseDocumentKind::Request->permission(PurchaseDocuments::CONVERT), PurchaseDocumentKind::Request->permission(PurchaseDocuments::VIEW_ALL), PurchaseDocumentKind::QuotationRequest->permission(PurchaseDocuments::ADD)], [$this->createBranch('B2')]);

        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $service->convert($request, $limited));
        $this->assertSame(0, $this->countRows('quotation_requests'));
        $this->assertSame(['created'], $this->auditActions(PurchaseDocumentKind::Request, $request));
    }

    public function testFind_ReadsADocumentOnlyInItsOwnKind(): void
    {
        $order = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertNotNull($this->documentsOf(PurchaseDocumentKind::Order)->find($order, $this->clerk));

        foreach ([PurchaseDocumentKind::Request, PurchaseDocumentKind::QuotationRequest, PurchaseDocumentKind::SupplierQuotation] as $kind) {
            $this->assertNull($this->documentsOf($kind)->find($order, $this->clerk), $kind->value);
        }
    }

    public function testUpdate_RefusesAnIdOfAnotherKindAsNotFound(): void
    {
        $order = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $this->documentsOf(PurchaseDocumentKind::Request)->update($order + 1000, $this->documentInput(), $this->clerk));
        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $this->documentsOf(PurchaseDocumentKind::Request)->cancel($order + 1000, 'x', $this->clerk));
    }

    public function testMadeFrom_ListsTheDocumentsOfTheNextStepWithTheirNumbersAndStatuses(): void
    {
        $request = $this->makeDocument(PurchaseDocumentKind::Request);
        $service = $this->documentsOf(PurchaseDocumentKind::Request);
        $first   = $service->convert($request, $this->clerk);

        $this->documentsOf(PurchaseDocumentKind::QuotationRequest)->cancel($first, 'no', $this->clerk);

        $second = $service->convert($request, $this->clerk);
        $made   = $service->madeFrom($request);

        $this->assertSame([$second, $first], array_map(static fn (object $row): int => (int) $row->document_id, $made));
        $this->assertSame(['open', 'cancelled'], array_map(static fn (object $row): string => $row->status, $made));
        $this->assertSame([], $this->documentsOf(PurchaseDocumentKind::Order)->madeFrom($request));

        $target = $this->documentsOf(PurchaseDocumentKind::QuotationRequest);
        $of     = $target->madeOf($target->find($second, $this->clerk));

        $this->assertSame(PurchaseDocumentKind::Request, $of->kind);
        $this->assertSame($request, $of->document_id);
        $this->assertNull($service->madeOf($service->find($request, $this->clerk)));
    }

    public function testSearch_FiltersByStatusSupplierDatesAndText(): void
    {
        $other   = $this->anotherSupplier('Najd Trading');
        $open    = $this->makeDocument(PurchaseDocumentKind::Order, ['document_date' => '2026-10-01']);
        $najd    = $this->makeDocument(PurchaseDocumentKind::Order, ['document_date' => '2026-10-10', 'supplier_id' => $other]);
        $stopped = $this->makeDocument(PurchaseDocumentKind::Order, ['document_date' => '2026-10-20']);
        $service = $this->documentsOf(PurchaseDocumentKind::Order);

        $service->cancel($stopped, 'no', $this->clerk);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->document_id, $result['rows']);

        $this->assertSame([$stopped, $najd, $open], $ids($service->search([], $this->clerk)));
        $this->assertSame([$stopped], $ids($service->search(['status' => 'cancelled'], $this->clerk)));
        $this->assertSame([$najd, $open], $ids($service->search(['status' => 'open'], $this->clerk)));
        $this->assertSame([$najd], $ids($service->search(['supplier_id' => $other], $this->clerk)));
        $this->assertSame([$najd], $ids($service->search(['search' => 'Najd'], $this->clerk)));
        $this->assertSame([$najd], $ids($service->search(['from' => '2026-10-05', 'to' => '2026-10-15'], $this->clerk)));
        $this->assertSame([], $ids($service->search(['status' => 'received'], $this->clerk)));
        $this->assertSame(3, $service->search([], $this->clerk)['total']);
        $this->assertSame([$najd], $ids($service->search([], $this->clerk, 1, 1)));
    }

    public function testSearch_FindsADocumentByItsNumber(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::PurchaseRequest, ['enabled' => true, 'prefix' => 'PR-', 'padding' => 5], null);

        $first   = $this->makeDocument(PurchaseDocumentKind::Request);
        $second  = $this->makeDocument(PurchaseDocumentKind::Request);
        $service = $this->documentsOf(PurchaseDocumentKind::Request);

        $this->assertSame([$second], array_map(static fn (object $row): int => (int) $row->document_id, $service->search(['search' => 'PR-00002'], $this->clerk)['rows']));
        $this->assertSame('PR-00001', $service->find($first, $this->clerk)->document_number);
    }

    public function testSearch_ReadsTheStatusFromTheDocumentsThatStandAndNotFromAColumn(): void
    {
        $request = $this->makeDocument(PurchaseDocumentKind::Request);
        $service = $this->documentsOf(PurchaseDocumentKind::Request);
        $rfq     = $service->convert($request, $this->clerk);

        $converted = fn (): array => array_map(static fn (object $row): int => (int) $row->document_id, $service->search(['status' => 'converted'], $this->clerk)['rows']);

        $this->assertSame([$request], $converted());
        $this->assertSame('converted', $service->search([], $this->clerk)['rows'][0]->derived_status);

        $this->documentsOf(PurchaseDocumentKind::QuotationRequest)->cancel($rfq, 'no', $this->clerk);

        $this->assertSame([], $converted());
        $this->assertSame('open', $service->search([], $this->clerk)['rows'][0]->derived_status);
    }

    public function testSearch_ShowsTheEmployeeOnlyTheDocumentsOfTheBranchesTheyWorkIn(): void
    {
        $branchB   = $this->createBranch('B2', 'Second branch');
        $locationB = $this->locationOfBranch($branchB);
        $here      = $this->makeDocument(PurchaseDocumentKind::Request);
        $there     = $this->makeDocument(PurchaseDocumentKind::Request, ['location_id' => $locationB]);
        $service   = $this->documentsOf(PurchaseDocumentKind::Request);

        $mainOnly = $this->employeeWithGrants($this->cycleGrants(), [1]);
        $bothOnly = $this->employeeWithGrants($this->cycleGrants(), [1, $branchB]);
        $nowhere  = $this->employeeWithGrants($this->cycleGrants(), []);
        $ids      = static fn (?int $viewer): array => array_map(static fn (object $row): int => (int) $row->document_id, $service->search([], $viewer)['rows']);

        $this->assertSame([$here], $ids($mainOnly));
        $this->assertSame([$there, $here], $ids($bothOnly));
        $this->assertSame([], $ids($nowhere));
        $this->assertNull($service->find($there, $mainOnly));
        $this->assertNotNull($service->find($there, $bothOnly));
    }

    public function testUpdate_TreatsADocumentOfAnotherBranchAsNotFoundAndLeavesItAsItWas(): void
    {
        $branchB   = $this->createBranch('B2', 'Second branch');
        $locationB = $this->locationOfBranch($branchB);
        $there     = $this->makeDocument(PurchaseDocumentKind::Request, ['location_id' => $locationB]);
        $mainOnly  = $this->employeeWithGrants($this->cycleGrants(), [1]);
        $service   = $this->documentsOf(PurchaseDocumentKind::Request);

        $this->turnOnManualStatuses();

        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $service->update($there, $this->documentInput(['note' => 'x', 'location_id' => self::LOCATION_ID]), $mainOnly));
        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $service->changeStatus($there, PurchaseDocumentStatus::Sent, $mainOnly));
        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $service->cancel($there, 'x', $mainOnly));
        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $service->convert($there, $mainOnly));
        $this->assertSame('cycle document', $this->row(PurchaseDocumentKind::Request, $there)->note);
        $this->assertSame('open', $this->row(PurchaseDocumentKind::Request, $there)->status);
    }

    public function testCreate_RefusesALocationOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $branchB   = $this->createBranch('B2', 'Second branch');
        $locationB = $this->locationOfBranch($branchB);
        $mainOnly  = $this->employeeWithGrants($this->cycleGrants(), [1]);

        $refusal = $this->refusal(fn () => $this->makeDocument(PurchaseDocumentKind::Request, ['location_id' => $locationB], $mainOnly));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame(0, $this->countRows('purchase_requests'));
    }

    public function testUpdate_MovesTheDocumentToTheBranchOfItsNewLocation(): void
    {
        $branchB   = $this->createBranch('B2', 'Second branch');
        $locationB = $this->locationOfBranch($branchB);
        $documentId = $this->makeDocument(PurchaseDocumentKind::Request);

        $this->documentsOf(PurchaseDocumentKind::Request)->update($documentId, $this->documentInput(['location_id' => $locationB]), $this->clerk);

        $this->assertSame($branchB, (int) $this->row(PurchaseDocumentKind::Request, $documentId)->branch_id);
    }

    public function testFind_ShowsTheEmployeeWithoutTheViewAllPermissionOnlyTheirOwnDocuments(): void
    {
        $mine   = $this->makeDocument(PurchaseDocumentKind::Order);
        $theirs = $this->makeDocument(PurchaseDocumentKind::Order, [], $this->employeeWithGrants($this->cycleGrants()));
        $writer = $this->employeeWithGrants([PurchaseDocumentKind::Order->permission(PurchaseDocuments::ADD), PurchaseDocumentKind::Order->permission(PurchaseDocuments::EDIT), PurchaseDocumentKind::Order->permission(PurchaseDocuments::DELETE)]);
        $own    = $this->makeDocument(PurchaseDocumentKind::Order, [], $writer);
        $order  = $this->documentsOf(PurchaseDocumentKind::Order);

        $this->assertNull($order->find($mine, $writer));
        $this->assertNull($order->find($theirs, $writer));
        $this->assertNotNull($order->find($own, $writer));
        $this->assertSame([$own], array_map(static fn (object $row): int => (int) $row->document_id, $order->search([], $writer)['rows']));
        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $order->update($mine, $this->documentInput(['note' => 'x']), $writer));
        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $order->cancel($theirs, 'x', $writer));
        $this->assertSame(3, $this->countRows('purchase_orders'));
    }

    public function testFind_ShowsAnEmployeeWhoseRoleNarrowsThemToTheirOwnRecordsOnlyTheirOwnDocumentsEvenWithViewAll(): void
    {
        $roleId = $this->createRole([], 'own');
        $narrow = $this->employeeWithRoles([$roleId], [...$this->cycleGrants(), 'org_all_branches']);
        $mine   = $this->makeDocument(PurchaseDocumentKind::Request, [], $narrow);
        $theirs = $this->makeDocument(PurchaseDocumentKind::Request);
        $this->forgetRoleGrants();
        $order  = $this->documentsOf(PurchaseDocumentKind::Request);

        $this->assertNotNull($order->find($mine, $narrow));
        $this->assertNull($order->find($theirs, $narrow));
        $this->assertNotNull($order->find($theirs, $this->clerk));
    }

    public function testMayViewAll_IsTrueForTheSystemAndForWhoHoldsThePermission(): void
    {
        $service = $this->documentsOf(PurchaseDocumentKind::Request);

        $this->assertTrue($service->mayViewAll(null));
        $this->assertTrue($service->mayViewAll($this->clerk));
        $this->assertFalse($service->mayViewAll($this->employeeWithGrants([PurchaseDocumentKind::Request->permission(PurchaseDocuments::ADD)])));
        $this->assertFalse($service->mayViewAll($this->employeeWithGrants([PurchaseDocumentKind::Order->permission(PurchaseDocuments::VIEW_ALL)])), 'the permission of another family does not count');
    }

    public function testPurchaseDocuments_AuditEverythingEvenWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $documentId = $this->makeDocument(PurchaseDocumentKind::Order);
        $service    = $this->documentsOf(PurchaseDocumentKind::Order);

        $service->cancel($documentId, 'no', $this->clerk);

        $this->assertSame(['created', 'cancelled'], $this->auditActions(PurchaseDocumentKind::Order, $documentId));
        $this->setConfigValue('accounting_enabled', '1');
    }

    public function testPurchaseDocuments_AreNotTouchedByTheLocksOfAClosedPeriod(): void
    {
        $this->closeThrough('2026-10-31');

        $documentId = $this->makeDocument(PurchaseDocumentKind::Order, ['document_date' => '2026-10-05', 'due_date' => '2026-10-10']);
        $service    = $this->documentsOf(PurchaseDocumentKind::Order);

        $service->update($documentId, $this->documentInput(['document_date' => '2026-10-06', 'note' => 'late']), $this->clerk);
        $service->cancel($documentId, 'late', $this->clerk);

        $this->assertSame('cancelled', $this->row(PurchaseDocumentKind::Order, $documentId)->status);
    }

    public function testCreate_NeverPostsAJournalMovesStockOrTouchesATreasuryForAnyOfTheFourDocuments(): void
    {
        $before = $this->bookCounts();

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $documentId = $this->makeDocument($kind);
            $this->documentsOf($kind)->update($documentId, $this->documentInput(['note' => 'x']), $this->clerk);
            $this->documentsOf($kind)->cancel($documentId, 'x', $this->clerk);
        }

        $this->assertSame($before, $this->bookCounts());
        $this->assertStockTies();
    }

    #[DataProvider('stepProvider')]
    public function testConvert_LetsOneOfTwoConversionsAtTheSameMomentMakeTheNextDocument(PurchaseDocumentKind $kind): void
    {
        $sourceId = $this->makeDocument($kind);

        [$first, $second] = $this->raceTwoProcesses('convertDocument', [$kind->value, $sourceId, $this->clerk], [$kind->value, $sourceId, $this->clerk]);

        $this->assertSame(1, (int) $first + (int) $second, 'of two conversions of one document, one makes the next document and the other is refused');
        $this->assertSame(1, $this->countRows($kind->next()->table()));
    }

    public function testCreateFromSource_StoresTheDocumentWithTheLinkToItsSourceAndRequiresThePermissionAndTheCycleToBeOn(): void
    {
        $request = $this->makeDocument(PurchaseDocumentKind::Request);
        $service = $this->documentsOf(PurchaseDocumentKind::QuotationRequest);
        $header  = ['branch_id' => 1, 'location_id' => self::LOCATION_ID, 'supplier_id' => null, 'document_date' => '2026-10-25', 'due_date' => null, 'note' => ''];
        $lines   = [['item_id' => $this->goods, 'description' => '', 'quantity' => '1.000', 'unit_price' => '2.00']];
        $nobody  = $this->employeeWithGrants([]);

        $rfq = $service->createFromSource($request, $header, $lines, $this->clerk);

        $this->assertSame($request, (int) $this->row(PurchaseDocumentKind::QuotationRequest, $rfq)->source_request_id);
        $this->assertSame(['created'], $this->auditActions(PurchaseDocumentKind::QuotationRequest, $rfq));
        $this->assertRefused(AccountingError::PermissionDenied, fn () => $service->createFromSource($request, $header, $lines, $nobody));

        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->assertRefused(AccountingError::PurchaseDocumentModuleOff, fn () => $service->createFromSource($request, $header, $lines, $this->clerk));
        $this->assertSame(1, $this->countRows('quotation_requests'));
    }

    public function testAssertReceivable_ReturnsAnOrderThatIsOpenOrReceivedInPartAndRefusesTheOthers(): void
    {
        $service = $this->documentsOf(PurchaseDocumentKind::Order);
        $open    = $this->makeDocument(PurchaseDocumentKind::Order);
        $partly  = $this->makeDocument(PurchaseDocumentKind::Order);
        $full    = $this->makeDocument(PurchaseDocumentKind::Order, ['lines' => [['item_id' => $this->goods, 'quantity' => '2', 'unit_price' => '4.00']]]);
        $stopped = $this->makeDocument(PurchaseDocumentKind::Order);

        $this->allowEmployeeEverywhere();
        $this->assertGreaterThan(0, $this->purchases->save_value([$this->purchaseLine($this->goods, 0, '3', '4.00')], $this->supplierId, $this->clerk, 'x', 'REF-A', lang('Sales.due'), NEW_ENTRY, null, null, null, null, $partly));
        $this->assertGreaterThan(0, $this->purchases->save_value([$this->purchaseLine($this->goods, 0, '2', '4.00')], $this->supplierId, $this->clerk, 'x', 'REF-B', lang('Sales.due'), NEW_ENTRY, null, null, null, null, $full));
        $this->receivingIds = array_map(static fn (array $row): int => (int) $row['receiving_id'], $this->accountingDb()->table('receivings')->select('receiving_id')->get()->getResultArray());
        $service->cancel($stopped, 'no', $this->clerk);

        $this->assertSame($open, (int) $service->assertReceivable($open, $this->clerk)->document_id);
        $this->assertSame($partly, (int) $service->assertReceivable($partly, $this->clerk)->document_id);
        $this->assertRefused(AccountingError::PurchaseOrderNotReceivable, fn () => $service->assertReceivable($full, $this->clerk));
        $this->assertRefused(AccountingError::PurchaseOrderNotReceivable, fn () => $service->assertReceivable($stopped, $this->clerk));
        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $service->assertReceivable(999999, $this->clerk));
    }

    public function testAssertReceivable_RequiresTheConvertPermissionTheCycleAndAnOrderOfAKindThatIsReceived(): void
    {
        $order   = $this->makeDocument(PurchaseDocumentKind::Order);
        $request = $this->makeDocument(PurchaseDocumentKind::Request);
        $service = $this->documentsOf(PurchaseDocumentKind::Order);
        $viewer  = $this->employeeWithGrants([PurchaseDocumentKind::Order->permission(PurchaseDocuments::VIEW_ALL)]);

        $this->assertRefused(AccountingError::PermissionDenied, fn () => $service->assertReceivable($order, $viewer));
        $this->assertRefused(AccountingError::PurchaseDocumentNotConvertible, fn () => $this->documentsOf(PurchaseDocumentKind::Request)->assertReceivable($request, $this->clerk));

        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->assertRefused(AccountingError::PurchaseDocumentModuleOff, fn () => $service->assertReceivable($order, $this->clerk));
    }

    public function testAssertReceivable_RefusesAnOrderOfAnotherBranchAsOneThatDoesNotExistAndAnOrderWhoseSupplierWasDeleted(): void
    {
        $branchB   = $this->createBranch('B2', 'Second branch');
        $locationB = $this->locationOfBranch($branchB);
        $there     = $this->makeDocument(PurchaseDocumentKind::Order, ['location_id' => $locationB]);
        $here      = $this->makeDocument(PurchaseDocumentKind::Order);
        $mainOnly  = $this->employeeWithGrants($this->cycleGrants(), [1]);
        $service   = $this->documentsOf(PurchaseDocumentKind::Order);

        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $service->assertReceivable($there, $mainOnly));

        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->update(['deleted' => 1]);

        $this->assertRefused(AccountingError::PurchaseDocumentSupplierInvalid, fn () => $service->assertReceivable($here, $this->clerk));
    }

    public function testRemainingLines_ListsOnlyTheLinesWithUnitsLeft(): void
    {
        $other   = $this->item('2.00');
        $orderId = $this->makeDocument(PurchaseDocumentKind::Order, ['lines' => [
            ['item_id' => $this->goods, 'quantity' => '2', 'unit_price' => '4.00'],
            ['item_id' => $other, 'quantity' => '5', 'unit_price' => '2.00'],
        ]]);

        $this->allowEmployeeEverywhere();
        $this->assertGreaterThan(0, $this->purchases->save_value([$this->purchaseLine($this->goods, 0, '2', '4.00')], $this->supplierId, $this->clerk, 'x', 'REF-C', lang('Sales.due'), NEW_ENTRY, null, null, null, null, $orderId));
        $this->receivingIds = array_map(static fn (array $row): int => (int) $row['receiving_id'], $this->accountingDb()->table('receivings')->select('receiving_id')->get()->getResultArray());

        $left = $this->documentsOf(PurchaseDocumentKind::Order)->remainingLines($orderId);

        $this->assertCount(1, $left);
        $this->assertSame([$other, '5.000'], [(int) $left[0]->item_id, $left[0]->remaining]);
    }

    #[DataProvider('kindProvider')]
    public function testUpdate_RefusesWhileTheCycleIsOffAndLeavesTheDocumentAsItWas(PurchaseDocumentKind $kind): void
    {
        $documentId = $this->makeDocument($kind);

        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->assertRefused(AccountingError::PurchaseDocumentModuleOff, fn () => $this->documentsOf($kind)->update($documentId, $this->documentInput(['note' => 'x']), $this->clerk));
        $this->assertSame('cycle document', $this->row($kind, $documentId)->note);
    }

    #[DataProvider('kindProvider')]
    public function testChangeStatus_RefusesWhileTheCycleIsOffWhateverTheSwitchesSay(PurchaseDocumentKind $kind): void
    {
        $this->turnOnManualStatuses();

        $documentId = $this->makeDocument($kind);

        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->assertRefused(AccountingError::PurchaseDocumentModuleOff, fn () => $this->documentsOf($kind)->changeStatus($documentId, PurchaseDocumentStatus::Sent, $this->clerk));
        $this->assertSame('open', $this->row($kind, $documentId)->status);
    }

    #[DataProvider('kindProvider')]
    public function testCancel_RefusesWhileTheCycleIsOffAndLeavesTheDocumentOpen(PurchaseDocumentKind $kind): void
    {
        $documentId = $this->makeDocument($kind);

        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->assertRefused(AccountingError::PurchaseDocumentModuleOff, fn () => $this->documentsOf($kind)->cancel($documentId, 'no', $this->clerk));
        $this->assertSame('open', $this->row($kind, $documentId)->status);
    }

    #[DataProvider('stepProvider')]
    public function testConvert_RefusesWhileTheCycleIsOffAndMakesNothing(PurchaseDocumentKind $kind): void
    {
        $sourceId = $this->makeDocument($kind);

        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->assertRefused(AccountingError::PurchaseDocumentModuleOff, fn () => $this->documentsOf($kind)->convert($sourceId, $this->clerk));
        $this->assertSame(0, $this->countRows($kind->next()->table()));
    }

    public function testUpdate_RefusesAnOrderAReceivingWasMadeFromEvenWhenItIsOpenAndLeavesItAsItWas(): void
    {
        $this->allowEmployeeEverywhere();

        $orderId = $this->makeDocument(PurchaseDocumentKind::Order);
        $service = $this->documentsOf(PurchaseDocumentKind::Order);

        $this->assertGreaterThan(0, $this->purchases->save_value([$this->purchaseLine($this->goods, 0, '2', '4.00')], $this->supplierId, $this->clerk, 'x', 'REF-U', lang('Sales.due'), NEW_ENTRY, null, null, null, null, $orderId));
        $this->receivingIds = array_map(static fn (array $row): int => (int) $row['receiving_id'], $this->accountingDb()->table('receivings')->select('receiving_id')->get()->getResultArray());

        $this->assertRefused(AccountingError::PurchaseDocumentLocked, fn () => $service->update($orderId, $this->documentInput(['note' => 'changed']), $this->clerk));
        $this->assertSame('cycle document', $this->row(PurchaseDocumentKind::Order, $orderId)->note);
        $this->assertSame('2.000', $service->lines($orderId)[0]->received);
        $this->assertSame(PurchaseDocumentStatus::PartlyReceived, $service->statusOf($orderId));
    }
}
