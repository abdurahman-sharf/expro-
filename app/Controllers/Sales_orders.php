<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Organization\BranchException;
use App\Libraries\Sale_lib;
use App\Libraries\SalesOrders\SalesOrders;
use App\Libraries\SalesOrders\SalesOrderSettings;
use App\Libraries\SalesOrders\SalesOrderStatus;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\OSPOS;

class Sales_orders extends DocumentScreen
{
    private const LOOKUP_LIMIT = 20;

    private SalesOrders $orders;

    public function __construct()
    {
        parent::__construct('sales_orders');

        $this->orders = new SalesOrders(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $filters = $this->filters();
        $offset  = max(0, (int) $this->request->getGet('offset'));
        $enabled = $this->orders->isEnabled();
        $result  = $enabled ? $this->orders->search($filters, $this->employeeId(), self::PAGE_SIZE, $offset) : ['total' => 0, 'rows' => []];

        return view('sales_orders/manage', [
            'rows'         => $result['rows'],
            'total'        => $result['total'],
            'offset'       => $offset,
            'page_size'    => self::PAGE_SIZE,
            'filters'      => $filters,
            'enabled'      => $enabled,
            'allow_excess' => $this->orders->allowsExcess(),
            'can_add'      => $enabled && $this->can(SalesOrders::ADD),
            'can_settings' => $this->can(SalesOrderSettings::PERMISSION),
            'statuses'     => SalesOrderStatus::cases(),
        ]);
    }

    public function getForm(int $orderId = NEW_ENTRY): RedirectResponse|string
    {
        if (! $this->orders->isEnabled()) {
            return redirect()->to(site_url('sales'));
        }

        $order = null;
        $lines = [];

        if ($orderId !== NEW_ENTRY) {
            $order = $this->orders->find($orderId, $this->employeeId());

            if ($order === null || ! $order->status_object->isEditable() || ! $this->can(SalesOrders::EDIT)) {
                throw PageNotFoundException::forPageNotFound();
            }

            $lines = $this->orders->lines($orderId);
        } elseif (! $this->can(SalesOrders::ADD)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('sales_orders/form', [
            'order'     => $order,
            'lines'     => $lines,
            'locations' => $this->locationOptions(),
            'number'    => $order === null ? $this->orders->peekNumber() : $this->orders->numberOf($orderId),
            'today'     => date('Y-m-d'),
        ]);
    }

    public function getView(int $orderId): string
    {
        $order = $this->orders->isEnabled() ? $this->orders->find($orderId, $this->employeeId()) : null;

        if ($order === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $status   = $order->status_object;
        $invoices = $this->orders->invoicesOf($orderId);

        return view('sales_orders/view', [
            'order'       => $order,
            'status'      => $status,
            'number'      => $this->orders->numberOf($orderId),
            'lines'       => $this->orders->lines($orderId),
            'invoices'    => $invoices,
            'can_edit'    => $this->can(SalesOrders::EDIT) && $status->isEditable() && $invoices === [],
            'can_status'  => $this->can(SalesOrders::EDIT) && $status->manualNext() !== [],
            'can_cancel'  => $this->can(SalesOrders::DELETE) && $status->canCancel(),
            'can_convert' => $this->can(SalesOrders::CONVERT) && $status->canConvert(),
            'next'        => $status->manualNext(),
        ]);
    }

    public function getCustomers(): ResponseInterface
    {
        if (! $this->can(SalesOrders::ADD) && ! $this->can(SalesOrders::EDIT)) {
            return $this->forbidden();
        }

        $builder = Database::connect()->table('customers c')
            ->select("c.person_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)
            ->join('people p', 'p.person_id = c.person_id')
            ->where('c.deleted', 0);

        $term = trim((string) $this->request->getGet('term'));

        if ($term !== '') {
            $builder->groupStart()->like('p.first_name', $term)->orLike('p.last_name', $term)->orLike("CONCAT(p.first_name, ' ', p.last_name)", $term)->groupEnd();
        }

        $rows = $builder->orderBy('p.first_name', 'ASC')->limit(self::LOOKUP_LIMIT)->get()->getResult();

        return $this->response->setJSON(array_map(static fn (object $row): array => ['value' => (int) $row->person_id, 'label' => $row->name], $rows));
    }

    public function getItems(): ResponseInterface
    {
        if (! $this->can(SalesOrders::ADD) && ! $this->can(SalesOrders::EDIT)) {
            return $this->forbidden();
        }

        $builder = Database::connect()->table('items')->select('item_id, name, item_number, unit_price')->where('deleted', 0);
        $term    = trim((string) $this->request->getGet('term'));

        if ($term !== '') {
            $builder->groupStart()->like('name', $term)->orLike('item_number', $term)->groupEnd();
        }

        $rows = $builder->orderBy('name', 'ASC')->limit(self::LOOKUP_LIMIT)->get()->getResult();

        return $this->response->setJSON(array_map(static fn (object $row): array => ['value' => (int) $row->item_id, 'label' => $row->name . ($row->item_number === null || $row->item_number === '' ? '' : ' (' . $row->item_number . ')'), 'price' => (string) $row->unit_price], $rows));
    }

    public function postSave(int $orderId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($orderId): array {
            $input = [
                'customer_id' => (int) $this->request->getPost('customer_id'),
                'location_id' => (int) $this->request->getPost('location_id'),
                'order_date'  => $this->postText('order_date'),
                'note'        => $this->postText('note'),
                'lines'       => $this->request->getPost('lines'),
            ];

            if ($orderId === NEW_ENTRY) {
                $orderId = $this->orders->create($input, $this->employeeId());

                return ['success' => true, 'message' => lang('Sales_orders.successful_adding'), 'id' => $orderId, 'redirect' => site_url('sales_orders/view/' . $orderId)];
            }

            $this->orders->update($orderId, $input, $this->employeeId());

            return ['success' => true, 'message' => lang('Sales_orders.successful_updating'), 'id' => $orderId, 'redirect' => site_url('sales_orders/view/' . $orderId)];
        });
    }

    public function postStatus(int $orderId): ResponseInterface
    {
        return $this->attempt(function () use ($orderId): array {
            $to = SalesOrderStatus::tryFrom($this->postText('status'));

            if ($to === null || ! $to->isStored()) {
                throw new AccountingException(AccountingError::SalesOrderStatusNotAllowed, 'The status is not one that is set by hand.', ['from' => '', 'to' => $this->postText('status')]);
            }

            $this->orders->changeStatus($orderId, $to, $this->employeeId());

            return ['success' => true, 'message' => lang('Sales_orders.status_changed'), 'id' => $orderId];
        });
    }

    public function postCancel(int $orderId): ResponseInterface
    {
        return $this->attempt(function () use ($orderId): array {
            $this->orders->cancel($orderId, $this->postText('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Sales_orders.successful_cancelling'), 'id' => $orderId];
        });
    }

    public function postConvert(int $orderId): ResponseInterface
    {
        try {
            $order = $this->orders->assertConvertible($orderId, $this->employeeId());
        } catch (AccountingException|BranchException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal), 'id' => $orderId]);
        }

        if (! config(OSPOS::class)->settings['invoice_enable']) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Sales_orders.invoice_unavailable'), 'id' => $orderId]);
        }

        (new Sale_lib())->prepareInvoiceFromOrder($orderId, (int) $order->customer_id, $this->orders->remainingLines($orderId));

        return $this->response->setJSON(['success' => true, 'message' => lang('Sales_orders.convert_prepared'), 'id' => $orderId, 'redirect' => site_url('sales')]);
    }

    public function postSettings(): ResponseInterface
    {
        return $this->attempt(function (): array {
            (new SalesOrderSettings())->update([
                'enabled'      => $this->request->getPost('enabled') === '1',
                'allow_excess' => $this->request->getPost('allow_excess') === '1',
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Sales_orders.settings_saved'), 'id' => NEW_ENTRY];
        });
    }

    /**
     * @return array{status: string, search: string, from: string, to: string}
     */
    private function filters(): array
    {
        $status = (string) $this->request->getGet('status');

        return [
            'status' => SalesOrderStatus::tryFrom($status) !== null ? $status : '',
            'search' => trim((string) $this->request->getGet('search')),
            'from'   => $this->dateOf('from'),
            'to'     => $this->dateOf('to'),
        ];
    }
}
