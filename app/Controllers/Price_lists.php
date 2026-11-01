<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Models\Price_list;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class Price_lists extends Secure_Controller
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE     = 500;

    private Price_list $priceList;

    public function __construct()
    {
        parent::__construct('price_lists');

        $this->priceList = model(Price_list::class);
    }

    public function getIndex(): string
    {
        return view('price_lists/manage', ['tableHeaders' => transform_headers($this->headers(), false, true)]);
    }

    public function getSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $result = $this->priceList->search(trim((string) $this->request->getGet('search')), (string) $this->request->getGet('sort'), (string) $this->request->getGet('order'), $limit, max(0, (int) $this->request->getGet('offset')));

        return $this->response->setJSON(['total' => $result['total'], 'rows' => array_map(fn (object $list): array => $this->row($list), $result['rows'])]);
    }

    public function getRow(int $priceListId): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->list($priceListId)));
    }

    public function getView(int $priceListId = NEW_ENTRY): string
    {
        return view('price_lists/form', ['priceList' => $priceListId === NEW_ENTRY ? null : $this->list($priceListId)]);
    }

    public function postSave(int $priceListId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($priceListId): array {
            if ($priceListId !== NEW_ENTRY) {
                $this->list($priceListId);
            }

            $id = $this->priceList->saveList((string) $this->request->getPost('name'), (string) $this->request->getPost('status', FILTER_SANITIZE_NUMBER_INT) !== '0', $priceListId === NEW_ENTRY ? null : $priceListId, $this->employeeId());

            return ['success' => true, 'message' => lang($priceListId === NEW_ENTRY ? 'Price_lists.successful_adding' : 'Price_lists.successful_updating'), 'id' => $id];
        });
    }

    public function postStatus(int $priceListId, int $active): ResponseInterface
    {
        return $this->attempt(function () use ($priceListId, $active): array {
            $this->list($priceListId);
            $this->priceList->setStatus($priceListId, $active === 1, $this->employeeId());

            return ['success' => true, 'message' => lang($active === 1 ? 'Price_lists.successful_activated' : 'Price_lists.successful_deactivated'), 'id' => $priceListId];
        });
    }

    public function postDelete(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $ids = array_map('intval', (array) $this->request->getPost('ids'));

            $this->priceList->deleteLists($ids, $this->employeeId());

            return ['success' => true, 'message' => lang('Price_lists.successful_deleted', [count($ids)]), 'ids' => $ids];
        });
    }

    private function employeeId(): int
    {
        return (int) $this->global_view_data['user_info']->person_id;
    }

    /**
     * @param callable(): array<string, mixed> $action Returns the JSON body of a successful call.
     */
    private function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (AccountingException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($exception), 'id' => NEW_ENTRY]);
        }
    }

    private function list(int $priceListId): object
    {
        $list = $this->priceList->getList($priceListId);

        if ($list === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['price_list_id' => lang('Price_lists.id'), 'sortable' => true],
            ['name'      => lang('Price_lists.name'), 'sortable' => true],
            ['status'    => lang('Price_lists.status'), 'sortable' => true, 'escape' => false],
            ['customers' => lang('Price_lists.customers'), 'sortable' => true],
            ['items'     => lang('Price_lists.items'), 'sortable' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $list): array
    {
        $id     = (int) $list->price_list_id;
        $active = (int) $list->status === 1;
        $toggle = '<button type="button" class="btn btn-link btn-sm p-0 price-list-status" data-id="' . $id . '" data-active="' . ($active ? 0 : 1) . '" title="' . esc(lang($active ? 'Price_lists.deactivate' : 'Price_lists.activate'), 'attr') . '"><span class="bi ' . ($active ? 'bi-toggle-on' : 'bi-toggle-off') . '" aria-hidden="true"></span></button>';

        return [
            'price_list_id' => $id,
            'name'          => $list->name,
            'status'        => '<span class="badge ' . ($active ? 'text-bg-success' : 'text-bg-secondary') . '">' . esc(lang($active ? 'Price_lists.active' : 'Price_lists.inactive')) . '</span> ' . $toggle,
            'customers'     => (int) $list->customers,
            'items'         => (int) $list->items,
            'edit'          => anchor("price_lists/view/{$id}", '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Price_lists.update')]),
        ];
    }
}
