<?php

namespace App\Libraries\Documents;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SupplierLedger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the printed purchase invoice says was paid and what is still due. While the books are on and
 * the purchase stands in the account of its supplier, the figures are the ones of that account: the
 * payment made when the purchase was saved, the payments made since and the credit applied to it, so
 * the print cannot disagree with the balance of the supplier. A purchase the books do not hold (they
 * are off, it has no supplier, it came before them, or it is in a foreign currency, whose books are in
 * the base currency) is read from the purchase itself: its payment type when it was saved, and the
 * payments that were completed. Nothing is written.
 */
final class PurchasePaidAmounts
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param numeric-string $total The total of the purchase as the document shows it.
     *
     * @return array{paid: numeric-string, due: numeric-string}
     */
    public function read(int $receivingId, ?int $supplierId, ?string $paymentType, bool $foreign, string $total): array
    {
        if ($supplierId !== null && ! $foreign && (new AccountingSettings($this->db))->isEnabled()) {
            $figures = (new SupplierLedger($this->db))->position($supplierId)->purchase($receivingId);

            if ($figures !== null) {
                return ['paid' => $figures->settled(), 'due' => $figures->open()];
            }
        }

        $paid = PaymentType::fromLabel((string) $paymentType)?->usesTreasury() === true ? $total : '0';

        foreach ($this->db->table('receivings_payments')->select('payment_status, payment_amount')->where('receiving_id', $receivingId)->get()->getResult() as $payment) {
            if (PaymentStatus::fromStored($payment->payment_status)->posts()) {
                $paid = bcadd($paid, (string) $payment->payment_amount, SaleAmounts::SCALE);
            }
        }

        $due = bcsub($total, $paid, SaleAmounts::SCALE);

        return ['paid' => bcadd($paid, '0', SaleAmounts::SCALE), 'due' => bccomp($due, '0', SaleAmounts::SCALE) > 0 ? $due : '0.0000'];
    }
}
