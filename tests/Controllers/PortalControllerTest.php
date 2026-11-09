<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalLoginGuard;
use App\Libraries\Portal\PortalSession;
use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PortalFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The portal of the customers: what each page shows, that every page is bound to the customer of the session
 * whatever the request says, that a number that is not a document of that customer is a page that does not
 * exist, and the sign-in, the sign-out and the change of the password with the token that every post needs.
 *
 * @internal
 */
final class PortalControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PortalFixtureTrait;

    private const TOKEN        = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const SECRET_COST  = '41.3377';
    private const OTHER_COST   = '77.7713';
    private const NEW_PASSWORD = 'Another-Pass-42';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /**
     * @var array{account_id: int, username: string, customer_id: int}
     */
    private array $account;

    /**
     * @var array{account_id: int, username: string, customer_id: int}
     */
    private array $otherAccount;

    private int $other;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->freshSecurity();
        $this->startSelling();
        $this->switchPortal(true);

        $this->account      = $this->portalAccountOf($this->customerId, 'omar');
        $this->other        = $this->anotherCustomer();
        $this->otherAccount = $this->portalAccountOf($this->other, 'hind');
    }

    protected function tearDown(): void
    {
        $this->cleanPortal();
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $more
     */
    private function asCustomer(?array $account = null, array $more = []): void
    {
        $this->withSession($this->portalSessionOf($account ?? $this->account) + [$this->tokenName() => self::TOKEN] + $more);
    }

    private function tokenName(): string
    {
        return config('Security')->tokenName;
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array<string, mixed>
     */
    private function withToken(array $form = []): array
    {
        return [$this->tokenName() => self::TOKEN] + $form;
    }

    /**
     * An invoice of the customer that costs the shop SECRET_COST a unit and was paid in part.
     */
    private function costlyInvoice(?int $customerId = null, string $cost = self::SECRET_COST): int
    {
        $item = $this->item($cost);

        return $this->save([$this->line($item, 0, '2', '100.00', $cost)], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_INVOICE, $customerId ?? -1);
    }

    private function body(string $path): string
    {
        return $this->get($path)->getBody();
    }

    public function testGetStatementShowsTheBalanceTheOpenInvoicesWithTheirDueDatesAndTheAging(): void
    {
        $invoice = $this->invoiceDated('300.00', $this->daysAgo(45), 30);
        $this->invoiceDated('120.00', $this->daysAgo(2), 30);
        $this->asCustomer();

        $html = $this->body('/portal/statement');
        $due  = to_date(strtotime($this->daysAgo(45) . ' +30 days'));

        $this->assertStringContainsString('id="portal_open_invoices"', $html);
        $this->assertStringContainsString('POS ' . $invoice, $html);
        $this->assertStringContainsString($due, $html);
        $this->assertStringContainsString('420.00', $html);
        $this->assertStringContainsString('id="portal_aging"', $html);
        $this->assertStringContainsString('300.00', $html);
        $this->assertStringContainsString('120.00', $html);
        $this->assertSame($this->position()->open(), '420.0000');
    }

    public function testGetStatementTiesToTheLedgerOfTheCustomerForBalanceOpenAndCredit(): void
    {
        $first = $this->invoiceOf('100.00');
        $this->creditNoteOf($first, '30.00');
        $this->invoiceOf('40.00');
        $this->asCustomer();

        $position = $this->position();
        $html     = $this->body('/portal/statement');

        $this->assertStringContainsString('<bdi dir="ltr">110.00 SAR</bdi>', $html);
        $this->assertSame('110.0000', $position->accountBalance);
        $this->assertStringContainsString(lang('Portal.balance'), $html);
    }

    public function testGetStatementListsCreditTheCustomerHolds(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $this->creditNoteOf($invoice, '100.00');
        $this->invoiceOf('10.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);
        $this->asCustomer();

        $html = $this->body('/portal/statement');

        $this->assertStringContainsString('id="portal_credits"', $html);
    }

    public function testGetStatementSaysItIsNotAvailableWhileAccountingIsOff(): void
    {
        $this->invoiceOf('100.00');
        $this->setConfigValue('accounting_enabled', '0');
        $this->asCustomer();

        $html = $this->body('/portal/statement');

        $this->assertStringContainsString('id="portal_statement_unavailable"', $html);
        $this->assertStringNotContainsString('id="portal_open_invoices"', $html);
    }

    public function testGetStatementIgnoresACustomerNumberInTheRequest(): void
    {
        $this->invoiceOf('100.00');
        $this->invoiceOf('555.00', $this->other);
        $this->asCustomer();

        $html = $this->body('/portal/statement?customer_id=' . $this->other . '&id=' . $this->other . '&customer=' . $this->other);

        $this->assertStringContainsString('100.00', $html);
        $this->assertStringNotContainsString('555.00', $html);
    }

    public function testGetInvoicesListsTheInvoicesOfTheCustomerOnly(): void
    {
        $mine = $this->invoiceOf('100.00');
        $this->invoiceOf('555.00', $this->other);
        $this->asCustomer();

        $html = $this->body('/portal/invoices');

        $this->assertStringContainsString('POS ' . $mine, $html);
        $this->assertStringContainsString('100.00', $html);
        $this->assertStringNotContainsString('555.00', $html);
        $this->assertStringNotContainsString('Hind', $html);
    }

    public function testGetInvoicesLeavesOutWhatIsNotAnIssuedInvoiceOfTheCustomer(): void
    {
        $draft = $this->draftInvoice(null, $this->customerId);
        $quote = $this->quote(null, null, $this->customerId);
        $this->asCustomer();

        $html = $this->body('/portal/invoices');

        $this->assertStringNotContainsString('POS ' . $draft, $html);
        $this->assertStringNotContainsString('POS ' . $quote, $html);
        $this->assertStringContainsString(lang('Portal.no_rows'), $html);
    }

    public function testGetCreditNotesListsTheCreditNotesAndRefundInvoicesOfTheCustomerOnly(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '30.00');
        $refund  = $this->refundInvoiceOf($invoice, '20.00');
        $foreign = $this->invoiceOf('90.00', $this->other);
        $this->creditNoteOf($foreign, '55.00', $this->other);
        $this->asCustomer();

        $html = $this->body('/portal/credit_notes');

        $this->assertStringContainsString('portal/credit_note/' . $note, $html);
        $this->assertStringContainsString('portal/credit_note/' . $refund, $html);
        $this->assertStringContainsString('30.00', $html);
        $this->assertStringContainsString('20.00', $html);
        $this->assertStringNotContainsString('55.00', $html);
        $this->assertStringContainsString(lang(ReturnDocument::RefundInvoice->languageKey()), $html);
    }

    public function testGetPaymentsListsTheMoneyTheCustomerPaidAndNotWhatAnotherCustomerPaid(): void
    {
        $mine = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);
        $this->invoiceOf('300.00', $this->other, [$this->payment(lang('Sales.cash'), '222.00')]);
        $this->asCustomer();

        $html = $this->body('/portal/payments');

        $this->assertStringContainsString('POS ' . $mine, $html);
        $this->assertStringContainsString('60.00', $html);
        $this->assertStringNotContainsString('222.00', $html);
    }

    public function testGetPaymentsLeavesOutWhatWasLeftDueAndTheRoundingOfCash(): void
    {
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.due'), '100.00')]);
        $this->asCustomer();

        $this->assertStringContainsString(lang('Portal.no_rows'), $this->body('/portal/payments'));
    }

    #[DataProvider('provideGetPage_TreatsABadPageNumberAsTheFirstPage')]
    public function testGetPageTreatsABadPageNumberAsTheFirstPage(string $page): void
    {
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '10.00')]);
        $this->asCustomer();

        foreach (['0', '-4', 'abc', '1'] as $number) {
            $this->get('/portal/' . $page . '?page=' . $number)->assertStatus(200);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideGetPage_TreatsABadPageNumberAsTheFirstPage(): iterable
    {
        return ['statement' => ['statement'], 'invoices' => ['invoices'], 'credit notes' => ['credit_notes'], 'payments' => ['payments']];
    }

    public function testGetInvoicesPagesTwentyFiveToAPageAndSaysWhichPageItIs(): void
    {
        for ($index = 0; $index < 26; $index++) {
            $this->invoiceOf('1.00');
        }

        $this->asCustomer();

        $first  = $this->body('/portal/invoices');
        $second = $this->body('/portal/invoices?page=2');

        $this->assertSame(25, substr_count($first, 'portal/invoice/'));
        $this->assertSame(1, substr_count($second, 'portal/invoice/'));
        $this->assertStringContainsString(lang('Portal.page_of', [2, 2]), $second);
    }

    public function testGetInvoiceShowsTheLinesTheTaxTheTotalAndWhatWasPaid(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '800.00')]);
        $this->asCustomer();

        $html = $this->body('/portal/invoice/' . $invoice);

        $this->assertStringContainsString('id="document"', $html);
        $this->assertStringContainsString('POS ' . $invoice, $html);
        $this->assertStringContainsString('816.50', $html);
        $this->assertStringContainsString('106.50', $html);
        $this->assertStringContainsString('710.00', $html);
        $this->assertStringContainsString('800.00', $html);
        $this->assertStringContainsString('Test Item', $html);
        $this->assertStringContainsString('Omar Khaled', $html);
        $this->assertStringContainsString('portal/invoice/' . $invoice . '/pdf', $html);
    }

    public function testGetInvoicePdfReturnsAPdfFile(): void
    {
        $invoice = $this->costlyInvoice();
        $this->asCustomer();

        $response = $this->get('/portal/invoice/' . $invoice . '/pdf');

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->response()->getBody());
        $this->assertStringContainsString('inline', $response->response()->getHeaderLine('Content-Disposition'));
    }

    public function testGetCreditNoteShowsTheNoteWithTheInvoiceItCameFrom(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '30.00');
        $this->asCustomer();

        $html = $this->body('/portal/credit_note/' . $note);

        $this->assertStringContainsString(lang('Sales.credit_note'), $html);
        $this->assertStringContainsString('POS ' . $invoice, $html);
        $this->assertStringContainsString('30.00', $html);
        $this->assertStringContainsString('portal/credit_note/' . $note . '/pdf', $html);
    }

    public function testGetCreditNotePdfReturnsAPdfFile(): void
    {
        $note = $this->creditNoteOf($this->invoiceOf('100.00'), '30.00');
        $this->asCustomer();

        $this->assertStringStartsWith('%PDF', $this->get('/portal/credit_note/' . $note . '/pdf')->response()->getBody());
    }

    public function testGetPaymentShowsTheReceiptWithoutTheTreasuryAndTheEmployeeWhoTookIt(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);
        $payment = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice)->get()->getRow()->payment_id;
        $this->accountingDb()->table('treasuries')->update(['name' => 'Secret Treasury']);
        $this->asCustomer();

        $html = $this->body('/portal/payment/' . $payment);

        $this->assertStringContainsString('60.00', $html);
        $this->assertStringContainsString('POS ' . $invoice, $html);
        $this->assertStringContainsString(lang('Documents.customer_receipt'), $html);
        $this->assertStringNotContainsString('Secret Treasury', $html);
        $this->assertStringNotContainsString(lang('Documents.treasury'), $html);
        $this->assertStringNotContainsString(lang('Documents.received_by'), $html);
        $this->assertStringContainsString('portal/payment/' . $payment . '/pdf', $html);
    }

    public function testGetPaymentPdfReturnsAPdfFile(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);
        $payment = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice)->get()->getRow()->payment_id;
        $this->asCustomer();

        $this->assertStringStartsWith('%PDF', $this->get('/portal/payment/' . $payment . '/pdf')->response()->getBody());
    }

    /**
     * Makes, for another customer, one document of each kind and returns the number a route of this kind takes.
     */
    private function foreignNumber(string $kind): int
    {
        $invoice = $this->invoiceOf('90.00', $this->other, [$this->payment(lang('Sales.cash'), '40.00')]);

        return match ($kind) {
            'invoice'     => $invoice,
            'credit_note' => $this->creditNoteOf($invoice, '10.00', $this->other),
            default       => (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice)->get()->getRow()->payment_id,
        };
    }

    #[DataProvider('documentRoutesProvider')]
    public function testGetDocumentReturnsNotFoundForTheNumberOfAnotherCustomersDocument(string $kind, string $suffix): void
    {
        $number = $this->foreignNumber($kind);
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/' . $kind . '/' . $number . $suffix);
    }

    #[DataProvider('documentRoutesProvider')]
    public function testGetDocumentReturnsNotFoundForANumberThatIsNoDocumentAtAll(string $kind, string $suffix): void
    {
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/' . $kind . '/987654' . $suffix);
    }

    #[DataProvider('documentRoutesProvider')]
    public function testGetDocumentOpensTheSameNumberForItsOwnerAndNotForTheCustomerWhoGuessedIt(string $kind, string $suffix): void
    {
        $number = $this->foreignNumber($kind);

        $this->asCustomer($this->otherAccount);
        $this->get('/portal/' . $kind . '/' . $number . $suffix)->assertStatus(200);

        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/' . $kind . '/' . $number . $suffix);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documentRoutesProvider(): iterable
    {
        return [
            'an invoice'         => ['invoice', ''],
            'an invoice file'    => ['invoice', '/pdf'],
            'a credit note'      => ['credit_note', ''],
            'a credit note file' => ['credit_note', '/pdf'],
            'a payment'          => ['payment', ''],
            'a payment file'     => ['payment', '/pdf'],
        ];
    }

    public function testGetInvoiceReturnsNotFoundForTheNumberOfADocumentOfAnotherKindOfTheSameCustomer(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '30.00');
        $this->asCustomer();

        $this->get('/portal/credit_note/' . $note)->assertStatus(200);

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/invoice/' . $note);
    }

    public function testGetCreditNoteReturnsNotFoundForTheNumberOfAnInvoice(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/credit_note/' . $invoice);
    }

    public function testGetInvoiceReturnsNotFoundForADraftAndForAQuoteOfTheCustomer(): void
    {
        $draft = $this->draftInvoice(null, $this->customerId);
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/invoice/' . $draft);
    }

    public function testGetInvoiceReturnsNotFoundForAQuoteOfTheCustomer(): void
    {
        $quote = $this->quote(null, null, $this->customerId);
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/invoice/' . $quote);
    }

    public function testGetInvoiceReturnsNotFoundForTheInvoiceOfACustomerWhoseAccountIsThisOneAfterTheNumberWasRead(): void
    {
        $foreign = $this->invoiceOf('90.00', $this->other);
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/invoice/' . $foreign . '?customer_id=' . $this->other);
    }

    public function testGetPagesShowNoCostNoMarginNoOtherPartyAndNoJournalData(): void
    {
        $invoice = $this->costlyInvoice();
        $this->costlyInvoice($this->other, self::OTHER_COST);
        $this->creditNoteOf($invoice, '25.00');
        $this->accountingDb()->table('treasuries')->update(['name' => 'Secret Treasury']);
        $this->accountingDb()->table('people')->where('person_id', $this->employeeId)->update(['first_name' => 'Secretive', 'last_name' => 'Cashier']);
        $payment = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice)->get()->getRow()->payment_id;
        $note    = (int) $this->accountingDb()->table('sales')->where('return_of_sale_id', $invoice)->get()->getRow()->sale_id;
        $this->asCustomer();

        $pages = [
            '/portal/statement', '/portal/invoices', '/portal/credit_notes', '/portal/payments', '/portal/password',
            '/portal/invoice/' . $invoice, '/portal/credit_note/' . $note, '/portal/payment/' . $payment,
        ];

        foreach ($pages as $page) {
            $html = $this->body($page);

            foreach ([self::SECRET_COST, self::OTHER_COST, 'item_cost_price', 'cost_price', 'margin', 'profit', 'Secret Treasury', 'Secretive', 'Cashier', 'Hind', 'Saad', 'hind', 'journal', 'Journal', 'accounting_journals', 'cost of goods', 'Cost of'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html, "{$page} shows {$forbidden}");
            }
        }
    }

    public function testGetPagesEscapeWhatTheShopTypedForTheCustomerTheItemsAndThePaymentMethods(): void
    {
        $invoice = $this->costlyInvoice();
        $this->accountingDb()->table('people')->where('person_id', $this->customerId)->update(['first_name' => '<script>alert(1)</script>', 'last_name' => '"><img src=x>']);
        $this->accountingDb()->table('items')->update(['name' => '<b onmouseover=alert(2)>Item</b>']);
        $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice)->update(['payment_type' => '<i>Cash</i>']);
        $payment = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice)->get()->getRow()->payment_id;
        $this->asCustomer();

        foreach (['/portal/invoice/' . $invoice, '/portal/payments', '/portal/payment/' . $payment] as $page) {
            $html = $this->body($page);

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $page);
            $this->assertStringNotContainsString('<b onmouseover', $html, $page);
            $this->assertStringNotContainsString('<i>Cash</i>', $html, $page);
            $this->assertStringNotContainsString('"><img src=x>', $html, $page);
        }

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->body('/portal/invoice/' . $invoice));
    }

    public function testGetLoginShowsTheFormWithAFieldForTheToken(): void
    {
        $this->withSession([$this->tokenName() => self::TOKEN]);

        $html = $this->body('/portal/login');

        $this->assertStringContainsString('id="portal_login_form"', $html);
        $this->assertStringContainsString('name="' . $this->tokenName() . '" value="' . self::TOKEN . '"', $html);
        $this->assertStringContainsString('type="password"', $html);
    }

    public function testGetLoginSendsASignedInCustomerToTheStatement(): void
    {
        $this->asCustomer();

        $this->assertStringContainsString('portal/statement', (string) $this->get('/portal/login')->getRedirectUrl());
    }

    public function testPostLoginOpensASessionOfTheCustomerRenewsTheIdentifierAndClearsWhatWasInTheSession(): void
    {
        $renewed = 0;
        Events::on(PortalSession::REGENERATED, static function () use (&$renewed): void {
            $renewed++;
        });
        $this->withSession([$this->tokenName() => self::TOKEN, 'planted' => 'by an attacker']);

        $response = $this->post('/portal/login', $this->withToken(['username' => 'OMAR', 'password' => self::PORTAL_PASSWORD]));

        $this->assertStringContainsString('portal/statement', (string) $response->getRedirectUrl());
        $this->assertSame($this->customerId, $_SESSION[PortalSession::KEY_CUSTOMER]);
        $this->assertSame($this->account['account_id'], $_SESSION[PortalSession::KEY_ACCOUNT]);
        $this->assertArrayNotHasKey('planted', $_SESSION);
        $this->assertArrayNotHasKey('person_id', $_SESSION);
        $this->assertGreaterThanOrEqual(1, $renewed);
        $this->assertCount(1, $this->portalAudit('portal_login_succeeded'));
    }

    public function testPostLoginAnswersAWrongPasswordAndAnUnknownNameWithTheSameRefusalAndOpensNothing(): void
    {
        $this->withSession([$this->tokenName() => self::TOKEN]);

        $wrong   = $this->post('/portal/login', $this->withToken(['username' => 'omar', 'password' => 'not the password']));
        $unknown = $this->post('/portal/login', $this->withToken(['username' => 'nobody', 'password' => 'not the password']));

        $this->assertSame(401, $wrong->response()->getStatusCode());
        $this->assertSame(401, $unknown->response()->getStatusCode());
        $this->assertStringContainsString(lang('Portal.invalid_login'), $wrong->getBody());
        $this->assertSame($this->visibleText($wrong->getBody()), $this->visibleText($unknown->getBody()));
        $this->assertArrayNotHasKey(PortalSession::KEY_CUSTOMER, $_SESSION);
    }

    private function visibleText(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    public function testPostLoginLocksTheUserNameAfterRepeatedFailuresAndRefusesTheRightPasswordWithTooManyAttempts(): void
    {
        $this->withSession([$this->tokenName() => self::TOKEN]);

        for ($index = 0; $index < PortalLoginGuard::USER_THRESHOLD; $index++) {
            $this->assertSame(401, $this->post('/portal/login', $this->withToken(['username' => 'omar', 'password' => 'wrong ' . $index]))->response()->getStatusCode());
        }

        $locked = $this->post('/portal/login', $this->withToken(['username' => 'omar', 'password' => self::PORTAL_PASSWORD]));

        $this->assertSame(429, $locked->response()->getStatusCode());
        $this->assertStringContainsString(lang('Portal.too_many_attempts'), $locked->getBody());
        $this->assertArrayNotHasKey(PortalSession::KEY_CUSTOMER, $_SESSION);
    }

    public function testPostLoginDoesNotSignInAnAccountThatWasDisabled(): void
    {
        $this->withSession([$this->tokenName() => self::TOKEN]);
        (new PortalAccounts())->setActive($this->account['account_id'], false, $this->portalManager());

        $this->assertSame(401, $this->post('/portal/login', $this->withToken(['username' => 'omar', 'password' => self::PORTAL_PASSWORD]))->response()->getStatusCode());
    }

    public function testPostLoginTakesTheNameAndPasswordFromTheFormOnlyAndRefusesAnArrayInTheirPlace(): void
    {
        $this->withSession([$this->tokenName() => self::TOKEN]);

        $response = $this->post('/portal/login', $this->withToken(['username' => ['omar'], 'password' => [self::PORTAL_PASSWORD]]));

        $this->assertSame(401, $response->response()->getStatusCode());
    }

    public function testPostLoginRefusesAPostWithoutTheTokenAndWithAWrongToken(): void
    {
        $this->withSession([$this->tokenName() => self::TOKEN]);

        try {
            $this->post('/portal/login', ['username' => 'omar', 'password' => self::PORTAL_PASSWORD]);
            $this->fail('A sign-in without a token was accepted.');
        } catch (SecurityException) {
            $this->addToAssertionCount(1);
        }

        $this->assertArrayNotHasKey(PortalSession::KEY_CUSTOMER, $_SESSION);

        $this->expectException(SecurityException::class);

        $this->post('/portal/login', [$this->tokenName() => 'f' . substr(self::TOKEN, 1), 'username' => 'omar', 'password' => self::PORTAL_PASSWORD]);
    }

    public function testPostLogoutEndsTheSessionAndTheNextPageSendsTheCustomerToTheSignIn(): void
    {
        $this->asCustomer();

        $response = $this->post('/portal/logout', $this->withToken());

        $this->assertStringContainsString('portal/login', (string) $response->getRedirectUrl());
        $this->assertArrayNotHasKey(PortalSession::KEY_CUSTOMER, $_SESSION);
        $this->assertArrayNotHasKey(PortalSession::KEY_ACCOUNT, $_SESSION);

        $this->withSession($_SESSION);

        $this->assertStringContainsString('portal/login', (string) $this->get('/portal/statement')->getRedirectUrl());
    }

    public function testPostLogoutRefusesAPostWithoutTheToken(): void
    {
        $this->asCustomer();

        $this->expectException(SecurityException::class);

        $this->post('/portal/logout');
    }

    public function testPostLogoutIsNotDoneByAGetRequestToTheSameMethod(): void
    {
        $this->asCustomer();

        try {
            $this->get('/portal/postLogout');
            $this->fail('A get request signed the customer out.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($this->customerId, $_SESSION[PortalSession::KEY_CUSTOMER]);
    }

    public function testPostLoginIsNotDoneByAGetRequestToTheSameMethod(): void
    {
        $this->withSession([$this->tokenName() => self::TOKEN]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/postLogin?username=omar&password=' . self::PORTAL_PASSWORD);
    }

    public function testGetPasswordShowsTheFormAndSaysItIsRequiredWhileThePasswordIsTemporary(): void
    {
        $this->accountingDb()->table('portal_accounts')->where('account_id', $this->account['account_id'])->update(['must_change' => 1]);
        $this->asCustomer();

        $html = $this->body('/portal/password');

        $this->assertStringContainsString('id="portal_password_form"', $html);
        $this->assertStringContainsString('id="portal_forced"', $html);
    }

    public function testPostPasswordChangesThePasswordRenewsTheSessionAndEndsTheOldVersion(): void
    {
        $renewed = 0;
        Events::on(PortalSession::REGENERATED, static function () use (&$renewed): void {
            $renewed++;
        });
        $this->accountingDb()->table('portal_accounts')->where('account_id', $this->account['account_id'])->update(['must_change' => 1]);
        $this->asCustomer();
        $old = (int) (new PortalAccounts())->find($this->account['account_id'])->credential_version;

        $response = $this->post('/portal/password', $this->withToken(['current_password' => self::PORTAL_PASSWORD, 'new_password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD]));

        $row = (new PortalAccounts())->find($this->account['account_id']);
        $this->assertStringContainsString('portal/statement', (string) $response->getRedirectUrl());
        $this->assertSame('0', (string) $row->must_change);
        $this->assertSame($old + 1, $_SESSION[PortalSession::KEY_VERSION]);
        $this->assertSame((int) $row->credential_version, $_SESSION[PortalSession::KEY_VERSION]);
        $this->assertGreaterThanOrEqual(1, $renewed);
        $this->assertNotNull((new PortalAccounts())->check('omar', self::NEW_PASSWORD)[0]);
    }

    public function testPostPasswordRejectsADifferentConfirmationAWrongCurrentPasswordAndAWeakNewOneAndChangesNothing(): void
    {
        $this->asCustomer();
        $hash = (new PortalAccounts())->find($this->account['account_id'])->password_hash;

        $mismatch = $this->post('/portal/password', $this->withToken(['current_password' => self::PORTAL_PASSWORD, 'new_password' => self::NEW_PASSWORD, 'confirm_password' => 'something else']));
        $wrong    = $this->post('/portal/password', $this->withToken(['current_password' => 'not it', 'new_password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD]));
        $weak     = $this->post('/portal/password', $this->withToken(['current_password' => self::PORTAL_PASSWORD, 'new_password' => 'short', 'confirm_password' => 'short']));

        $this->assertSame([422, 422, 422], [$mismatch->response()->getStatusCode(), $wrong->response()->getStatusCode(), $weak->response()->getStatusCode()]);
        $this->assertStringContainsString(lang('Portal.password_mismatch'), $mismatch->getBody());
        $this->assertSame($hash, (new PortalAccounts())->find($this->account['account_id'])->password_hash);
    }

    public function testPostPasswordRefusesAPostWithoutTheToken(): void
    {
        $this->asCustomer();

        $this->expectException(SecurityException::class);

        $this->post('/portal/password', ['current_password' => self::PORTAL_PASSWORD, 'new_password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD]);
    }

    public function testPostPasswordChangesTheAccountOfTheSessionAndNotOneNamedInTheRequest(): void
    {
        $this->asCustomer();
        $hash = (new PortalAccounts())->find($this->otherAccount['account_id'])->password_hash;

        $this->post('/portal/password', $this->withToken(['account_id' => $this->otherAccount['account_id'], 'username' => 'hind', 'current_password' => self::PORTAL_PASSWORD, 'new_password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD]));

        $this->assertSame($hash, (new PortalAccounts())->find($this->otherAccount['account_id'])->password_hash);
        $this->assertNotNull((new PortalAccounts())->check('omar', self::NEW_PASSWORD)[0]);
    }

    public function testPostPasswordIsNotDoneByAGetRequestToTheSameMethod(): void
    {
        $this->asCustomer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/portal/postPassword?current_password=' . self::PORTAL_PASSWORD . '&new_password=' . self::NEW_PASSWORD . '&confirm_password=' . self::NEW_PASSWORD);
    }

    public function testGetIndexSendsASignedInCustomerToTheStatement(): void
    {
        $this->asCustomer();

        $this->assertStringContainsString('portal/statement', (string) $this->get('/portal')->getRedirectUrl());
    }

    public function testGetPagesChangeNoJournalAndNoBalance(): void
    {
        $invoice = $this->costlyInvoice();
        $this->asCustomer();
        $db       = $this->accountingDb();
        $tables   = array_map(static fn (string $table): string => $db->prefixTable($table), ['accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'sales', 'sales_items', 'sales_payments', 'customer_credit_applications', 'inventory']);
        $checksum = static fn (): array => array_column($db->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`')->getResultArray(), 'Checksum', 'Table');
        $before   = $checksum();
        $payment  = (int) $db->table('sales_payments')->where('sale_id', $invoice)->get()->getRow()->payment_id;

        foreach (['/portal/statement', '/portal/invoices', '/portal/credit_notes', '/portal/payments', '/portal/invoice/' . $invoice, '/portal/invoice/' . $invoice . '/pdf', '/portal/payment/' . $payment, '/portal/payment/' . $payment . '/pdf'] as $page) {
            $this->get($page)->assertStatus(200);
        }

        $this->assertSame($before, $checksum());
    }

    public function testGetInvoiceAgreesWithTheInvoiceTheLedgerCarriesForTheCustomer(): void
    {
        $invoice = $this->invoiceOf('250.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->asCustomer();

        $figures = $this->position()->invoice($invoice);
        $html    = $this->body('/portal/invoice/' . $invoice);

        $this->assertSame('250.0000', $figures->total);
        $this->assertSame('150.0000', $figures->open());
        $this->assertStringContainsString('250.00 SAR', $html);
        $this->assertStringContainsString('150.00 SAR', $html);
    }

    public function testGetPagesReadTheCustomerFromTheSessionOfThePortalAndNotFromAnEmployeeKeyOfIt(): void
    {
        $this->asCustomer(null, ['employee_id' => $this->employeeId, 'customer_id' => $this->other]);
        $this->invoiceOf('555.00', $this->other);

        $this->assertStringNotContainsString('555.00', $this->body('/portal/invoices'));
    }
}
