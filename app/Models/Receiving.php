<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\InvoiceDiscountShares;
use App\Libraries\Accounting\InvoiceTiming;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingDocument;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReceivingLineEdit;
use App\Libraries\Accounting\ReceivingLoader;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\ReturnDiscountShares;
use App\Libraries\Accounting\ReturnLimit;
use App\Libraries\Accounting\SupplierLock;
use App\Libraries\Accounting\SupplierPaymentDate;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Integrations\PaymentMethods;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\PurchaseCycle\PurchaseOrderReceipts;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Stock\NegativeStockGuard;
use App\Libraries\Stock\StockTracking;
use App\Libraries\Stock\TrackingEntry;
use App\Libraries\Stock\UnitLines;
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

    /** @var array{string, ?string}|null The currency the next purchase saved is in, and the rate typed for it. */
    private ?array $currencyChoice = null;

    /** @var array{string, ?string}|null */
    private ?array $savingCurrency = null;
    private ?RecordScope $recordScope     = null;

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

    /**
     * The purchases an employee who sees their own records only may not open, change or delete are
     * the ones another employee made, and the ones that do not exist, which they cannot tell apart.
     */
    public function outsideRecordScope(int $receivingId, ?int $viewerId = null): bool
    {
        $viewer = $viewerId ?? RecordScope::sessionEmployee();
        $scope  = $this->recordScope ??= new RecordScope($this->db);

        if (! $scope->ownOnly($viewer)) {
            return false;
        }

        $row = $this->db->table('receivings')->select('employee_id')->where('receiving_id', $receivingId)->get()->getRow();

        return ! $scope->allows($viewer, $row?->employee_id);
    }

    private function viewerCut(?int $viewerId): ?int
    {
        return ($this->recordScope ??= new RecordScope($this->db))->limit($viewerId ?? RecordScope::sessionEmployee());
    }

    public function get_info(int $receiving_id, ?int $viewerId = null): ResultInterface
    {
        $cut     = $this->viewerCut($viewerId);
        $builder = $this->db->table('receivings');
        $builder->join('people', 'people.person_id = receivings.supplier_id', 'LEFT');
        $builder->join('suppliers', 'suppliers.person_id = receivings.supplier_id', 'LEFT');
        $builder->where('receiving_id', $receiving_id);

        if ($cut !== null) {
            $builder->where('receivings.employee_id', $cut);
        }

        return $builder->get();
    }

    public function get_receiving_by_reference(string $reference, ?int $viewerId = null): ResultInterface
    {
        $cut     = $this->viewerCut($viewerId);
        $builder = $this->db->table('receivings');
        $builder->where('reference', $reference);

        if ($cut !== null) {
            $builder->where('receivings.employee_id', $cut);
        }

        return $builder->get();
    }

    public function is_valid_receipt(string $receipt_receiving_id): bool    // TODO: maybe receipt_receiving_id should be an array rather than a space delimited string
    {
        if (! empty($receipt_receiving_id)) {
            // RECV #
            $pieces = explode(' ', $receipt_receiving_id);

            if (count($pieces) === 2 && preg_match('/(RECV|KIT)/', $pieces[0])) {
                return $this->exists($pieces[1]) && ! $this->outsideRecordScope((int) $pieces[1]);
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
    public function update($receivingId = null, $receivingData = null): bool
    {
        $this->accountingFailure = null;

        if ($this->outsideRecordScope((int) $receivingId, $this->employeeOfEdit(is_array($receivingData) ? $receivingData : null, is_array($receivingData) && isset($receivingData['payments']) ? $receivingData['payments'] : []))) {
            return false;
        }

        try {
            return (new SupplierLock($this->db, $this->supplierLockWait))->run($this->supplierOf((int) $receivingId), fn (): bool => $this->updateLocked($receivingId, $receivingData));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::SupplierBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return false;
        }
    }

    private function updateLocked($receivingId, $receivingData): bool
    {
        $payments     = is_array($receivingData) && isset($receivingData['payments']) ? $receivingData['payments'] : [];
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();
        $employeeId   = $this->employeeOfEdit($receivingData, $payments);
        $hasDate      = is_array($receivingData) && array_key_exists('invoice_date', $receivingData);
        $typedDate    = is_array($receivingData) ? ($receivingData['invoice_date'] ?? null) : null;
        $hasTerms     = is_array($receivingData) && array_key_exists('payment_terms_days', $receivingData);
        $typedTerms   = is_array($receivingData) ? ($receivingData['payment_terms_days'] ?? null) : null;

        if (is_array($receivingData)) {
            unset($receivingData['payments'], $receivingData['invoice_date'], $receivingData['payment_terms_days'], $receivingData['due_date'], $receivingData['changed_by']);
        }

        if (is_array($receivingData) && array_key_exists('supplier_id', $receivingData)) {
            try {
                (new PurchaseOrderReceipts($this->db))->assertSupplierKept((int) $receivingId, $receivingData['supplier_id'] === null || $receivingData['supplier_id'] === '' ? null : (int) $receivingData['supplier_id']);
            } catch (AccountingException $refusal) {
                $this->accountingFailure = $refusal;

                return false;
            }
        }

        if ($accountingOn && is_array($receivingData)) {
            $changes = [];

            if (array_key_exists('supplier_id', $receivingData)) {
                $changes['supplier_id'] = $receivingData['supplier_id'] === null || $receivingData['supplier_id'] === '' ? null : (int) $receivingData['supplier_id'];
            }

            if (array_key_exists('receiving_time', $receivingData)) {
                $changes['date'] = substr((string) $receivingData['receiving_time'], 0, 10);
            }

            if ($hasDate) {
                $changes['invoice_date'] = (string) $typedDate;
            }

            foreach (['currency_code', 'currency_rate'] as $column) {
                if (array_key_exists($column, $receivingData)) {
                    $changes[$column] = $receivingData[$column] === null ? null : (string) $receivingData[$column];
                }
            }

            try {
                (new ReceivingPosting($this->db))->assertEditable((int) $receivingId, $changes);
            } catch (AccountingException $refusal) {
                $this->accountingFailure = $refusal;

                return false;
            }
        }

        $this->db->transStart();

        try {
            $success = true;

            if ($accountingOn && $hasTerms) {
                $receivingData = [...(is_array($receivingData) ? $receivingData : []), ...$this->paymentTermsChange((int) $receivingId, $typedTerms, $employeeId)];
            }

            if (is_array($receivingData) && $receivingData !== []) {
                $builder = $this->db->table('receivings');
                $builder->where('receiving_id', $receivingId);
                $success = $builder->update($receivingData);
            }

            if ($success && $payments !== []) {
                $this->savePayments((int) $receivingId, $payments, $accountingOn);
            }
        } catch (AccountingException|PostingException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            if ($accountingOn && $failure instanceof PostingException) {
                (new ReceivingPosting($this->db))->recordFailure((int) $receivingId, $failure, $employeeId, $this->getBranchId((int) $receivingId));
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
     * The day typed on a purchase, its payment terms and its due date, each empty when the purchase has none.
     *
     * @return array{invoice_date: ?string, payment_terms_days: ?int, due_date: ?string}
     */
    public function getInvoiceTiming(int $receivingId): array
    {
        $row = $this->db->table('receivings')->select('invoice_date, payment_terms_days, due_date')->where('receiving_id', $receivingId)->get()->getRow();

        return [
            'invoice_date'       => $row === null || $row->invoice_date === null ? null : (string) $row->invoice_date,
            'payment_terms_days' => $row === null || $row->payment_terms_days === null ? null : (int) $row->payment_terms_days,
            'due_date'           => $row === null || $row->due_date === null ? null : (string) $row->due_date,
        ];
    }

    /**
     * The payment terms of a purchase and the due date they give from the day the purchase is
     * dated, which are the only part of its timing that can change after it is saved, and change
     * nothing in its journals. Nothing is returned when the terms are not new or the document is
     * not a purchase of goods.
     *
     * @return array{payment_terms_days?: ?int, due_date?: ?string}
     *
     * @throws AccountingException
     */
    private function paymentTermsChange(int $receivingId, int|string|null $typed, ?int $employeeId): array
    {
        $terms    = InvoiceTiming::terms($typed);
        $purchase = $this->db->table('receivings')->select('receiving_type, receiving_time, invoice_date, payment_terms_days, branch_id')->where('receiving_id', $receivingId)->get()->getRow();

        if ($purchase === null || $purchase->receiving_type !== ReceivingType::Receive->value) {
            return [];
        }

        $before = $purchase->payment_terms_days === null ? null : (int) $purchase->payment_terms_days;

        if ($before === $terms) {
            return [];
        }

        $date = $purchase->invoice_date === null ? substr((string) $purchase->receiving_time, 0, 10) : (string) $purchase->invoice_date;
        $due  = $terms === null ? null : InvoiceTiming::dueDate($date, $terms);

        (new AuditLog($this->db))->write('receiving', $receivingId, 'payment_terms_changed', $employeeId, JournalOrigin::User, ['from' => $before, 'to' => $terms, 'due_date' => $due ?? $date], (int) $purchase->branch_id);

        return ['payment_terms_days' => $terms, 'due_date' => $due];
    }

    /**
     * @param array<string, mixed>|null $receivingData
     * @param list<array<string, mixed>> $payments
     */
    private function employeeOfEdit(?array $receivingData, array $payments): ?int
    {
        $employeeId = $receivingData['changed_by'] ?? $payments[0]['employee_id'] ?? null;

        return $employeeId === null || $employeeId === '' ? null : (int) $employeeId;
    }

    /**
     * What a purchase carries about time besides the moment it was saved: the day typed on it, its
     * payment terms and the due date they give. Only a purchase of goods in books that are on has
     * any of it, and the day is typed by an employee who may.
     *
     * @return array{invoice_date: ?string, payment_terms_days: ?int, due_date: ?string}
     *
     * @throws AccountingException
     */
    private function timingOf(ReceivingType $type, ?string $invoiceDate, int|string|null $paymentTerms, int $employeeId): array
    {
        $none     = ['invoice_date' => null, 'payment_terms_days' => null, 'due_date' => null];
        $settings = new AccountingSettings($this->db);

        if (! $settings->isEnabled() || $type !== ReceivingType::Receive) {
            return $none;
        }

        $today = date('Y-m-d');
        $date  = InvoiceTiming::date($invoiceDate, $today, $settings->startDate());
        $terms = InvoiceTiming::terms($paymentTerms);

        if ($date !== null && ! model(Employee::class)->has_grant('receivings_invoice_date', $employeeId)) {
            throw new AccountingException(AccountingError::PermissionDenied, 'The employee may not type the date of a purchase.');
        }

        return [
            'invoice_date'       => $date,
            'payment_terms_days' => $terms,
            'due_date'           => $terms === null ? null : InvoiceTiming::dueDate($date ?? $today, $terms),
        ];
    }

    /**
     * Corrects the lines of a receiving of goods from a supplier: a line is taken out or some of what
     * it holds changes. The supplier and the date stay as they were. When the books follow the
     * receiving, its journal that stands is reversed with the stock movements it wrote at the value
     * they were written at, and the lines as they now are go in as the next revision, so that the
     * average cost of the items, the account of the stock location and what is owed to the supplier
     * all follow; the sales that were costed before stay as they were costed. The payments made
     * against the receiving are kept and the credit of the supplier is settled again. Refused, with
     * nothing changed, when the receiving has returns, is not goods from a supplier, lies in a branch
     * the employee does not work in or in a closed period, when a line is not valid, or when
     * the correction would leave a stock location with less than nothing of an item. Everything
     * happens in one transaction under the lock of the supplier, and a correction that changes
     * nothing writes nothing.
     *
     * @param list<ReceivingLineEdit> $edits
     */
    public function updateLines(int $receivingId, array $edits, int $employeeId): bool
    {
        $this->accountingFailure = null;

        if ($this->outsideRecordScope($receivingId, $employeeId)) {
            return false;
        }

        try {
            return (new SupplierLock($this->db, $this->supplierLockWait))->run($this->supplierOf($receivingId), fn (): bool => $this->updateLinesLocked($receivingId, $edits, $employeeId));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::SupplierBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return false;
        }
    }

    /**
     * @param list<ReceivingLineEdit> $edits
     */
    private function updateLinesLocked(int $receivingId, array $edits, int $employeeId): bool
    {
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();
        $loader       = new ReceivingLoader($this->db);
        $branchId     = null;

        $this->db->transStart();

        try {
            $locked   = $this->db->query('SELECT receiving_id, receiving_type, branch_id, invoice_discount_type, invoice_discount FROM ' . $this->db->prefixTable('receivings') . ' WHERE receiving_id = ? FOR UPDATE', [$receivingId]);
            $purchase = $locked === false ? null : $locked->getRow();

            if ($purchase === null || $purchase->receiving_type !== ReceivingType::Receive->value) {
                throw new AccountingException(AccountingError::PurchaseEditNotAReceipt, 'Only a receiving of goods from a supplier has lines that can be edited.', ['receiving_id' => $receivingId]);
            }

            $branchId = (int) $purchase->branch_id;

            (new BranchGuard($this->db))->requireBranch($branchId, $employeeId);

            if ($this->hasReturns($receivingId)) {
                throw new AccountingException(AccountingError::PurchaseEditHasReturns, 'A receiving that goods have been returned from cannot be edited.', ['receiving_id' => $receivingId]);
            }

            $stored = [];

            foreach ($this->get_receiving_items($receivingId)->getResultArray() as $row) {
                $stored[$row['item_id'] . ':' . $row['line']] = $row;
            }

            $this->lockItems(array_values($stored));

            ['changed' => $changed, 'removed' => $removed] = $this->correctedLines($receivingId, $stored, $edits);

            if ($changed === [] && $removed === []) {
                $this->db->transComplete();

                return $this->db->transStatus();
            }

            $before = $loader->load($receivingId, false);

            $this->rewriteLines($receivingId, $changed, $removed, $stored);
            $this->shareInvoiceDiscountAgain($receivingId, $purchase);
            $this->takeOrderUnitsAgain($receivingId, $employeeId, $branchId, $changed, $removed, $stored);

            $after  = $loader->load($receivingId, false);
            $deltas = $this->stockDeltas($before, $after);

            $this->assertStockStays($deltas);

            if ($this->costCarries($before)) {
                $moved = $this->movedLines($before, $after);

                $this->correctTheCost(model(Item::class), $this->linesOf($before, $moved), $this->linesOf($after, $moved), $before->currency?->rate);
            }

            if ($accountingOn) {
                (new ReceivingPosting($this->db))->repost($receivingId, $employeeId, [
                    'total_before'  => ReceivingAmounts::totals($before->lines)['total'],
                    'total_after'   => ReceivingAmounts::totals($after->lines)['total'],
                    'lines_changed' => count($changed),
                    'lines_removed' => count($removed),
                ]);
            }

            $this->moveStock($deltas, 'Editing receiving ' . $receivingId, $employeeId);
        } catch (AccountingException|PostingException|BranchException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            if ($accountingOn && $failure instanceof PostingException) {
                (new ReceivingPosting($this->db))->recordFailure($receivingId, $failure, $employeeId, $branchId);
            }

            return false;
        } catch (Throwable $unexpected) {
            $this->db->transRollback();

            throw $unexpected;
        }

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * What a correction changes and what it takes out, by the key of the line (its item and its line
     * number). A line given its own values again is no change.
     *
     * @param array<string, array<string, mixed>> $stored
     * @param list<ReceivingLineEdit>             $edits
     *
     * @return array{changed: array<string, array<string, mixed>>, removed: list<string>}
     *
     * @throws AccountingException
     */
    private function correctedLines(int $receivingId, array $stored, array $edits): array
    {
        $changed = [];
        $removed = [];

        foreach ($edits as $edit) {
            $key = $edit->itemId . ':' . $edit->line;

            if (! isset($stored[$key]) || isset($changed[$key]) || in_array($key, $removed, true)) {
                throw new AccountingException(AccountingError::PurchaseEditLineInvalid, 'The line does not belong to this receiving, or is corrected twice.', ['receiving_id' => $receivingId, 'item_id' => $edit->itemId]);
            }

            if ($edit->remove) {
                $removed[] = $key;

                continue;
            }

            $corrected = $edit->applyTo($stored[$key]);

            if ($corrected === null) {
                throw new AccountingException(AccountingError::PurchaseEditLineInvalid, 'The line cannot be saved as it was given.', ['receiving_id' => $receivingId, 'item_id' => $edit->itemId]);
            }

            if ($corrected !== ReceivingLineEdit::fieldsOf($stored[$key])) {
                $changed[$key] = $corrected;
            }
        }

        if (count($removed) === count($stored)) {
            throw new AccountingException(AccountingError::PurchaseEditEmpty, 'A receiving keeps at least one line.', ['receiving_id' => $receivingId]);
        }

        return ['changed' => $changed, 'removed' => $removed];
    }

    /**
     * @param array<string, array<string, mixed>> $changed
     * @param list<string>                        $removed
     * @param array<string, array<string, mixed>> $stored
     */
    private function rewriteLines(int $receivingId, array $changed, array $removed, array $stored): void
    {
        foreach ($changed as $key => $fields) {
            $this->db->table('receivings_items')->where(['receiving_id' => $receivingId, 'item_id' => $stored[$key]['item_id'], 'line' => $stored[$key]['line']])->update([...$fields, ...$this->unitAfterCorrection($stored[$key], $fields)]);
        }

        foreach ($removed as $key) {
            $this->db->table('receivings_items')->where(['receiving_id' => $receivingId, 'item_id' => $stored[$key]['item_id'], 'line' => $stored[$key]['line']])->delete();
        }
    }

    /**
     * A receiving made from a purchase order takes from its lines what it holds; when the units of its lines are corrected it
     * takes again, and a correction that would take more than the order has left is refused. A correction of a price or a
     * note takes nothing and is left alone.
     *
     * @param array<string, array<string, mixed>> $changed
     * @param list<string>                        $removed
     * @param array<string, array<string, mixed>> $stored
     *
     * @throws AccountingException|BranchException
     */
    private function takeOrderUnitsAgain(int $receivingId, int $employeeId, int $branchId, array $changed, array $removed, array $stored): void
    {
        $receipts = new PurchaseOrderReceipts($this->db);
        $orderId  = $receipts->orderOfReceiving($receivingId);

        if ($orderId === null) {
            return;
        }

        $moved = array_filter($changed, static fn (array $fields, string $key): bool => bccomp((string) $fields['quantity_purchased'], bcadd((string) $stored[$key]['quantity_purchased'], '0', 3), 3) !== 0, ARRAY_FILTER_USE_BOTH);

        if ($moved === [] && $removed === []) {
            return;
        }

        $items = array_map(
            static fn (array $row): array => ['item_id' => (int) $row['item_id'], 'quantity' => $row['quantity_purchased'], 'receiving_quantity' => $row['receiving_quantity']],
            $this->get_receiving_items($receivingId)->getResultArray(),
        );

        $receipts->recordReceiving($receivingId, $orderId, $items, (int) $this->supplierOf($receivingId), $employeeId, $branchId, PurchaseOrderReceipts::RECEIPT_CHANGED);
    }

    /**
     * A line entered in a larger unit whose quantity or price a correction changes is a line of base units
     * from then on: what was entered no longer says what the line holds, and the base figures are the ones
     * everything reads.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $fields
     *
     * @return array<string, null>
     */
    private function unitAfterCorrection(array $stored, array $fields): array
    {
        if (($stored['unit_id'] ?? null) === null) {
            return [];
        }

        $moved = bccomp((string) $fields['quantity_purchased'], bcadd((string) $stored['quantity_purchased'], '0', 3), 3) !== 0
            || bccomp((string) $fields['item_unit_price'], bcadd((string) $stored['item_unit_price'], '0', 2), 2) !== 0;

        return $moved ? ['unit_id' => null, 'unit_factor' => null, 'unit_quantity' => null, 'unit_price_entered' => null] : [];
    }

    /**
     * The discount on the whole receiving, as it was entered, is shared out again over the lines as
     * they now are, and what each line takes is kept on it, so that every figure that is read
     * afterwards still comes from the lines alone. A receiving saved without one is left as it is.
     *
     * @throws PostingException
     */
    private function shareInvoiceDiscountAgain(int $receivingId, object $purchase): void
    {
        $discount = InvoiceDiscount::stored($purchase->invoice_discount_type === null ? null : (int) $purchase->invoice_discount_type, $purchase->invoice_discount === null ? null : (string) $purchase->invoice_discount);

        if ($discount->isNone()) {
            return;
        }

        $this->db->table('receivings_items')->where('receiving_id', $receivingId)->update(['invoice_discount_share' => null]);
        $this->applyInvoiceDiscount($receivingId, ReceivingType::Receive, null, $discount);
    }

    /**
     * What the stock of each item in each location changes by when a receiving goes from one state to
     * the other: the units it brings in afterwards less the units it brought in before. A line the
     * receiving no longer has counts for nothing afterwards.
     *
     * @return list<array{itemId: int, locationId: int, delta: string, hasStock: bool}>
     */
    private function stockDeltas(?ReceivingDocument $before, ?ReceivingDocument $after): array
    {
        $deltas = [];

        foreach ([[$before, -1], [$after, 1]] as [$document, $sign]) {
            foreach ($document?->lines ?? [] as $line) {
                $key = $line->itemId . ':' . $line->locationId;

                $deltas[$key] ??= ['itemId' => $line->itemId, 'locationId' => $line->locationId, 'delta' => '0.000', 'hasStock' => $line->hasStock];
                $deltas[$key]['delta'] = $sign > 0 ? bcadd($deltas[$key]['delta'], $line->units(), 3) : bcsub($deltas[$key]['delta'], $line->units(), 3);
            }
        }

        return array_values(array_filter($deltas, static fn (array $delta): bool => bccomp($delta['delta'], '0', 3) !== 0));
    }

    /**
     * A change that takes stock out of a location never takes more than is there: the goods of the
     * receiving may already have been sold or moved.
     *
     * @param list<array{itemId: int, locationId: int, delta: string, hasStock: bool}> $deltas
     *
     * @throws AccountingException
     */
    private function assertStockStays(array $deltas): void
    {
        foreach ($deltas as $delta) {
            if (! $delta['hasStock'] || bccomp($delta['delta'], '0', 3) >= 0) {
                continue;
            }

            $shelf = $this->db->query('SELECT quantity FROM ' . $this->db->prefixTable('item_quantities') . ' WHERE item_id = ? AND location_id = ? FOR UPDATE', [$delta['itemId'], $delta['locationId']]);
            $onHand = $shelf === false || $shelf->getRow() === null ? '0' : (string) $shelf->getRow()->quantity;

            if (bccomp(bcadd($onHand, $delta['delta'], 3), '0', 3) < 0) {
                $item     = $this->db->table('items')->select('name')->where('item_id', $delta['itemId'])->get()->getRow();
                $location = $this->db->table('stock_locations')->select('location_name')->where('location_id', $delta['locationId'])->get()->getRow();

                throw new AccountingException(AccountingError::PurchaseStockWouldGoNegative, 'The change would leave a stock location with less than nothing of an item.', [
                    'item_id'     => $delta['itemId'],
                    'location_id' => $delta['locationId'],
                    'name'        => $item === null ? (string) $delta['itemId'] : (string) $item->name,
                    'scope_name'  => $location === null ? (string) $delta['locationId'] : (string) $location->location_name,
                ]);
            }
        }
    }

    /**
     * Changes the stock on the shelves by the deltas and writes each change in the inventory record.
     *
     * @param list<array{itemId: int, locationId: int, delta: string, hasStock: bool}> $deltas
     *
     * @throws ReflectionException
     */
    private function moveStock(array $deltas, string $comment, int $employeeId): void
    {
        $inventory    = model('Inventory');
        $itemQuantity = model(Item_quantity::class);

        foreach ($deltas as $delta) {
            $units = (float) $delta['delta'];

            $itemQuantity->changeQuantity($delta['itemId'], $delta['locationId'], $units);
            $inventory->insert([
                'trans_date'      => date('Y-m-d H:i:s'),
                'trans_items'     => $delta['itemId'],
                'trans_user'      => $employeeId,
                'trans_location'  => $delta['locationId'],
                'trans_comment'   => $comment,
                'trans_inventory' => $units,
            ], false);
        }
    }

    /**
     * The lines of a receiving that carry the keys given (an item and a line number).
     *
     * @param list<string> $keys
     *
     * @return list<ReceivingLine>
     */
    private function linesOf(?ReceivingDocument $document, array $keys): array
    {
        return array_values(array_filter($document?->lines ?? [], static fn (ReceivingLine $line): bool => in_array($line->itemId . ':' . $line->line, $keys, true)));
    }

    /**
     * Whether the cost the items carry already holds the receiving, because it was averaged when the
     * receiving was saved: the books do that for every receiving they follow, and the setting for
     * the others. The cost is not corrected for a receiving that was never in it.
     */
    private function costCarries(?ReceivingDocument $purchase): bool
    {
        if ((bool) config(OSPOS::class)->settings['receiving_calculate_average_price']) {
            return true;
        }

        $posting = new ReceivingPosting($this->db);

        return $purchase !== null && $posting->follows($purchase) && $posting->isPosted($purchase->receivingId);
    }

    /**
     * The keys of the lines of a receiving whose units or whose value after discounts are not the
     * same once it is corrected, those a correction takes out among them. The discount on the whole
     * receiving is shared out again, so a line nobody touched can move too.
     *
     * @return list<string>
     */
    private function movedLines(ReceivingDocument $before, ReceivingDocument $after): array
    {
        $kept = [];

        foreach ($after->lines as $line) {
            $kept[$line->itemId . ':' . $line->line] = $line;
        }

        $moved = [];

        foreach ($before->lines as $line) {
            $key = $line->itemId . ':' . $line->line;
            $now = $kept[$key] ?? null;

            if ($now === null || bccomp(ReceivingAmounts::units($now), ReceivingAmounts::units($line), 3) !== 0 || bccomp(ReceivingAmounts::net($now), ReceivingAmounts::net($line), ReceivingAmounts::SCALE) !== 0) {
                $moved[] = $key;
            }
        }

        return $moved;
    }

    /**
     * Brings the cost of each stock item to the weighted average once the lines taken are out of
     * the stock and the lines given are in, each at what it costs after its discounts.
     *
     * @param list<ReceivingLine> $taken
     * @param list<ReceivingLine> $given
     * @param numeric-string|null $rate   The rate of a purchase in a foreign currency, which the cost of the items is kept in the base by.
     */
    private function correctTheCost(Item $item, array $taken, array $given, ?string $rate = null): void
    {
        $figures = [];

        foreach ([[$taken, 0], [$given, 2]] as [$lines, $offset]) {
            foreach ($lines as $line) {
                $units = ReceivingAmounts::units($line);

                if (! $line->hasStock || bccomp($units, '0', 3) <= 0) {
                    continue;
                }

                $figures[$line->itemId] ??= ['0', '0', '0', '0'];
                $figures[$line->itemId][$offset]     = bcadd($figures[$line->itemId][$offset], $units, 3);
                $net                                 = $rate === null ? ReceivingAmounts::net($line) : bcmul(ReceivingAmounts::net($line), $rate, ReceivingAmounts::SCALE);
                $figures[$line->itemId][$offset + 1] = bcadd($figures[$line->itemId][$offset + 1], $net, ReceivingAmounts::SCALE);
            }
        }

        ksort($figures);

        foreach ($figures as $itemId => [$takenUnits, $takenValue, $givenUnits, $givenValue]) {
            $item->correctPurchaseCost($itemId, $takenUnits, $takenValue, $givenUnits, $givenValue);
        }
    }

    /**
     * Saves the payments made against a purchase: new ones are added, changed ones updated, and
     * one given an amount of nothing is removed with its journal. Only a purchase of goods
     * received from a supplier takes payments. A payment has the date it was entered (today when
     * none is given, and never after today), which is the day it is posted on; the time it was saved
     * is kept as it was. Where the payments are posted, the date is not before the day the books start;
     * a purchase the books do not hold has no such limit. A payment saved before dates could be
     * entered keeps the day it was saved until someone gives it another.
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

        $builder    = $this->db->table('receivings_payments');
        $posting    = $accountingOn ? new ReceivingPosting($this->db) : null;
        $booksStart = $posting?->postsPayments($receivingId) ? (new AccountingSettings($this->db))->startDate() : null;
        $last       = null;

        foreach ($payments as $payment) {
            $paymentId = (int) ($payment['payment_id'] ?? NEW_ENTRY);
            $amount    = (string) ($payment['payment_amount'] ?? '0');
            $last      = isset($payment['employee_id']) ? (int) $payment['employee_id'] : $last;

            if ($paymentId === NEW_ENTRY) {
                if (bccomp($amount, '0', 2) === 0) {
                    continue;
                }

                $date = SupplierPaymentDate::entered(($payment['payment_date'] ?? '') === '' ? date('Y-m-d') : $payment['payment_date'], null, $booksStart);

                [$treasuryId, $treasuryBranchId] = $this->placeOfPayment($payment, isset($payment['employee_id']) ? (int) $payment['employee_id'] : null, (int) $purchase->branch_id, bccomp($amount, '0', 2) < 0 ? TreasuryScopes::DEPOSIT : TreasuryScopes::WITHDRAW);

                $builder->insert([
                    'receiving_id'       => $receivingId,
                    'payment_type'       => (string) $payment['payment_type'],
                    'payment_amount'     => $amount,
                    'payment_date'       => $date,
                    'employee_id'        => $payment['employee_id'] ?? null,
                    'reference_code'     => (string) ($payment['reference_code'] ?? ''),
                    'payment_status'     => PaymentStatus::fromStored($payment['payment_status'] ?? null)->value,
                    'treasury_id'        => $treasuryId,
                    'treasury_branch_id' => $treasuryBranchId,
                ]);

                (new DocumentNumbering($this->db))->assign(DocumentType::SupplierPayment, 'receivings_payments', (int) $this->db->insertID(), (int) $purchase->branch_id);

                continue;
            }

            $stored = $this->db->table('receivings_payments')->select('receiving_id, payment_time, payment_date')->where('payment_id', $paymentId)->get()->getRow();

            if ($stored === null || (int) $stored->receiving_id !== $receivingId) {
                throw new AccountingException(AccountingError::SupplierPaymentNotOnInvoice, 'The payment does not belong to this purchase.', ['receiving_id' => $receivingId]);
            }

            if (bccomp($amount, '0', 2) === 0) {
                $posting?->reversePayment($paymentId, $last);
                $this->db->table('receivings_payments')->where('payment_id', $paymentId)->delete();

                continue;
            }

            $changes = ['payment_type' => (string) $payment['payment_type'], 'payment_amount' => $amount];

            if (($payment['payment_date'] ?? '') !== '' && $payment['payment_date'] !== SupplierPaymentDate::of($stored->payment_date, (string) $stored->payment_time)) {
                $changes['payment_date'] = SupplierPaymentDate::entered($payment['payment_date'], null, $booksStart);
            }

            if (isset($payment['payment_status']) && PaymentStatus::tryFrom((string) $payment['payment_status']) !== null) {
                $changes['payment_status'] = $payment['payment_status'];
            }

            [$treasuryId, $treasuryBranchId] = $this->treasuryAfterChange($payment, $paymentId, isset($payment['employee_id']) ? (int) $payment['employee_id'] : null, (int) $purchase->branch_id);

            if (array_key_exists('treasury_id', $payment)) {
                $changes['treasury_id']        = $treasuryId;
                $changes['treasury_branch_id'] = $treasuryBranchId;
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
     * The treasury a payment made against the purchase afterwards is made from, with the branch it
     * belongs to when that is not the branch of the purchase, which an employee who works in both
     * may name.
     *
     * @param array<string, mixed> $payment
     *
     * @return array{?int, ?int}
     */
    private function placeOfPayment(array $payment, ?int $employeeId, int $branchId, string $direction): array
    {
        $type = PaymentType::fromLabel((string) ($payment['payment_type'] ?? ''));

        if ($type === null || ! $type->usesTreasury()) {
            return [null, null];
        }

        return (new TreasuryService($this->db))->placeOfPayment((int) ($payment['treasury_id'] ?? 0), $type, $direction, $employeeId, $branchId);
    }

    /**
     * The treasury an existing payment is moved to, or none for one that goes where its type does,
     * with the branch it belongs to when that is not the branch of the purchase. A change of the
     * treasury, of the way of payment or of the amount asks the scope of the treasuries involved,
     * whether or not the edit names a treasury.
     *
     * @param array<string, mixed> $payment
     *
     * @return array{?int, ?int}
     */
    private function treasuryAfterChange(array $payment, int $paymentId, ?int $employeeId, int $branchId): array
    {
        $treasuries     = new TreasuryService($this->db);
        $stored         = $this->db->table('receivings_payments')->select('treasury_id, treasury_branch_id, payment_type, payment_amount')->where('payment_id', $paymentId)->get()->getRow();
        $storedId       = $stored === null || $stored->treasury_id === null ? 0 : (int) $stored->treasury_id;
        $named          = array_key_exists('treasury_id', $payment);
        $treasuryId     = $named ? (int) $payment['treasury_id'] : $storedId;
        $treasuryBranch = $stored === null || $stored->treasury_branch_id === null ? null : (int) $stored->treasury_branch_id;

        if ($named && $treasuryId > 0 && $treasuryId !== $storedId) {
            $treasuryBranch = $treasuries->otherBranchOf($treasuryId, $branchId, $employeeId);
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

        return $treasuryId > 0 ? [$treasuryId, $treasuryBranch] : [null, null];
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
     * Says which currency the next purchase that is saved is in. The choice is for that one save and is
     * forgotten after it. The rate is the one of the table for the day of the purchase unless one is typed,
     * which asks the permission to manage currencies. A return takes the currency and the rate of the
     * purchase it gives back whatever is said, and a transfer of stock is never in a foreign currency.
     */
    public function useCurrency(?string $code, ?string $typedRate = null): void
    {
        $this->currencyChoice = $code === null || $code === '' ? null : [$code, $typedRate];
    }

    /**
     * Saves a purchase: goods coming in from a supplier, going back to one, or moving between
     * stock locations, as the signs of its lines say. Everything happens in one transaction,
     * the purchase, its stock, the cost of the items and the journals, and the books refusing it
     * undoes all of it. Returns the id, -1 when it could not be saved, or ACCOUNTING_FAILED when
     * the books or the rules of a return or of the discount refused it; accountingFailure() then
     * says why. The discount on the whole purchase is only taken by goods coming in; a return takes
     * the share of it that belongs to what it gives back.
     *
     * @throws ReflectionException
     */
    public function save_value(array $items, int $supplierId, int $employeeId, string $comment, string $reference, ?string $paymentType, int $receivingId = NEW_ENTRY, ?int $returnOfReceivingId = null, ?InvoiceDiscount $invoiceDiscount = null, ?string $invoiceDate = null, int|string|null $paymentTerms = null, ?int $purchaseOrderId = null, ?string $amountPaid = null): int    // TODO: $receivingId gets overwritten before it's evaluated. It doesn't make sense to pass this here.
    {
        $this->accountingFailure = null;

        if ($returnOfReceivingId !== null && $this->outsideRecordScope($returnOfReceivingId, $employeeId)) {
            return -1;    // TODO: Replace -1 with a constant
        }

        $this->savingCurrency = $this->currencyChoice;
        $this->currencyChoice = null;

        try {
            return (new SupplierLock($this->db, $this->supplierLockWait))->run($supplierId > 0 ? $supplierId : null, fn (): int => $this->saveValueLocked($items, $supplierId, $employeeId, $comment, $reference, $paymentType, $receivingId, $returnOfReceivingId, $invoiceDiscount ?? InvoiceDiscount::none(), $invoiceDate, $paymentTerms, $purchaseOrderId, $amountPaid));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::SupplierBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return ACCOUNTING_FAILED;
        } finally {
            $this->savingCurrency = null;
        }
    }

    /**
     * @throws ReflectionException
     */
    private function saveValueLocked(array $items, int $supplierId, int $employeeId, string $comment, string $reference, ?string $paymentType, int $receivingId, ?int $returnOfReceivingId, InvoiceDiscount $invoiceDiscount, ?string $invoiceDate, int|string|null $paymentTerms, ?int $purchaseOrderId, ?string $amountPaid): int
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

        try {
            $unitColumns = $this->unitColumnsOf($items);
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        $config       = config(OSPOS::class)->settings;
        $type         = ReceivingType::ofQuantities(array_map(static fn (array $line): string => self::unitsOf($line), $items));
        $tracking     = new StockTracking($this->db);

        try {
            if ($type !== ReceivingType::Receive) {
                $tracking->requireUntracked(array_map(static fn (array $line): int => (int) $line['item_id'], $items));
            } else {
                foreach ($items as $itemData) {
                    $tracking->assertSerialEntry((int) $itemData['item_id'], (int) $itemData['line'], sprintf('%.3F', (float) self::unitsOf($itemData)), TrackingEntry::ofLine($itemData));
                }
            }
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        $branchIds    = $this->branchIdsOfItems($items);
        $between      = $this->transferBetweenBranches($items);
        $branchId     = $between['from'] ?? $branchIds[0] ?? (new BranchGuard($this->db))->companyDefaultBranchId();
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();

        if ($between !== null) {
            try {
                $this->requireBranches($between, $employeeId);
            } catch (BranchException $refusal) {
                $this->accountingFailure = $refusal;

                return ACCOUNTING_FAILED;
            }
        }

        try {
            $treasuryId = $this->treasuryOfPayment(['payment_type' => $paymentType], $employeeId === null ? null : (int) $employeeId, $branchId, $type === ReceivingType::Return ? TreasuryScopes::DEPOSIT : TreasuryScopes::WITHDRAW);
            $timing     = $this->timingOf($type, $invoiceDate, $paymentTerms, $employeeId);
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        try {
            $currencyColumns = $this->currencyColumns($type, $returnOfReceivingId, $timing['invoice_date'] ?? date('Y-m-d'), $employeeId);
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        $receivingsData = [
            'receiving_time'         => date('Y-m-d H:i:s'),
            'supplier_id'            => $supplier->exists($supplierId) ? $supplierId : null,
            'employee_id'            => $employeeId,
            'payment_type'           => $paymentType,
            'comment'                => $comment,
            'reference'              => $reference,
            'receiving_type'         => $type->value,
            'branch_id'              => $branchId,
            'to_branch_id'           => $between['to'] ?? null,
            'return_of_receiving_id' => $type === ReceivingType::Return ? $returnOfReceivingId : null,
            'treasury_id'            => $treasuryId,
            ...$currencyColumns,
            ...$timing,
        ];

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        try {
            if ($accountingOn && count($branchIds) > 1 && $between === null) {
                throw new PostingException(PostingError::PurchaseAcrossBranches, 'A purchase or a return stays within one branch; only a transfer between stock locations goes from one branch to another.');
            }

            $this->lockItems($items);

            if ($receivingsData['return_of_receiving_id'] !== null) {
                $this->lockOrigin((int) $receivingsData['return_of_receiving_id']);
            }

            $builder = $this->db->table('receivings');
            $builder->insert($receivingsData);
            $receivingId = $this->db->insertID();

            $builder = $this->db->table('receivings_items');

            foreach ($items as $line => $itemData) {
                $curItemInfo = $item->get_info($itemData['item_id']);

                $receivingsItemsData = [
                    'receiving_id'       => $receivingId,
                    'item_id'            => $itemData['item_id'],
                    'line'               => $itemData['line'],
                    'description'        => $itemData['description'],
                    'serialnumber'       => $itemData['serialnumber'],
                    'quantity_purchased' => $itemData['quantity'],
                    'receiving_quantity' => $itemData['receiving_quantity'],
                    'discount'           => $itemData['discount'],
                    'discount_type'      => $itemData['discount_type'],
                    'item_cost_price'    => $curItemInfo->cost_price,
                    'item_unit_price'    => $itemData['price'],
                    'item_location'      => $itemData['item_location'],
                    'tax_percent'        => $itemData['tax_percent'] ?? 0,
                    ...$unitColumns[$line],
                ];

                $builder->insert($receivingsItemsData);
            }

            if ($type === ReceivingType::Return && $receivingsData['return_of_receiving_id'] !== null) {
                (new ReturnLimit($this->db))->assertPurchaseReturnWithin($receivingId);
            }

            if ($purchaseOrderId !== null) {
                if ($type !== ReceivingType::Receive) {
                    throw new AccountingException(AccountingError::PurchaseOrderReceivingKind, 'Only goods coming in from the supplier are received against a purchase order.');
                }

                (new PurchaseOrderReceipts($this->db))->recordReceiving((int) $receivingId, $purchaseOrderId, $items, $supplierId, $employeeId, $branchId);
            }

            $this->applyInvoiceDiscount((int) $receivingId, $type, $receivingsData['return_of_receiving_id'], $invoiceDiscount);

            $partPayment = $this->leaveWhatWasNotPaidOnAccount((int) $receivingId, $type, $supplierId, $paymentType, $amountPaid);

            if ($type === ReceivingType::Receive && ($accountingOn || $config['receiving_calculate_average_price'])) {
                $this->averageTheCost($receivingId, $item);
            }

            $this->numberDocument($type, (int) $receivingId, $branchId);

            if ($accountingOn) {
                (new ReceivingPosting($this->db))->postCompleted($receivingId, $employeeId);

                if ($timing['invoice_date'] !== null) {
                    (new AuditLog($this->db))->write('receiving', (int) $receivingId, 'invoice_date_typed', $employeeId, JournalOrigin::User, ['date' => $timing['invoice_date'], 'saved_on' => date('Y-m-d')], $branchId);
                }
            }

            if ($partPayment !== null) {
                $this->savePayments((int) $receivingId, [[
                    'payment_id'     => NEW_ENTRY,
                    'payment_type'   => (string) $paymentType,
                    'payment_amount' => $partPayment,
                    'employee_id'    => $employeeId,
                    'payment_status' => PaymentStatus::Completed->value,
                    'reference_code' => '',
                ]], $accountingOn);
            }

            foreach ($items as $line => $itemData) {
                $itemsReceived = (float) self::unitsOf($itemData);

                if ($tracking->handles((int) $itemData['item_id'])) {
                    $tracking->receive('receiving', (int) $receivingId, (int) $itemData['line'], (int) $itemData['item_id'], (int) $itemData['item_location'], sprintf('%.3F', $itemsReceived), TrackingEntry::ofLine($itemData));
                } else {
                    $this->assertNoTrackingEntry($itemData);

                    if ($type === ReceivingType::Requisition && $itemsReceived < 0 && $item->get_info($itemData['item_id'])->stock_type == HAS_STOCK) {
                        (new NegativeStockGuard($this->db))->assertSale((int) $itemData['item_id'], (int) $itemData['item_location'], bcmul(self::unitsOf($itemData), '-1', 3));
                    }

                    $itemQuantity->changeQuantity((int) $itemData['item_id'], (int) $itemData['item_location'], $itemsReceived);
                }

                $invData = [
                    'trans_date'      => date('Y-m-d H:i:s'),
                    'trans_items'     => $itemData['item_id'],
                    'trans_user'      => $employeeId,
                    'trans_location'  => $itemData['item_location'],
                    'trans_comment'   => 'RECV ' . $receivingId,
                    'trans_inventory' => $itemsReceived,
                ];

                $inventory->insert($invData, false);
                $attribute->copy_attribute_links($itemData['item_id'], 'receiving_id', $receivingId);
            }
        } catch (AccountingException|PostingException|BranchException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            if ($accountingOn && $failure instanceof PostingException) {
                (new ReceivingPosting($this->db))->recordFailure($receivingId > 0 ? (int) $receivingId : null, $failure, $employeeId, $branchId);
            }

            return ACCOUNTING_FAILED;
        } catch (Throwable $unexpected) {
            $this->db->transRollback();

            throw $unexpected;
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $receivingId : -1;
    }

    /**
     * Pays only what was typed when the amount paid is less than what the purchase comes to: the purchase
     * is saved on account, as if its way of payment were due, and what was paid is the payment of the
     * purchase to the supplier, made with the way of payment chosen, which the caller records once the
     * purchase is posted. An amount that covers the purchase leaves it paid at once, as it always was,
     * and a refund for a return is paid whole or not at all, so only an amount of zero leaves it on account.
     *
     * @param numeric-string|null $amountPaid What was typed in the amount tendered, or null when it was left empty.
     *
     * @return numeric-string|null The payment to record against the purchase, if any.
     */
    private function leaveWhatWasNotPaidOnAccount(int $receivingId, ReceivingType $type, int $supplierId, ?string $paymentType, ?string $amountPaid): ?string
    {
        $way = $paymentType === null ? null : PaymentType::fromLabel($paymentType);

        if ($amountPaid === null || $supplierId <= 0 || $type === ReceivingType::Requisition || $way === null || ! $way->usesTreasury()) {
            return null;
        }

        $purchase = (new ReceivingLoader($this->db))->load($receivingId, false);
        $owed     = ReceivingAmounts::totals($purchase->lines, $purchase->currency?->decimals ?? 2)['total'];

        if (bccomp($amountPaid, $owed, 2) >= 0 || ($type === ReceivingType::Return && bccomp($amountPaid, '0', 2) > 0)) {
            return null;
        }

        $this->db->table('receivings')->where('receiving_id', $receivingId)->update(['payment_type' => lang(PaymentType::Due->languageKey()), 'treasury_id' => null]);

        return $type === ReceivingType::Receive && bccomp($amountPaid, '0', 2) > 0 ? bcadd($amountPaid, '0', 2) : null;
    }

    /**
     * @param array<string, mixed> $line
     *
     * @throws AccountingException
     */
    private function assertNoTrackingEntry(array $line): void
    {
        $entry = $line['tracking'] ?? null;

        if ($entry instanceof TrackingEntry ? ! $entry->isEmpty() : (is_array($entry) && array_filter($entry) !== [])) {
            throw new AccountingException(AccountingError::TrackedEntryNotAllowed, 'The item is not tracked.', ['line' => (int) ($line['line'] ?? 0), 'name' => (string) ($line['name'] ?? $line['item_id'])]);
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $items
     *
     * @return array<int|string, array<string, string|int|null>> The columns that remember the unit of each line, by the key of the line.
     *
     * @throws AccountingException
     */
    private function unitColumnsOf(array $items): array
    {
        $units   = new UnitLines($this->db);
        $columns = [];

        foreach ($items as $key => $itemData) {
            $columns[$key] = $units->columnsOf($itemData, (int) ($itemData['line'] ?? 0), 'quantity', 'price', 'receiving_quantity', 'unit_price_entered');
        }

        return $columns;
    }

    /**
     * A purchase and a return to the supplier (a debit note) have a number of their own, when the
     * sequence of their kind is on. A transfer between locations is not a document of either kind.
     *
     * @throws AccountingException
     */
    private function numberDocument(ReceivingType $type, int $receivingId, int $branchId): void
    {
        $kind = match ($type) {
            ReceivingType::Receive => DocumentType::Purchase,
            ReceivingType::Return  => DocumentType::DebitNote,
            default                => null,
        };

        if ($kind !== null) {
            (new DocumentNumbering($this->db))->assign($kind, 'receivings', $receivingId, $branchId);
        }
    }

    /**
     * Shares the discount on the whole purchase out over the lines that were just stored, and keeps
     * on each line what it takes, so that every figure that is read afterwards (the cost of the
     * stock, the average, the tax, what the supplier is owed) comes from the lines alone. A return
     * takes the share of the purchase it comes from that belongs to the units it gives back. Only
     * the lines of this purchase are written.
     *
     * @throws PostingException
     */
    private function applyInvoiceDiscount(int $receivingId, ReceivingType $type, ?int $originId, InvoiceDiscount $discount): void
    {
        if ($type !== ReceivingType::Receive && ! $discount->isNone()) {
            throw new PostingException(PostingError::PurchaseDiscountInvalid, 'Only goods coming in take a discount on the whole purchase.', ['receiving_id' => $receivingId]);
        }

        if ($type === ReceivingType::Requisition || ($type === ReceivingType::Return && $originId === null)) {
            return;
        }

        $lines = (new ReceivingLoader($this->db))->load($receivingId, false)?->lines ?? [];

        if ($type === ReceivingType::Receive) {
            if ($discount->isNone()) {
                return;
            }

            $base = array_reduce($lines, static fn (string $sum, ReceivingLine $line): string => bcadd($sum, ReceivingAmounts::lineNet($line), ReceivingAmounts::SCALE), '0');

            if (! $discount->fitsOn($base)) {
                throw new PostingException(PostingError::PurchaseDiscountInvalid, 'The discount on the whole purchase is more than the purchase comes to.', ['receiving_id' => $receivingId, 'type' => $discount->type, 'value' => $discount->value]);
            }

            $shares = InvoiceDiscountShares::forPurchase($lines, $discount);
        } else {
            $shares = (new ReturnDiscountShares($this->db))->forReturn((int) $originId, $lines, $receivingId);
        }

        $given = '0';

        foreach ($lines as $index => $line) {
            if (bccomp($shares[$index], '0', ReceivingAmounts::SCALE) > 0) {
                $this->db->table('receivings_items')->where(['receiving_id' => $receivingId, 'item_id' => $line->itemId, 'line' => $line->line])->update(['invoice_discount_share' => $shares[$index]]);
                $given = bcadd($given, $shares[$index], ReceivingAmounts::SCALE);
            }
        }

        if ($type === ReceivingType::Receive && bccomp($given, '0', ReceivingAmounts::SCALE) > 0) {
            $this->db->table('receivings')->where('receiving_id', $receivingId)->update(['invoice_discount_type' => $discount->type, 'invoice_discount' => $discount->value]);
        }
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
     * The branches a transfer between locations works in when it takes its stock out of the
     * locations of one branch and puts all of it in those of another, or null when it is not such
     * a transfer.
     *
     * @return array{from: int, to: int}|null
     */
    public function transferBetweenBranches(array $items): ?array
    {
        $locationIds = array_values(array_unique(array_filter(array_map('intval', array_column($items, 'item_location')))));

        if ($locationIds === []) {
            return null;
        }

        $branchOf = [];

        foreach ($this->db->table('stock_locations')->select('location_id, branch_id')->whereIn('location_id', $locationIds)->get()->getResultArray() as $row) {
            $branchOf[(int) $row['location_id']] = (int) $row['branch_id'];
        }

        $out = [];
        $in  = [];

        foreach ($items as $line) {
            $branch = $branchOf[(int) ($line['item_location'] ?? 0)] ?? null;
            $sign   = bccomp(self::unitsOf($line), '0', 3);

            if ($branch === null) {
                return null;
            }

            if ($sign < 0) {
                $out[$branch] = true;
            } elseif ($sign > 0) {
                $in[$branch] = true;
            }
        }

        if (count($out) !== 1 || count($in) !== 1) {
            return null;
        }

        $from = array_key_first($out);
        $to   = array_key_first($in);

        return $from === $to ? null : ['from' => $from, 'to' => $to];
    }

    /**
     * The codes of the branches two stock locations belong to when they belong to different ones,
     * or null when they belong to the same branch or one of them does not exist.
     *
     * @return array{from: string, to: string}|null
     */
    public function branchesBetweenLocations(int $sourceId, int $destinationId): ?array
    {
        $rows = $this->db->table('stock_locations l')
            ->select('l.location_id, l.branch_id, b.code')
            ->join('branches b', 'b.branch_id = l.branch_id')
            ->whereIn('l.location_id', [$sourceId, $destinationId])
            ->get()
            ->getResult();
        $byLocation = [];

        foreach ($rows as $row) {
            $byLocation[(int) $row->location_id] = $row;
        }

        $from = $byLocation[$sourceId] ?? null;
        $to   = $byLocation[$destinationId] ?? null;

        if ($from === null || $to === null || (int) $from->branch_id === (int) $to->branch_id) {
            return null;
        }

        return ['from' => (string) $from->code, 'to' => (string) $to->code];
    }

    /**
     * @param list<int|string> $locationIds
     *
     * @return array<int, string> The code of the branch of each location, by the number of the location.
     */
    public function branchCodesOfLocations(array $locationIds): array
    {
        $codes = [];

        if ($locationIds === []) {
            return $codes;
        }

        $rows = $this->db->table('stock_locations l')
            ->select('l.location_id, b.code')
            ->join('branches b', 'b.branch_id = l.branch_id')
            ->whereIn('l.location_id', array_map('intval', $locationIds))
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $codes[(int) $row->location_id] = (string) $row->code;
        }

        return $codes;
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
     * Every branch a purchase works in: the one it belongs to, and the one its stock goes to when
     * it is a transfer between branches. Empty when there is no such purchase.
     *
     * @return list<int>
     */
    public function getBranchIds(int $receivingId): array
    {
        $row = $this->db->table('receivings')->select('branch_id, to_branch_id')->where('receiving_id', $receivingId)->get()->getRow();

        if ($row === null) {
            return [];
        }

        return $row->to_branch_id === null ? [(int) $row->branch_id] : [(int) $row->branch_id, (int) $row->to_branch_id];
    }

    /**
     * A transfer between branches is made and taken back by an employee who works in both.
     *
     * @param array{from: int, to: int} $between
     *
     * @throws BranchException
     */
    private function requireBranches(array $between, int $employeeId): void
    {
        $guard = new BranchGuard($this->db);

        foreach ([$between['from'], $between['to']] as $branchId) {
            $guard->requireBranch($branchId, $employeeId);
        }
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
     * The currency and the rate a purchase is saved with. A return is in the currency and at the rate of the
     * purchase it gives back: it mirrors it, and is never re-rated. A transfer of stock is in the base. Anything
     * else is in the base currency unless a currency was chosen, which takes the rate of the day of the
     * purchase. A foreign currency needs the books to be on, because without them there is no base to convert to.
     *
     * @return array{currency_code: ?string, currency_rate: ?string}
     *
     * @throws AccountingException
     */
    private function currencyColumns(ReceivingType $type, ?int $returnOfReceivingId, string $date, int $employeeId): array
    {
        $none   = ['currency_code' => null, 'currency_rate' => null];
        $choice = $this->savingCurrency;
        $origin = $type === ReceivingType::Return && $returnOfReceivingId !== null
            ? $this->db->table('receivings')->select('currency_code, currency_rate')->where('receiving_id', $returnOfReceivingId)->get()->getRow()
            : null;

        if ($origin !== null) {
            $code = $origin->currency_code;

            if ($choice !== null && $choice[0] !== (string) $code) {
                throw new AccountingException(AccountingError::DocumentCurrencyLocked, 'A return is in the currency of the purchase it gives back.', ['receiving_id' => $returnOfReceivingId]);
            }

            return $code === null ? $none : ['currency_code' => $code, 'currency_rate' => $origin->currency_rate];
        }

        if ($choice === null) {
            return $none;
        }

        if ($type === ReceivingType::Requisition || ! (new AccountingSettings($this->db))->isEnabled()) {
            throw new AccountingException(AccountingError::ForeignCurrencyUnsupported, 'A purchase is in a foreign currency only while the books are on, and a transfer of stock never is.');
        }

        $currency = (new Currencies($this->db))->documentCurrency($choice[0], $date, $choice[1], $employeeId);

        return $currency === null ? $none : ['currency_code' => $currency->code, 'currency_rate' => $currency->rate];
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
                $unitCost = bcdiv(ReceivingAmounts::net($line), $units, 8);

                $item->applyPurchaseCost($line->itemId, $units, $purchase->currency === null ? $unitCost : bcmul($unitCost, $purchase->currency->rate, 8));
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

        if ($this->outsideRecordScope($receiving_id, $employee_id)) {
            return false;
        }

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
        $locked   = $this->db->query('SELECT receiving_id, branch_id, to_branch_id FROM ' . $this->db->prefixTable('receivings') . ' WHERE receiving_id = ? FOR UPDATE', [$receiving_id]);
        $purchase = $locked === false ? null : $locked->getRow();

        if ($purchase === null) {
            $this->db->transComplete();

            return $this->db->transStatus();
        }

        if ($purchase->to_branch_id !== null) {
            try {
                $this->requireBranches(['from' => (int) $purchase->branch_id, 'to' => (int) $purchase->to_branch_id], $employee_id);
            } catch (BranchException $refusal) {
                $this->db->transRollback();
                $this->accountingFailure = $refusal;

                return false;
            }
        }

        if ($this->hasReturns($receiving_id)) {
            $this->db->transRollback();
            $this->accountingFailure = new AccountingException(AccountingError::PurchaseHasReturns, 'A purchase that goods have been returned from cannot be deleted.', ['receiving_id' => $receiving_id]);

            return false;
        }

        $items        = $this->get_receiving_items($receiving_id)->getResultArray();
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();
        $this->lockItems($items);

        if ($accountingOn && $update_inventory) {
            try {
                $document = (new ReceivingLoader($this->db))->load($receiving_id, false);

                $this->assertStockStays($this->stockDeltas($document, null));

                if ($document?->type === ReceivingType::Receive && $this->costCarries($document)) {
                    $this->correctTheCost(model(Item::class), $document->lines, []);
                }
            } catch (AccountingException $refusal) {
                $this->db->transRollback();
                $this->accountingFailure = $refusal;

                return false;
            }
        }

        if ($update_inventory) {
            $inventory     = model('Inventory');
            $item_quantity = model(Item_quantity::class);
            $tracking      = new StockTracking($this->db);
            $reversed      = [];

            try {
                $reversed = $tracking->reverseSource('receiving', (int) $receiving_id);
            } catch (AccountingException $refusal) {
                $this->db->transRollback();
                $this->accountingFailure = $refusal;

                return false;
            }

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
                if (! in_array((int) $item['item_id'], $reversed, true)) {
                    $item_quantity->changeQuantity($item['item_id'], $item['item_location'], $units);
                }
            }
        }

        if ($accountingOn) {
            try {
                (new ReceivingPosting($this->db))->cancel($receiving_id, $employee_id);
            } catch (AccountingException|PostingException $failure) {
                $this->db->transRollback();
                $this->accountingFailure = $failure;
                (new ReceivingPosting($this->db))->recordFailure($receiving_id, $failure, $employee_id, (int) $purchase->branch_id);

                return false;
            }
        }

        try {
            (new PurchaseOrderReceipts($this->db))->releaseReceiving($receiving_id, $employee_id);
        } catch (AccountingException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            return false;
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

    public function get_payment_options(array $keep = []): array
    {
        return (new PaymentMethods())->offered([
            lang('Sales.cash')          => lang('Sales.cash'),
            lang('Sales.check')         => lang('Sales.check'),
            lang('Sales.debit')         => lang('Sales.debit'),
            lang('Sales.credit')        => lang('Sales.credit'),
            lang('Sales.due')           => lang('Sales.due'),
            lang('Sales.bank_transfer') => lang('Sales.bank_transfer'),
            lang('Sales.wallet')        => lang('Sales.wallet'),
        ], $keep);
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

        if (isset($inputs['branch_ids'])) {
            $list = implode(', ', array_map('intval', $inputs['branch_ids']));
            $where .= $inputs['branch_ids'] === [] ? ' AND 1 = 0' : " AND (receivings.branch_id IN ({$list}) OR receivings.to_branch_id IN ({$list}))";
        }

        $units = '`quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity`';
        $gross = '`item_unit_price` * ' . $units;
        $type  = '`' . $db_prefix . 'receivings_items`.`discount_type`';
        $share = 'SIGN(`quantity_purchased`) * COALESCE(`' . $db_prefix . 'receivings_items`.`invoice_discount_share`, 0)';
        $net   = '(CASE WHEN ' . $type . ' = ' . PERCENT . ' THEN ' . $gross . ' - ' . $gross . ' * `discount` / 100'
            . ' WHEN ' . $type . ' = ' . FIXED_LINE . ' THEN ' . $gross . ' - SIGN(`quantity_purchased`) * `discount`'
            . ' ELSE ' . $gross . ' - `discount` * ' . $units . ' END) - ' . $share;

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
