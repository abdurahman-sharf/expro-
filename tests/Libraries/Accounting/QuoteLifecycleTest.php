<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\QuoteLifecycle;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The state of a quote: where it may move and nowhere else, who may move it, what is written about
 * it, and that the quote an invoice was made from becomes converted with the invoice.
 */
class QuoteLifecycleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private QuoteLifecycle $lifecycle;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->allowEmployeeEverywhere();
        $this->clearNumbers();

        $this->lifecycle = new QuoteLifecycle($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $this->clearNumbers();
        $this->accountingDb()->table('accounting_audit_log')->whereIn('action', ['quote_status_changed', 'quote_converted', 'numbering_configured'])->delete();
        $this->stopSelling();

        parent::tearDown();
    }

    private function clearNumbers(): void
    {
        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
    }

    private function setStored(int $saleId, ?QuoteStatus $status): void
    {
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['quote_status' => $status?->value]);
    }

    private function storedStatus(int $saleId): ?string
    {
        $value = $this->saleRow($saleId)->quote_status;

        return $value === null ? null : (string) $value;
    }

    /**
     * @return list<object>
     */
    private function auditOf(int $saleId, string $action): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $saleId, 'action' => $action])->orderBy('audit_id', 'ASC')->get()->getResult();
    }

    private function quoteInBranch(int $branchId): int
    {
        $service = $this->item('0', false);

        return $this->save([$this->line($service, 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($branchId))], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED);
    }

    public function testStatusOf_ReadsAQuoteSavedBeforeTheStatesExistedAsOpen(): void
    {
        $quote = $this->quote();

        $this->setStored($quote, null);

        $this->assertSame(QuoteStatus::Open, $this->lifecycle->statusOf($quote));
    }

    public function testStatusOf_ReadsTheStateAQuoteWasSavedWith(): void
    {
        $this->assertSame(QuoteStatus::Open, $this->lifecycle->statusOf($this->quote()));
        $this->assertSame(QuoteStatus::Draft, $this->lifecycle->statusOf($this->quote(QuoteStatus::Draft)));
    }

    public function testStatusOf_IsNothingForWhatIsNotAQuoteThatIsStillBeingWorkedOn(): void
    {
        $cancelled = $this->quote();
        $this->sales->update_sale_status($cancelled, CANCELED);

        $this->assertNull($this->lifecycle->statusOf($this->draftInvoice()));
        $this->assertNull($this->lifecycle->statusOf($this->save($this->referenceInvoice()['items'], $this->referenceInvoice()['taxes'])));
        $this->assertNull($this->lifecycle->statusOf($cancelled));
        $this->assertNull($this->lifecycle->statusOf(999999));
    }

    public function testChange_MovesAQuoteAlongTheAllowedTransitionsAndKeepsTheStatusOfTheSale(): void
    {
        $quote = $this->quote();

        foreach ([QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Replied, QuoteStatus::Approved, QuoteStatus::Ordered, QuoteStatus::Approved, QuoteStatus::Open] as $to) {
            $this->assertSame($to, $this->lifecycle->change($quote, $to, $this->employeeId));
            $this->assertSame((string) $to->value, $this->storedStatus($quote));
            $this->assertSame(QuoteStatus::from((int) $this->storedStatus($quote)), $this->lifecycle->statusOf($quote));
            $this->assertSame((string) SUSPENDED, (string) $this->saleRow($quote)->sale_status, 'the status of the sale is not the state of the quote');
        }
    }

    public function testChange_StoresTheStateApartFromTheStatusAndTheTypeOfTheSale(): void
    {
        $quote = $this->quote();

        $this->lifecycle->change($quote, QuoteStatus::Approved, $this->employeeId);

        $row = $this->saleRow($quote);

        $this->assertSame((string) SUSPENDED, (string) $row->sale_status);
        $this->assertSame((string) SALE_TYPE_QUOTE, (string) $row->sale_type);
        $this->assertSame('4', (string) $row->quote_status);
    }

    public function testChange_WritesEachChangeInTheAuditLogWithWhoMadeItAndFromWhichStateToWhich(): void
    {
        $quote = $this->quote();

        $this->lifecycle->change($quote, QuoteStatus::Sent, $this->employeeId);
        $this->lifecycle->change($quote, QuoteStatus::Approved, $this->employeeId);

        $entries = $this->auditOf($quote, 'quote_status_changed');

        $this->assertCount(2, $entries);
        $this->assertSame(['from' => 'Open', 'to' => 'Sent'], json_decode((string) $entries[0]->details, true));
        $this->assertSame(['from' => 'Sent', 'to' => 'Approved'], json_decode((string) $entries[1]->details, true));
        $this->assertSame($this->employeeId, (int) $entries[0]->employee_id);
        $this->assertSame('user', $entries[0]->origin);
        $this->assertSame(1, (int) $entries[0]->branch_id);
    }

    public function testChange_WritesNothingWhenTheQuoteIsAlreadyInTheStateAsked(): void
    {
        $quote = $this->quote();

        $this->assertSame(QuoteStatus::Open, $this->lifecycle->change($quote, QuoteStatus::Open, $this->employeeId));
        $this->assertSame([], $this->auditOf($quote, 'quote_status_changed'));
    }

    public function testChange_RecordsTheSystemWhenNoEmployeeActs(): void
    {
        $quote = $this->quote();

        $this->lifecycle->change($quote, QuoteStatus::Viewed, null);

        $this->assertSame('system', $this->auditOf($quote, 'quote_status_changed')[0]->origin);
    }

    public function testChange_RejectsEveryMoveTheStateDoesNotAllowAndLeavesTheState(): void
    {
        foreach (QuoteStatus::cases() as $from) {
            foreach (QuoteStatus::cases() as $to) {
                if ($from === $to || $from->canMoveTo($to)) {
                    continue;
                }

                $quote = $this->quote();
                $this->setStored($quote, $from);

                try {
                    $this->lifecycle->change($quote, $to, $this->employeeId);
                    $this->fail("{$from->name} to {$to->name} was allowed");
                } catch (AccountingException $refusal) {
                    $this->assertSame(AccountingError::QuoteStatusNotAllowed, $refusal->error, "{$from->name} to {$to->name}");
                }

                $this->assertSame((string) $from->value, $this->storedStatus($quote), "{$from->name} stays after the refusal of {$to->name}");
                $this->assertSame([], $this->auditOf($quote, 'quote_status_changed'));
            }
        }
    }

    public function testChange_NamesTheTwoStatesInTheMessageOfARefusal(): void
    {
        $quote = $this->quote(QuoteStatus::Draft);

        try {
            $this->lifecycle->change($quote, QuoteStatus::Approved, $this->employeeId);
            $this->fail('A draft cannot be approved.');
        } catch (AccountingException $refusal) {
            $this->assertSame(['from' => lang('Sales.quote_status_draft'), 'to' => lang('Sales.quote_status_approved')], $refusal->context);
            $this->assertSame(lang('Accounting.error_quote_status_not_allowed', [lang('Sales.quote_status_draft'), lang('Sales.quote_status_approved')]), AccountingMessage::of($refusal));
        }
    }

    public function testChange_RejectsMovingAQuoteToConvertedByHand(): void
    {
        foreach ([QuoteStatus::Open, QuoteStatus::Approved, QuoteStatus::Ordered] as $from) {
            $quote = $this->quote();
            $this->setStored($quote, $from);

            try {
                $this->lifecycle->change($quote, QuoteStatus::Converted, $this->employeeId);
                $this->fail("A quote that is {$from->name} was set to converted by hand");
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::QuoteStatusNotAllowed, $refusal->error);
            }

            $this->assertSame((string) $from->value, $this->storedStatus($quote));
        }
    }

    public function testChange_RejectsWhatIsNotAQuoteThatIsBeingWorkedOn(): void
    {
        $cancelled = $this->quote();
        $this->sales->update_sale_status($cancelled, CANCELED);

        foreach ([$this->draftInvoice(), $cancelled, 999999] as $saleId) {
            try {
                $this->lifecycle->change($saleId, QuoteStatus::Approved, $this->employeeId);
                $this->fail("Sale {$saleId} is not a quote");
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::QuoteNotFound, $refusal->error);
            }
        }
    }

    public function testChange_RejectsAnEmployeeWhoDoesNotWorkInTheBranchOfTheQuote(): void
    {
        $second = $this->createBranch();
        $quote  = $this->quoteInBranch($second);
        $clerk  = $this->employeeWithGrants([], [1]);

        try {
            $this->lifecycle->change($quote, QuoteStatus::Approved, $clerk);
            $this->fail('The clerk of the first branch changed a quote of the second');
        } catch (BranchException $refusal) {
            $this->assertSame(BranchError::NotAllowed, $refusal->error);
        }

        $this->assertSame('0', $this->storedStatus($quote));
        $this->assertSame([], $this->auditOf($quote, 'quote_status_changed'));
    }

    public function testChange_AcceptsAnEmployeeWhoWorksInTheBranchOfTheQuoteAndOneWhoWorksInEveryBranch(): void
    {
        $second = $this->createBranch();
        $quote  = $this->quoteInBranch($second);

        $this->assertSame(QuoteStatus::Sent, $this->lifecycle->change($quote, QuoteStatus::Sent, $this->employeeWithGrants([], [$second])));
        $this->assertSame(QuoteStatus::Viewed, $this->lifecycle->change($quote, QuoteStatus::Viewed, $this->employeeWithGrants([])));
        $this->assertSame(QuoteStatus::Replied, $this->lifecycle->change($quote, QuoteStatus::Replied, null));
    }

    public function testChange_WritesNothingInTheAuditLogWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $quote = $this->quote();

        $this->lifecycle->change($quote, QuoteStatus::Approved, $this->employeeId);

        $this->assertSame('4', $this->storedStatus($quote));
        $this->assertSame([], $this->auditOf($quote, 'quote_status_changed'));
    }

    public function testChange_OpensADraftWithTheNextNumberOfTheQuoteSequence(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::SalesQuote, ['enabled' => true, 'prefix' => 'QT-', 'padding' => 3]);

        $first  = $this->quote(QuoteStatus::Draft);
        $second = $this->quote(QuoteStatus::Draft);

        $this->assertNull($this->saleRow($first)->quote_number, 'a draft takes no number of the sequence');
        $this->assertSame(1, (new DocumentNumbering($this->accountingDb()))->sequence(DocumentType::SalesQuote)->nextNumber, 'and leaves the sequence where it was');

        $this->lifecycle->change($second, QuoteStatus::Open, $this->employeeId);
        $this->lifecycle->change($first, QuoteStatus::Open, $this->employeeId);

        $this->assertSame('QT-001', $this->saleRow($second)->quote_number);
        $this->assertSame('QT-002', $this->saleRow($first)->quote_number);
        $this->assertSame('QT-001', json_decode((string) $this->auditOf($second, 'quote_status_changed')[0]->details, true)['number']);
        $this->assertSame(3, (new DocumentNumbering($this->accountingDb()))->sequence(DocumentType::SalesQuote)->nextNumber);
    }

    public function testChange_KeepsTheNumberADraftAlreadyCarriesWhenItIsOpened(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::SalesQuote, ['enabled' => true, 'prefix' => 'QT-', 'padding' => 3]);

        $quote = $this->quote(QuoteStatus::Draft);

        $this->accountingDb()->table('sales')->where('sale_id', $quote)->update(['quote_number' => 'TYPED-7']);
        $this->lifecycle->change($quote, QuoteStatus::Open, $this->employeeId);

        $this->assertSame('TYPED-7', $this->saleRow($quote)->quote_number);
        $this->assertSame(1, (new DocumentNumbering($this->accountingDb()))->sequence(DocumentType::SalesQuote)->nextNumber);
    }

    public function testChange_OpensADraftWithNoNumberWhileTheQuoteSequenceIsOff(): void
    {
        $quote = $this->quote(QuoteStatus::Draft);

        $this->lifecycle->change($quote, QuoteStatus::Open, $this->employeeId);

        $this->assertNull($this->saleRow($quote)->quote_number);
        $this->assertSame(QuoteStatus::Open, $this->lifecycle->statusOf($quote));
    }

    public function testChange_GivesNoNumberWhenAQuoteMovesBetweenStatesOtherThanOpeningADraft(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::SalesQuote, ['enabled' => true, 'prefix' => 'QT-', 'padding' => 3]);

        $quote = $this->quote();
        $this->setStored($quote, QuoteStatus::Open);
        $this->accountingDb()->table('sales')->where('sale_id', $quote)->update(['quote_number' => null]);

        $this->lifecycle->change($quote, QuoteStatus::Approved, $this->employeeId);

        $this->assertNull($this->saleRow($quote)->quote_number);
    }

    public function testChange_StopsOnTheLockAnotherRequestHoldsOnTheQuote(): void
    {
        $quote = $this->quote();
        $other = $this->openSecondConnection();

        $other->transStart();
        $other->query('SELECT sale_id FROM ' . $other->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$quote]);

        try {
            $this->assertWaitsForLock(fn () => (new QuoteLifecycle($this->openSecondConnection()))->change($quote, QuoteStatus::Approved, $this->employeeId));
        } finally {
            $other->transComplete();
            $other->close();
        }

        $this->assertSame('0', $this->storedStatus($quote));
    }

    public function testMarkConverted_ConvertsTheQuoteAndWritesItInTheAuditLogWithTheInvoice(): void
    {
        $quote = $this->quote();
        $this->setStored($quote, QuoteStatus::Approved);

        $this->lifecycle->markConverted($quote, 4242, $this->employeeId);

        $this->assertSame(QuoteStatus::Converted, $this->lifecycle->statusOf($quote));
        $this->assertSame('5', $this->storedStatus($quote));
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($quote)->sale_status);

        $entries = $this->auditOf($quote, 'quote_converted');

        $this->assertCount(1, $entries);
        $this->assertSame(['from' => 'Approved', 'invoice_sale_id' => 4242], json_decode((string) $entries[0]->details, true));
        $this->assertSame($this->employeeId, (int) $entries[0]->employee_id);
    }

    public function testMarkConverted_ConvertsAQuoteFromEveryStateAnInvoiceMayBeMadeFrom(): void
    {
        foreach (QuoteStatus::cases() as $from) {
            if (! $from->canConvert()) {
                continue;
            }

            $quote = $this->quote();
            $this->setStored($quote, $from);

            $this->lifecycle->markConverted($quote, 1, $this->employeeId);

            $this->assertSame(QuoteStatus::Converted, $this->lifecycle->statusOf($quote), $from->name);
        }
    }

    public function testMarkConverted_RejectsADraftAndAQuoteThatWasConvertedAlready(): void
    {
        foreach ([QuoteStatus::Draft, QuoteStatus::Converted] as $from) {
            $quote = $this->quote();
            $this->setStored($quote, $from);

            try {
                $this->lifecycle->markConverted($quote, 1, $this->employeeId);
                $this->fail("A quote that is {$from->name} became an invoice");
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::QuoteNotConvertible, $refusal->error, $from->name);
            }

            $this->assertSame((string) $from->value, $this->storedStatus($quote));
            $this->assertSame([], $this->auditOf($quote, 'quote_converted'));
        }
    }

    public function testMarkConverted_RejectsWhatIsNotAQuote(): void
    {
        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage('The sale is not a quote.');

        $this->lifecycle->markConverted($this->draftInvoice(), 1, $this->employeeId);
    }

    public function testMarkConverted_RejectsAnEmployeeWhoDoesNotWorkInTheBranchOfTheQuote(): void
    {
        $second = $this->createBranch();
        $quote  = $this->quoteInBranch($second);

        try {
            $this->lifecycle->markConverted($quote, 1, $this->employeeWithGrants([], [1]));
            $this->fail('The clerk of the first branch converted a quote of the second');
        } catch (BranchException $refusal) {
            $this->assertSame(BranchError::NotAllowed, $refusal->error);
        }

        $this->assertSame('0', $this->storedStatus($quote));
    }

    public function testMarkConverted_IsKeptOrUndoneWithTheTransactionOfTheCaller(): void
    {
        $quote = $this->quote();
        $db    = $this->accountingDb();

        $db->transStart();
        $this->lifecycle->markConverted($quote, 1, $this->employeeId);
        $db->transRollback();

        $this->assertSame('0', $this->storedStatus($quote));
        $this->assertSame([], $this->auditOf($quote, 'quote_converted'));
    }

    public function testMarkConverted_WritesNothingInTheAuditLogWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $quote = $this->quote();

        $this->lifecycle->markConverted($quote, 1, $this->employeeId);

        $this->assertSame('5', $this->storedStatus($quote));
        $this->assertSame([], $this->auditOf($quote, 'quote_converted'));
    }

    public function testMayConvert_FollowsTheStateOfTheQuote(): void
    {
        $this->assertFalse($this->lifecycle->mayConvert($this->quote(QuoteStatus::Draft)));
        $this->assertTrue($this->lifecycle->mayConvert($this->quote()));
        $this->assertFalse($this->lifecycle->mayConvert($this->draftInvoice()));
        $this->assertFalse($this->lifecycle->mayConvert(999999));

        $converted = $this->quote();
        $this->setStored($converted, QuoteStatus::Converted);

        $this->assertFalse($this->lifecycle->mayConvert($converted));
    }

    public function testAssertUnlocked_RejectsAConvertedQuoteAndLetsEverythingElsePass(): void
    {
        foreach ([$this->quote(), $this->quote(QuoteStatus::Draft), $this->draftInvoice(), 999999] as $saleId) {
            $this->lifecycle->assertUnlocked($saleId);
        }

        $converted = $this->quote();
        $this->setStored($converted, QuoteStatus::Converted);

        $this->expectException(AccountingException::class);

        try {
            $this->lifecycle->assertUnlocked($converted);
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::QuoteLocked, $refusal->error);

            throw $refusal;
        }
    }

    public function testInvoiceOf_FindsTheFirstCompletedInvoiceMadeFromTheQuoteAndNoOtherInvoice(): void
    {
        $quote = $this->quote();

        $this->assertNull($this->lifecycle->invoiceOf($quote));

        $draft = $this->save($this->referenceInvoice()['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, quoteSourceId: $quote);

        $this->assertNull($this->lifecycle->invoiceOf($quote), 'a draft is not the invoice yet');

        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft);

        $this->assertSame($invoice, $this->lifecycle->invoiceOf($quote));
        $this->assertNull($this->lifecycle->invoiceOf($this->quote()));
    }

    public function testChange_ReadsTheStateAnotherRequestJustCommittedAndNotTheOneItsOwnTransactionSaw(): void
    {
        $quote = $this->quote();
        $other = $this->openSecondConnection();

        $this->startWithSnapshot($other);

        $this->lifecycle->change($quote, QuoteStatus::Approved, $this->employeeId);

        try {
            (new QuoteLifecycle($other))->change($quote, QuoteStatus::Sent, $this->employeeId);
            $this->fail('A quote that was approved a moment ago was sent');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::QuoteStatusNotAllowed, $refusal->error);
        } finally {
            $other->transRollback();
            $other->resetTransStatus();
            $other->close();
        }

        $this->assertSame((string) QuoteStatus::Approved->value, $this->storedStatus($quote));
    }

    public function testMarkConverted_ReadsTheStateAnotherRequestJustCommittedAndNotTheOneItsOwnTransactionSaw(): void
    {
        $quote = $this->quote();
        $other = $this->openSecondConnection();

        $this->startWithSnapshot($other);

        $this->lifecycle->markConverted($quote, 7, $this->employeeId);

        try {
            (new QuoteLifecycle($other))->markConverted($quote, 8, $this->employeeId);
            $this->fail('A quote that was converted a moment ago was converted again');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::QuoteNotConvertible, $refusal->error);
        } finally {
            $other->transRollback();
            $other->resetTransStatus();
            $other->close();
        }

        $this->assertCount(1, $this->auditOf($quote, 'quote_converted'));
    }
}
