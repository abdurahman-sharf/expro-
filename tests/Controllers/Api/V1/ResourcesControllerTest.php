<?php

namespace Tests\Controllers\Api\V1;

use App\Libraries\Integrations\ApiKeys;
use App\Libraries\Integrations\IntegrationOptions;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\IntegrationFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the three reads of the API give: who the key is, what the customers owe and the sales documents
 * of a stretch of days, each by the scope of the key and the branch it is limited to, with paging, with
 * the refusals each bad parameter earns, and with nothing of the books' inside.
 */
class ResourcesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;
    use IntegrationFixtureTrait;
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

        $this->wipeIntegration();
        $this->startSelling();
        $this->switchOn(IntegrationOptions::API);
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param list<string> $scopes
     */
    private function secret(array $scopes = ['read:customers', 'read:documents'], ?int $branchId = null): string
    {
        return (new ApiKeys())->create(['name' => 'Reader', 'scopes' => $scopes, 'branch_id' => $branchId === null ? '' : (string) $branchId, 'rate_limit' => '6000'], $this->keyManager())['secret'];
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function read(string $path, string $secret, array $query = []): array
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $secret])->get('api/v1/' . $path . ($query === [] ? '' : '?' . http_build_query($query)));

        return [$response->response()->getStatusCode(), json_decode($response->getJSON(), true)];
    }

    public function testPing_NamesTheKeyItsScopesItsBranchAndItsRate(): void
    {
        $branch = $this->createBranch('B2');

        [$status, $body] = $this->read('ping', $this->secret(['read:documents'], $branch));

        $this->assertSame(200, $status);
        $this->assertSame(['read:documents'], $body['data']['scopes']);
        $this->assertSame($branch, $body['data']['branch_id']);
        $this->assertSame('ok', $body['data']['status']);
    }

    public function testCustomerBalances_ListsWhatEachCustomerOwesByNameWithPaging(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $this->invoiceOf('100.00');
        $this->invoiceOf('40.50', $other);
        $this->invoiceOf('10.00', $other);

        [$status, $body] = $this->read('customers/balances', $this->secret(['read:customers']));

        $this->assertSame(200, $status);
        $this->assertSame([
            ['customer_id' => $other, 'name' => 'Hind Saad', 'balance' => '50.5000'],
            ['customer_id' => $this->customerId, 'name' => 'Omar Khaled', 'balance' => '100.0000'],
        ], $body['data']);
        $this->assertSame(['total' => 2, 'limit' => 50, 'offset' => 0], $body['meta']);

        [, $page] = $this->read('customers/balances', $this->secret(['read:customers']), ['limit' => '1', 'offset' => '1']);

        $this->assertSame([$this->customerId], array_column($page['data'], 'customer_id'));
        $this->assertSame(['total' => 2, 'limit' => 1, 'offset' => 1], $page['meta']);

        [, $past] = $this->read('customers/balances', $this->secret(['read:customers']), ['offset' => '5']);

        $this->assertSame([], $past['data']);
    }

    public function testCustomerBalances_LeavesOutACustomerWhoOwesNothingAndShowsCreditAsNegative(): void
    {
        $paid   = $this->anotherCustomer('Paid', 'Up');
        $credit = $this->anotherCustomer('Has', 'Credit');
        $debtor = $this->anotherCustomer('Owes', 'Money');

        $this->invoiceOf('30.00', $paid, [$this->payment(lang('Sales.cash'), '30.00')]);
        $this->invoiceOf('30.00', $credit, [$this->payment(lang('Sales.cash'), '50.00')]);
        $this->invoiceOf('12.00', $debtor);

        [, $body] = $this->read('customers/balances', $this->secret(['read:customers']));

        $balances = array_column($body['data'], 'balance', 'customer_id');

        $this->assertArrayNotHasKey($paid, $balances);
        $this->assertSame('12.0000', $balances[$debtor]);
        $this->assertSame('-20.0000', $balances[$credit]);
    }

    public function testCustomerBalances_NeedsTheScopeAndRefusesAKeyLimitedToABranch(): void
    {
        $branch = $this->createBranch('B2');

        $this->invoiceOf('100.00');

        [$status, $body] = $this->read('customers/balances', $this->secret(['read:documents']));

        $this->assertSame(403, $status);
        $this->assertSame('insufficient_scope', $body['error']);

        [$status, $body] = $this->read('customers/balances', $this->secret(['read:customers'], $branch));

        $this->assertSame(403, $status);
        $this->assertSame('branch_limited_key', $body['error']);
    }

    public function testCustomerBalances_AnswersConflictWhileAccountingIsOff(): void
    {
        $secret = $this->secret();

        $this->setConfigValue('accounting_enabled', '0');

        [$status, $body] = $this->read('customers/balances', $secret);

        $this->assertSame(409, $status);
        $this->assertSame('accounting_disabled', $body['error']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function badPaging(): array
    {
        return [
            'no limit'        => [['limit' => '0'], 'limit'],
            'over the limit'  => [['limit' => '201'], 'limit'],
            'a word'          => [['limit' => 'many'], 'limit'],
            'a negative limit' => [['limit' => '-1'], 'limit'],
            'a fraction'      => [['offset' => '1.5'], 'offset'],
            'a negative offset' => [['offset' => '-1'], 'offset'],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('badPaging')]
    public function testCustomerBalances_RefusesAPageThatIsNotAPage(array $query, string $parameter): void
    {
        [$status, $body] = $this->read('customers/balances', $this->secret(), $query);

        $this->assertSame(422, $status);
        $this->assertSame(['error' => 'invalid_parameter', 'message' => "The parameter {$parameter} is not valid.", 'parameter' => $parameter], $body);
    }

    public function testCustomerBalances_AcceptsTheLargestPageAndTheFirstOffset(): void
    {
        [$status, $body] = $this->read('customers/balances', $this->secret(), ['limit' => '200', 'offset' => '0']);

        $this->assertSame(200, $status);
        $this->assertSame(200, $body['meta']['limit']);
    }

    public function testSalesDocuments_ListsTheDocumentsOfTheDaysWithTheirFiguresAndNothingOfTheBooksInside(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $credit  = $this->creditNoteOf($invoice, '25.00');

        [$status, $body] = $this->read('documents/sales', $this->secret(['read:documents']));

        $this->assertSame(200, $status);
        $this->assertSame(2, $body['meta']['total']);

        $keys = ['id', 'kind', 'number', 'date', 'branch', 'customer_id', 'customer_name', 'value', 'tax', 'total', 'due_date', 'paid', 'credited', 'open', 'overdue_days'];

        foreach ($body['data'] as $row) {
            $this->assertSame($keys, array_keys($row), 'Nothing but the figures of a document is given: no journal, no ledger amount and no cost.');
        }

        $rows = array_column($body['data'], null, 'id');

        $this->assertSame('invoice', $rows[$invoice]['kind']);
        $this->assertSame('100.0000', $rows[$invoice]['total']);
        $this->assertSame('100.0000', $rows[$invoice]['value']);
        $this->assertSame('0.0000', $rows[$invoice]['tax']);
        $this->assertSame('Omar Khaled', $rows[$invoice]['customer_name']);
        $this->assertSame($this->customerId, $rows[$invoice]['customer_id']);
        $this->assertSame('0.0000', $rows[$invoice]['paid']);
        $this->assertSame('25.0000', $rows[$invoice]['credited']);
        $this->assertSame('75.0000', $rows[$invoice]['open'], 'What the credit note took back is not owed.');
        $this->assertSame(date('Y-m-d'), $rows[$invoice]['date']);
        $this->assertSame('credit_note', $rows[$credit]['kind']);
        $this->assertSame('-25.0000', $rows[$credit]['total']);
        $this->assertNull($rows[$credit]['open']);
        $this->assertNull($rows[$credit]['due_date']);
    }

    public function testSalesDocuments_ShowsPaymentsAndHowLateAnInvoiceIs(): void
    {
        $late = $this->invoiceDated('80.00', $this->daysAgo(10), 4, null, [$this->payment(lang('Sales.cash'), '30.00')]);

        [, $body] = $this->read('documents/sales', $this->secret(['read:documents']));

        $row = array_column($body['data'], null, 'id')[$late];

        $this->assertSame('30.0000', $row['paid']);
        $this->assertSame('0.0000', $row['credited']);
        $this->assertSame('50.0000', $row['open']);
        $this->assertSame($this->daysAgo(6), $row['due_date']);
        $this->assertSame(6, $row['overdue_days']);
    }

    public function testSalesDocuments_FiltersByKindByCustomerAndByDays(): void
    {
        $other   = $this->anotherCustomer('Hind', 'Saad');
        $recent  = $this->invoiceOf('10.00');
        $older   = $this->invoiceDated('20.00', $this->daysAgo(20));
        $theirs  = $this->invoiceOf('30.00', $other);
        $credit  = $this->creditNoteOf($recent, '5.00');
        $secret  = $this->secret(['read:documents']);

        $ids = static fn (array $body): array => array_column($body['data'], 'id');

        [, $invoices] = $this->read('documents/sales', $secret, ['kind' => 'invoice']);
        [, $returns]  = $this->read('documents/sales', $secret, ['kind' => 'credit_note']);
        [, $mine]     = $this->read('documents/sales', $secret, ['customer_id' => (string) $other]);
        [, $recently] = $this->read('documents/sales', $secret, ['from' => $this->daysAgo(5), 'to' => date('Y-m-d')]);
        [, $long]     = $this->read('documents/sales', $secret, ['from' => $this->daysAgo(25), 'to' => date('Y-m-d')]);
        [, $all]      = $this->read('documents/sales', $secret, ['kind' => 'all']);

        $this->assertEqualsCanonicalizing([$recent, $older, $theirs], $ids($invoices));
        $this->assertSame([$credit], $ids($returns));
        $this->assertSame([$theirs], $ids($mine));
        $this->assertEqualsCanonicalizing([$recent, $theirs, $credit], $ids($recently));
        $this->assertContains($older, $ids($long));
        $this->assertCount(4, $ids($all));
    }

    public function testSalesDocuments_ReadsTheLastThirtyDaysWhenNoDaysAreGiven(): void
    {
        $inside  = $this->invoiceDated('10.00', $this->daysAgo(30));
        $outside = $this->invoiceDated('10.00', $this->daysAgo(31));

        [, $body] = $this->read('documents/sales', $this->secret(['read:documents']));

        $this->assertContains($inside, array_column($body['data'], 'id'));
        $this->assertNotContains($outside, array_column($body['data'], 'id'));
        $this->assertSame($this->daysAgo(30), $body['meta']['from']);
        $this->assertSame(date('Y-m-d'), $body['meta']['to']);
    }

    public function testSalesDocuments_PagesTheListOldestFirst(): void
    {
        $one   = $this->invoiceDated('10.00', $this->daysAgo(3));
        $two   = $this->invoiceDated('10.00', $this->daysAgo(2));
        $three = $this->invoiceDated('10.00', $this->daysAgo(1));

        [, $first]  = $this->read('documents/sales', $this->secret(['read:documents']), ['limit' => '2']);
        [, $second] = $this->read('documents/sales', $this->secret(['read:documents']), ['limit' => '2', 'offset' => '2']);

        $this->assertSame([$one, $two], array_column($first['data'], 'id'));
        $this->assertSame([$three], array_column($second['data'], 'id'));
        $this->assertSame(3, $first['meta']['total']);
    }

    public function testSalesDocuments_GivesAKeyLimitedToABranchThatBranchAloneAndAKeyOfEveryBranchAll(): void
    {
        $second = $this->createBranch('B2');
        $here   = $this->invoiceOf('10.00');
        $there  = $this->invoiceInBranch($second, '20.00');

        [, $limited]  = $this->read('documents/sales', $this->secret(['read:documents'], $second));
        [, $inFirst]  = $this->read('documents/sales', $this->secret(['read:documents'], 1));
        [, $everyone] = $this->read('documents/sales', $this->secret(['read:documents']));

        $this->assertSame([$there], array_column($limited['data'], 'id'));
        $this->assertSame(['B2'], array_unique(array_column($limited['data'], 'branch')));
        $this->assertSame([$here], array_column($inFirst['data'], 'id'));
        $this->assertEqualsCanonicalizing([$here, $there], array_column($everyone['data'], 'id'));
    }

    public function testSalesDocuments_NeedsTheScopeAndAnAccountingThatIsOn(): void
    {
        [$status, $body] = $this->read('documents/sales', $this->secret(['read:customers']));

        $this->assertSame(403, $status);
        $this->assertSame('insufficient_scope', $body['error']);

        $secret = $this->secret(['read:documents']);

        $this->setConfigValue('accounting_enabled', '0');

        [$status, $body] = $this->read('documents/sales', $secret);

        $this->assertSame(409, $status);
        $this->assertSame('accounting_disabled', $body['error']);
    }

    /**
     * @return array<string, array{array<string, string>, string, int}>
     */
    public static function badDocumentQueries(): array
    {
        return [
            'a day that is not a day'      => [['from' => '2027-02-30'], 'from', 422],
            'a word for the end'           => [['to' => 'today'], 'to', 422],
            'the end before the start'     => [['from' => '2027-03-02', 'to' => '2027-03-01'], 'from', 422],
            'a kind that is not one'       => [['kind' => 'journal'], 'kind', 422],
            'a debit note, not offered'    => [['kind' => 'debit_note'], 'kind', 422],
            'a customer that is not a number' => [['customer_id' => 'abc'], 'customer_id', 422],
            'customer zero'                => [['customer_id' => '0'], 'customer_id', 422],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('badDocumentQueries')]
    public function testSalesDocuments_RefusesWhatIsNotAQuestion(array $query, string $parameter, int $expected): void
    {
        [$status, $body] = $this->read('documents/sales', $this->secret(['read:documents']), $query);

        $this->assertSame($expected, $status);
        $this->assertSame($parameter, $body['parameter']);
    }

    public function testSalesDocuments_ReadsAtMostAYearAtATime(): void
    {
        $secret = $this->secret(['read:documents']);

        [$status, $refusal] = $this->read('documents/sales', $secret, ['from' => '2026-01-01', 'to' => '2027-01-02']);

        $this->assertSame(422, $status);
        $this->assertSame('range_too_long', $refusal['error']);

        [$status, $body] = $this->read('documents/sales', $secret, ['from' => '2026-01-01', 'to' => '2027-01-01']);

        $this->assertSame(200, $status);
        $this->assertSame('2027-01-01', $body['meta']['to']);
    }

    public function testRead_ChangesNothingInTheBooks(): void
    {
        $this->invoiceOf('100.00');
        $secret = $this->secret();

        $db     = Database::connect();
        $tables = ['accounting_journals', 'accounting_journal_lines', 'sales', 'sales_items', 'sales_payments', 'customers', 'items', 'inventory', 'accounting_accounts'];
        $before = [];

        foreach ($tables as $table) {
            $before[$table] = $db->query('CHECKSUM TABLE ' . $db->prefixTable($table))->getRow()->Checksum;
        }

        $this->read('ping', $secret);
        $this->read('customers/balances', $secret);
        $this->read('documents/sales', $secret);

        foreach ($tables as $table) {
            $this->assertSame($before[$table], $db->query('CHECKSUM TABLE ' . $db->prefixTable($table))->getRow()->Checksum, $table);
        }
    }
}
