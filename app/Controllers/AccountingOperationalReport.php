<?php

namespace App\Controllers;

use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\OperationalReports\DateFilter;
use App\Libraries\Accounting\OperationalReports\ReportColumn;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ScopedReportRequest;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * What the operational reports of sales, items, payments, profit and net position share: they are read
 * under the module of the financial reports and, one by one, under the permission of the report an
 * employee asks for, for the branches the employee works in. A report shows its filters first and its
 * figures only when it is run, so a link with every filter in it can be copied and shared. The same
 * request gives the file, in which a text that a spreadsheet would read as a formula is shown as text.
 */
abstract class AccountingOperationalReport extends AccountingController
{
    use ExportsCsv;
    use ScopedReportRequest;

    public function __construct()
    {
        parent::__construct('accounting_reports');
    }

    protected function allow(string $permissionId): void
    {
        if (! $this->can($permissionId)) {
            throw new RedirectException('no_access/accounting_reports/' . $permissionId);
        }
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }

    protected function dates(string $defaultPreset = ''): DateFilter
    {
        return DateFilter::read($this->request->getGet(), $this->today(), $defaultPreset);
    }

    protected function isRun(): bool
    {
        return $this->query('show') === '1';
    }

    protected function runs(DateFilter $dates): bool
    {
        return $this->isRun() && $dates->isRun();
    }

