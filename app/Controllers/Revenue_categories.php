<?php

namespace App\Controllers;

use App\Libraries\Accounting\RevenueCategories;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The categories of receipt vouchers, each with the account its vouchers are credited to.
 */
class Revenue_categories extends AccountingController
{
    private const MAX_PAGE_SIZE = 500;

    private RevenueCategories $categories;

    public function __construct()
    {
        parent::__construct('revenue_categories');

        $this->categories = new RevenueCategories(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        return view('revenue_categories/manage', ['table_headers' => transform_headers($this->headers(), false, true)]);
    }

    public function getSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $result = $this->categories->search(trim((string) $this->request->getGet('search')), (string) $this->request->getGet('sort'), (string) $this->request->getGet('order'), $limit, max(0, (int) $this->request->getGet('offset')));

        return $this->response->setJSON(['total' => $result['total'], 'rows' => array_map(fn (object $category): array => $this->row($category), $result['rows'])]);
    }

    public function getRow(int $categoryId): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->category($categoryId)));
    }

    public function getView(int $categoryId = NEW_ENTRY): string
    {
        $accounts = [0 => lang('Revenue_categories.account_default')];

        foreach ($this->categories->accountOptions() as $account) {
            $accounts[(int) $account->account_id] = esc($account->code . ' - ' . $account->name);
        }

        return view('revenue_categories/form', [
            'category'           => $categoryId === NEW_ENTRY ? null : $this->category($categoryId),
            'account_options'    => $accounts,
            'can_assign_account' => $this->categories->mayAssign($this->employeeId()),
        ]);
    }

    public function postSave(int $categoryId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($categoryId): array {
            if ($categoryId !== NEW_ENTRY) {
                $this->category($categoryId);
            }

            $input = [
                'category_name'        => (string) $this->request->getPost('category_name'),
                'category_description' => (string) $this->request->getPost('category_description'),
            ];

            if ($this->request->getPost('account_id') !== null) {
                $input['account_id'] = $this->request->getPost('account_id');
            }

            $id = $this->categories->save($input, $categoryId === NEW_ENTRY ? null : $categoryId, $this->employeeId());

            return ['success' => true, 'message' => lang($categoryId === NEW_ENTRY ? 'Revenue_categories.successful_adding' : 'Revenue_categories.successful_updating'), 'id' => $id];
        });
    }

    public function postDelete(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $ids = array_map('intval', (array) $this->request->getPost('ids'));

            $this->categories->delete($ids, $this->employeeId());

            return ['success' => true, 'message' => lang('Revenue_categories.successful_deleted', [count($ids)]), 'ids' => $ids];
        });
    }

    private function category(int $categoryId): object
    {
        $category = $this->categories->find($categoryId);

        if ($category === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $category;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['revenue_category_id' => lang('Revenue_categories.id'), 'sortable' => true],
            ['category_name'        => lang('Revenue_categories.name'), 'sortable' => true],
            ['category_description' => lang('Revenue_categories.description'), 'sortable' => true],
            ['account'              => lang('Revenue_categories.account'), 'sortable' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $category): array
    {
        $id = (int) $category->revenue_category_id;

        return [
            'revenue_category_id'  => $id,
            'category_name'        => $category->category_name,
            'category_description' => $category->category_description,
            'account'              => $category->account_code === null ? lang('Revenue_categories.account_default') : $category->account_code . ' - ' . $category->account_name,
            'edit'                 => anchor("revenue_categories/view/{$id}", '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Revenue_categories.update')]),
        ];
    }
}
