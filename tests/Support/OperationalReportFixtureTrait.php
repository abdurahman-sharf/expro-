<?php

namespace Tests\Support;

use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use DOMDocument;
use DOMXPath;

/**
 * What a test of the screens of the operational reports needs: someone who may read them, the cells of the
 * table of a screen and the rows of its file, and what the filter of the customer looks like. A class that uses it
 * also uses FeatureTestTrait, AccountingFixtureTrait and EmployeeFixtureTrait.
 */
trait OperationalReportFixtureTrait
{
    /**
     * The reports permissions every report of the family is read under.
     *
     * @return list<string>
     */
    protected function everyReportPermission(): array
    {
        return ['accounting_reports', 'reports_sales', 'reports_customers', 'reports_employees', 'reports_items', 'reports_categories', 'reports_payments'];
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    protected function reader(array $permissions = [], ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants($permissions === [] ? $this->everyReportPermission() : $permissions, $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * A request that starts from a response of its own: the response of a request is a service shared by
     * the requests of one test, and one that was redirected leaves its address in it, so a later request
     * would look redirected whatever the screen did.
     */
    protected function fetch(string $url): TestResponse
    {
        Services::resetSingle('response');

        return $this->get($url);
    }

    protected function pageOf(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $response->getBody());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /**
     * The text of every cell of a column in the rows of the table, in order.
     *
     * @return list<string>
     */
    protected function column(TestResponse $response, string $key): array
    {
        $texts = [];

        foreach ($this->pageOf($response)->query("//table[@id='report_table']/tbody/tr[@data-row]/td[@data-cell='{$key}']") as $node) {
            $texts[] = trim($node->textContent);
        }

        return $texts;
    }

    /**
     * What the row of the grand total says in a column.
     */
    protected function grandTotal(TestResponse $response, string $key): ?string
    {
        $nodes = $this->pageOf($response)->query("//table[@id='report_table']/tfoot/tr[@data-total]/td[@data-cell='{$key}']");

        return $nodes->length === 0 ? null : trim($nodes->item(0)->textContent);
    }

    /**
     * @return list<string>
     */
    protected function tieRows(TestResponse $response): array
    {
        $rows = [];

        foreach ($this->pageOf($response)->query("//table[@id='report_ties']/tbody/tr") as $node) {
            $rows[] = trim($node->getAttribute('data-tie')) . ':' . trim($node->textContent);
        }

        return $rows;
    }

    /**
     * The addresses of the buttons of the title bar of a screen, the file and the other reports.
     *
     * @return list<string>
     */
    protected function titleBarLinks(TestResponse $response): array
    {
        $links = [];

        foreach ($this->pageOf($response)->query("//div[@id='title_bar']//a/@href") as $node) {
            $links[] = $node->nodeValue;
        }

        return $links;
    }

    /**
     * The filters the link of the file of a screen carries, as the address gives them.
     *
     * @return array<string, mixed>
     */
    protected function fileQuery(TestResponse $response): array
    {
        $nodes = $this->pageOf($response)->query("//a[@id='report_csv']/@href");

        $this->assertSame(1, $nodes->length, 'The screen has no link to its file.');

        parse_str((string) parse_url($nodes->item(0)->nodeValue, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * Gives every filter name a value in the address of the screen and says the link of its file carries
     * each, so that a filter left out of the list of what is carried is found.
     *
     * @param list<string> $names
     */
    protected function assertFileCarries(string $route, array $names, string $extra = ''): void
    {
        $values = ['group' => 'day', 'view' => 'details', 'preset' => 'last_month', 'mode' => 'between', 'from' => '2020-01-01', 'to' => '2020-01-31', 'date' => '2020-01-15', 'status' => ['paid'], 'customer' => '7', 'user' => '8', 'collector' => '9', 'not_taxed' => '1', 'type' => 'invoice', 'order' => 'date_asc', 'item' => '10', 'category' => 'Oils', 'location' => '1', 'method' => 'cash', 'treasury' => '11', 'state' => 'open', 'hide_credit_notes' => '1', 'show' => '1'];
        $query  = [];

        foreach ($names as $name) {
            $query[$name] = $values[$name];
        }

        $carried = $this->fileQuery($this->fetch($route . '?' . http_build_query($query) . $extra));

        foreach ($names as $name) {
            $this->assertArrayHasKey($name, $carried, "The file of {$route} does not carry the filter {$name}.");
        }
    }

    protected function shown(string $amount): string
    {
        return to_currency_no_money($amount);
    }

    /**
     * @return list<list<string>>
     */
    protected function csvRows(TestResponse $response): array
    {
        $body = $response->response()->getBody();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, substr($body, 3));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * A customer with an account in the books, as the filter of a report lists them.
     */
    protected function bookedCustomer(string $firstName, string $lastName, ?string $company = null): int
    {
        $customerId = $this->insertCustomer($firstName, $lastName, $company);

        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customerId, $company ?? trim($firstName . ' ' . $lastName));

        return $customerId;
    }

    protected function setPickerThreshold(?int $threshold): void
    {
        if ($threshold === null) {
            $this->accountingDb()->table('app_config')->where('key', 'accounting_picker_threshold')->delete();

            return;
        }

        $this->setConfigValue('accounting_picker_threshold', (string) $threshold);
    }

    /**
     * Says the screen filters by customer in a box that searches, which the lookup answers, in place of a list.
     */
    protected function assertCustomerFilterSearches(string $url): void
    {
        $this->bookedCustomer('Filter', 'First');
        $this->bookedCustomer('Filter', 'Second');
        $this->setPickerThreshold(1);
        $this->reader();

        try {
            $response = $this->fetch($url);
            $page     = $this->pageOf($response);
            $body     = (string) $response->response()->getBody();

            $this->assertSame(1, $page->query("//input[@type='text'][@id='f_customer_text']")->length, "{$url} offers a search box for the customer");
            $this->assertSame(1, $page->query("//input[@type='hidden'][@id='f_customer'][@name='customer']")->length, "{$url} sends the number of the customer");
            $this->assertSame(0, $page->query("//select[@name='customer']")->length, "{$url} no longer lists the customers");
            $this->assertStringContainsString('accounting_lookup\/search', $body);
            $this->assertStringContainsString('"mode":"ledger"', $body);
            $this->assertStringContainsString('"selector":"#f_customer_text"', $body);
        } finally {
            $this->setPickerThreshold(null);
        }
    }

    /**
     * Says the search box of the screen is one the reader of its permissions is answered by: the box is offered
     * and the lookup it asks finds the customer typed, which a reader refused by the lookup would never see.
     *
     * @param list<string> $permissions
     */
    protected function assertCustomerFilterIsAnsweredToTheReaderOf(string $url, array $permissions): void
    {
        $served = $this->bookedCustomer('Filter', 'Served');
        $this->bookedCustomer('Filter', 'Another');
        $this->setPickerThreshold(1);
        $this->reader($permissions);

        try {
            $this->assertSame(1, $this->pageOf($this->fetch($url))->query("//input[@type='text'][@id='f_customer_text']")->length, "{$url} offers a search box to " . implode(', ', $permissions));

            $lookup = $this->fetch('/accounting_lookup/search?' . http_build_query(['kind' => 'customer', 'mode' => 'ledger', 'term' => 'Filter Served']));

            $lookup->assertStatus(200);
            $this->assertSame([$served], array_column(json_decode($lookup->getJSON(), true)['results'], 'id'), "{$url} finds the customer for " . implode(', ', $permissions));
        } finally {
            $this->setPickerThreshold(null);
        }
    }

    /**
     * Says the screen lists the customers in a select box while there are no more than the threshold.
     */
    protected function assertCustomerFilterLists(string $url): void
    {
        $customerId = $this->bookedCustomer('Filter', 'Listed');
        $this->setPickerThreshold(100000);
        $this->reader();

        try {
            $page = $this->pageOf($this->fetch($url));

            $this->assertSame(1, $page->query("//select[@id='f_customer']/option[@value='{$customerId}']")->length, "{$url} lists the customer");
            $this->assertSame(0, $page->query("//input[@id='f_customer_text']")->length, "{$url} offers no search box");
        } finally {
            $this->setPickerThreshold(null);
        }
    }
}
