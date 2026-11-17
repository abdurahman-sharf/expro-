<?php

namespace App\Controllers;

use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ScopedReportRequest;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Purchases\PurchaseInvoiceFilters;
use App\Libraries\Purchases\PurchaseInvoiceList;
use App\Libraries\Purchases\PurchaseInvoiceRow;
use App\Libraries\Purchases\PurchaseInvoiceRows;
use App\Libraries\Purchases\PurchaseSums;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The purchase invoices and the returns to suppliers as a list: filtered by branch, supplier, status of the
 * purchase, status of the payment and day, a page at a time, with the options of each document, the totals of
 * what the filters keep, and the same rows as a file. It is open to who holds the purchase reports, and it
 * shows the branches the employee works in and, to someone who sees their own records only, the documents
 * they made. It reads: what it offers to do with a document is done by the screens of the purchases.
 */
class Purchases extends Secure_Controller
{
    use ExportsCsv;
    use ScopedReportRequest;

    private const PERMISSION = 'reports_receivings';
    private const PAGE_SIZES = [10, 25, 50, 100];
    private const PAGE_SIZE  = 25;

    private PurchaseInvoiceList $list;
    private ?BranchGuard $branchGuard = null;

    public function __construct()
    {
        parent::__construct('reports');

        $this->list = new PurchaseInvoiceList();
    }

    public function getIndex(): ResponseInterface|string
    {
        if (! $this->allowed()) {
            return redirect()->to('no_access/reports/' . self::PERMISSION);
        }

        $scope   = $this->requestedScope();
        $filters = $this->withDays(PurchaseInvoiceFilters::fromInput($this->request->getGet()));

        return view('purchases/manage', [
            'page_title'     => lang('Purchases.title'),
            'editable'       => 'receivings',
            'table_headers'  => transform_headers(PurchaseInvoiceRows::headers($scope->choosable()), true, false),
            'branches'       => $this->branchChoices($scope),
            'selected'       => $scope->ids,
            'scope_label'    => $this->scopeNote($scope),
            'filters'        => $filters,
            'supplier_label' => $filters->supplierId > 0 ? $this->supplierLabel($scope, $filters->supplierId) : '',
            'legacy_report'  => ! $this->ownRecordsOnly(),
            'statuses'       => $this->statuses(),
            'payments'       => $this->payments(),
            'page_sizes'     => self::PAGE_SIZES,
            'page_size'      => self::PAGE_SIZE,
            'can_receive'    => $this->employee->has_module_grant('receivings', $this->personId()),
            'employee_id'    => $this->personId(),
            'csv_query'      => $this->carried($filters, $scope),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        if (! $this->allowed()) {
            return $this->denied();
        }

        $scope = $this->requestedScope();
        $page  = $this->list->search(
            $this->branchesOf($scope),
            PurchaseInvoiceFilters::fromInput($this->request->getGet()),
            $this->query('sort'),
            $this->query('order'),
            max(1, min((int) $this->query('limit') ?: self::PAGE_SIZE, PurchaseInvoiceList::MAX_PAGE_SIZE)),
            max(0, (int) $this->query('offset')),
            $this->personId(),
        );

        if ($page->refusal !== null) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setJSON(['success' => false, 'message' => lang($page->refusal)]);
        }

        $canReceive = $this->employee->has_module_grant('receivings', $this->personId());

        return $this->response->setJSON([
            'total' => $page->total,
            'rows'  => array_map(static fn (PurchaseInvoiceRow $row): array => PurchaseInvoiceRows::dataRow($row, $canReceive), $page->rows),
        ]);
    }

    public function getSums(): ResponseInterface
    {
        if (! $this->allowed()) {
            return $this->denied();
        }

        $sums = $this->list->sums($this->branchesOf($this->requestedScope()), PurchaseInvoiceFilters::fromInput($this->request->getGet()), $this->personId());

        return $this->response->setJSON(['success' => $sums->refusal === null, 'message' => $sums->refusal === null ? '' : lang($sums->refusal), 'html' => $sums->refusal === null ? $this->sumsHtml($sums) : '']);
    }

    public function getRow(int $receivingId): ResponseInterface
    {
        if (! $this->allowed()) {
            return $this->denied();
        }

        $row = $this->list->row($this->branchesOf($this->requestedScope()), $receivingId, $this->personId());

        if ($row === null) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_NOT_FOUND)->setJSON(['success' => false, 'message' => lang('Purchases.not_found')]);
        }

