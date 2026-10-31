<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\HeaderCharges;
use App\Libraries\Accounting\DraftInvoice;
use App\Libraries\InvoiceDiscountProblem;
use App\Libraries\Tax_lib;
use App\Models\Enums\Rounding_mode;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * A draft invoice as the books know it: a suspended sale made as an invoice, known by a temporary
 * number that is never stored, and read in the shape the sale model saves it as an issued invoice.
 */
class DraftInvoiceTest extends CIUnitTestCase
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

    private DraftInvoice $drafts;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->drafts = new DraftInvoice($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @return array{items: list<array<string, mixed>>, taxes: list<array<string, mixed>>, cream: int, perfume: int}
     */
    private function taxedReference(): array
    {
        $cycle = $this->referenceInvoice();

        foreach ([$cycle['cream'], $cycle['perfume']] as $itemId) {
            $this->accountingDb()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'VAT', 'percent' => '15']);
        }

        return $cycle;
    }

    public function testLabel_IsTheTemporaryNumberWorkedOutFromTheIdOfTheDraft(): void
    {
        $this->assertSame('draft-11', DraftInvoice::label(11));
        $this->assertSame('draft-', DraftInvoice::PREFIX);
    }

    public function testLabel_IsNeverStoredWithTheDraft(): void
    {
        $draft = $this->draftInvoice();
        $row   = $this->saleRow($draft);

        $this->assertNull($row->invoice_number);
        $this->assertNull($row->quote_number);
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testFind_ReadsASuspendedInvoiceAndNothingElse(): void
    {
        $cycle     = $this->referenceInvoice();
        $draft     = $this->draftInvoice($cycle['items']);
        $invoice   = $this->save($cycle['items'], $cycle['taxes']);
        $quote     = $this->quote();
        $pos       = $this->save([$this->line($this->item('0', false), 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS, -1, true, null, SUSPENDED);
        $cancelled = $this->draftInvoice($cycle['items']);

        $this->sales->update_sale_status($cancelled, CANCELED);

        $this->assertSame($draft, (int) $this->drafts->find($draft)->sale_id);
        $this->assertTrue($this->drafts->isDraft($draft));

        foreach ([$invoice, $quote, $pos, $cancelled, 999999] as $saleId) {
            $this->assertNull($this->drafts->find($saleId), (string) $saleId);
            $this->assertFalse($this->drafts->isDraft($saleId), (string) $saleId);
        }
    }

    public function testForIssue_ReadsTheLinesOfTheDraftWithThePriceTheyWereSavedAtAndTheCostOfTheItemNow(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->accountingDb()->table('items')->where('item_id', $cycle['cream'])->update(['cost_price' => '45.00', 'unit_price' => '999.00']);

        $issue = $this->drafts->forIssue($draft);

        $this->assertSame($draft, $issue->saleId);
        $this->assertCount(2, $issue->items);

        $cream = $issue->items[0];

        $this->assertSame($cycle['cream'], $cream['item_id']);
        $this->assertSame('75.000000', bcadd($cream['price'], '0', 6), 'the price of the draft, not the list price of today');
        $this->assertSame('45.000000', bcadd($cream['cost_price'], '0', 6), 'the cost of the item now');
        $this->assertSame('4.000', bcadd($cream['quantity'], '0', 3));
        $this->assertSame(PERCENT, $cream['discount_type']);
        $this->assertSame(self::LOCATION_ID, $cream['item_location']);
        $this->assertSame([0, 1], array_keys($issue->items));
    }

    public function testForIssue_WorksTheTaxesOutNowAndNotFromWhatTheDraftStoredWhichIsNothing(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame(0, $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $draft)->countAllResults(), 'a draft stores no taxes');

        $issue = $this->drafts->forIssue($draft);
        $taxes = array_map(static fn (array $tax): string => bcadd((string) $tax['item_tax_amount'], '0', 2), $issue->taxes[1]);

        $this->assertSame(['40.50', '66.00'], $taxes);
        $this->assertSame(['1', '1'], array_map(static fn (array $tax): string => (string) $tax['tax_type'], $issue->taxes[1]));
    }

    public function testForIssue_TaxesAsAnInvoiceWithTheTaxCodeOfTheCustomerWhenTaxesAreByDestination(): void
    {
        $db    = $this->accountingDb();
        $cycle = $this->referenceInvoice();

        $db->table('tax_categories')->insert(['tax_category' => 'Goods ' . uniqid(), 'tax_group_sequence' => 1]);
        $categoryId = (int) $db->insertID();
        $db->table('tax_jurisdictions')->insert(['jurisdiction_name' => 'Zone', 'tax_group' => 'ZG' . random_int(100000, 999999), 'tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'tax_group_sequence' => 1, 'cascade_sequence' => 0]);
        $jurisdictionId = (int) $db->insertID();
        $codes          = [];

        foreach (['10' => 'Customer', '20' => 'Default'] as $rate => $name) {
            $db->table('tax_codes')->insert(['tax_code' => $name . random_int(1000, 9999), 'tax_code_name' => $name, 'city' => '', 'state' => '']);
            $codes[$rate] = (int) $db->insertID();
            $db->table('tax_rates')->insert(['rate_tax_code_id' => $codes[$rate], 'rate_tax_category_id' => $categoryId, 'rate_jurisdiction_id' => $jurisdictionId, 'tax_rate' => $rate, 'tax_rounding_code' => Rounding_mode::HALF_UP]);
        }

        $db->table('customers')->where('person_id', $this->customerId)->update(['sales_tax_code_id' => $codes['10']]);

        $draft  = $this->draftInvoice($cycle['items']);
        $config = config(OSPOS::class);
        $before = $config->settings;

        $config->settings = [...$before, 'use_destination_based_tax' => '1', 'default_tax_code' => $codes['20'], 'default_tax_category' => $categoryId];

        try {
            $issue = $this->drafts->forIssue($draft);
        } finally {
            $config->settings = $before;
            $db->table('customers')->where('person_id', $this->customerId)->update(['sales_tax_code_id' => null]);
            $db->table('tax_rates')->whereIn('rate_tax_code_id', array_values($codes))->delete();
            $db->table('tax_codes')->whereIn('tax_code_id', array_values($codes))->delete();
            $db->table('tax_jurisdictions')->where('jurisdiction_id', $jurisdictionId)->delete();
            $db->table('tax_categories')->where('tax_category_id', $categoryId)->delete();
        }

        $this->assertSame(['27.00', '44.00'], array_map(static fn (array $tax): string => bcadd((string) $tax['item_tax_amount'], '0', 2), $issue->taxes[1]), 'the customer code of an invoice, 10%, and not the default code of a sale, 20%');
    }

    public function testFingerprint_ChangesWithTheHeaderTheLinesAndThePaymentsButNotWithASaveThatChangesNothing(): void
    {
        $db    = $this->accountingDb();
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $read  = $this->drafts->fingerprint($draft);

        $this->assertSame($read, $this->drafts->forIssue($draft)->fingerprint);

        $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, $draft);
        $this->assertSame($read, $this->drafts->fingerprint($draft), 'the same draft saved again');

        $seller  = $this->employeeId;
        $changes = [
            'its comment'     => static fn () => $db->table('sales')->where('sale_id', $draft)->update(['comment' => 'changed']),
            'its customer'    => static fn () => $db->table('sales')->where('sale_id', $draft)->update(['customer_id' => null]),
            'its discount'    => static fn () => $db->table('sales')->where('sale_id', $draft)->update(['invoice_discount_type' => PERCENT, 'invoice_discount' => '5']),
            'its day'         => static fn () => $db->table('sales')->where('sale_id', $draft)->update(['invoice_date' => '2020-01-02']),
            'its terms'       => static fn () => $db->table('sales')->where('sale_id', $draft)->update(['payment_terms_days' => 9]),
            'its delivery'    => static fn () => $db->table('sales')->where('sale_id', $draft)->update(['delivered' => 0]),
            'a quantity'      => static fn () => $db->table('sales_items')->where(['sale_id' => $draft, 'line' => 0])->update(['quantity_purchased' => '7']),
            'a price'         => static fn () => $db->table('sales_items')->where(['sale_id' => $draft, 'line' => 0])->update(['item_unit_price' => '1.5']),
            'a line removed'  => static fn () => $db->table('sales_items')->where(['sale_id' => $draft, 'line' => 1])->delete(),
            'a payment added' => static fn () => $db->table('sales_payments')->insert(['sale_id' => $draft, 'payment_type' => 'Cash', 'payment_amount' => '10', 'cash_refund' => '0', 'cash_adjustment' => 0, 'employee_id' => $seller, 'reference_code' => '', 'treasury_id' => null, 'treasury_branch_id' => null]),
        ];

        $previous = $read;

        foreach ($changes as $name => $change) {
            $change();

            $now = $this->drafts->fingerprint($draft);

            $this->assertNotSame($previous, $now, "after {$name}");

            $previous = $now;
        }
    }

    public function testForIssue_TakesTheFingerprintBeforeItReadsTheDraftSoAChangeWhileItReadsIsNotCoveredByIt(): void
    {
        $cycle   = $this->taxedReference();
        $draft   = $this->draftInvoice($cycle['items']);
        $changed = false;

        $listen = function (Query $query) use (&$changed, $draft): void {
            if ($changed || ! str_contains($query->getQuery(), 'sales_payments')) {
                return;
            }

            $changed = true;

            $db     = $this->accountingDb();
            $result = $db->resultID;

            $db->table('sales')->where('sale_id', $draft)->update(['comment' => 'saved again while the draft was read']);

            $db->resultID = $result;
        };

        Events::on('DBQuery', $listen);

        try {
            $issue = $this->drafts->forIssue($draft);
        } finally {
            Events::removeListener('DBQuery', $listen);
        }

        $this->assertTrue($changed, 'the draft was saved again while it was read');
        $this->assertNotSame($this->drafts->fingerprint($draft), $issue->fingerprint, 'what was read is refused when it is issued, never accepted with a fingerprint taken after the change');
    }

    public function testForIssue_TaxesNothingWhenTheCustomerIsNotTaxable(): void
    {
        $cycle = $this->taxedReference();
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['taxable' => 0]);

        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame([[], []], $this->drafts->forIssue($draft)->taxes);
    }

    public function testForIssue_CarriesTheCustomerTheCommentTheDeliveryAndTheNumbersTheDraftWasSavedWith(): void
    {
        $cycle = $this->referenceInvoice();
        $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, false, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: null, paymentTerms: 21);

        $this->accountingDb()->table('sales')->where('sale_id', $draft)->update(['invoice_number' => 'TYPED-1']);

        $issue = $this->drafts->forIssue($draft);

        $this->assertSame($this->customerId, $issue->customerId);
        $this->assertSame('test', $issue->comment);
        $this->assertFalse($issue->delivered);
        $this->assertSame('TYPED-1', $issue->invoiceNumber);
        $this->assertSame(21, $issue->paymentTermsDays);
        $this->assertNull($issue->invoiceDate);
        $this->assertNull($issue->invoiceDiscount);
        $this->assertNull($issue->problem);
    }

    public function testForIssue_ReadsTheStoredPaymentsByTheirType(): void
    {
        $cycle = $this->referenceInvoice();
        $draft = $this->save($cycle['items'], [], [$this->payment(lang('Sales.cash'), '100.00'), $this->payment(lang('Sales.check'), '50.00')], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);
        $issue = $this->drafts->forIssue($draft);

        $this->assertSame([lang('Sales.cash'), lang('Sales.check')], array_keys($issue->payments));
        $this->assertSame('100.00', $issue->payments[lang('Sales.cash')]['payment_amount']);
        $this->assertSame(0, $issue->payments[lang('Sales.cash')]['treasury_id']);
        $this->assertFalse($issue->paidWithStoredValue);
    }

    public function testForIssue_SaysWhenADraftWasPaidWithAGiftCardOrWithRewardPoints(): void
    {
        $cycle = $this->referenceInvoice();
        $db    = $this->accountingDb();

        $db->table('giftcards')->insert(['giftcard_number' => 7771236, 'value' => 500, 'deleted' => 0, 'person_id' => null]);

        $withCard    = $this->save($cycle['items'], [], [$this->payment(lang('Sales.giftcard') . ':7771236', '10.00')], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);
        $withRewards = $this->draftInvoice($cycle['items']);

        $db->table('sales_payments')->insert(['sale_id' => $withRewards, 'payment_type' => lang('Sales.rewards'), 'payment_amount' => '5.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId]);

        $this->assertTrue($this->drafts->forIssue($withCard)->paidWithStoredValue);
        $this->assertTrue($this->drafts->forIssue($withRewards)->paidWithStoredValue);

        $db->table('sales_payments')->whereIn('sale_id', [$withCard, $withRewards])->delete();
        $db->table('giftcards')->where('giftcard_number', 7771236)->delete();
    }

    public function testForIssue_GivesTheDiscountOnTheWholeOfTheDraftItsSharesAndTheTaxesOfWhatIsLeft(): void
    {
        $cycle = $this->discountedReferenceInvoice();

        foreach ([$cycle['cream'], $cycle['perfume']] as $itemId) {
            $this->accountingDb()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'VAT', 'percent' => '15']);
        }

        $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, $cycle['discount']);
        $issue = $this->drafts->forIssue($draft);

        $this->assertSame(['type' => PERCENT, 'value' => '10.0000'], ['type' => $issue->invoiceDiscount['type'], 'value' => $issue->invoiceDiscount['value']]);
        $this->assertSame(['27.0000', '44.0000'], array_map(static fn (array $row): string => (string) $row['invoice_discount_share'], array_values($issue->items)));
        $this->assertSame(['36.45', '59.40'], array_map(static fn (array $tax): string => bcadd((string) $tax['item_tax_amount'], '0', 2), $issue->taxes[1]));
    }

    public function testForIssue_SaysWhyADiscountThatNoLongerFitsTheLinesIsRefusedAndWorksNoTaxOut(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->accountingDb()->table('sales')->where('sale_id', $draft)->update(['invoice_discount_type' => PERCENT, 'invoice_discount' => '150']);

        $issue = $this->drafts->forIssue($draft);

        $this->assertSame(InvoiceDiscountProblem::PercentOver100, $issue->problem);
        $this->assertSame([[], []], $issue->taxes);
    }

    public function testForIssue_IsNothingForWhatIsNotADraftInvoice(): void
    {
        $this->assertNull($this->drafts->forIssue($this->quote()));
        $this->assertNull($this->drafts->forIssue($this->save($this->referenceInvoice()['items'], [])));
        $this->assertNull($this->drafts->forIssue(999999));
    }

    public function testPreview_ReadsADraftAndAQuoteAndNothingElse(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $quote = $this->quote(null, $cycle['items']);
        $done  = $this->save($cycle['items'], $cycle['taxes']);
        $pos   = $this->save([$this->line($this->item('0', false), 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS, -1, true, null, SUSPENDED);

        $this->assertSame($draft, $this->drafts->preview($draft)->saleId);
        $this->assertSame($quote, $this->drafts->preview($quote)->saleId);

        foreach ([$done, $pos, 999999] as $saleId) {
            $this->assertNull($this->drafts->preview($saleId), (string) $saleId);
        }
    }

    public function testFigures_SaysWhatTheDraftComesToWithTheTaxItWouldCarryNow(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame(
            ['gross' => '740.0000', 'discount' => '30.0000', 'invoiceDiscount' => '0.0000', 'tax' => '106.5000', 'total' => '816.5000', 'shipping' => '0.0000', 'shippingTax' => '0.0000', 'adjustment' => '0.0000', 'adjustmentLabel' => ''],
            $this->drafts->figures($this->drafts->forIssue($draft)),
        );
    }

    public function testFigures_TakesTheDiscountOnTheWholeOffBeforeTheTax(): void
    {
        $cycle = $this->discountedReferenceInvoice();

        foreach ([$cycle['cream'], $cycle['perfume']] as $itemId) {
            $this->accountingDb()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'VAT', 'percent' => '15']);
        }

        $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, $cycle['discount']);

        $this->assertSame(
            ['gross' => '740.0000', 'discount' => '30.0000', 'invoiceDiscount' => '71.0000', 'tax' => '95.8500', 'total' => '734.8500', 'shipping' => '0.0000', 'shippingTax' => '0.0000', 'adjustment' => '0.0000', 'adjustmentLabel' => ''],
            $this->drafts->figures($this->drafts->forIssue($draft)),
        );
    }

    public function testFigures_ComesToWhatTheInvoiceCarriesOnceItIsIssued(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $total = $this->drafts->figures($this->drafts->forIssue($draft))['total'];

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame($this->customerAccount() . ':' . bcadd($total, '0', 2) . ':0.00', $this->standing($draft, 'revenue')[0]['lines'][0]);
    }

    public function testFigures_IsEmptyForADraftWithNoLines(): void
    {
        $service = $this->item('0', false);
        $draft   = $this->draftInvoice([$this->line($service, 0, '1', '10')]);

        $this->accountingDb()->table('sales_items')->where('sale_id', $draft)->delete();

        $issue = $this->drafts->forIssue($draft);

        $this->assertSame([], $issue->items);
        $this->assertSame(['gross' => '0.0000', 'discount' => '0.0000', 'invoiceDiscount' => '0.0000', 'tax' => '0.0000', 'total' => '0.0000', 'shipping' => '0.0000', 'shippingTax' => '0.0000', 'adjustment' => '0.0000', 'adjustmentLabel' => ''], $this->drafts->figures($issue));
    }

    public function testForIssue_WorksTheTaxesOutWithTheScaleOfTheRegisterWhateverScaleTheCallerLeftAndPutsItBack(): void
    {
        $cycle  = $this->taxedReference();
        $draft  = $this->draftInvoice($cycle['items']);
        $before = bcscale();

        bcscale(0);

        try {
            $issue = $this->drafts->forIssue($draft);
            $after = bcscale();
        } finally {
            bcscale($before);
        }

        $this->assertSame(['40.50', '66.00'], array_map(static fn (array $tax): string => bcadd((string) $tax['item_tax_amount'], '0', 2), $issue->taxes[1]));
        $this->assertSame(0, $after, 'the scale of the caller is put back');
    }

    public function testFigures_CountsTheChargesOfTheHeaderInTheTotalOfTheDraft(): void
    {
        $this->setConfigValue('accounting_sales_adjustment', '1');
        $this->setConfigValue('accounting_sales_shipping', '1');

        try {
            $cycle = $this->taxedReference();
            $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, headerCharges: new HeaderCharges('Handling', '-10', '20'));
            $issue = $this->drafts->forIssue($draft);

            $this->assertSame(
                ['gross' => '740.0000', 'discount' => '30.0000', 'invoiceDiscount' => '0.0000', 'tax' => '106.5000', 'total' => '826.5000', 'shipping' => '20.0000', 'shippingTax' => '0.0000', 'adjustment' => '-10.0000', 'adjustmentLabel' => 'Handling'],
                $this->drafts->figures($issue),
            );
            $this->assertSame('20.0000', $issue->headerCharges->shipping);
        } finally {
            $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_sales_adjustment', 'accounting_sales_shipping'])->delete();
        }
    }
}
