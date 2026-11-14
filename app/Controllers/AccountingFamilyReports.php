<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Export\StreamedCsvResponse;
use App\Libraries\Accounting\FamilyReports\FamilyReport;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use App\Libraries\Accounting\FamilyReports\FilterChoices;
use App\Libraries\Accounting\FamilyReports\ReportCsv;
use App\Libraries\Accounting\FamilyReports\ReportInput;
use App\Libraries\Accounting\FamilyReports\ReportStream;
use App\Libraries\Accounting\FamilyReports\ReportTable;
use App\Libraries\Accounting\FamilyReports\StreamsReport;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportSizeGuard;
use App\Libraries\Accounting\ScopedReportRequest;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The reports of purchases, suppliers, stock, expenses, income and the books, one screen and one file
 * for each. They are read under the module of the financial reports and the permission of the report,
 * for one branch, for several or for the company, and what is asked for is cut down to the branches the
 * employee works in. Nothing here changes the books.
 */
class AccountingFamilyReports extends AccountingController
{
    use ScopedReportRequest;

    public function __construct()
    {
        parent::__construct('accounting_reports');
    }

    public function getShow(string $key): string
    {
        $definition = $this->definition($key);
        $scope      = $this->requestedScope();
        $error      = null;

        try {
            $screen = $this->screen($definition, $this->input($scope, $this->request->getGet()));
        } catch (AccountingException $refusal) {
            $error = $this->messageFor($refusal);

            try {
                $screen = $this->screen($definition, $this->input($scope, []));
            } catch (AccountingException) {
                $screen = $this->emptyScreen($definition, $this->input($scope, []));
            }
        }

        $choices = new FilterChoices();

        return view('accounting_reports/family', [
            'key'         => $key,
            'family'      => $definition['family'],
            'table'       => $screen['table'],
            'all'         => $screen['table'],
            'rows'        => $screen['rows'],
            'error'       => $error,
            'filters'     => $definition['filters'],
            'values'      => $this->values($definition['filters']),
            'choices'     => static fn (string $source): array => $choices->of($source, $scope),
            'branches'    => $this->branchChoices($scope),
            'selected'    => $scope->ids,
            'scope'       => $scope,
            'scope_label' => $this->scopeLabel($scope),
            'page'        => $screen['page'],
            'pages'       => $screen['pages'],
            'csvUrl'      => base_url('accounting_reports/' . $key . '/csv') . $this->queryString($definition['filters'], $scope),
            'pageUrl'     => fn (int $target): string => base_url('accounting_reports/' . $key) . $this->queryString($definition['filters'], $scope, ['page' => $target]),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'quantity'    => fn (string $value): string => $this->amountCell(to_quantity_decimals($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getCsv(string $key): ResponseInterface
    {
        $definition = $this->definition($key);
        $scope      = $this->requestedScope();
        $input      = $this->input($scope, $this->request->getGet());
        $label      = $this->scopeLabel($scope);

        try {
            $source = $this->source($definition, $input);
        } catch (AccountingException $refusal) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        return new StreamedCsvResponse($key . '_' . date('Y-m-d') . '.csv', fn (): iterable => ReportCsv::rows($source instanceof ReportStream ? $this->source($definition, $input) : $source, $label));
    }

    /**
     * @return array{class: class-string<FamilyReport>, permission: string, family: string, filters: list<array<string, mixed>>}
     */
    private function definition(string $key): array
    {
        $definition = FamilyReportCatalog::find($key);

        if ($definition === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (! $this->can($definition['permission'])) {
            throw new RedirectException('no_access/accounting_reports/' . $definition['permission']);
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function input(ReportScope $scope, array $query): ReportInput
    {
        return new ReportInput($scope, $this->employeeId(), $query, date('Y-m-d'), $this->can(AccountingGuard::JOURNALS_ALL) ? null : $this->employeeId());
    }

    /**
     * @param array{class: class-string<FamilyReport>} $definition
     */
    private function source(array $definition, ReportInput $input): ReportStream|ReportTable
    {
        $report = new $definition['class']();

        return $report instanceof StreamsReport ? $report->stream($input) : $report->build($input);
    }

    /**
     * @param array{class: class-string<FamilyReport>} $definition
     *
     * @return array{table: ReportTable, rows: int, page: int, pages: int}
     */
    private function screen(array $definition, ReportInput $input): array
    {
        $source = $this->source($definition, $input);

        if ($source instanceof ReportStream) {
            $rows = $source->rowCount();

            ReportSizeGuard::require($rows, ReportSizeGuard::screenRows());

            $pages = $source->pages(ReportInput::PAGE_SIZE);
            $page  = min($input->page(), $pages);

            return ['table' => $source->page($page, ReportInput::PAGE_SIZE), 'rows' => $rows, 'page' => $page, 'pages' => $pages];
        }

        $pages = $source->pages(ReportInput::PAGE_SIZE);
        $page  = min($input->page(), $pages);

        return ['table' => $source->page($page, ReportInput::PAGE_SIZE), 'rows' => $source->rowCount(), 'page' => $page, 'pages' => $pages];
    }

    /**
     * @param array{class: class-string<FamilyReport>} $definition
     *
     * @return array{table: ReportTable, rows: int, page: int, pages: int}
     */
    private function emptyScreen(array $definition, ReportInput $input): array
    {
        $source = $this->source($definition, $input);

        return ['table' => $source instanceof ReportStream ? $source->empty() : new ReportTable($source->title, $source->columns, []), 'rows' => 0, 'page' => 1, 'pages' => 1];
    }

    /**
     * @param list<array<string, mixed>> $filters
     *
     * @return array<string, string>
     */
    private function values(array $filters): array
    {
        $values = [];

        foreach ($filters as $filter) {
            $values[(string) $filter['name']] = $this->query((string) $filter['name']);
        }

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $filters
     * @param array<string, int>         $extra
     */
    private function queryString(array $filters, ReportScope $scope, array $extra = []): string
    {
        $query = [];

        foreach ($this->values($filters) as $name => $value) {
            if ($value !== '') {
                $query[$name] = $value;
            }
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        $query = [...$query, ...$extra];

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
