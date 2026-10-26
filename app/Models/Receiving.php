<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLoader;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\ReturnLimit;
use App\Libraries\Accounting\SupplierLock;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use ReflectionException;
use Throwable;

/**
 * Receiving class
 */
class Receiving extends Model
{
    protected $table            = 'receivings';
    protected $primaryKey       = 'receiving_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'receiving_time',
        'supplier_id',
        'employee_id',
        'comment',
        'receiving_id',
        'payment_type',
        'reference',
    ];
    private ?Throwable $accountingFailure = null;
    private int $supplierLockWait         = 15;

    /**
     * How long a change to a purchase waits for another change to the same supplier to finish
     * before it is refused.
     */
    public function setSupplierLockWait(int $seconds): static
    {
        $this->supplierLockWait = $seconds;

        return $this;
    }

    /**
     * Why the last change to a purchase was refused by the books, or null when it was not.
     */
    public function accountingFailure(): ?Throwable
    {
        return $this->accountingFailure;
    }

    public function get_info(int $receiving_id): ResultInterface
    {
        $builder = $this->db->table('receivings');
        $builder->join('people', 'people.person_id = receivings.supplier_id', 'LEFT');
        $builder->join('suppliers', 'suppliers.person_id = receivings.supplier_id', 'LEFT');
        $builder->where('receiving_id', $receiving_id);

        return $builder->get();
    }

    public function get_receiving_by_reference(string $reference): ResultInterface
    {
        $builder = $this->db->table('receivings');
        $builder->where('reference', $reference);

        return $builder->get();
    }

    public function is_valid_receipt(string $receipt_receiving_id): bool    // TODO: maybe receipt_receiving_id should be an array rather than a space delimited string
    {
        if (! empty($receipt_receiving_id)) {
            // RECV #
            $pieces = explode(' ', $receipt_receiving_id);

            if (count($pieces) === 2 && preg_match('/(RECV|KIT)/', $pieces[0])) {
                return $this->exists($pieces[1]);
            }

            return $this->get_receiving_by_reference($receipt_receiving_id)->getNumRows() > 0;
        }

        return false;
    }

    public function exists(int $receiving_id): bool
    {
        $builder = $this->db->table('receivings');
        $builder->where('receiving_id', $receiving_id);

        return $builder->get()->getNumRows() === 1;
    }

    /**
     * Updates a purchase. When accounting is on, a purchase that has been posted keeps its
     * supplier and its date: what it needs corrected is corrected by a return. The payments
     * made against it, when the data carry them, are saved with it and posted again where they
     * changed, and the credit of the supplier is settled, all in the transaction of the update
     * and under the lock of the supplier.
     */
    public function update($receiving_id = null, $receiving_data = null): bool
    {
        $this->accountingFailure = null;

        try {
            return (new SupplierLock($this->db, $this->supplierLockWait))->run($this->supplierOf((int) $receiving_id), fn (): bool => $this->updateLocked($receiving_id, $receiving_data));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::SupplierBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return false;
        }
    }

    private function updateLocked($receiving_id, $receiving_data): bool
    {
        $payments     = is_array($receiving_data) && isset($receiving_data['payments']) ? $receiving_data['payments'] : [];
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();
        $employeeId   = isset($payments[0]['employee_id']) ? (int) $payments[0]['employee_id'] : null;

        if (is_array($receiving_data)) {
            unset($receiving_data['payments']);
        }

        if ($accountingOn && is_array($receiving_data)) {
            $changes = [];

            if (array_key_exists('supplier_id', $receiving_data)) {
                $changes['supplier_id'] = $receiving_data['supplier_id'] === null || $receiving_data['supplier_id'] === '' ? null : (int) $receiving_data['supplier_id'];
            }

            if (array_key_exists('receiving_time', $receiving_data)) {
                $changes['date'] = substr((string) $receiving_data['receiving_time'], 0, 10);
            }

            try {
                (new ReceivingPosting($this->db))->assertEditable((int) $receiving_id, $changes);
            } catch (AccountingException $refusal) {
                $this->accountingFailure = $refusal;

                return false;
            }
        }

        $this->db->transStart();

        try {
            $success = true;

            if (is_array($receiving_data) && $receiving_data !== []) {
                $builder = $this->db->table('receivings');
                $builder->where('receiving_id', $receiving_id);
                $success = $builder->update($receiving_data);
            }

            if ($success && $payments !== []) {
                $this->savePayments((int) $receiving_id, $payments, $accountingOn);
            }
        } catch (AccountingException|PostingException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            if ($accountingOn && $failure instanceof PostingException) {
                (new ReceivingPosting($this->db))->recordFailure((int) $receiving_id, $failure, $employeeId, $this->getBranchId((int) $receiving_id));
            }

            return false;
        } catch (Throwable $unexpected) {
            $this->db->transRollback();

            throw $unexpected;
        }

        $this->db->transComplete();

        return (bool) $success && $this->db->transStatus();
    }

    /**
     * Saves the payments made against a purchase: new ones are added, changed ones updated, and
     * one given an amount of nothing is removed with its journal. Only a purchase of goods
     * received from a supplier takes payments.
     *
     * @param list<array<string, mixed>> $payments
     *
     * @throws AccountingException|PostingException
     */
    private function savePayments(int $receivingId, array $payments, bool $accountingOn): void
    {
        $purchase = $this->db->table('receivings')->select('receiving_type, supplier_id, branch_id')->where('receiving_id', $receivingId)->get()->getRow();

        if ($purchase === null || $purchase->receiving_type !== ReceivingType::Receive->value) {
            throw new AccountingException(AccountingError::SupplierPaymentNotOnInvoice, 'A payment is made against a purchase, not against a return or a transfer.', ['receiving_id' => $receivingId]);
        }

        if ($purchase->supplier_id === null) {
            throw new AccountingException(AccountingError::SupplierPaymentNeedsSupplier, 'A payment to a supplier needs the purchase to have a supplier.', ['receiving_id' => $receivingId]);
        }

        $builder = $this->db->table('receivings_payments');
        $posting = $accountingOn ? new ReceivingPosting($this->db) : null;
        $last    = null;

        foreach ($payments as $payment) {
            $paymentId = (int) ($payment['payment_id'] ?? NEW_ENTRY);
            $amount    = (string) ($payment['payment_amount'] ?? '0');
            $last      = isset($payment['employee_id']) ? (int) $payment['employee_id'] : $last;

            if ($paymentId === NEW_ENTRY) {
                if (bccomp($amount, '0', 2) === 0) {
                    continue;
                }

                $builder->insert([
                    'receiving_id'   => $receivingId,
                    'payment_type'   => (string) $payment['payment_type'],
                    'payment_amount' => $amount,
                    'employee_id'    => $payment['employee_id'] ?? null,
                    'reference_code' => (string) ($payment['reference_code'] ?? ''),
                    'payment_status' => PaymentStatus::fromStored($payment['payment_status'] ?? null)->value,
                    'treasury_id'    => $this->treasuryOfPayment($payment, isset($payment['employee_id']) ? (int) $payment['employee_id'] : null, (int) $purchase->branch_id, bccomp($amount, '0', 2) < 0 ? TreasuryScopes::DEPOSIT : TreasuryScopes::WITHDRAW),
                ]);

                continue;
            }

            $stored = $this->db->table('receivings_payments')->select('receiving_id')->where('payment_id', $paymentId)->get()->getRow();

            if ($stored === null || (int) $stored->receiving_id !== $receivingId) {
                throw new AccountingException(AccountingError::SupplierPaymentNotOnInvoice, 'The payment does not belong to this purchase.', ['receiving_id' => $receivingId]);
            }

            if (bccomp($amount, '0', 2) === 0) {
                $posting?->reversePayment($paymentId, $last);
                $this->db->table('receivings_payments')->where('payment_id', $paymentId)->delete();

                continue;
            }

            $changes = ['payment_type' => (string) $payment['payment_type'], 'payment_amount' => $amount];

            if (isset($payment['payment_status']) && PaymentStatus::tryFrom((string) $payment['payment_status']) !== null) {
                $changes['payment_status'] = $payment['payment_status'];
            }

            $treasuryId = $this->treasuryAfterChange($payment, $paymentId, isset($payment['employee_id']) ? (int) $payment['employee_id'] : null, (int) $purchase->branch_id);

            if (array_key_exists('treasury_id', $payment)) {
                $changes['treasury_id'] = $treasuryId;
            }

            $this->db->table('receivings_payments')->where('payment_id', $paymentId)->update($changes);
        }

        $posting?->syncPayments($receivingId, $last);
    }

    /**
     * The treasury a payment is made from: the one it names, or else the treasury its employee
     * works from when it belongs to the branch of the purchase. A payment left on credit, or one
     * with no such treasury, goes to the account of its type as it always did. Money paid out is a
     * withdrawal from the treasury, and money a supplier gives back is a deposit.
     *
     * @param array<string, mixed> $payment
     */
    private function treasuryOfPayment(array $payment, ?int $employeeId, int $branchId, string $direction = TreasuryScopes::WITHDRAW): ?int
    {
        $type = PaymentType::fromLabel((string) ($payment['payment_type'] ?? ''));

        if ($type === null || ! $type->usesTreasury()) {
            return null;
        }

        return (new TreasuryService($this->db))->ofPayment((int) ($payment['treasury_id'] ?? 0), $type, $direction, $employeeId, $branchId);
    }

    /**
     * The treasury an existing payment is moved to, or none for one that goes where its type does.
     * A change of the treasury, of the way of payment or of the amount asks the scope of the
     * treasuries involved, whether or not the edit names a treasury.
     *
     * @param array<string, mixed> $payment
     */
    private function treasuryAfterChange(array $payment, int $paymentId, ?int $employeeId, int $branchId): ?int
    {
        $treasuries = new TreasuryService($this->db);
        $stored     = $this->db->table('receivings_payments')->select('treasury_id, payment_type, payment_amount')->where('payment_id', $paymentId)->get()->getRow();
        $storedId   = $stored === null || $stored->treasury_id === null ? 0 : (int) $stored->treasury_id;
        $named      = array_key_exists('treasury_id', $payment);
        $treasuryId = $named ? (int) $payment['treasury_id'] : $storedId;

        if ($named && $treasuryId > 0 && $treasuryId !== $storedId) {
            $treasuries->assertForBranch($treasuryId, $branchId);
        }

        $type = PaymentType::fromLabel((string) ($payment['payment_type'] ?? ''));

        if ($type !== null && $type->usesTreasury() && $stored !== null) {
            $amount = (string) ($payment['payment_amount'] ?? '0');

            $treasuries->assertMayChangePayment(
                $treasuryId > 0 ? $treasuryId : null,
                $storedId > 0 ? $storedId : null,
                bccomp($amount, (string) $stored->payment_amount, 2) !== 0,
                $type,
                bccomp($amount, '0', 2) < 0 ? TreasuryScopes::DEPOSIT : TreasuryScopes::WITHDRAW,
                $employeeId,
                PaymentType::fromLabel((string) $stored->payment_type),
            );
        }

        return $treasuryId > 0 ? $treasuryId : null;
    }

    /**
     * The payments made against a purchase, oldest first.
     *
     * @return list<object>
     */
    public function get_payments(int $receivingId): array
    {
        return $this->db->table('receivings_payments')->where('receiving_id', $receivingId)->orderBy('payment_id', 'ASC')->get()->getResult();
    }

    /**
     * The supplier a purchase is stored for, read before its transaction starts so that the lock
     * of the supplier is taken before the transaction reads anything.
     */
    private function supplierOf(int $receivingId): ?int
    {
        $row = $this->db->table('receivings')->select('supplier_id')->where('receiving_id', $receivingId)->get()->getRow();

        return $row === null || $row->supplier_id === null ? null : (int) $row->supplier_id;
    }

    /**
     * Saves a purchase: goods coming in from a supplier, going back to one, or moving between
     * stock locations, as the signs of its lines say. Everything happens in one transaction,
     * the purchase, its stock, the cost of the items and the journals, and the books refusing it
     * undoes all of it. Returns the id, -1 when it could not be saved, or ACCOUNTING_FAILED when
     * the books or the rules of a return refused it; accountingFailure() then says why.
     *
     * @throws ReflectionException
     */
    public function save_value(array $items, int $supplier_id, int $employee_id, string $comment, string $reference, ?string $payment_type, int $receiving_id = NEW_ENTRY, ?int $returnOfReceivingId = null): int    // TODO: $receiving_id gets overwritten before it's evaluated. It doesn't make sense to pass this here.
    {
        $this->accountingFailure = null;

        try {
            return (new SupplierLock($this->db, $this->supplierLockWait))->run($supplier_id > 0 ? $supplier_id : null, fn (): int => $this->saveValueLocked($items, $supplier_id, $employee_id, $comment, $reference, $payment_type, $receiving_id, $returnOfReceivingId));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::SupplierBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return ACCOUNTING_FAILED;
        }
    }

    /**
     * @throws ReflectionException
     */
    private function saveValueLocked(array $items, int $supplier_id, int $employee_id, string $comment, string $reference, ?string $payment_type, int $receiving_id, ?int $returnOfReceivingId): int
    {
        $attribute    = model(Attribute::class);
        $inventory    = model('Inventory');
        $itemQuantity = model(Item_quantity::class);
        $item         = model(Item::class);
        $supplier     = model(Supplier::class);

        $this->accountingFailure = null;

        if (count($items) === 0) {
            return -1;    // TODO: Replace -1 with a constant
        }

        $config       = config(OSPOS::class)->settings;
        $type         = ReceivingType::ofQuantities(array_map(static fn (array $line): string => self::unitsOf($line), $items));
        $branchIds    = $this->branchIdsOfItems($items);
        $branchId     = $branchIds[0] ?? (new BranchGuard($this->db))->companyDefaultBranchId();
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();

        try {
            $treasuryId = $this->treasuryOfPayment(['payment_type' => $payment_type], $employee_id === null ? null : (int) $employee_id, $branchId, $type === ReceivingType::Return ? TreasuryScopes::DEPOSIT : TreasuryScopes::WITHDRAW);
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        $receivings_data = [
            'receiving_time'         => date('Y-m-d H:i:s'),
            'supplier_id'            => $supplier->exists($supplier_id) ? $supplier_id : null,
            'employee_id'            => $employee_id,
            'payment_type'           => $payment_type,
            'comment'                => $comment,
            'reference'              => $reference,
            'receiving_type'         => $type->value,
            'branch_id'              => $branchId,
            'return_of_receiving_id' => $type === ReceivingType::Return ? $returnOfReceivingId : null,
            'treasury_id'            => $treasuryId,
        ];

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        try {
            if ($accountingOn && count($branchIds) > 1) {
                throw new PostingException(PostingError::PurchaseAcrossBranches, 'A purchase or a transfer stays within one branch.');
            }

            $this->lockItems($items);

            if ($receivings_data['return_of_receiving_id'] !== null) {
                $this->lockOrigin((int) $receivings_data['return_of_receiving_id']);
            }

            $builder = $this->db->table('receivings');
            $builder->insert($receivings_data);
            $receiving_id = $this->db->insertID();

            $builder = $this->db->table('receivings_items');

            foreach ($items as $line => $item_data) {
                $cur_item_info = $item->get_info($item_data['item_id']);

                $receivings_items_data = [
                    'receiving_id'       => $receiving_id,
                    'item_id'            => $item_data['item_id'],
                    'line'               => $item_data['line'],
                    'description'        => $item_data['description'],
                    'serialnumber'       => $item_data['serialnumber'],
                    'quantity_purchased' => $item_data['quantity'],
                    'receiving_quantity' => $item_data['receiving_quantity'],
                    'discount'           => $item_data['discount'],
                    'discount_type'      => $item_data['discount_type'],
                    'item_cost_price'    => $cur_item_info->cost_price,
                    'item_unit_price'    => $item_data['price'],
                    'item_location'      => $item_data['item_location'],
                    'tax_percent'        => $item_data['tax_percent'] ?? 0,
                ];

                $builder->insert($receivings_items_data);
            }

            if ($type === ReceivingType::Return && $receivings_data['return_of_receiving_id'] !== null) {
                (new ReturnLimit($this->db))->assertPurchaseReturnWithin($receiving_id);
            }

            if ($type === ReceivingType::Receive && ($accountingOn || $config['receiving_calculate_average_price'])) {
                $this->averageTheCost($receiving_id, $item);
            }

            if ($accountingOn) {
                (new ReceivingPosting($this->db))->postCompleted($receiving_id, $employee_id);
            }

            foreach ($items as $line => $item_data) {
                $items_received = (float) self::unitsOf($item_data);

                $itemQuantity->changeQuantity((int) $item_data['item_id'], (int) $item_data['item_location'], $items_received);

                $inv_data = [
                    'trans_date'      => date('Y-m-d H:i:s'),
                    'trans_items'     => $item_data['item_id'],
                    'trans_user'      => $employee_id,
                    'trans_location'  => $item_data['item_location'],
                    'trans_comment'   => 'RECV ' . $receiving_id,
                    'trans_inventory' => $items_received,
                ];

                $inventory->insert($inv_data, false);
                $attribute->copy_attribute_links($item_data['item_id'], 'receiving_id', $receiving_id);
            }
        } catch (AccountingException|PostingException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            if ($accountingOn && $failure instanceof PostingException) {
                (new ReceivingPosting($this->db))->recordFailure($receiving_id > 0 ? (int) $receiving_id : null, $failure, $employee_id, $branchId);
            }

            return ACCOUNTING_FAILED;
        } catch (Throwable $unexpected) {
            $this->db->transRollback();

            throw $unexpected;
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $receiving_id : -1;
    }

    /**
     * The branches of the stock locations the lines of a purchase work in, without repeats.
     *
     * @return list<int>
     */
    public function branchIdsOfItems(array $items): array
    {
        $locationIds = array_values(array_unique(array_filter(array_column($items, 'item_location'))));

        if ($locationIds === []) {
            return [];
        }

        $rows = $this->db->table('stock_locations')->distinct()->select('branch_id')->whereIn('location_id', $locationIds)->orderBy('branch_id', 'ASC')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'branch_id'));
    }

    /**
     * The branch a purchase belongs to, or null when there is no such purchase.
     */
    public function getBranchId(int $receivingId): ?int
    {
        $row = $this->db->table('receivings')->select('branch_id')->where('receiving_id', $receivingId)->get()->getRow();

        return $row === null ? null : (int) $row->branch_id;
    }

    /**
     * Whether any purchase takes goods back from this one.
     */
    public function hasReturns(int $receivingId): bool
    {
        return $this->db->table('receivings')->where('return_of_receiving_id', $receivingId)->countAllResults() > 0;
    }

    /**
     * The units each item of a purchase brought in and the units earlier returns gave back, so
     * that a return can offer only what is left.
     *
     * @return array<int, array{bought: float, returned: float}>
     */
    public function getReturnableQuantities(int $receivingId): array
    {
        $rows = $this->db->query(
            'SELECT l.item_id,
                    SUM(CASE WHEN r.receiving_id = ? THEN ABS(l.quantity_purchased * IF(l.receiving_quantity = 0, 1, l.receiving_quantity)) ELSE 0 END) AS bought,
                    SUM(CASE WHEN r.receiving_id <> ? THEN ABS(l.quantity_purchased * IF(l.receiving_quantity = 0, 1, l.receiving_quantity)) ELSE 0 END) AS returned
             FROM ' . $this->db->prefixTable('receivings') . ' r
             JOIN ' . $this->db->prefixTable('receivings_items') . ' l ON l.receiving_id = r.receiving_id
             WHERE r.receiving_id = ? OR (r.return_of_receiving_id = ? AND r.receiving_type = ?)
             GROUP BY l.item_id',
            [$receivingId, $receivingId, $receivingId, $receivingId, ReceivingType::Return->value],
        )->getResult();

        $quantities = [];

        foreach ($rows as $row) {
            $quantities[(int) $row->item_id] = ['bought' => (float) $row->bought, 'returned' => (float) $row->returned];
        }

        return $quantities;
    }

    /**
     * @param array<string, mixed> $line A line of the purchase as the cart keeps it.
     */
    private static function unitsOf(array $line): string
    {
        $quantity = sprintf('%.3F', (float) $line['quantity']);
        $perPack  = (float) ($line['receiving_quantity'] ?? 1);

        return $perPack !== 0.0 ? bcmul($quantity, sprintf('%.3F', $perPack), 3) : $quantity;
    }

    /**
     * Takes the lock of every item of a purchase, in the order of their ids so that two purchases
     * of the same items cannot wait for each other. What follows reads the stock and the cost the
     * purchase before it left.
     */
    private function lockItems(array $items): void
    {
        $ids = array_values(array_unique(array_map('intval', array_column($items, 'item_id'))));
        sort($ids);

        if ($ids !== []) {
            $this->db->query('SELECT item_id FROM ' . $this->db->prefixTable('items') . ' WHERE item_id IN (' . implode(',', $ids) . ') ORDER BY item_id FOR UPDATE');
        }
    }

    /**
     * The purchase a return comes from is locked while the return is saved, so that two returns
     * of it never run side by side. It must exist, and be a purchase and not itself a return.
     *
     * @throws PostingException
     */
    private function lockOrigin(int $originId): void
    {
        $locked = $this->db->query('SELECT receiving_type FROM ' . $this->db->prefixTable('receivings') . ' WHERE receiving_id = ? FOR UPDATE', [$originId]);
        $origin = $locked === false ? null : $locked->getRow();

        if ($origin === null || $origin->receiving_type !== ReceivingType::Receive->value) {
            throw new PostingException(PostingError::PurchaseReturnOriginInvalid, 'A return can only be taken from a purchase.', ['origin_id' => $originId]);
        }
    }

    /**
     * Brings the cost of every stock item of a purchase to the weighted average, at what the
     * units cost after the discount of their line.
     */
    private function averageTheCost(int $receivingId, Item $item): void
    {
        $purchase = (new ReceivingLoader($this->db))->load($receivingId, false);

        foreach ($purchase?->lines ?? [] as $line) {
            $units = ReceivingAmounts::units($line);

            if ($line->hasStock && bccomp($units, '0', 3) > 0) {
                $item->applyPurchaseCost($line->itemId, $units, bcdiv(ReceivingAmounts::net($line), $units, 8));
            }
        }
    }

    /**
     * @throws ReflectionException
     */
    public function delete_list(array $receiving_ids, int $employee_id, bool $update_inventory = true): bool
    {
        $this->accountingFailure = null;

        $suppliers = [];

        foreach ($receiving_ids as $receiving_id) {
            $supplier = $this->supplierOf((int) $receiving_id);

            if ($supplier !== null) {
                $suppliers[$supplier] = $supplier;
            }
        }

        ksort($suppliers);

        try {
            return $this->lockSuppliers(array_values($suppliers), fn (): bool => $this->deleteListLocked($receiving_ids, $employee_id, $update_inventory));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::SupplierBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return false;
        }
    }

    /**
     * Runs work with the lock of every supplier given, taken in the order of their ids so that
     * two requests for the same suppliers never wait for each other, before any transaction.
     *
     * @param list<int>        $supplierIds
     * @param callable(): bool $work
     *
     * @throws AccountingException
     */
    private function lockSuppliers(array $supplierIds, callable $work): bool
    {
        if ($supplierIds === []) {
            return $work();
        }

        $first = array_shift($supplierIds);

        return (new SupplierLock($this->db, $this->supplierLockWait))->run($first, fn (): bool => $this->lockSuppliers($supplierIds, $work));
    }

    /**
     * @throws ReflectionException
     */
    private function deleteListLocked(array $receiving_ids, int $employee_id, bool $update_inventory): bool
    {
        // Start a transaction to assure data integrity
        $this->db->transStart();

        foreach ($receiving_ids as $receiving_id) {
            if (! $this->delete_value((int) $receiving_id, $employee_id, $update_inventory)) {
                $this->db->transRollback();

                return false;
            }
        }

        // Execute transaction
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * Deletes a purchase and gives its stock back. When accounting is on, its journals and its
     * stock movements are taken back too, in the same transaction. A purchase that goods have
     * been returned from cannot be deleted: its returns come first.
     *
     * @throws ReflectionException
     */
    public function delete_value(int $receiving_id, int $employee_id, bool $update_inventory = true): bool
    {
        $this->accountingFailure = null;

        try {
            return (new SupplierLock($this->db, $this->supplierLockWait))->run($this->supplierOf($receiving_id), fn (): bool => $this->deleteValueLocked($receiving_id, $employee_id, $update_inventory));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::SupplierBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return false;
        }
    }

    /**
     * @throws ReflectionException
     */
    private function deleteValueLocked(int $receiving_id, int $employee_id, bool $update_inventory): bool
    {
        // Start a transaction to assure data integrity
        $this->db->transStart();

        // The lock of the purchase comes first, so two requests deleting it never run side by side
        $locked   = $this->db->query('SELECT receiving_id, branch_id FROM ' . $this->db->prefixTable('receivings') . ' WHERE receiving_id = ? FOR UPDATE', [$receiving_id]);
        $purchase = $locked === false ? null : $locked->getRow();

        if ($purchase === null) {
            $this->db->transComplete();

            return $this->db->transStatus();
        }

        if ($this->hasReturns($receiving_id)) {
            $this->db->transRollback();
            $this->accountingFailure = new AccountingException(AccountingError::PurchaseHasReturns, 'A purchase that goods have been returned from cannot be deleted.', ['receiving_id' => $receiving_id]);

            return false;
        }

        $items = $this->get_receiving_items($receiving_id)->getResultArray();
        $this->lockItems($items);

        if ($update_inventory) {
            $inventory     = model('Inventory');
            $item_quantity = model(Item_quantity::class);

            foreach ($items as $item) {
                $units = $item['quantity_purchased'] * (-$item['receiving_quantity']);

                // Create query to update inventory tracking
                $inv_data = [
                    'trans_date'      => date('Y-m-d H:i:s'),
                    'trans_items'     => $item['item_id'],
                    'trans_user'      => $employee_id,
                    'trans_comment'   => 'Deleting receiving ' . $receiving_id,
                    'trans_location'  => $item['item_location'],
                    'trans_inventory' => $units,
                ];
                // Update inventory
                $inventory->insert($inv_data, false);

                // Update quantities
                $item_quantity->changeQuantity($item['item_id'], $item['item_location'], $units);
            }
        }

        if ((new AccountingSettings($this->db))->isEnabled()) {
            try {
                (new ReceivingPosting($this->db))->cancel($receiving_id, $employee_id);
            } catch (AccountingException|PostingException $failure) {
                $this->db->transRollback();
                $this->accountingFailure = $failure;
                (new ReceivingPosting($this->db))->recordFailure($receiving_id, $failure, $employee_id, (int) $purchase->branch_id);

                return false;
            }
        }

        $this->db->table('receivings_payments')->where('receiving_id', $receiving_id)->delete();

        // Delete all items
        $builder = $this->db->table('receivings_items');
        $builder->delete(['receiving_id' => $receiving_id]);

        // Delete sale itself
        $builder = $this->db->table('receivings');
        $builder->delete(['receiving_id' => $receiving_id]);

        // Execute transaction
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    public function get_receiving_items(int $receiving_id): ResultInterface
    {
        $builder = $this->db->table('receivings_items');
        $builder->where('receiving_id', $receiving_id);

        return $builder->get();
    }

    public function get_supplier(int $receiving_id): object
    {
        $builder = $this->db->table('receivings');
        $builder->where('receiving_id', $receiving_id);

        $supplier = model(Supplier::class);

        return $supplier->get_info($builder->get()->getRow()->supplier_id);
    }

    public function get_payment_options(): array
    {
        return [
            lang('Sales.cash')          => lang('Sales.cash'),
            lang('Sales.check')         => lang('Sales.check'),
            lang('Sales.debit')         => lang('Sales.debit'),
            lang('Sales.credit')        => lang('Sales.credit'),
            lang('Sales.due')           => lang('Sales.due'),
            lang('Sales.bank_transfer') => lang('Sales.bank_transfer'),
            lang('Sales.wallet')        => lang('Sales.wallet'),
        ];
    }

    /**
     * Create a temp table that allows us to do easy report/receiving queries
     */
    public function create_temp_table(array $inputs): void
    {
        $config    = config(OSPOS::class)->settings;
        $db_prefix = $this->db->getPrefix();

        if (empty($inputs['receiving_id'])) {
            $where = empty($config['date_or_time_format'])
                ? 'DATE(`receiving_time`) BETWEEN ' . $this->db->escape($inputs['start_date']) . ' AND ' . $this->db->escape($inputs['end_date'])
                : 'receiving_time BETWEEN ' . $this->db->escape(rawurldecode($inputs['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($inputs['end_date']));
        } else {
            $where = 'receivings_items.receiving_id = ' . $this->db->escape($inputs['receiving_id']);
        }

        $units = '`quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity`';
        $gross = '`item_unit_price` * ' . $units;
        $type  = '`' . $db_prefix . 'receivings_items`.`discount_type`';
        $net   = 'CASE WHEN ' . $type . ' = ' . PERCENT . ' THEN ' . $gross . ' - ' . $gross . ' * `discount` / 100'
            . ' WHEN ' . $type . ' = ' . FIXED_LINE . ' THEN ' . $gross . ' - SIGN(`quantity_purchased`) * `discount`'
            . ' ELSE ' . $gross . ' - `discount` * ' . $units . ' END';

        $builder = $this->db->table('receivings_items');
        $builder->select([
            'MAX(DATE(`receiving_time`)) AS receiving_date',
            'MAX(`receiving_time`) AS receiving_time',
            'receivings_items.receiving_id AS receiving_id',
            'MAX(`comment`) AS comment',
            'MAX(`item_location`) AS item_location',
            'MAX(`reference`) AS reference',
            'MAX(`payment_type`) AS payment_type',
            'MAX(`employee_id`) AS employee_id',
            'items.item_id AS item_id',
            'MAX(`' . $db_prefix . 'receivings`.`supplier_id`) AS supplier_id',
            'MAX(`quantity_purchased`) AS quantity_purchased',
            'MAX(`' . $db_prefix . 'receivings_items`.`receiving_quantity`) AS item_receiving_quantity',
            'MAX(`item_cost_price`) AS item_cost_price',
            'MAX(`item_unit_price`) AS item_unit_price',
            'MAX(`discount`) AS discount',
            'MAX(`discount_type`) AS discount_type',
            'receivings_items.line AS line',
            'MAX(`serialnumber`) AS serialnumber',
            'MAX(`' . $db_prefix . 'receivings_items`.`description`) AS description',
            'MAX(' . $net . ') AS subtotal',
            'MAX(' . $net . ') AS total',
            'MAX((' . $net . ') - (`item_cost_price` * `quantity_purchased`)) AS profit',
            'MAX(`item_cost_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` ) AS cost',
        ]);
        $builder->join('receivings', 'receivings_items.receiving_id = receivings.receiving_id', 'inner');
        $builder->join('items', 'receivings_items.item_id = items.item_id', 'inner');
        $builder->where($where);
        $builder->groupBy(['receivings_items.receiving_id', 'items.item_id', 'receivings_items.line']);
        $selectQuery = $builder->getCompiledSelect();

        // QueryBuilder does not support creating temporary tables.
        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->prefixTable('receivings_items_temp') .
            ' (INDEX(receiving_date), INDEX(receiving_time), INDEX(receiving_id)) AS (' . $selectQuery . ')';

        $this->db->query($sql);
    }
}