    /**
     * The filters that show the figures of one customer, one employee or one category are read under the
     * permission of the grouping by it, as the grouping is.
     *
     * @param array<string, string> $permissions The permission by the name of the filter.
     */
    protected function allowFilters(array $permissions): void
    {
        foreach ($permissions as $name => $permissionId) {
            if ($this->query($name) !== '') {
                $this->allow($permissionId);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, string>      $permissions The permission by the name of the filter.
     *
     * @return list<array<string, mixed>>
     */
    protected function permittedFields(array $fields, array $permissions): array
    {
        return array_values(array_filter($fields, fn (array $field): bool => ! isset($permissions[$field['name']]) || $this->can($permissions[$field['name']])));
    }

    /**
     * The first grouping of the list that the employee may read, so that a screen opened with no grouping
     * is not one that sends the employee away.
     *
     * @template T of object
     *
     * @param non-empty-list<T> $groups Each with the permission it is read under.
     *
     * @return T
     */
    protected function firstReadable(array $groups): object
    {
        foreach ($groups as $group) {
            if ($this->can($group->permission())) {
                return $group;
            }
        }

        return $groups[0];
    }

    protected function wantsDetails(): bool
    {
        return $this->query('view') === 'details';
    }

    /**
     * @param list<string> $allowed
     */
    protected function choice(string $name, array $allowed, string $default): string
    {
        $value = $this->query($name);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    protected function positiveId(string $name): ?int
    {
        $value = $this->query($name);

        return ctype_digit($value) && $value !== '' && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    protected function carried(array $names, ReportScope $scope): array
    {
        $query = [];

        foreach ($names as $name) {
            $value = $this->request->getGet($name);

            if (is_array($value) ? $value !== [] : (is_string($value) && trim($value) !== '')) {
                $query[$name] = $value;
            }
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $page
     */
    protected function page(array $page): string
    {
        return view('accounting_operational/report', [
            'amount'   => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'quantity' => fn (string $value): string => $this->amountCell(to_quantity_decimals($value)),
            'percent'  => fn (string $value): string => $this->amountCell($value . '%'),
            'date'     => fn (string $value): string => $this->formatDate($value),
            ...$page,
        ]);
    }

    protected function csvResponse(string $name, ReportTable $table, string $titleKey): ResponseInterface
    {
        if ($table->isRefused()) {
            return $this->refused(lang((string) $table->refusal, $table->refusalArguments));
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '_' . ($table->from ?? 'start') . '_' . $table->to . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->csv($table, $titleKey));
    }

    protected function refused(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($message);
    }

    /**
     * The customers that have an account in the books, by name, for a filter.
     *
     * @return array<string, string>
     */
    protected function customerChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.all_customers')];

        foreach (Database::connect()->table('accounting_accounts')->select('entity_id, name')->where('entity_type', 'customer')->orderBy('name', 'ASC')->limit(500)->get()->getResult() as $account) {
            $choices[(string) $account->entity_id] = (string) $account->name;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    protected function categoryChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.all_categories')];

        foreach (Database::connect()->table('items')->select('category')->where('deleted', 0)->where('category !=', '')->distinct()->orderBy('category', 'ASC')->limit(500)->get()->getResult() as $item) {
            $choices[(string) $item->category] = (string) $item->category;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    protected function itemChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.all_items')];

        foreach (Database::connect()->table('items')->select('item_id, name')->where('deleted', 0)->orderBy('name', 'ASC')->limit(500)->get()->getResult() as $item) {
            $choices[(string) $item->item_id] = (string) $item->name;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    protected function locationChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.all_locations')];

        foreach (Database::connect()->table('stock_locations')->select('location_id, location_name')->where('deleted', 0)->orderBy('location_name', 'ASC')->get()->getResult() as $location) {
            $choices[(string) $location->location_id] = (string) $location->location_name;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    protected function employeeChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.all_users')];

        foreach (Database::connect()->table('employees e')->select("e.person_id, TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS name", false)->join('people p', 'p.person_id = e.person_id')->where('e.deleted', 0)->orderBy('name', 'ASC')->limit(500)->get()->getResult() as $employee) {
            $choices[(string) $employee->person_id] = (string) $employee->name;
        }

        return $choices;
    }

    private function csv(ReportTable $table, string $titleKey): string
    {
        $handle  = fopen('php://temp', 'r+b');
        $columns = $table->columns;

        $this->csvRow($handle, [lang($titleKey)]);
        $this->csvRow($handle, [lang('Accounting_reports.from'), $table->from ?? '', lang('Accounting_reports.to'), $table->to]);
        $this->csvRow($handle, [lang('Accounting_reports.csv_branches'), implode(' ', $table->scope->codes)]);
        $this->csvNote($handle, $this->scopeLabel($table->scope));
        $this->csvRow($handle, []);
        $this->csvRow($handle, array_map(static fn (ReportColumn $column): string => lang($column->labelKey), $columns));

        foreach ($table->blocks as $block) {
            if ($block->heading !== []) {
                $this->csvRow($handle, [($block->heading['labelKind'] ?? 'text') === 'range' ? str_replace('|', ' - ', (string) $block->heading['label']) : (string) $block->heading['label']]);
            }

            foreach ($block->rows as $row) {
                $this->csvRow($handle, $this->cells($columns, $row));
            }

            if ($block->subtotal !== null) {
                $this->csvRow($handle, $this->cells($columns, [...$block->subtotal, $columns[0]->key => lang('Accounting_operational_reports.subtotal')]));
            }
        }

        $this->csvRow($handle, $this->cells($columns, [...$table->total, $columns[0]->key => lang('Accounting_operational_reports.grand_total')]));
        $this->csvRow($handle, []);

        foreach ($table->ties as $tie) {
            $this->csvRow($handle, $tie->isRefused() ? [lang($tie->labelKey), '', '', '', lang((string) $tie->refusal, $tie->refusalArguments)] : [lang($tie->labelKey), $tie->reported, $tie->ledger, $tie->difference(), lang($tie->ties() ? 'Accounting_operational_reports.ties' : 'Accounting_operational_reports.differs')]);
        }

        foreach ($table->notes as $note) {
            $this->csvNote($handle, lang($note));
        }

        return $this->csvText($handle);
    }

    /**
     * @param list<ReportColumn>    $columns
     * @param array<string, string> $row
     *
     * @return list<string>
     */
    private function cells(array $columns, array $row): array
    {
        return array_map(static fn (ReportColumn $column): string => (string) ($row[$column->key] ?? ''), $columns);
    }
}
