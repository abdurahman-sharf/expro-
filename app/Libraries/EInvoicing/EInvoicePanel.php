<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\SalePostingBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the page of an invoice says of its electronic document: not generated, generated or signed, and
 * whether it was submitted (it never is: no transport sends anything). Nothing is shown while the layer is
 * off, and a sale that is not an invoice or a return that was completed has none.
 */
final class EInvoicePanel
{
    public const NOT_GENERATED = 'not_generated';
    public const GENERATED     = 'generated';
    public const SIGNED        = 'signed';

    private BaseConnection $db;
    private EInvoiceService $service;
    private EInvoiceSettings $settings;

    public function __construct(?BaseConnection $db = null, ?EInvoiceService $service = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->service  = $service ?? new EInvoiceService($this->db);
        $this->settings = new EInvoiceSettings($this->db);
    }

    /**
     * @return array{state: string, submission: string, document: ?IssuedEInvoice, in_sync: bool, can_issue: bool, is_return: bool, can_manage: bool, default_subtype: string, original_issued: bool}|null Null when nothing is to be shown.
     */
    public function forSale(int $saleId, bool $canManage): ?array
    {
        if (! $this->settings->isEnabled()) {
            return null;
        }

        $sale = $this->db->table('sales')->select('sale_status, sale_type, customer_id, return_of_sale_id')->where('sale_id', $saleId)->get()->getRow();

        if ($sale === null || (int) $sale->sale_status !== COMPLETED || ! in_array((int) $sale->sale_type, [SALE_TYPE_INVOICE, SALE_TYPE_RETURN], true)) {
            return null;
        }

        $document = $this->service->documentOf(SalePostingBuilder::SOURCE_SALE, $saleId);
        $isReturn = (int) $sale->sale_type === SALE_TYPE_RETURN;

        return [
            'state'           => $document === null ? self::NOT_GENERATED : ($document->signed ? self::SIGNED : self::GENERATED),
            'submission'      => $document === null ? TransportResult::NOT_SUBMITTED : $this->service->submissionOf($document->documentId),
            'document'        => $document,
            'in_sync'         => $document === null || $this->service->inSync($document),
            'can_issue'       => $canManage && $document === null,
            'is_return'       => $isReturn,
            'can_manage'      => $canManage,
            'default_subtype' => $isReturn ? '' : (new EInvoiceBuilder($this->db, $this->settings))->defaultSubtype($saleId)->value,
            'original_issued' => ! $isReturn || ($sale->return_of_sale_id !== null && $this->service->documentOf(SalePostingBuilder::SOURCE_SALE, (int) $sale->return_of_sale_id) !== null),
        ];
    }
}
