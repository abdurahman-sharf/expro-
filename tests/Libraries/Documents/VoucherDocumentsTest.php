<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Documents\VoucherDocument;
use App\Libraries\Documents\VoucherDocuments;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Roles\RoleGrants;
use App\Models\Expense;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The vouchers and the receipts as they are read from the documents: what they hold, that a document
 * that does not stand is not there, that a branch or a record the viewer may not see is not there, and
 * that reading one changes nothing.
 */
class VoucherDocumentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private VoucherDocuments $vouchers;
    private int $treasuryId;

    /** @var array<string, mixed> */
    private array $settings;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();
        $this->startBuying();

        $this->settings = config(OSPOS::class)->settings;
        $this->vouchers = new VoucherDocuments();
        $this->treasuryId = $this->treasury('Voucher drawer');
    }

    protected function tearDown(): void
    {
        config(OSPOS::class)->settings = $this->settings;
        $this->releaseOpenTransactions();
        $this->accountingDb()->table('employee_record_scopes')->truncate();
        RoleGrants::flush();
        $this->stopExpenses();
        $this->stopBuying();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();

        parent::tearDown();
    }

    private function ownRecordsOnly(int $personId): void
    {
        $this->accountingDb()->table('employee_record_scopes')->insert(['person_id' => $personId, 'record_scope' => 'own']);
        RoleGrants::flush();
    }

    private function speak(string $language): void
    {
        config(OSPOS::class)->settings['language_code'] = $language;
    }

    private function expenseOf(string $amount, array $changes = []): int
    {
        $data = [...$this->expenseData($amount, '0', 'Cash', null, '2026-10-20 10:00:00', $this->clerkId), 'description' => 'Office rent for October', ...$changes];

        $this->assertTrue(model(Expense::class)->save_value($data));

        return (int) $data['expense_id'];
    }

    private function revenueOf(string $amount, array $changes = []): int
    {
        return (new RevenueVouchers())->save([...['date' => '2026-10-20', 'amount' => $amount, 'payment_type' => 'Cash', 'treasury_id' => $this->treasuryId, 'description' => 'Stand rent'], ...$changes], null, $this->clerkId);
    }

    private function customerPaymentOf(string $amount): int
    {
        $sale = $this->invoiceOf($amount, null, [$this->payment('Cash', $amount)]);

        return (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $sale)->get()->getRow()->payment_id;
    }

    public function testExpense_HoldsTheNumberPayeePurposeAmountAndTheAmountInWords(): void
    {
        $this->speak('ar-EG');
        $numbering = new DocumentNumbering();
        $numbering->configure(DocumentType::Expense, ['enabled' => true, 'prefix' => 'EXP-', 'padding' => 5, 'next_number' => 1]);
        $payee = $this->insertSupplier('Gulf Supplies');
        $id    = $this->expenseOf('3000.00', ['supplier_id' => $payee]);

        $voucher = $this->vouchers->expense($id, $this->clerkId);

        $this->assertInstanceOf(VoucherDocument::class, $voucher);
        $this->assertSame(VoucherDocument::PAYMENT_VOUCHER, $voucher->kind);
        $this->assertSame(lang('Documents.payment_voucher'), $voucher->title);
        $this->assertSame('EXP-00001', $voucher->number);
        $this->assertSame('Gulf Supplies', $voucher->partyName);
        $this->assertSame('3000.00', $voucher->amount);
        $this->assertSame('3,000.00 SAR', $voucher->amountText);
        $this->assertSame('ثلاثة آلاف ريال سعودي', $voucher->amountWords);
        $this->assertContains([lang('Documents.purpose'), 'Office rent for October'], $voucher->rows);
        $this->assertContains([lang('Documents.method'), 'Cash'], $voucher->rows);
        $this->assertSame([lang('Documents.signature_receiver'), lang('Documents.signature_cashier')], $voucher->signatures);
    }

    public function testExpense_SpellsTheAmountInEnglishWhenTheLanguageIsNotArabic(): void
    {
        $this->speak('en');
        $id = $this->expenseOf('3000.50');

        $this->assertSame('Three thousand Saudi Riyals and fifty Halalas', $this->vouchers->expense($id, $this->clerkId)->amountWords);
    }

    public function testExpense_LeavesThePayeeBlankWhenTheExpenseHasNone(): void
    {
        $id = $this->expenseOf('10.00');

        $this->assertSame('', $this->vouchers->expense($id, $this->clerkId)->partyName);
    }

    public function testExpense_LeavesTheWordsOutForACurrencyItHasNoNamesFor(): void
    {
        $this->setConfigValue('accounting_base_currency', 'XYZ');
        $id = $this->expenseOf('10.00');

        $this->assertNull($this->vouchers->expense($id, $this->clerkId)->amountWords);
    }

    public function testExpense_IsNotThereWhenItWasDeletedOrIsAnAmountReceivedBack(): void
    {
        $deleted = $this->expenseOf('10.00');
        $this->accountingDb()->table('expenses')->where('expense_id', $deleted)->update(['deleted' => 1]);
        $refund = $this->expenseOf('10.00');
        $this->accountingDb()->table('expenses')->where('expense_id', $refund)->update(['amount' => '-10.00']);
        $zero = $this->expenseOf('0.00');

        $this->assertNull($this->vouchers->expense($deleted, $this->clerkId));
        $this->assertNull($this->vouchers->expense($refund, $this->clerkId));
        $this->assertNull($this->vouchers->expense($zero, $this->clerkId));
        $this->assertNull($this->vouchers->expense(999999, $this->clerkId));
    }

    public function testExpense_IsNotThereForAViewerLimitedToAnotherBranch(): void
    {
        $id     = $this->expenseOf('10.00');
        $other  = $this->createBranch();
        $viewer = $this->employeeWithGrants([], [$other]);

        $this->assertNotNull($this->vouchers->expense($id, $this->clerkId));
        $this->assertNull($this->vouchers->expense($id, $viewer));
    }

    public function testExpense_IsNotThereForAnEmployeeWhoSeesTheirOwnRecordsOnlyAndDidNotMakeIt(): void
    {
        $id    = $this->expenseOf('10.00');
        $other = $this->employeeWithGrants([]);
        $this->ownRecordsOnly($other);

        $this->assertNull($this->vouchers->expense($id, $other));
        $this->assertNotNull($this->vouchers->expense($id, $this->clerkId));
    }

    public function testRevenue_HoldsTheCategoryTreasuryAmountAndTheAmountInWords(): void
    {
        $this->speak('en');
        $id = $this->revenueOf('250.00');

        $voucher = $this->vouchers->revenue($id, $this->clerkId);

        $this->assertSame(VoucherDocument::RECEIPT_VOUCHER, $voucher->kind);
        $this->assertSame(lang('Documents.receipt_voucher'), $voucher->title);
        $this->assertSame('250.00', $voucher->amount);
        $this->assertSame('Two hundred fifty Saudi Riyals', $voucher->amountWords);
        $this->assertSame(1, count(array_filter($voucher->rows, static fn (array $row): bool => $row[0] === lang('Documents.treasury') && str_starts_with($row[1], 'Voucher drawer'))));
        $this->assertContains([lang('Documents.purpose'), 'Stand rent'], $voucher->rows);
        $this->assertSame([lang('Documents.signature_payer'), lang('Documents.signature_cashier')], $voucher->signatures);
    }

    public function testExpense_IsNotThereWhileTheExpenseIsDueAndIsThereOnceItIsPaid(): void
    {
        $due = $this->expenseOf('1000.00', ['payment_type' => lang('Sales.due')]);

        $this->assertNull($this->vouchers->expense($due, $this->clerkId));
        $this->accountingDb()->table('expenses')->where('expense_id', $due)->update(['payment_type' => lang('Sales.cash')]);
        $this->assertNotNull($this->vouchers->expense($due, $this->clerkId));
    }

    public function testRevenue_ShowsTheTaxItHoldsAndIsNotThereOnceDeleted(): void
    {
        $id = $this->revenueOf('115.00', ['tax_amount' => '15.00']);

        $this->assertContains([lang('Documents.tax_included'), '15.00'], $this->vouchers->revenue($id, $this->clerkId)->rows);

        (new RevenueVouchers())->delete([$id], $this->clerkId);

        $this->assertNull($this->vouchers->revenue($id, $this->clerkId));
    }

    public function testRevenue_IsNotThereForAViewerLimitedToAnotherBranch(): void
    {
        $id     = $this->revenueOf('10.00');
        $viewer = $this->employeeWithGrants([], [$this->createBranch()]);

        $this->assertNull($this->vouchers->revenue($id, $viewer));
    }

    public function testCustomerPayment_HoldsTheCustomerInvoiceMethodAndAmount(): void
    {
        $this->speak('en');
        $payment = $this->customerPaymentOf('115.00');

        $voucher = $this->vouchers->customerPayment($payment, $this->clerkId);

        $this->assertSame(VoucherDocument::CUSTOMER_RECEIPT, $voucher->kind);
        $this->assertSame(lang('Documents.customer_receipt'), $voucher->title);
        $this->assertSame('Omar Khaled', $voucher->partyName);
        $this->assertSame('115.00', $voucher->amount);
        $this->assertSame('One hundred fifteen Saudi Riyals', $voucher->amountWords);
        $this->assertSame(lang('Documents.invoice'), $voucher->rows[0][0]);
        $this->assertSame([lang('Documents.signature')], $voucher->signatures);
    }

    public function testCustomerPayment_TakesTheCashBackFromTheAmount(): void
    {
        $sale = $this->invoiceOf('100.00', null, [$this->payment('Cash', '120.00', '20.00')]);
        $id   = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $sale)->get()->getRow()->payment_id;

        $this->assertSame('100.00', $this->vouchers->customerPayment($id, $this->clerkId)->amount);
    }

    public function testCustomerPayment_IsNotThereForTheDuePartOfAnInvoiceThatNobodyPaid(): void
    {
        $sale = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '40.00'), $this->payment(lang('Sales.due'), '60.00')]);
        $rows = $this->accountingDb()->table('sales_payments')->where('sale_id', $sale)->orderBy('payment_id')->get()->getResult();

        $this->assertCount(2, $rows);
        $this->assertSame(lang('Sales.cash'), $rows[0]->payment_type);
        $this->assertSame(lang('Sales.due'), $rows[1]->payment_type);
        $this->assertSame('40.00', $this->vouchers->customerPayment((int) $rows[0]->payment_id, $this->clerkId)->amount);
        $this->assertNull($this->vouchers->customerPayment((int) $rows[1]->payment_id, $this->clerkId));
    }

    public function testCustomerPayment_IsNotThereForAPaymentThatIsNotCompletedOrOnlyRoundsCash(): void
    {
        $payment = $this->customerPaymentOf('115.00');
        $this->accountingDb()->table('sales_payments')->where('payment_id', $payment)->update(['payment_status' => 'draft']);
        $rounding = $this->customerPaymentOf('10.00');
        $this->accountingDb()->table('sales_payments')->where('payment_id', $rounding)->update(['cash_adjustment' => 1]);

        $this->assertNull($this->vouchers->customerPayment($payment, $this->clerkId));
        $this->assertNull($this->vouchers->customerPayment($rounding, $this->clerkId));
        $this->assertNull($this->vouchers->customerPayment(999999, $this->clerkId));
    }

    public function testCustomerPayment_IsNotThereForASaleThatIsNotCompleted(): void
    {
        $payment = $this->customerPaymentOf('115.00');
        $saleId  = (int) $this->accountingDb()->table('sales_payments')->where('payment_id', $payment)->get()->getRow()->sale_id;
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_status' => SUSPENDED]);

        $this->assertNull($this->vouchers->customerPayment($payment, $this->clerkId));
    }

    public function testCustomerPayment_IsNotThereForAViewerLimitedToAnotherBranch(): void
    {
        $payment = $this->customerPaymentOf('115.00');
        $viewer  = $this->employeeWithGrants([], [$this->createBranch()]);

        $this->assertNull($this->vouchers->customerPayment($payment, $viewer));
        $this->assertNotNull($this->vouchers->customerPayment($payment, $this->clerkId));
    }

    public function testCustomerPayment_IsNotThereForAnEmployeeWhoSeesTheirOwnRecordsOnlyAndDidNotCollectIt(): void
    {
        $payment = $this->customerPaymentOf('115.00');
        $other   = $this->employeeWithGrants([]);
        $this->ownRecordsOnly($other);

        $this->assertNull($this->vouchers->customerPayment($payment, $other));
    }

    public function testSupplierPayment_HoldsTheSupplierPurchaseInvoiceMethodAndAmount(): void
    {
        $this->speak('en');
        $receiving = $this->purchaseOf('500.00');
        $payment   = $this->payPurchase($receiving, '500.00');

        $voucher = $this->vouchers->supplierPayment($payment, $this->clerkId);

        $this->assertSame(VoucherDocument::SUPPLIER_RECEIPT, $voucher->kind);
        $this->assertSame(lang('Documents.supplier_receipt'), $voucher->title);
        $this->assertSame('Gulf Supplies', $voucher->partyName);
        $this->assertSame('500.00', $voucher->amount);
        $this->assertSame('Five hundred Saudi Riyals', $voucher->amountWords);
        $this->assertSame(lang('Documents.purchase_invoice'), $voucher->rows[0][0]);
    }

    public function testSupplierPayment_IsNotThereForAPaymentThatIsNotCompleted(): void
    {
        $receiving = $this->purchaseOf('500.00');
        $payment   = $this->payPurchase($receiving, '100.00', null, 'draft');

        $this->assertNull($this->vouchers->supplierPayment($payment, $this->clerkId));
        $this->assertNull($this->vouchers->supplierPayment(999999, $this->clerkId));
    }

    public function testSupplierPayment_IsNotThereForAViewerLimitedToAnotherBranch(): void
    {
        $receiving = $this->purchaseOf('500.00');
        $payment   = $this->payPurchase($receiving, '500.00');
        $viewer    = $this->employeeWithGrants([], [$this->createBranch()]);

        $this->assertNull($this->vouchers->supplierPayment($payment, $viewer));
    }

    public function testReading_ChangesNoRowOfTheBooksNorOfTheDocuments(): void
    {
        $expense   = $this->expenseOf('3000.00');
        $revenue   = $this->revenueOf('250.00');
        $customer  = $this->customerPaymentOf('115.00');
        $supplier  = $this->payPurchase($this->purchaseOf('500.00'), '500.00');
        $before    = $this->databaseFingerprint();

        $this->vouchers->expense($expense, $this->clerkId);
        $this->vouchers->revenue($revenue, $this->clerkId);
        $this->vouchers->customerPayment($customer, $this->clerkId);
        $this->vouchers->supplierPayment($supplier, $this->clerkId);

        $this->assertDatabaseUnchanged($before, 'Reading a voucher changed a row.');
    }
}
