<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockRequest;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The stock requests between locations: the list of those of the branches the employee works in, the
 * form that asks, the request as a document with the steps that approve, fulfil and cancel it. A request
 * posts nothing: fulfilling it makes the transfer permit. Every change is a POST that the permit's rules
 * may refuse in words. A person limited to some branches sees and changes only the requests of those
 * branches, the ones that ask them for stock included.
 */
class StockRequests extends StockDocumentController
{
    private StockRequest $requests;

    public function __construct()
    {
        parent::__construct();

        $this->requests = new StockRequest(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $filters  = $this->filters();
        $result   = $this->requests->search($filters, $this->employeeId(), self::PAGE_SIZE, $this->offset());
        $settings = $this->stockSettings();

        return view('stock_requests/manage', [
            'rows'        => $result['rows'],
            'total'       => $result['total'],
            'offset'      => $this->offset(),
            'page_size'   => self::PAGE_SIZE,
            'filters'     => $filters,
            'enabled'     => $settings->requestsEnabled(),
            'can_manage'  => $this->can(StockPermissions::REQUESTS_MANAGE),
            'scopeNotice' => $this->scopeNoticeNeeded(),
            'active'      => 'requests',
            'requestsOn'  => true,
        ]);
    }

    public function getForm(): ResponseInterface|string
    {
        if (! $this->can(StockPermissions::REQUESTS_MANAGE) || ! $this->stockSettings()->requestsEnabled()) {
            return $this->forbidden();
        }

        return view('stock_requests/form', [
            'from'   => $this->everyLocation(),
            'to'     => $this->ownLocations(),
            'number' => $this->requests->peekNumber(),
            'today'  => date('Y-m-d'),
        ]);
    }

    public function getDocument(int $requestId): ResponseInterface|string
    {
        $request = $this->visible($requestId);

        if ($request === null) {
            return $this->forbidden();
        }

        return view('stock_requests/view', [
            'stock_request' => $request,
            'lines'         => $this->requests->lines($requestId),
            'number'        => $this->requests->numberOf($requestId),
            'enabled'       => $this->stockSettings()->requestsEnabled(),
            'can_manage'    => $this->can(StockPermissions::REQUESTS_MANAGE),
            'can_approve'   => $this->can(StockPermissions::REQUESTS_APPROVE),
            'active'        => 'requests',
            'requestsOn'    => true,
        ]);
    }

    public function getCancelForm(int $requestId): ResponseInterface|string
    {
        $request = $this->visible($requestId);

        if ($request === null || ! in_array($request->status, ['requested', 'approved'], true) || ! ($this->can(StockPermissions::REQUESTS_MANAGE) || $this->can(StockPermissions::REQUESTS_APPROVE))) {
            return $this->forbidden();
        }

        return view('stock_requests/cancel_form', ['request_id' => $requestId, 'number' => $this->requests->numberOf($requestId)]);
    }

    public function postSaveRequest(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $id = $this->requests->create([
                'from_location_id' => (int) $this->request->getPost('from_location_id'),
                'to_location_id'   => (int) $this->request->getPost('to_location_id'),
                'date'             => trim((string) $this->request->getPost('request_date')),
                'note'             => (string) $this->request->getPost('note'),
                'lines'            => $this->postedLines(),
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.request_saved'), 'id' => $id];
        });
    }

    public function postApprove(int $requestId): ResponseInterface
    {
        return $this->attempt(function () use ($requestId): array {
            $this->assertVisible($requestId);

            $this->requests->approve($requestId, $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.request_approved'), 'id' => $requestId];
        });
    }

    public function postFulfil(int $requestId): ResponseInterface
    {
        return $this->attempt(function () use ($requestId): array {
            $this->assertVisible($requestId);

            $permitId = $this->requests->fulfil($requestId, $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.request_fulfilled'), 'id' => $requestId, 'permit_id' => $permitId];
        });
    }

    public function postCancel(int $requestId): ResponseInterface
    {
        return $this->attempt(function () use ($requestId): array {
            $this->assertVisible($requestId);

            $this->requests->cancel($requestId, (string) $this->request->getPost('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.request_cancelled'), 'id' => $requestId];
        });
    }

    private function visible(int $requestId): ?object
    {
        $request = $this->requests->find($requestId);

        if ($request === null) {
            return null;
        }

        $guard = $this->branchGuard();

        return $guard->canAccess((int) $request->branch_id, $this->employeeId()) || $guard->canAccess((int) $request->from_branch_id, $this->employeeId()) ? $request : null;
    }

    /**
     * @throws AccountingException
     */
    private function assertVisible(int $requestId): void
    {
        if ($this->visible($requestId) === null) {
            throw new AccountingException(AccountingError::StockRequestNotFound, "Stock request {$requestId} does not exist.", ['request_id' => $requestId]);
        }
    }

    /**
     * @return array{status: string, search: string, from: string, to: string}
     */
    private function filters(): array
    {
        $status = (string) $this->request->getGet('status');

        return [
            'status' => in_array($status, ['requested', 'approved', 'fulfilled', 'cancelled'], true) ? $status : '',
            'search' => trim((string) $this->request->getGet('search')),
            'from'   => $this->dateOf('from'),
            'to'     => $this->dateOf('to'),
        ];
    }
}
