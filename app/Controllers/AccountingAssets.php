<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ScopedReportRequest;
use App\Libraries\Assets\AssetCategories;
use App\Libraries\Assets\AssetChoices;
use App\Libraries\Assets\AssetDisposals;
use App\Libraries\Assets\AssetFigures;
use App\Libraries\Assets\AssetRegister;
use App\Libraries\Assets\AssetsFilter;
use App\Libraries\Assets\AssetsReport;
use App\Libraries\Assets\AssetsReportResult;
use App\Libraries\Assets\AssetStatus;
use App\Libraries\Assets\DepreciationPeriod;
use App\Libraries\Assets\DepreciationRun;
use App\Libraries\Assets\DepreciationSchedule;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

class AccountingAssets extends AccountingController
{
    use ExportsCsv;
    use ScopedReportRequest;

    private AssetRegister $register;
    private AssetCategories $categories;
    private AssetDisposals $disposals;
    private DepreciationRun $depreciation;
    private AssetsReport $report;
    private AssetChoices $choices;

    public function __construct()
    {
        parent::__construct('accounting_assets');

        $this->register     = new AssetRegister(null, null, $this->branchGuard());
        $this->categories   = new AssetCategories();
        $this->disposals    = new AssetDisposals(null, null, $this->branchGuard());
        $this->depreciation = new DepreciationRun(null, null, $this->branchGuard());
        $this->report       = new AssetsReport();
        $this->choices      = new AssetChoices();
    }

