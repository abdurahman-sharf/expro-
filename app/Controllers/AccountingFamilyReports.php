<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\FamilyReports\FamilyReport;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use App\Libraries\Accounting\FamilyReports\FilterChoices;
use App\Libraries\Accounting\FamilyReports\ReportColumn;
use App\Libraries\Accounting\FamilyReports\ReportInput;
use App\Libraries\Accounting\FamilyReports\ReportRow;
use App\Libraries\Accounting\FamilyReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
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
    use ExportsCsv;

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
            $table = $this->table($definition, $this->input($scope, $this->request->getGet()));
        } catch (AccountingException $refusal) {
            $error = $this->messageFor($refusal);
            $table = $this->table($definition, $this->input($scope, []));
        }

        $page  = $this->input($scope, $this->request->getGet())->page();
        $pages = $table->pages(ReportInput::PAGE_SIZE);
        $page  = min($page, $pages);
        $choices = new FilterChoices();

        return view('accounting_reports/family', [
            'key'         => $key,
            'family'      => $definition['family'],
            'table'       => $table->page($page, ReportInput::PAGE_SIZE),
            'all'         => $table,
            'error'       => $error,
            'filters'     => $definition['filters'],
            'values'      => $this->values($definition['filters']),
            'choices'     => fn (string $source): array => $choices->of($source, $scope),
            'branches'    => $this->branchChoices($scope),
            'selected'    => $scope->ids,
            'scope'       => $scope,
            'scope_label' => $this->scopeLabel($scope),
            'page'        => $page,
            'pages'       => $pages,
            'csv_url'     => base_url('accounting_reports/' . $key . '/csv') . $this->queryString($definition['filters'], $scope),
            'page_url'    => fn (int $target): string => base_url('accounting_reports/' . $key) . $this->queryString($definition['filters'], $scope, ['page' => $target]),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'quantity'    => fn (string $value): string => $this->amountCell(to_quantity_decimals($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getCsv(string $key): ResponseInterface
    {
        $definition = $this->definition($key);

        try {
            $table = $this->table($definition, $this->input($this->requestedScope(), $this->request->getGet()));
        } catch (AccountingException $refusal) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $key . '_' . date('Y-m-d') . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->csv($table, $this->scopeLabel($this->requestedScope())));
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
    private function table(array $definition, ReportInput $input): ReportTable
    {
        return (new $definition['class']())->build($input);
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

    private function csv(ReportTable $table, ?string $scopeLabel): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [$table->title]);
        $this->csvNote($handle, $scopeLabel);
        $this->csvRow($handle, array_map(static fn (ReportColumn $column): string => $column->label, $table->columns));

        $cells = fn (ReportRow $row): array => array_map(static fn (ReportColumn $column): string => $row->cell($column->key), $table->columns);

        foreach ($table->blocks as $block) {
            if ($block->title !== null) {
                $this->csvRow($handle, [$block->title]);
            }

            foreach ($block->rows as $row) {
                $this->csvRow($handle, $cells($row));
            }

            if ($block->total !== null) {
                $this->csvRow($handle, $cells($block->total));
            }
        }

        if ($table->grand !== null) {
            $this->csvRow($handle, $cells($table->grand));
        }

        foreach ($table->ties as $tie) {
            $this->csvRow($handle, [$tie->label, $tie->reported, $tie->book, $tie->difference()]);
        }

        foreach ($table->notes as $note) {
            $this->csvNote($handle, $note);
        }

        return $this->csvText($handle);
    }
}
