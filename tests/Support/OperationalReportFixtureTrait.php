<?php

namespace Tests\Support;

use CodeIgniter\Test\TestResponse;
use Config\Services;
use DOMDocument;
use DOMXPath;

/**
 * What a test of the screens of the operational reports needs: someone who may read them, the cells of the
 * table of a screen and the rows of its file. A class that uses it also uses FeatureTestTrait,
 * AccountingFixtureTrait and EmployeeFixtureTrait.
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
}
