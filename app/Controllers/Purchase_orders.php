<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Organization\BranchException;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\Receiving_lib;
use App\Models\Stock_location;
use CodeIgniter\HTTP\ResponseInterface;

class Purchase_orders extends PurchaseCycleScreen
{
    public function __construct()
    {
        parent::__construct(PurchaseDocumentKind::Order);
    }

    /**
     * An order is not turned into another document: the receiving register is filled in with what is left of it, and nothing is
     * saved until the receiving is completed there. The register is filled in at the stock location of the order, which the employee
     * must hold the receiving grant of as the register itself asks of them.
     */
    public function postConvert(int $documentId): ResponseInterface
    {
        try {
            $order = $this->documents->assertReceivable($documentId, $this->employeeId());
        } catch (AccountingException|BranchException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal), 'id' => $documentId]);
        }

        if (! $this->employee->has_module_grant('receivings', $this->employeeId())) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Purchase_cycle.receiving_not_allowed'), 'id' => $documentId]);
        }

        if (! model(Stock_location::class)->is_allowed_location((int) $order->location_id, 'receivings', true)) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Receivings.location_not_allowed'), 'id' => $documentId]);
        }

        (new Receiving_lib())->prepareReceivingFromOrder($documentId, (int) $order->supplier_id, (int) $order->location_id, $this->documents->remainingLines($documentId));

        return $this->response->setJSON(['success' => true, 'message' => lang('Purchase_cycle.receiving_prepared'), 'id' => $documentId, 'redirect' => site_url('receivings')]);
    }
}
