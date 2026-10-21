<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\FiscalYearService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingPeriods extends AccountingController
{
    private FiscalYearService $years;

    public function __construct()
    {
        parent::__construct('accounting_periods');

        $this->years = new FiscalYearService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        return view('accounting_periods/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
            'can_manage'    => $this->canManage(AccountingGuard::PERIODS_MANAGE),
            'days_left'     => $this->years->daysUntilLastYearEnds(),
            'auto_create'   => $this->years->autoCreateEnabled(),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $years = $this->years->all();
        $last  = count($years) - 1;
        $rows  = [];

        foreach ($years as $index => $year) {
            $rows[] = $this->row($year, $index === 0 || $index === $last);
        }

        return $this->response->setJSON(['total' => count($rows), 'rows' => $rows]);
    }

    public function getRow(int $fiscalYearId): ResponseInterface
    {
        $years = $this->years->all();
        $last  = count($years) - 1;

        foreach ($years as $index => $year) {
            if ((int) $year->fiscal_year_id === $fiscalYearId) {
                return $this->response->setJSON($this->row($year, $index === 0 || $index === $last));
            }
        }

        throw PageNotFoundException::forPageNotFound();
    }

    public function getView(int $fiscalYearId = NEW_ENTRY): string
    {
        if (! $this->canManage(AccountingGuard::PERIODS_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $isNew = $fiscalYearId === NEW_ENTRY;
        $year  = $isNew ? null : $this->years->find($fiscalYearId);

        if (! $isNew && $year === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $all   = $this->years->all();
        $first = $all === [] ? null : (int) $all[0]->fiscal_year_id;
        $last  = $all === [] ? null : (int) $all[count($all) - 1]->fiscal_year_id;

        return view('accounting_periods/form', [
            'year'         => $year,
            'suggested'    => $this->years->suggest(),
            'start_locked' => ! $isNew && $fiscalYearId !== $first,
            'end_locked'   => ! $isNew && $fiscalYearId !== $last,
        ]);
    }

    public function postSave(int $fiscalYearId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($fiscalYearId): array {
            $input = [
                'name'        => (string) $this->request->getPost('name'),
                'start_date'  => (string) $this->request->getPost('start_date'),
                'end_date'    => (string) $this->request->getPost('end_date'),
                'description' => (string) $this->request->getPost('description'),
            ];

            if ($fiscalYearId === NEW_ENTRY) {
                $id = $this->years->create($input, $this->employeeId());

                return ['success' => true, 'message' => lang('Accounting_periods.successful_adding'), 'id' => $id];
            }

            $this->years->update($fiscalYearId, $input, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_periods.successful_updating'), 'id' => $fiscalYearId];
        });
    }

    public function postDeleteYear(int $fiscalYearId): ResponseInterface
    {
        return $this->attempt(function () use ($fiscalYearId): array {
            $this->years->delete($fiscalYearId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_periods.successful_deleting'), 'id' => $fiscalYearId];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['name' => lang('Accounting_periods.year_name'), 'sortable' => false],
            ['start_date'    => lang('Accounting_periods.start_date'), 'sortable' => false, 'escape' => false],
            ['end_date'      => lang('Accounting_periods.end_date'), 'sortable' => false, 'escape' => false],
            ['journal_count' => lang('Accounting_periods.journal_count'), 'sortable' => false],
            ['status'        => lang('Accounting.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $year, bool $atAnEdge): array
    {
        $id    = (int) $year->fiscal_year_id;
        $state = $year->state;
        $class = match ($state) {
            'current' => 'text-bg-success',
            'future'  => 'text-bg-primary',
            default   => 'text-bg-secondary',
        };

        return [
            'fiscal_year_id' => $id,
            'name'           => $year->name,
            'start_date'     => $this->amountCell($this->formatDate($year->start_date)),
            'end_date'       => $this->amountCell($this->formatDate($year->end_date)),
            'journal_count'  => (int) $year->journal_count,
            'status'         => '<span class="badge ' . $class . '">' . esc(lang('Accounting_periods.state_' . $state)) . '</span>',
            'edit'           => $this->actions($year, $atAnEdge),
        ];
    }

    private function actions(object $year, bool $atAnEdge): string
    {
        if (! $this->canManage(AccountingGuard::PERIODS_MANAGE)) {
            return '';
        }

        $id   = (int) $year->fiscal_year_id;
        $html = anchor(
            "accounting_periods/view/{$id}",
            '<span class="bi bi-pencil-square" aria-hidden="true"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_periods.update')],
        );

        if ($atAnEdge && (int) $year->journal_count === 0) {
            $delete = esc(lang('Common.delete'), 'attr');
            $html .= '<button type="button" class="btn btn-link p-0 acc-action text-danger" data-acc-action="delete" data-acc-id="' . $id . '" title="' . $delete . '" aria-label="' . $delete . '"><span class="bi bi-trash" aria-hidden="true"></span></button>';
        }

        return $html;
    }
}
