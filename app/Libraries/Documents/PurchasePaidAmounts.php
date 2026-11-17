<?php

namespace App\Libraries\Documents;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PurchaseFigures;
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
        $read = $this->readMany([$receivingId => ['supplier_id' => $supplierId, 'payment_type' => $paymentType, 'foreign' => $foreign, 'total' => $total]])[$receivingId];

        return ['paid' => $read['paid'], 'due' => $read['due']];
    }

    /**
     * What each of some purchases was paid and still owes, the way read() tells it for one: from the books for
     * the purchases they hold, from the purchases themselves for the others, which are read together.
     *
     * @param array<int, array{supplier_id: ?int, payment_type: ?string, foreign: bool, total: numeric-string}> $purchases By the number of the receiving.
     *
     * @return array<int, array{paid: numeric-string, due: numeric-string, figures: ?PurchaseFigures}>
     */
    public function readMany(array $purchases): array
    {
        $figures = [];

        if ($purchases !== [] && (new AccountingSettings($this->db))->isEnabled()) {
            $inBooks = array_keys(array_filter($purchases, static fn (array $purchase): bool => $purchase['supplier_id'] !== null && ! $purchase['foreign']));
            $figures = $inBooks === [] ? [] : (new SupplierLedger($this->db))->figuresOf($inBooks);
        }

        $own      = array_values(array_diff(array_keys($purchases), array_keys($figures)));
        $payments = $own === [] ? [] : $this->completedPayments($own);
        $read     = [];

        foreach ($purchases as $receivingId => $purchase) {
            if (isset($figures[$receivingId])) {
                $read[$receivingId] = ['paid' => $figures[$receivingId]->settled(), 'due' => $figures[$receivingId]->open(), 'figures' => $figures[$receivingId]];

                continue;
            }

            $paid = PaymentType::fromLabel((string) $purchase['payment_type'])?->usesTreasury() === true ? $purchase['total'] : '0';
            $paid = bcadd($paid, $payments[$receivingId] ?? '0', SaleAmounts::SCALE);
            $due  = bcsub($purchase['total'], $paid, SaleAmounts::SCALE);

            $read[$receivingId] = ['paid' => bcadd($paid, '0', SaleAmounts::SCALE), 'due' => bccomp($due, '0', SaleAmounts::SCALE) > 0 ? $due : '0.0000', 'figures' => null];
        }

        return $read;
    }

    /**
     * The payments of some purchases that count, by purchase.
     *
     * @param list<int> $receivingIds
     *
     * @return array<int, numeric-string>
     */
    private function completedPayments(array $receivingIds): array
    {
        $paid = [];

        foreach (array_chunk($receivingIds, 500) as $chunk) {
            foreach ($this->db->table('receivings_payments')->select('receiving_id, payment_status, payment_amount')->whereIn('receiving_id', $chunk)->get()->getResult() as $payment) {
                if (PaymentStatus::fromStored($payment->payment_status)->posts()) {
                    $paid[(int) $payment->receiving_id] = bcadd($paid[(int) $payment->receiving_id] ?? '0', (string) $payment->payment_amount, SaleAmounts::SCALE);
                }
            }
        }

        return $paid;
    }
}
