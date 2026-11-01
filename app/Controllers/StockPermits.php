<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\StockPermitType;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * The numbered stock permits: the list of those of the branches the employee works in, the forms that
 * make and correct an add, an issue or a transfer permit, the permit as a printable document, and the
 * cancelling of a permit with a reason. Every change is a POST that the books or the shelves may
 * refuse in words. A person limited to some branches sees and changes only the permits of those branches.
 * The two settings of the stock documents are kept on this screen, for whoever may change the settings.
 */
class StockPermits extends StockDocumentController
{
    private StockPermit $permits;

    public function __construct()
    {
        parent::__construct();

        $this->permits = new StockPermit(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $filters  = $this->filters();
        $result   = $this->permits->search($filters, $this->employeeId(), self::PAGE_SIZE, $this->offset());
        $settings = $this->stockSettings()->all();

        return view('stock_permits/manage', [
            'rows'         => $result['rows'],
            'total'        => $result['total'],
            'offset'       => $this->offset(),
            'page_size'    => self::PAGE_SIZE,
            'filters'      => $filters,
            'can_manage'   => $this->can(StockPermissions::PERMITS_MANAGE),
            'can_settings' => $this->can(AccountingGuard::SETTINGS_MANAGE),
            'settings'     => $settings,
            'scopeNotice'  => $this->scopeNoticeNeeded(),
            'active'       => 'permits',
            'requestsOn'   => $settings['requests'],
        ]);
    }

    public function getForm(string $type): ResponseInterface|string
    {
        $kind = StockPermitType::tryFrom($type);

        if ($kind === null || ! $this->can(StockPermissions::PERMITS_MANAGE) || ($kind === StockPermitType::Transfer && ! $this->stockSettings()->transferPermitEnabled())) {
            return $this->forbidden();
        }

        return view('stock_permits/form', [
            'permit'    => null,
            'lines'     => [],
            'type'      => $kind,
            'locations' => $this->ownLocations(),
            'accounts'  => $this->permits->allowedAccounts($kind),
            'number'    => $this->permits->peekNumber($kind),
            'today'     => date('Y-m-d'),
        ]);
    }

    public function getEditForm(int $permitId): ResponseInterface|string
    {
        $permit = $this->visible($permitId);

        if ($permit === null || $permit->status !== 'posted' || ! $this->can(StockPermissions::PERMITS_MANAGE)) {
            return $this->forbidden();
        }

        $kind = StockPermitType::from((string) $permit->permit_type);

        return view('stock_permits/form', [
            'permit'    => $permit,
            'lines'     => $this->permits->lines($permitId),
            'type'      => $kind,
            'locations' => $this->ownLocations(),
            'accounts'  => $this->permits->allowedAccounts($kind),
            'number'    => $this->permits->numberOf($permitId),
            'today'     => date('Y-m-d'),
        ]);
    }

    public function getDocument(int $permitId): ResponseInterface|string
    {
        $permit = $this->visible($permitId);

        if ($permit === null) {
            return $this->forbidden();
        }

        return view('stock_permits/view', [
            'permit'     => $permit,
            'lines'      => $this->permits->lines($permitId),
            'journals'   => $this->can('accounting_journals') ? $this->permits->journals($permitId) : [],
            'number'     => $this->permits->numberOf($permitId),
            'type'       => StockPermitType::from((string) $permit->permit_type),
            'can_manage' => $this->can(StockPermissions::PERMITS_MANAGE),
        ]);
    }

    public function getCancelForm(int $permitId): ResponseInterface|string
    {
        $permit = $this->visible($permitId);

        if ($permit === null || $permit->status !== 'posted' || ! $this->can(StockPermissions::PERMITS_MANAGE)) {
            return $this->forbidden();
        }

        return view('stock_permits/cancel_form', ['permit_id' => $permitId, 'number' => $this->permits->numberOf($permitId)]);
    }

    public function postSavePermit(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $id = $this->permits->create([
                'type'           => (string) $this->request->getPost('type'),
                'date'           => trim((string) $this->request->getPost('permit_date')),
                'location_id'    => (int) $this->request->getPost('location_id'),
                'to_location_id' => (int) $this->request->getPost('to_location_id'),
                'supplier_id'    => (int) $this->request->getPost('supplier_id'),
                'customer_id'    => (int) $this->request->getPost('customer_id'),
                'account_id'     => (int) $this->request->getPost('account_id'),
                'note'           => (string) $this->request->getPost('note'),
                'lines'          => $this->postedLines(),
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.permit_saved'), 'id' => $id];
        });
    }

    public function postUpdate(int $permitId): ResponseInterface
    {
        return $this->attempt(function () use ($permitId): array {
            $this->assertVisible($permitId);

            $this->permits->update($permitId, [
                'date'        => trim((string) $this->request->getPost('permit_date')),
                'supplier_id' => (int) $this->request->getPost('supplier_id'),
                'customer_id' => (int) $this->request->getPost('customer_id'),
                'account_id'  => (int) $this->request->getPost('account_id'),
                'note'        => (string) $this->request->getPost('note'),
                'lines'       => $this->postedLines(),
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.permit_updated'), 'id' => $permitId];
        });
    }

    public function postCancel(int $permitId): ResponseInterface
    {
        return $this->attempt(function () use ($permitId): array {
            $this->assertVisible($permitId);

            $this->permits->cancel($permitId, (string) $this->request->getPost('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.permit_cancelled'), 'id' => $permitId];
        });
    }

    public function postSettings(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $input = [];

            foreach (['transfer_permit', 'requests'] as $flag) {
                if ($this->request->getPost($flag) !== null) {
                    $input[$flag] = $this->request->getPost($flag) === '1';
                }
            }

            $this->stockSettings()->update($input, $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.settings_saved'), 'id' => 1];
        });
    }

    public function getItems(): ResponseInterface
    {
        return $this->can(StockPermissions::PERMITS_MANAGE) || $this->can(StockPermissions::STOCKTAKE_MANAGE) || $this->can(StockPermissions::REQUESTS_MANAGE) ? $this->itemLookup() : $this->forbidden();
    }

    public function getParties(): ResponseInterface
    {
        if (! $this->can(StockPermissions::PERMITS_MANAGE)) {
            return $this->forbidden();
        }

        $kind  = (string) $this->request->getGet('kind') === 'customer' ? 'customers' : 'suppliers';
        $term  = trim((string) $this->request->getGet('term'));
        $query = Database::connect()->table($kind . ' x')
            ->select("x.person_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name, x.company_name", false)
            ->join('people p', 'p.person_id = x.person_id')
            ->where('x.deleted', 0);

        if ($term !== '') {
            $query->groupStart()->like('p.first_name', $term)->orLike('p.last_name', $term)->orLike('x.company_name', $term)->groupEnd();
        }

        $rows = $query->orderBy('p.first_name', 'ASC')->limit(self::LOOKUP_LIMIT)->get()->getResult();

        return $this->response->setJSON(array_map(static fn (object $row): array => ['value' => (int) $row->person_id, 'label' => trim(($row->company_name ?? '') !== '' ? $row->company_name . ' - ' . $row->name : $row->name)], $rows));
    }

    private function visible(int $permitId): ?object
    {
        $permit = $this->permits->find($permitId);

        if ($permit === null) {
            return null;
        }

        $guard = $this->branchGuard();

        return $guard->canAccess((int) $permit->branch_id, $this->employeeId()) || ($permit->to_branch_id !== null && $guard->canAccess((int) $permit->to_branch_id, $this->employeeId())) ? $permit : null;
    }

    /**
     * @throws AccountingException
     */
    private function assertVisible(int $permitId): void
    {
        if ($this->visible($permitId) === null) {
            throw new AccountingException(AccountingError::StockPermitNotFound, "Stock permit {$permitId} does not exist.", ['permit_id' => $permitId]);
        }
    }

    /**
     * @return array{status: string, type: string, search: string, from: string, to: string}
     */
    private function filters(): array
    {
        $status = (string) $this->request->getGet('status');
        $type   = (string) $this->request->getGet('type');

        return [
            'status' => in_array($status, ['posted', 'cancelled'], true) ? $status : '',
            'type'   => StockPermitType::tryFrom($type) !== null ? $type : '',
            'search' => trim((string) $this->request->getGet('search')),
            'from'   => $this->dateOf('from'),
            'to'     => $this->dateOf('to'),
        ];
    }
}