    public function getIndex(): string
    {
        $scope  = $this->requestedScope();
        $filter = $this->filter();
        $result = $this->report->build($scope, $filter);

        return view('accounting_assets/index', [
            'result'      => $result,
            'filter'      => $filter,
            'scope'       => $scope,
            'branches'    => $this->branchChoices($scope),
            'scope_label' => $this->scopeLabel($scope),
            'employees'   => $this->choices->employees(),
            'accounts'    => $this->choices->accounts()['cost'],
            'categories'  => $this->categories->all(),
            'statuses'    => AssetStatus::cases(),
            'can_manage'  => $this->can(AccountingGuard::ASSETS_MANAGE),
            'csv_url'     => base_url('accounting_assets/csv') . '?' . $this->queryString($scope),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
            'notice'      => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $scope  = $this->requestedScope();
        $result = $this->report->build($scope, $this->filter());

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="fixed_assets_' . $result->asOf . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->csv($result, $this->scopeLabel($scope)));
    }

    public function getDetail(int $assetId): string
    {
        $asset = $this->visible($assetId);

        return view('accounting_assets/detail', [
            'asset'        => $asset,
            'schedule'     => $this->upcoming($asset),
            'depreciation' => $this->history($assetId),
            'operations'   => $this->disposals->operations($this->employeeId(), $assetId),
            'can_manage'   => $this->can(AccountingGuard::ASSETS_MANAGE),
            'can_dispose'  => $this->can(AccountingGuard::ASSETS_MANAGE) && AssetStatus::from((string) $asset->status)->canBeDisposed(),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (string $value): string => $this->formatDate($value),
            'notice'       => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getForm(int $assetId = NEW_ENTRY): string
    {
        $this->requireManage();

        $asset = $assetId === NEW_ENTRY ? null : $this->visible($assetId);

        return view('accounting_assets/form', [
            'asset'      => $asset,
            'code'       => $asset === null ? $this->register->suggestCode() : (string) $asset->code,
            'categories' => $this->categories->all(true),
            'accounts'   => $this->choices->accounts(),
            'branches'   => (new BranchService(null, $this->branchGuard()))->all($this->employeeId()),
            'treasuries' => $this->choices->treasuries($this->branchIds()),
            'suppliers'  => $this->choices->suppliers(),
            'employees'  => $this->choices->employees(),
            'storages'   => $this->choices->storages(),
            'today'      => date('Y-m-d'),
            'locked'     => $asset !== null && $this->hasHistory($asset),
            'notice'     => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function postSave(int $assetId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($assetId): array {
            $input = $this->assetInput();

            if ($assetId !== NEW_ENTRY) {
                $this->visible($assetId);
                $this->register->update($assetId, $input, $this->employeeId());
            } else {
                $assetId = $this->register->create($input, $this->employeeId());
            }

            $message = lang('Accounting_assets.saved');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $assetId, 'redirect' => base_url('accounting_assets/view/' . $assetId)];
        });
    }

    public function postDeleteAsset(int $assetId): ResponseInterface
    {
        return $this->attempt(function () use ($assetId): array {
            $this->visible($assetId);
            $this->register->delete($assetId, $this->employeeId());

            $message = lang('Accounting_assets.deleted');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $assetId, 'redirect' => base_url('accounting_assets')];
        });
    }

    public function postStatus(int $assetId, int $active): ResponseInterface
    {
        return $this->attempt(function () use ($assetId, $active): array {
            $this->visible($assetId);
            $this->register->setActive($assetId, $active === 1, $this->employeeId());

            $message = lang($active === 1 ? 'Accounting_assets.activated' : 'Accounting_assets.deactivated');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $assetId, 'redirect' => base_url('accounting_assets/view/' . $assetId)];
        });
    }

    public function getUsageForm(int $assetId): string
    {
        $this->requireManage();

        return view('accounting_assets/usage_form', ['asset' => $this->visible($assetId), 'month' => date('Y-m', strtotime('first day of last month'))]);
    }

    public function postUsage(int $assetId): ResponseInterface
    {
        return $this->attempt(function () use ($assetId): array {
            $this->visible($assetId);
            $this->register->recordUsage($assetId, trim((string) $this->request->getPost('month')), trim((string) $this->request->getPost('units')), $this->employeeId());

            $message = lang('Accounting_assets.usage_saved');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $assetId, 'redirect' => base_url('accounting_assets/view/' . $assetId)];
        });
    }

    public function getDisposeForm(string $kind, int $assetId): string
    {
        $this->requireManage();

        $asset = $this->visible($assetId);

        if (! in_array($kind, ['sale', 'write_off'], true) || ! AssetStatus::from((string) $asset->status)->canBeDisposed()) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_assets/dispose_form', [
            'asset'      => $asset,
            'kind'       => $kind,
            'treasuries' => array_values(array_filter($this->choices->treasuries($this->branchIds()), static fn (object $treasury): bool => (int) $treasury->branch_id === (int) $asset->branch_id)),
            'today'      => date('Y-m-d'),
            'book_value' => $this->formatAmount($asset->book_value),
        ]);
    }

    public function postDispose(string $kind, int $assetId): ResponseInterface
    {
        return $this->attempt(function () use ($kind, $assetId): array {
            $this->visible($assetId);

            $input = ['date' => trim((string) $this->request->getPost('date')), 'note' => (string) $this->request->getPost('note')];

            if ($kind === 'sale') {
                $this->disposals->sell($assetId, $input + ['proceeds' => trim((string) $this->request->getPost('proceeds')), 'treasury_id' => (int) $this->request->getPost('treasury_id'), 'customer_id' => (int) $this->request->getPost('customer_id')], $this->employeeId());
            } elseif ($kind === 'write_off') {
                $this->disposals->writeOff($assetId, $input, $this->employeeId());
            } else {
                throw PageNotFoundException::forPageNotFound();
            }

            $message = lang($kind === 'sale' ? 'Accounting_assets.sold' : 'Accounting_assets.written_off');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $assetId, 'redirect' => base_url('accounting_assets/view/' . $assetId)];
        });
    }

    public function getDisposeReverseForm(int $operationId): string
    {
        $this->requireManage();

        foreach ($this->disposals->operations($this->employeeId()) as $operation) {
            if ((int) $operation->operation_id === $operationId && $operation->status === 'active') {
                return view('accounting_assets/dispose_reverse_form', ['operation' => $operation]);
            }
        }

        throw PageNotFoundException::forPageNotFound();
    }