        return $this->response->setJSON(PurchaseInvoiceRows::dataRow($row, $this->employee->has_module_grant('receivings', $this->personId())));
    }

    public function getSuppliers(): ResponseInterface
    {
        if (! $this->allowed()) {
            return $this->denied();
        }

        return $this->response->setJSON($this->list->suppliers($this->branchesOf($this->requestedScope()), $this->query('term'), $this->personId()));
    }

    public function getCsv(): ResponseInterface
    {
        if (! $this->allowed()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $scope   = $this->requestedScope();
        $filters = PurchaseInvoiceFilters::fromInput($this->request->getGet());
        $rows    = $this->list->all($this->branchesOf($scope), $filters, $this->query('sort'), $this->query('order'), $this->personId());

        if ($rows === null) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setContentType('text/plain', 'UTF-8')->setBody(lang('Purchases.export_too_large'));
        }

        $withBranch = $scope->choosable();
        $handle     = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [lang('Purchases.title')]);
        $this->csvRow($handle, [$this->scopeNote($scope) ?? lang('Purchases.all_purchases')]);
        $this->csvRow($handle, PurchaseInvoiceRows::csvHeader($withBranch));

        foreach ($rows as $row) {
            $this->csvRow($handle, PurchaseInvoiceRows::csvRow($row, $withBranch));
        }

        foreach (PurchaseSums::of($rows)->byCurrency as $currency => $sum) {
            $decimals = $sum['decimals'] ?? 2;

            $this->csvRow($handle, [lang('Purchases.sums_net') . ($currency === '' ? '' : ' (' . $currency . ')'), number_format((float) $sum['net'], $decimals, '.', ''), lang('Purchases.sums_paid'), number_format((float) $sum['paid'], $decimals, '.', ''), lang('Purchases.sums_due'), number_format((float) $sum['due'], $decimals, '.', '')]);
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="purchase_invoices_' . date('Y-m-d') . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->csvText($handle));
    }

    /**
     * @return list<int>|null Null means every branch.
     */
    protected function branchIds(): ?array
    {
        return $this->branchGuard()->allowedBranchIds($this->personId());
    }

    private function branchGuard(): BranchGuard
    {
        return $this->branchGuard ??= new BranchGuard(null, $this->employee);
    }

    /**
     * @return list<int>|null
     */
    private function branchesOf(ReportScope $scope): ?array
    {
        return $scope->isCompany() ? null : $scope->ids;
    }

    private function personId(): int
    {
        return (int) $this->global_view_data['user_info']->person_id;
    }

    private function allowed(): bool
    {
        return $this->employee->has_grant(self::PERMISSION, $this->personId());
    }

    private function denied(): ResponseInterface
    {
        return $this->response->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)->setJSON(['success' => false, 'message' => lang('Purchases.not_authorized')]);
    }

    private function supplierLabel(ReportScope $scope, int $supplierId): string
    {
        $found = $this->list->suppliers($this->branchesOf($scope), '', $this->personId(), $supplierId);

        return $found === [] ? '#' . $supplierId : $found[0]['name'];
    }

    private function ownRecordsOnly(): bool
    {
        return (new RecordScope())->ownOnly($this->personId());
    }

    private function scopeNote(ReportScope $scope): ?string
    {
        return $this->ownRecordsOnly() ? lang('Purchases.scope_own_records') : $this->scopeLabel($scope);
    }

    private function withDays(PurchaseInvoiceFilters $filters): PurchaseInvoiceFilters
    {
        if ($filters->dateFrom !== null && $filters->dateTo !== null) {
            return $filters;
        }

        $to   = $filters->dateTo ?? max(date('Y-m-d'), (string) $filters->dateFrom);
        $from = $filters->dateFrom ?? substr($to, 0, 8) . '01';

        return new PurchaseInvoiceFilters($from, $to, $filters->supplierId, $filters->status, $filters->payment, $filters->search);
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return [
            ''                => lang('Purchases.all_purchase_statuses'),
            'received'        => lang('Purchases.status_received'),
            'return'          => lang('Purchases.status_return'),
            'has_returns'     => lang('Purchases.status_has_returns'),
            'partly_returned' => lang('Purchases.status_partly_returned'),
            'fully_returned'  => lang('Purchases.status_fully_returned'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function payments(): array
    {
        return [
            ''        => lang('Purchases.all_payment_statuses'),
            'unpaid'  => lang('Sales.invoice_unpaid'),
            'partial' => lang('Sales.invoice_partial'),
            'paid'    => lang('Sales.invoice_paid'),
            'overdue' => lang('Purchases.payment_overdue'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function carried(PurchaseInvoiceFilters $filters, ReportScope $scope): array
    {
        $query = array_filter([
            'date_from'   => $filters->dateFrom,
            'date_to'     => $filters->dateTo,
            'supplier_id' => $filters->supplierId > 0 ? (string) $filters->supplierId : null,
            'status'      => $filters->status !== '' ? $filters->status : null,
            'payment'     => $filters->payment !== '' ? $filters->payment : null,
        ], static fn (?string $value): bool => $value !== null);

        $branches = $this->branchesToCarry($scope);

        return $branches === null ? $query : $query + ['branches' => $branches];
    }

    private function sumsHtml(PurchaseSums $sums): string
    {
        $html = '';

        foreach ($sums->byCurrency as $currency => $sum) {
            $label = $currency === '' ? '' : '<span class="badge text-bg-secondary">' . esc(lang('Purchases.sums_foreign', [$currency])) . '</span> ';
            $html .= '<p class="purchase-sums">' . $label
                . esc(lang('Purchases.sums_documents')) . ' <bdi dir="ltr">' . (int) $sum['count'] . '</bdi> · '
                . esc(lang('Purchases.sums_net')) . ' <bdi dir="ltr">' . esc($this->sumText($sum['net'], $sum['decimals'])) . '</bdi> · '
                . esc(lang('Purchases.sums_returns')) . ' <bdi dir="ltr">' . esc($this->sumText($sum['returns'], $sum['decimals'])) . '</bdi> · '
                . esc(lang('Purchases.sums_paid')) . ' <bdi dir="ltr">' . esc($this->sumText($sum['paid'], $sum['decimals'])) . '</bdi> · '
                . esc(lang('Purchases.sums_due')) . ' <bdi dir="ltr">' . esc($this->sumText($sum['due'], $sum['decimals'])) . '</bdi></p>';
        }

        return $html;
    }

    private function sumText(string $amount, ?int $decimals): string
    {
        return $decimals === null ? to_currency_no_money($amount) : number_format((float) $amount, $decimals, '.', ',');
    }
}
