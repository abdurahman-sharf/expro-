<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\Stocktake;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The stocktakes: the list of those of the branches the employee works in, the form that starts a count,
 * the sheet where the counts are entered and the stocktake is approved or cancelled, and the count sheet
 * of the system quantities to print. Every change is a POST that the books or the shelves may refuse in
 * words. A person limited to some branches sees and changes only the stocktakes of those branches.
 */
class Stocktakes extends StockDocumentController
{
    private Stocktake $takes;

    public function __construct()
    {
        parent::__construct();

        $this->takes = new Stocktake(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $filters = $this->filters();
        $result  = $this->takes->search($filters, $this->employeeId(), self::PAGE_SIZE, $this->offset());

        return view('stocktakes/manage', [
            'rows'        => $result['rows'],
            'total'       => $result['total'],
            'offset'      => $this->offset(),
            'page_size'   => self::PAGE_SIZE,
            'filters'     => $filters,
            'locations'   => $this->ownLocations(),
            'can_manage'  => $this->can(StockPermissions::STOCKTAKE_MANAGE),
            'scopeNotice' => $this->scopeNoticeNeeded(),
            'active'      => 'stocktakes',
            'requestsOn'  => $this->stockSettings()->requestsEnabled(),
        ]);
    }

    public function getForm(): ResponseInterface|string
    {
        if (! $this->can(StockPermissions::STOCKTAKE_MANAGE)) {
            return $this->forbidden();
        }

        return view('stocktakes/form', [
            'locations' => $this->ownLocations(),
            'number'    => $this->takes->peekNumber(),
            'today'     => date('Y-m-d'),
        ]);
    }

    public function getDocument(int $stocktakeId): ResponseInterface|string
    {
        $stocktake = $this->visible($stocktakeId);

        if ($stocktake === null) {
            return $this->forbidden();
        }

        return view('stocktakes/sheet', [
            'stocktake'   => $stocktake,
            'lines'       => $this->takes->lines($stocktakeId),
            'journals'    => $this->can('accounting_journals') ? $this->takes->journals($stocktakeId) : [],
            'number'      => $this->takes->numberOf($stocktakeId),
            'digest'      => $this->takes->digest($stocktakeId),
            'canManage'   => $this->can(StockPermissions::STOCKTAKE_MANAGE),
            'canApprove'  => $this->can(StockPermissions::STOCKTAKE_APPROVE),
            'active'      => 'stocktakes',
            'requestsOn'  => $this->stockSettings()->requestsEnabled(),
        ]);
    }

    public function getSheet(int $stocktakeId): ResponseInterface|string
    {
        $stocktake = $this->visible($stocktakeId);

        if ($stocktake === null) {
            return $this->forbidden();
        }

        return view('stocktakes/print', [
            'stocktake' => $stocktake,
            'lines'     => $this->takes->lines($stocktakeId),
            'number'    => $this->takes->numberOf($stocktakeId),
        ]);
    }

    public function getCancelForm(int $stocktakeId): ResponseInterface|string
    {
        $stocktake = $this->visible($stocktakeId);

        if ($stocktake === null || $stocktake->status === 'cancelled' || ! $this->can($stocktake->status === 'approved' ? StockPermissions::STOCKTAKE_APPROVE : StockPermissions::STOCKTAKE_MANAGE)) {
            return $this->forbidden();
        }

        return view('stocktakes/cancel_form', ['stocktake_id' => $stocktakeId, 'number' => $this->takes->numberOf($stocktakeId), 'approved' => $stocktake->status === 'approved']);
    }

    public function postSaveStocktake(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $id = $this->takes->create([
                'location_id' => (int) $this->request->getPost('location_id'),
                'date'        => trim($this->postedText('count_date')),
                'note'        => $this->postedText('note'),
                'all_items'   => $this->request->getPost('all_items') === '1',
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.stocktake_started'), 'id' => $id];
        });
    }

    public function postCount(int $stocktakeId): ResponseInterface
    {
        return $this->attempt(function () use ($stocktakeId): array {
            $this->assertVisible($stocktakeId);

            $counted = $this->request->getPost('counted');
            $seen    = $this->request->getPost('seen');

            $this->takes->count($stocktakeId, is_array($counted) ? $counted : [], $this->employeeId(), is_array($seen) ? $seen : []);

            return ['success' => true, 'message' => lang('Stock_documents.counts_saved'), 'id' => $stocktakeId];
        });
    }

    public function postAddItems(int $stocktakeId): ResponseInterface
    {
        return $this->attempt(function () use ($stocktakeId): array {
            $this->assertVisible($stocktakeId);

            $itemIds = $this->request->getPost('item_ids');

            $this->takes->addItems($stocktakeId, is_array($itemIds) ? $itemIds : [(string) $itemIds], $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.items_added'), 'id' => $stocktakeId];
        });
    }

    public function postRemoveItem(int $stocktakeId): ResponseInterface
    {
        return $this->attempt(function () use ($stocktakeId): array {
            $this->assertVisible($stocktakeId);

            $this->takes->removeItem($stocktakeId, (int) $this->request->getPost('item_id'), $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.item_removed'), 'id' => $stocktakeId];
        });
    }

    public function postApprove(int $stocktakeId): ResponseInterface
    {
        return $this->attempt(function () use ($stocktakeId): array {
            $this->assertVisible($stocktakeId);

            $this->takes->approve($stocktakeId, $this->employeeId(), $this->postedText('digest'));

            return ['success' => true, 'message' => lang('Stock_documents.stocktake_approved'), 'id' => $stocktakeId];
        });
    }

    public function postCancel(int $stocktakeId): ResponseInterface
    {
        return $this->attempt(function () use ($stocktakeId): array {
            $this->assertVisible($stocktakeId);

            $this->takes->cancel($stocktakeId, $this->postedText('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Stock_documents.stocktake_cancelled'), 'id' => $stocktakeId];
        });
    }

    private function visible(int $stocktakeId): ?object
    {
        $stocktake = $this->takes->find($stocktakeId);

        return $stocktake !== null && $this->branchGuard()->canAccess((int) $stocktake->branch_id, $this->employeeId()) ? $stocktake : null;
    }

    /**
     * @throws AccountingException
     */
    private function assertVisible(int $stocktakeId): void
    {
        if ($this->visible($stocktakeId) === null) {
            throw new AccountingException(AccountingError::StocktakeNotFound, "Stocktake {$stocktakeId} does not exist.", ['stocktake_id' => $stocktakeId]);
        }
    }

    /**
     * @return array{status: string, location_id: int, search: string, from: string, to: string}
     */
    private function filters(): array
    {
        $status = $this->queryText('status');

        return [
            'status'      => in_array($status, ['counting', 'approved', 'cancelled'], true) ? $status : '',
            'location_id' => (int) $this->request->getGet('location_id'),
            'search'      => trim($this->queryText('search')),
            'from'        => $this->dateOf('from'),
            'to'          => $this->dateOf('to'),
        ];
    }
}