    public function postDisposeReverse(int $operationId): ResponseInterface
    {
        return $this->attempt(function () use ($operationId): array {
            $this->disposals->reverse($operationId, (string) $this->request->getPost('reason'), $this->employeeId());

            $message = lang('Accounting_assets.disposal_reversed');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $operationId, 'redirect' => base_url('accounting_assets')];
        });
    }

    public function getCategories(): string
    {
        return view('accounting_assets/categories', [
            'categories' => $this->categories->all(),
            'can_manage' => $this->can(AccountingGuard::ASSETS_MANAGE),
            'notice'     => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getCategoryForm(int $categoryId = NEW_ENTRY): string
    {
        $this->requireManage();

        $category = $categoryId === NEW_ENTRY ? null : $this->categories->find($categoryId);

        if ($categoryId !== NEW_ENTRY && $category === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_assets/category_form', ['category' => $category, 'accounts' => $this->choices->accounts()]);
    }

    public function postSaveCategory(int $categoryId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($categoryId): array {
            $id = $this->categories->save([
                'name'                   => (string) $this->request->getPost('name'),
                'cost_account_id'        => (int) $this->request->getPost('cost_account_id'),
                'accumulated_account_id' => (int) $this->request->getPost('accumulated_account_id'),
                'expense_account_id'     => (int) $this->request->getPost('expense_account_id'),
                'method'                 => (string) $this->request->getPost('method'),
                'life_months'            => (string) $this->request->getPost('life_months'),
            ], $this->employeeId(), $categoryId === NEW_ENTRY ? null : $categoryId);

            return ['success' => true, 'message' => lang('Accounting_assets.category_saved'), 'id' => $id];
        });
    }

    public function postCategoryActive(int $categoryId, int $active): ResponseInterface
    {
        return $this->attempt(function () use ($categoryId, $active): array {
            $this->categories->setActive($categoryId, $active === 1, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_assets.category_saved'), 'id' => $categoryId];
        });
    }

    public function postDeleteCategory(int $categoryId): ResponseInterface
    {
        return $this->attempt(function () use ($categoryId): array {
            $this->categories->delete($categoryId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_assets.category_deleted'), 'id' => $categoryId];
        });
    }

    public function getDepreciation(): string
    {
        $canRun  = $this->can(AccountingGuard::ASSETS_DEPRECIATE);
        $options = $this->scopeOptions();
        $scope   = $this->scopeParameter($options);
        $month   = $this->monthParameter();
        $preview = null;
        $error   = null;

        if ($canRun && $options !== []) {
            try {
                $preview = $this->depreciation->preview($scope === 'company' ? null : (int) $scope, $month, $this->employeeId());
            } catch (AccountingException|BranchException $refusal) {
                $error = $this->messageFor($refusal);
            }
        }

        return view('accounting_assets/depreciation', [
            'can_run'  => $canRun,
            'scopes'   => $options,
            'scope'    => $scope,
            'month'    => $month,
            'preview'  => $preview,
            'error'    => $error,
            'blockers' => $preview === null ? [] : array_map(fn (AccountingException $blocker): string => $this->messageFor($blocker), $preview->blockers),
            'runs'     => $this->depreciation->runs($this->employeeId()),
            'amount'   => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'     => fn (string $value): string => $this->formatDate($value),
            'notice'   => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getApproveForm(): string
    {
        $scope = $this->scopeParameter($this->scopeOptions());
        $month = $this->monthParameter();
        $shown = $this->request->getGet('fingerprint');

        if (! is_string($shown) || preg_match('/^[0-9a-f]{64}$/', $shown) !== 1) {
            throw PageNotFoundException::forPageNotFound();
        }

        try {
            $preview = $this->depreciation->preview($scope === 'company' ? null : (int) $scope, $month, $this->employeeId());
        } catch (AccountingException|BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (! $preview->canApprove()) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_assets/approve_form', ['preview' => $preview, 'scope' => $scope, 'month' => $month, 'fingerprint' => $shown]);
    }

    public function postApprove(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $scope = $this->postedScope();
            $month = $this->postedText('month');

            $this->depreciation->approve($scope === 'company' ? null : (int) $scope, $month, $this->postedText('fingerprint'), $this->employeeId());

            $message = lang('Accounting_assets.run_approved');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => 0, 'redirect' => base_url('accounting_assets/depreciation') . '?' . http_build_query(['month' => $month, 'scope' => $scope])];
        });
    }

    public function getReverseForm(int $runId): string
    {
        if (! $this->can(AccountingGuard::ASSETS_DEPRECIATE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        foreach ($this->depreciation->runs($this->employeeId()) as $run) {
            if ((int) $run->run_id === $runId && $run->status === 'active') {
                return view('accounting_assets/reverse_form', ['run' => $run]);
            }
        }

        throw PageNotFoundException::forPageNotFound();
    }

    public function postReverse(int $runId): ResponseInterface
    {
        return $this->attempt(function () use ($runId): array {
            $this->depreciation->reverse($runId, (string) $this->request->getPost('reason'), $this->employeeId());

            $message = lang('Accounting_assets.run_reverse_done');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $runId, 'redirect' => base_url('accounting_assets/depreciation')];
        });
    }

    private function requireManage(): void
    {
        if (! $this->can(AccountingGuard::ASSETS_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function visible(int $assetId): object
    {
        try {
            return $this->register->find($assetId, $this->employeeId());
        } catch (AccountingException) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function hasHistory(object $asset): bool
    {
        $db = db_connect();

        return $db->table('asset_depreciations')->where(['asset_id' => (int) $asset->asset_id, 'active_key' => 1])->countAllResults() > 0
            || $db->table('asset_operations')->where(['asset_id' => (int) $asset->asset_id, 'active_key' => 1])->countAllResults() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetInput(): array
    {
        $names = [
            'name', 'code', 'category_id', 'branch_id', 'description', 'supplier_id', 'purchase_reference', 'cost_center_id', 'purchase_date', 'in_service_date', 'cost', 'salvage',
            'useful_life_value', 'useful_life_unit', 'method', 'period_length', 'period_interval', 'amount_per_period', 'percentage', 'cost_per_unit', 'unit_name', 'total_units',
            'first_depreciation_date', 'end_date', 'cost_account_id', 'accumulated_account_id', 'expense_account_id', 'funding', 'treasury_id', 'quantity', 'track_units', 'opening_accumulated',
        ];

        $input = [];

        foreach ($names as $name) {
            $value        = $this->request->getPost($name);
            $input[$name] = is_string($value) ? trim($value) : '';
        }

        $units = $this->request->getPost('units');

        $input['units'] = is_array($units) ? array_values(array_filter(array_map(static fn (mixed $unit): array => is_array($unit) ? array_map(static fn (mixed $field): string => is_string($field) ? trim($field) : '', $unit) : [], $units))) : [];

        return $input;
    }

    private function filter(): AssetsFilter
    {
        $statuses = [];
        $asked    = $this->request->getGet('status');

        foreach (is_array($asked) ? $asked : [] as $value) {
            $status = is_string($value) ? AssetStatus::tryFrom($value) : null;

            if ($status !== null) {
                $statuses[$status->value] = $status;
            }
        }

        $group = $this->query('group');

        $depreciationFrom = $this->dateQuery('depreciation_from');
        $depreciationTo   = $this->dateQuery('depreciation_to');

        if ($this->request->getGet('depreciation_from') === null && $this->request->getGet('depreciation_to') === null) {
            $depreciationFrom = date('Y-m-01', strtotime('first day of last month'));
            $depreciationTo   = date('Y-m-t', strtotime('last day of last month'));
        }

        return new AssetsFilter(
            employeeId: (int) $this->query('employee') ?: null,
            purchaseFrom: $this->dateQuery('purchase_from'),
            purchaseTo: $this->dateQuery('purchase_to'),
            depreciationFrom: $depreciationFrom,
            depreciationTo: $depreciationTo,
            statuses: array_values($statuses),
            accountId: (int) $this->query('account') ?: null,
            categoryId: (int) $this->query('category') ?: null,
            group: in_array($group, AssetsFilter::GROUPS, true) ? $group : 'all',
            asOf: $this->dateQuery('as_of') ?? date('Y-m-d'),
        );
    }

    private function dateQuery(string $name): ?string
    {
        $value  = $this->query($name);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    private function queryString(ReportScope $scope): string
    {
        $query = array_filter([
            'employee'          => $this->query('employee'),
            'purchase_from'     => $this->query('purchase_from'),
            'purchase_to'       => $this->query('purchase_to'),
            'depreciation_from' => $this->query('depreciation_from'),
            'depreciation_to'   => $this->query('depreciation_to'),
            'account'           => $this->query('account'),
            'category'          => $this->query('category'),
            'group'             => $this->query('group'),
            'as_of'             => $this->query('as_of'),
        ], static fn (string $value): bool => $value !== '');

        $status = $this->request->getGet('status');

        if (is_array($status)) {
            $query['status'] = array_values(array_filter($status, 'is_string'));
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return http_build_query($query);
    }

    /**
     * @return list<array{date: string, amount: string, posted: bool}>
     */
    private function upcoming(object $asset): array
    {
        $terms = AssetFigures::termsOf($asset);

        if (! $terms->method->isScheduled()) {
            return [];
        }

        $schedule = new DepreciationSchedule();
        $posted   = (new AssetFigures(db_connect(), $schedule))->posted((int) $asset->asset_id);
        $events   = [];

        foreach ($schedule->events($terms, date('Y-m-d', strtotime('+5 years'))) as $event) {
            $events[] = ['date' => $event['date'], 'amount' => $event['amount'], 'posted' => isset($posted[DepreciationPeriod::containing($event['date'])->start])];

            if (count($events) >= 60) {
                break;
            }
        }

        return $events;
    }

    /**
     * @return list<object>
     */
    private function history(int $assetId): array
    {
        return db_connect()->table('asset_depreciations d')
            ->select('d.period_start, d.amount, d.units, d.active_key, r.run_id, r.status AS run_status, r.journal_id')
            ->join('asset_depreciation_runs r', 'r.run_id = d.run_id')
            ->where('d.asset_id', $assetId)
            ->orderBy('d.period_start', 'DESC')
            ->orderBy('d.depreciation_id', 'DESC')
            ->get()
            ->getResult();
    }

    private function csv(AssetsReportResult $result, ?string $scopeLabel): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [lang('Accounting_assets.title')]);
        $this->csvRow($handle, [lang('Accounting_assets.as_of'), $result->asOf]);
        $this->csvNote($handle, $scopeLabel);
        $this->csvRow($handle, []);
        $this->csvRow($handle, [
            lang('Accounting_assets.column_code'), lang('Accounting_assets.column_name'), lang('Accounting_assets.column_branch'), lang('Accounting_assets.column_category'), lang('Accounting_assets.column_status'),
            lang('Accounting_assets.column_purchase_date'), lang('Accounting_assets.column_service_date'), lang('Accounting_assets.column_cost'), lang('Accounting_assets.column_accumulated'),
            lang('Accounting_assets.column_depreciation'), lang('Accounting_assets.column_book_value'),
        ]);

        foreach ($result->groups as $group) {
            if ($group->key !== '') {
                $this->csvRow($handle, [lang('Accounting_assets.status_' . $group->key) === 'Accounting_assets.status_' . $group->key ? $group->key : lang('Accounting_assets.status_' . $group->key)]);
            }

            foreach ($group->rows as $row) {
                $this->csvRow($handle, [$row->code, $row->name, $row->branchCode, (string) $row->category, lang('Accounting_assets.status_' . $row->status->value), $row->purchaseDate, $row->inServiceDate, $row->cost, $row->accumulated, $row->depreciation, $row->bookValue]);
            }

            if ($group->key !== '') {
                $this->csvRow($handle, [lang('Accounting_assets.group_total'), '', '', '', '', '', '', $group->cost, $group->accumulated, $group->depreciation, $group->bookValue]);
            }
        }

        $this->csvRow($handle, [lang('Accounting_assets.total'), '', '', '', '', '', '', $result->cost, $result->accumulated, $result->depreciation, $result->bookValue]);
        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_assets.tie_title'), lang('Accounting_assets.tie_account'), lang('Accounting_assets.tie_register'), lang('Accounting_assets.tie_ledger'), lang('Accounting_assets.tie_difference')]);

        foreach ($result->tie as $tie) {
            $this->csvRow($handle, [lang('Accounting_assets.tie_' . $tie->kind), $tie->code . ' ' . $tie->name, $tie->register, $tie->ledger, $tie->difference()]);
        }

        return $this->csvText($handle);
    }

    /**
     * @return array<string, string>
     */
    private function scopeOptions(): array
    {
        $options = [];

        if ($this->branchGuard()->isUnrestricted($this->employeeId())) {
            $options['company'] = lang('Accounting_assets.scope_company');
        }

        foreach ((new BranchService(null, $this->branchGuard()))->all($this->employeeId()) as $branch) {
            $options[(string) $branch->branch_id] = $branch->code . ' - ' . $branch->name;
        }

        return $options;
    }

    /**
     * @param array<string, string> $options
     */
    private function scopeParameter(array $options): string
    {
        $value = $this->query('scope');

        if ($options === []) {
            return '';
        }

        if ($value === '') {
            return (string) array_key_first($options);
        }

        if (! array_key_exists($value, $options)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $value;
    }

    private function monthParameter(): string
    {
        $month = $this->query('month');

        return DepreciationPeriod::fromMonth($month) === null ? date('Y-m', strtotime('first day of last month')) : $month;
    }

    private function postedText(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? $value : '';
    }

    private function postedScope(): string
    {
        $value = $this->request->getPost('scope');

        if (! is_string($value) || ! ($value === 'company' || (ctype_digit($value) && (int) $value > 0))) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $value;
    }
}
