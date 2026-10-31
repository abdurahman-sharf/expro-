<?php

namespace App\Controllers;

use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\RevenueCategories;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The receipt vouchers: money received into a treasury that is not a sale, with the tax in it. A
 * person limited to some branches sees and changes the vouchers of those branches only.
 */
class Revenues extends AccountingController
{
    private const MAX_PAGE_SIZE = 500;

    private RevenueVouchers $vouchers;
    private RevenueCategories $categories;

    public function __construct()
    {
        parent::__construct('revenues');

        $this->vouchers   = new RevenueVouchers(null, $this->branchGuard());
        $this->categories = new RevenueCategories(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        return view('revenues/manage', [
            'table_headers' => transform_headers($this->headers(), false, true),
            'categories'    => $this->categories->all(),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $result = $this->vouchers->search(
            $this->branchIds(),
            [
                'search'      => trim((string) $this->request->getGet('search')),
                'date_from'   => (string) $this->request->getGet('start_date'),
                'date_to'     => (string) $this->request->getGet('end_date'),
                'category_id' => (int) $this->request->getGet('category_id'),
                'deleted'     => $this->request->getGet('deleted') === '1',
            ],
            (string) $this->request->getGet('sort'),
            (string) $this->request->getGet('order'),
            $limit,
            max(0, (int) $this->request->getGet('offset')),
        );

        return $this->response->setJSON(['total' => $result['total'], 'rows' => array_map(fn (object $voucher): array => $this->row($voucher), $result['rows'])]);
    }

    public function getRow(int $revenueId): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->visible($revenueId, true)));
    }

    public function getView(int $revenueId = NEW_ENTRY): string
    {
        $voucher    = $revenueId === NEW_ENTRY ? null : $this->visible($revenueId);
        $numbering  = new DocumentNumbering();
        $treasuries = new TreasuryService(null, null, $this->branchGuard());
        $usable     = (new TreasuryScopes(null, $this->branchGuard()))->usable($treasuries->all($this->branchIds(), false), TreasuryScopes::DEPOSIT, $this->employeeId());

        return view('revenues/form', [
            'voucher'         => $voucher,
            'number'          => $voucher === null ? $numbering->peek(DocumentType::Revenue) : $numbering->label(DocumentType::Revenue, 'revenues', $revenueId),
            'categories'      => $this->categories->all(),
            'accounts'        => $this->categories->accountOptions(),
            'treasuries'      => $usable,
            'payment_options' => $this->paymentOptions(),
            'today'           => date('Y-m-d'),
        ]);
    }

    public function postSave(int $revenueId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($revenueId): array {
            if ($revenueId !== NEW_ENTRY) {
                $this->visible($revenueId);
            }

            $input = [
                'date'         => trim((string) $this->request->getPost('date')),
                'amount'       => trim((string) $this->request->getPost('amount')),
                'tax_amount'   => trim((string) $this->request->getPost('tax_amount')),
                'payment_type' => (string) $this->request->getPost('payment_type'),
                'category_id'  => (int) $this->request->getPost('category_id'),
                'account_id'   => (int) $this->request->getPost('account_id'),
                'description'  => (string) $this->request->getPost('description'),
            ];

            if ($this->request->getPost('treasury_id') !== null) {
                $input['treasury_id'] = (int) $this->request->getPost('treasury_id');
            }

            $id = $this->vouchers->save($input, $revenueId === NEW_ENTRY ? null : $revenueId, $this->employeeId());

            return ['success' => true, 'message' => lang($revenueId === NEW_ENTRY ? 'Revenues.successful_adding' : 'Revenues.successful_updating'), 'id' => $id];
        });
    }

    public function postDelete(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $ids = array_map('intval', (array) $this->request->getPost('ids'));

            foreach ($ids as $id) {
                $this->visible($id);
            }

            $this->vouchers->delete($ids, $this->employeeId());

            return ['success' => true, 'message' => lang('Revenues.successful_deleted', [count($ids)]), 'ids' => $ids];
        });
    }

    /**
     * A voucher of a branch the person may see, or a page that is not there.
     */
    private function visible(int $revenueId, bool $withDeleted = false): object
    {
        $voucher = $this->vouchers->find($revenueId);

        if ($voucher === null || (! $withDeleted && (int) $voucher->deleted === 1) || ! $this->branchGuard()->canAccess((int) $voucher->branch_id, $this->employeeId())) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $voucher;
    }

    /**
     * The ways a voucher can be received: the ones that go into a treasury.
     *
     * @return array<string, string>
     */
    private function paymentOptions(): array
    {
        helper('locale');

        return array_filter(get_payment_options(), static fn (string $label): bool => PaymentType::fromLabel($label)?->usesTreasury() === true, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['revenue_number' => lang('Revenues.number'), 'sortable' => false, 'escape' => false],
            ['date'           => lang('Revenues.date'), 'sortable' => true],
            ['category_name'  => lang('Revenues.category'), 'sortable' => true],
            ['treasury_name'  => lang('Revenues.treasury'), 'sortable' => true],
            ['payment_type'   => lang('Revenues.payment_type'), 'sortable' => false],
            ['amount'         => lang('Revenues.amount'), 'sortable' => true, 'escape' => false],
            ['tax_amount'     => lang('Revenues.tax_amount'), 'sortable' => false, 'escape' => false],
            ['employee_name'  => lang('Revenues.employee'), 'sortable' => false],
            ['description'    => lang('Revenues.description'), 'sortable' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $voucher): array
    {
        $id = (int) $voucher->revenue_id;

        return [
            'revenue_id'     => $id,
            'revenue_number' => '<bdi dir="ltr">' . esc((string) ($voucher->document_number ?? (new DocumentNumbering())->label(DocumentType::Revenue, 'revenues', $id))) . '</bdi>',
            'date'           => $this->formatDate($voucher->date),
            'category_name'  => $voucher->category_name,
            'treasury_name'  => $voucher->treasury_name,
            'payment_type'   => $voucher->payment_type,
            'amount'         => $this->amountCell($this->formatAmount((string) $voucher->amount)),
            'tax_amount'     => $this->amountCell($this->formatAmount((string) $voucher->tax_amount)),
            'employee_name'  => $voucher->employee_name,
            'description'    => $voucher->description,
            'edit'           => (int) $voucher->deleted === 1 ? '' : anchor("revenues/view/{$id}", '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Revenues.update')]),
        ];
    }
}
