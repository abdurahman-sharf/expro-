<?php

namespace App\Controllers;

use App\Libraries\Accounting\TaxDefinitions;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The tax list: the mode of each tax and the account it is credited to.
 */
class Tax_definitions extends AccountingController
{
    private const MAX_PAGE_SIZE = 500;

    private TaxDefinitions $definitions;

    public function __construct()
    {
        parent::__construct('taxes', false);

        $this->definitions = new TaxDefinitions(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        return view('tax_definitions/manage', ['tableHeaders' => transform_headers($this->headers(), false, true)]);
    }

    public function getSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $result = $this->definitions->search(trim((string) $this->request->getGet('search')), (string) $this->request->getGet('sort'), (string) $this->request->getGet('order'), $limit, max(0, (int) $this->request->getGet('offset')));

        return $this->response->setJSON(['total' => $result['total'], 'rows' => array_map(fn (object $definition): array => $this->row($definition), $result['rows'])]);
    }

    public function getRow(int $definitionId): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->definition($definitionId)));
    }

    public function getView(int $definitionId = NEW_ENTRY): string
    {
        $accounts = [0 => lang('Tax_definitions.account_default')];

        foreach ($this->definitions->accountOptions() as $account) {
            $accounts[(int) $account->account_id] = esc($account->code . ' - ' . $account->name);
        }

        return view('tax_definitions/form', [
            'definition'  => $definitionId === NEW_ENTRY ? null : $this->definition($definitionId),
            'modeOptions' => [
                TaxDefinitions::DEFAULT  => lang('Tax_definitions.mode_default'),
                TaxDefinitions::INCLUDED => lang('Tax_definitions.mode_included'),
                TaxDefinitions::EXCLUDED => lang('Tax_definitions.mode_excluded'),
            ],
            'accountOptions'   => $accounts,
            'canAssignAccount' => $this->definitions->mayAssign($this->employeeId()),
        ]);
    }

    public function postSave(int $definitionId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($definitionId): array {
            if ($definitionId !== NEW_ENTRY) {
                $this->definition($definitionId);
            }

            $input = ['tax_name' => (string) $this->request->getPost('tax_name')];

            foreach (['tax_mode', 'account_id'] as $field) {
                if ($this->request->getPost($field) !== null) {
                    $input[$field] = $this->request->getPost($field);
                }
            }

            $id = $this->definitions->save($input, $definitionId === NEW_ENTRY ? null : $definitionId, $this->employeeId());

            return ['success' => true, 'message' => lang($definitionId === NEW_ENTRY ? 'Tax_definitions.successful_adding' : 'Tax_definitions.successful_updating'), 'id' => $id];
        });
    }

    public function postDelete(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $ids = array_map('intval', (array) $this->request->getPost('ids'));

            $this->definitions->delete($ids, $this->employeeId());

            return ['success' => true, 'message' => lang('Tax_definitions.successful_deleted', [count($ids)]), 'ids' => $ids];
        });
    }

    private function definition(int $definitionId): object
    {
        $definition = $this->definitions->find($definitionId);

        if ($definition === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $definition;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['tax_definition_id' => lang('Tax_definitions.id'), 'sortable' => true],
            ['tax_name' => lang('Tax_definitions.name'), 'sortable' => true],
            ['tax_mode' => lang('Tax_definitions.mode'), 'sortable' => true],
            ['account'  => lang('Tax_definitions.account'), 'sortable' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $definition): array
    {
        $id   = (int) $definition->tax_definition_id;
        $mode = $definition->tax_included === null ? 'mode_default' : ((int) $definition->tax_included === 1 ? 'mode_included' : 'mode_excluded');

        return [
            'tax_definition_id' => $id,
            'tax_name'          => esc($definition->tax_name),
            'tax_mode'          => lang('Tax_definitions.' . $mode),
            'account'           => $definition->account_code === null ? lang('Tax_definitions.account_default') : esc($definition->account_code . ' - ' . $definition->account_name),
            'edit'              => anchor("tax_definitions/view/{$id}", '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Tax_definitions.update')]),
        ];
    }
}
