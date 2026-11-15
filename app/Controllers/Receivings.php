<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\InvoiceTiming;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLineEdit;
use App\Libraries\Accounting\ReceivingLoader;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\SupplierPaymentDate;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Barcode_lib;
use App\Libraries\Documents\CustomFields;
use App\Libraries\Documents\PurchasePaidAmounts;
use App\Libraries\Documents\RendersPdf;
use App\Libraries\Documents\VoucherDocument;
use App\Libraries\Documents\VoucherDocuments;
use App\Libraries\Integrations\PaymentMethods;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Receiving_lib;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Token_lib;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_kit;
use App\Models\Receiving;
use App\Models\Stock_location;
use App\Models\Supplier;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use ReflectionException;

class Receivings extends Secure_Controller
{
    use RendersPdf;

    private Receiving_lib $receiving_lib;
    private Token_lib $token_lib;
    private Barcode_lib $barcode_lib;
    private Inventory $inventory;
    private Item $item;
    private Item_kit $item_kit;
    private Receiving $receiving;
    private Stock_location $stock_location;
    private Supplier $supplier;
    private array $config;

    public function __construct()
    {
        parent::__construct('receivings');

        $this->receiving_lib = new Receiving_lib();
        $this->token_lib     = new Token_lib();
        $this->barcode_lib   = new Barcode_lib();

        $this->inventory      = model(Inventory::class);
        $this->item_kit       = model(Item_kit::class);
        $this->item           = model(Item::class);
        $this->receiving      = model(Receiving::class);
        $this->stock_location = model(Stock_location::class);
        $this->supplier       = model(Supplier::class);
        $this->config         = config(OSPOS::class)->settings;
    }

    public function getIndex(): ResponseInterface|string
    {
        if ($this->stock_location->get_allowed_locations('receivings', true) === []) {
            return redirect()->to('no_access/receivings/receivings_stock');
        }

        return $this->_reload();
    }

    /**
     * Returns search suggestions for an item. Used in app/Views/sales/register.php
     *
     * @noinspection PhpUnused
     */
    public function getItemSearch(): ResponseInterface
    {
        $search      = $this->request->getGet('term');
        $suggestions = $this->item->get_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false, 'hide_inactive' => true], true);
        $suggestions = array_merge($suggestions, $this->item_kit->get_search_suggestions($search));

        return $this->response->setJSON($suggestions);
    }

    /**
     * Gets search suggestions for a stock item. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function getStockItemSearch(): ResponseInterface
    {
        $search      = $this->request->getGet('term');
        $suggestions = $this->item->get_stock_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false, 'hide_inactive' => true], true);
        $suggestions = array_merge($suggestions, $this->item_kit->get_search_suggestions($search));

        return $this->response->setJSON($suggestions);
    }

    /**
     * Set supplier if it exists in the database. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSelectSupplier(): string
    {
        $supplier_id = $this->request->getPost('supplier', FILTER_SANITIZE_NUMBER_INT);
        if ($this->supplier->exists($supplier_id)) {
            $this->receiving_lib->set_supplier($supplier_id);
        }

        return $this->_reload();    // TODO: Hungarian notation
    }

    /**
     * Change receiving mode for current receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postChangeMode(): string
    {
        $stockDestination = $this->request->getPost('stock_destination', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $stockSource      = $this->request->getPost('stock_source', FILTER_SANITIZE_NUMBER_INT);

        if ((! $stockSource || $stockSource == $this->receiving_lib->get_stock_source())
            && (! $stockDestination || $stockDestination == $this->receiving_lib->get_stock_destination())
        ) {
            $this->receiving_lib->clear_reference();
            $mode = $this->request->getPost('mode', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $this->receiving_lib->set_mode($mode);
        } elseif ($this->stock_location->is_allowed_location((int) $stockSource, 'receivings', true) && $this->destinationAllowed($stockDestination)) {
            $this->receiving_lib->set_stock_source($stockSource);
            $this->receiving_lib->set_stock_destination($stockDestination === null || $stockDestination === '' ? null : (string) (int) $stockDestination);
        } else {
            $refused = ['error' => lang('Receivings.location_not_allowed')];
        }

        if ($this->receiving_lib->get_mode() !== 'return') {
            $this->receiving_lib->set_return_origin(null);
        }

        if ($this->receiving_lib->get_mode() !== 'receive') {
            $this->receiving_lib->clearOrderSource();
        }

        if ($this->receiving_lib->get_mode() !== 'receive') {
            $this->receiving_lib->clearInvoiceDiscount();
        }

        return $this->_reload($refused ?? []);    // TODO: Hungarian notation
    }

    private function destinationAllowed(?string $destination): bool
    {
        return $destination === null || $destination === '' || $this->stock_location->is_allowed_location((int) $destination, 'receivings', true);
    }

    /**
     * Sets receiving comment. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSetComment(): ResponseInterface
    {
        $this->receiving_lib->set_comment($this->request->getPost('comment', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets the print after sale flag for the receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSetPrintAfterSale(): ResponseInterface
    {
        $this->receiving_lib->set_print_after_sale($this->request->getPost('recv_print_after_sale') != null);

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets the reference number for the receiving.  Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSetReference(): ResponseInterface
    {
        $this->receiving_lib->set_reference($this->request->getPost('recv_reference', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets the day typed on the purchase being made, for an employee who may type it, or takes it
     * back when it is sent empty. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSetInvoiceDate(): ResponseInterface
    {
        $settings = new AccountingSettings();

        if (! $settings->isEnabled() || ! $this->mayTypeInvoiceDate()) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => AccountingMessage::of(new AccountingException(AccountingError::PermissionDenied))]);
        }

        $typed = $this->request->getPost('recv_invoice_date');

        try {
            $date = InvoiceTiming::date(is_array($typed) ? 'not a day' : (string) $typed, date('Y-m-d'), $settings->startDate());
        } catch (AccountingException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal)]);
        }

        $this->receiving_lib->setInvoiceDate($date);

        return $this->response->setJSON(['success' => true, 'due_date_label' => $this->registerDueDateLabel()]);
    }

    /**
     * Sets the payment terms typed on the purchase being made, in days, or takes them back when they
     * are sent empty. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSetPaymentTerms(): ResponseInterface
    {
        if (! (new AccountingSettings())->isEnabled()) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => AccountingMessage::of(new AccountingException(AccountingError::PermissionDenied))]);
        }

        $typed = $this->request->getPost('recv_payment_terms');

        try {
            $terms = InvoiceTiming::terms(is_array($typed) ? 'not a number' : (string) $typed);
        } catch (AccountingException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal)]);
        }

        $this->receiving_lib->setPaymentTerms($terms === null ? null : (string) $terms);

        return $this->response->setJSON(['success' => true, 'due_date_label' => $this->registerDueDateLabel()]);
    }

    /**
     * The day the purchase being made falls due, from the day it is dated and its payment terms,
     * written for the screen, or empty when it has no terms.
     */
    private function registerDueDateLabel(): string
    {
        $terms = InvoiceTiming::terms($this->receiving_lib->getPaymentTerms());

        return $terms === null ? '' : to_date(strtotime(InvoiceTiming::dueDate($this->receiving_lib->getInvoiceDate() ?? date('Y-m-d'), $terms)));
    }

    private function mayTypeInvoiceDate(): bool
    {
        return $this->employee->has_grant('receivings_invoice_date', (int) $this->employee->get_logged_in_employee_info()->person_id);
    }

    /**
     * Names the receiving the goods of a return go back from, or clears it. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSetReturnOrigin(): ResponseInterface
    {
        $receivingId = (int) $this->request->getPost('recv_return_origin', FILTER_SANITIZE_NUMBER_INT);

        if ($receivingId === 0) {
            $this->receiving_lib->set_return_origin(null);

            return $this->response->setJSON(['success' => true]);
        }

        if ($this->outsideBranchScope($receivingId)) {
            return $this->response->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        $origin = $this->receiving->get_info($receivingId)->getRowArray();

        if ($origin === null || $origin['receiving_type'] !== 'receive') {
            return $this->response->setJSON(['success' => false, 'message' => lang('Receivings.return_origin_invalid')]);
        }

        $this->receiving_lib->set_return_origin($receivingId);

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets or removes the discount on the whole receiving: a percentage or an amount, taken after the
     * discounts of the lines. Only goods that come in take one. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSetInvoiceDiscount(): string
    {
        if ($this->receiving_lib->get_mode() !== 'receive') {
            return $this->_reload(['error' => lang('Receivings.invoice_discount_wrong_mode')]);
        }

        $posted     = $this->request->getPost('invoice_discount');
        $postedType = $this->request->getPost('invoice_discount_type', FILTER_SANITIZE_NUMBER_INT);

        if (is_array($posted) || is_array($postedType)) {
            return $this->_reload(['error' => lang('Receivings.invoice_discount_invalid')]);
        }

        $raw = trim((string) $posted);

        if ($raw === '') {
            $this->receiving_lib->clearInvoiceDiscount();

            return $this->_reload();
        }

        $type   = (int) $postedType === FIXED ? FIXED : PERCENT;
        $parsed = parse_decimals($raw);

        $discount = $parsed === false || ! is_numeric($parsed) ? null : InvoiceDiscount::of($type, number_format((float) $parsed, InvoiceDiscount::SCALE, '.', ''));
        $base     = $this->receiving_lib->get_total();

        if ($discount === null || ($this->receiving_lib->get_cart() !== [] && ! $discount->fitsOn(bcadd($base, '0', InvoiceDiscount::SCALE)))) {
            return $this->_reload(['error' => lang('Receivings.invoice_discount_invalid')]);
        }

        $this->receiving_lib->setInvoiceDiscount($discount);

        return $this->_reload();
    }

    /**
     * Add an item to the receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postAdd(): string
    {
        $data = [];

        $mode                                     = $this->receiving_lib->get_mode();
        $item_id_or_number_or_item_kit_or_receipt = $this->request->getPost('item', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $this->token_lib->parse_barcode($quantity, $price, $item_id_or_number_or_item_kit_or_receipt);
        $quantity      = ($mode === 'receive' || $mode === 'requisition') ? $quantity : -$quantity;
        $item_location = $this->receiving_lib->get_stock_source();
        $discount      = $this->config['default_receivings_discount'];
        $discount_type = $this->config['default_receivings_discount_type'];

        if ($mode === 'return' && $this->receiving->is_valid_receipt($item_id_or_number_or_item_kit_or_receipt)) {
            if ($this->outsideBranchScope($this->receivingIdOf($item_id_or_number_or_item_kit_or_receipt))) {
                $data['error'] = $this->scopeMessage();
            } else {
                $this->receiving_lib->return_entire_receiving($item_id_or_number_or_item_kit_or_receipt);
            }
        } elseif ($this->item_kit->is_valid_item_kit($item_id_or_number_or_item_kit_or_receipt)) {
            $this->receiving_lib->add_item_kit($item_id_or_number_or_item_kit_or_receipt, $item_location, $discount, $discount_type);
        } elseif (! $this->receiving_lib->add_item($item_id_or_number_or_item_kit_or_receipt, $quantity, $item_location, $discount, $discount_type)) {
            $data['error'] = lang('Receivings.unable_to_add_item');
        }

        return $this->_reload($data);    // TODO: Hungarian notation
    }

    /**
     * Edit line item in current receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postEditItem(int|string|null $item_id): string
    {
        $data = [];

        $validation_rule = [
            'price'       => 'trim|required|decimal_locale',
            'quantity'    => 'trim|required|decimal_locale',
            'discount'    => 'trim|permit_empty|decimal_locale',
            'tax_percent' => 'trim|permit_empty|decimal_locale',
        ];

        $price                  = parse_decimals($this->request->getPost('price'));
        $quantity               = parse_quantity($this->request->getPost('quantity'));
        $raw_receiving_quantity = parse_quantity($this->request->getPost('receiving_quantity'));

        $description   = $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);    // TODO: Duplicated code
        $serialnumber  = $this->request->getPost('serialnumber', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
        $discount_type = $this->request->getPost('discount_type', FILTER_SANITIZE_NUMBER_INT);
        $discount      = $discount_type
            ? parse_quantity(filter_var($this->request->getPost('discount'), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION))
            : parse_decimals(filter_var($this->request->getPost('discount'), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION));

        $receiving_quantity = filter_var($raw_receiving_quantity, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        $rawTax     = $this->request->getPost('tax_percent');
        $taxPercent = $rawTax === null || $rawTax === '' || ! $this->employee->has_grant('items', $this->employee->get_logged_in_employee_info()->person_id)
            ? null
            : (string) parse_tax((string) $rawTax);

        if ($taxPercent !== null && (! is_numeric($taxPercent) || $taxPercent < 0 || $taxPercent > 100)) {
            $data['error'] = lang('Receivings.error_editing_item');
        } elseif ($this->validate($validation_rule)) {
            $postedUnit = $this->request->getPost('unit_id');
            $unitId     = $postedUnit === null || $postedUnit === '' ? null : (int) $postedUnit;
            $inUnit     = isset($this->receiving_lib->get_cart()[$item_id]['unit_id']) ? (int) $this->receiving_lib->get_cart()[$item_id]['unit_id'] : null;

            if ($postedUnit !== null && $unitId !== $inUnit) {
                $this->receiving_lib->changeUnit((string) $item_id, $unitId);
            } else {
                $this->receiving_lib->edit_item($item_id, $description, $serialnumber, $quantity, $discount, $discount_type, $price, $receiving_quantity, $taxPercent);
            }

            $unitFailure = $this->receiving_lib->unitFailure();

            if ($unitFailure !== null) {
                $data['error'] = AccountingMessage::of($unitFailure);
            }

            if ($this->request->getPost('tracking_serials') !== null || $this->request->getPost('batch_number') !== null) {
                $this->receiving_lib->setTracking((string) $item_id, $this->postedText('tracking_serials'), $this->postedText('batch_number'), $this->postedText('batch_expiry'));
            }
        } else {
            $data['error'] = lang('Receivings.error_editing_item');
        }

        return $this->_reload($data);    // TODO: Hungarian notation
    }

    /**
     * Edit a receiving. Used in app/Controllers/Receivings.php
     *
     * @noinspection PhpUnused
     *
     * @param mixed $receiving_id
     *
     * @return string
     */
    public function getEdit($receiving_id): ResponseInterface|string
    {
        if ($this->outsideBranchScope((int) $receiving_id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = [];

        $data['suppliers'] = ['' => 'No Supplier'];

        foreach ($this->supplier->get_all()->getResult() as $supplier) {
            $data['suppliers'][$supplier->person_id] = $supplier->first_name . ' ' . $supplier->last_name;
        }

        $receivingInfo = $this->receiving->get_info($receiving_id)->getRowArray();

        $currentEmployeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $canAssignEmployee = $this->employee->has_grant('employees', $currentEmployeeId);

        $data['employees'] = [];
        if ($canAssignEmployee) {
            foreach ($this->employee->get_all()->getResult() as $employee) {
                $data['employees'][$employee->person_id] = $employee->first_name . ' ' . $employee->last_name;
            }
        } else {
            $storedEmployeeId                     = $receivingInfo['employee_id'];
            $storedEmployee                       = $this->employee->get_info($storedEmployeeId);
            $data['employees'][$storedEmployeeId] = $storedEmployee->first_name . ' ' . $storedEmployee->last_name;
        }

        $data['selected_supplier_name'] = ! empty($receivingInfo['supplier_id']) ? $receivingInfo['company_name'] : '';
        $data['selected_supplier_id']   = $receivingInfo['supplier_id'];
        $data['receiving_info']         = $receivingInfo;
        $data['can_assign_employee']    = $canAssignEmployee;
        $data['locked']                 = false;
        $data['journals']               = [];
        $data['takes_payments']         = $receivingInfo['receiving_type'] === ReceivingType::Receive->value && $receivingInfo['supplier_id'] !== null;
        $data['payments']               = $data['takes_payments'] ? $this->receiving->get_payments((int) $receiving_id) : [];
        $data['payment_statuses']       = [];
        $data['payable']                = null;
        $data['credit']                 = '0';
        $data['applications']           = [];
        $data['can_debit_note']         = false;
        $data['payment_options']        = $this->supplierPaymentOptions();
        $data['row_payment_options']    = $this->supplierPaymentOptions(array_map(static fn (object $payment): string => (string) $payment->payment_type, $data['payments']));
        $data['paymentNumbers']         = (new DocumentNumbering())->numbers(DocumentType::SupplierPayment, 'receivings_payments', array_map(static fn (object $payment): int => (int) $payment->payment_id, $data['payments']));
        $data['paymentDates']           = [];
        $data['today']                  = date('Y-m-d');
        $vouchers                       = new VoucherDocuments();
        $data['receiptIds']             = array_values(array_filter(
            array_map(static fn (object $payment): int => (int) $payment->payment_id, $data['payments']),
            static fn (int $paymentId): bool => $vouchers->supplierPayment($paymentId, (int) $currentEmployeeId) !== null,
        ));

        foreach ($data['payments'] as $payment) {
            $data['paymentDates'][(int) $payment->payment_id] = SupplierPaymentDate::of($payment->payment_date, (string) $payment->payment_time);
        }

        $data += $this->documentNumberOf((int) $receiving_id);
        $data['treasury_options']     = [];
        $data['new_treasury_options'] = [];

        $data['invoiceDiscount']      = InvoiceDiscount::stored($receivingInfo['invoice_discount_type'] === null ? null : (int) $receivingInfo['invoice_discount_type'], $receivingInfo['invoice_discount'] === null ? null : (string) $receivingInfo['invoice_discount']);
        $data['invoiceDiscountShare'] = $this->invoiceDiscountOf((int) $receiving_id);
        $data['invoice_timing']       = null;

        if ((new AccountingSettings())->isEnabled() && $receivingInfo['receiving_type'] === ReceivingType::Receive->value) {
            $timing = $this->receiving->getInvoiceTiming((int) $receiving_id);
            $date   = $timing['invoice_date'] ?? substr((string) $receivingInfo['receiving_time'], 0, 10);

            $data['invoice_timing'] = ['date' => $date, 'terms' => $timing['payment_terms_days'], 'due' => $timing['due_date'] ?? $date];
        }

        $data['lines_editable']    = $receivingInfo['receiving_type'] === ReceivingType::Receive->value && ! $this->receiving->hasReturns((int) $receiving_id);
        $data['lines_has_returns'] = $receivingInfo['receiving_type'] === ReceivingType::Receive->value && ! $data['lines_editable'];
        $data['lines']             = $data['lines_editable'] ? $this->editableLines((int) $receiving_id) : [];
        $data['lines_allowed']     = $this->mayCorrectLocationsOf($data['lines']);
        $data['can_edit_tax']      = $this->employee->has_grant('items', $currentEmployeeId);

        if ((new AccountingSettings())->isEnabled()) {
            $data['treasury_options']     = $data['takes_payments'] ? $this->treasuryOptions((int) ($receivingInfo['branch_id'] ?? 0), $data['payments']) : [];
            $data['new_treasury_options'] = $data['takes_payments'] ? $this->treasuryOptions((int) ($receivingInfo['branch_id'] ?? 0), []) : [];

            $posting = new ReceivingPosting();

            $data['locked']           = $posting->isPosted((int) $receiving_id);
            $data['payment_statuses'] = $this->paymentStatusOptions();

            if ($this->employee->has_grant('accounting_journals', $currentEmployeeId)) {
                $data['journals'] = $posting->journalsOf((int) $receiving_id);
            }

            if ($data['locked'] && $data['takes_payments']) {
                $position               = (new SupplierLedger())->position((int) $receivingInfo['supplier_id']);
                $data['payable']        = $position->purchase((int) $receiving_id);
                $data['credit']         = $position->credit();
                $data['applications']   = $this->applicationsOf((int) $receiving_id, (int) $receivingInfo['supplier_id']);
                $data['can_debit_note'] = $data['payable'] !== null && ! $this->nothingLeftToReturn((int) $receiving_id);
            } elseif ($data['locked'] && $receivingInfo['receiving_type'] === ReceivingType::Return->value && $receivingInfo['supplier_id'] !== null) {
                $data['applications'] = $this->applicationsOf((int) $receiving_id, (int) $receivingInfo['supplier_id']);
            }
        }

        return view('receivings/form', $data);
    }

    /**
     * Deletes an item from the current receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     *
     * @param mixed $item_number
     */
    public function getDeleteItem($item_number): string
    {
        $this->receiving_lib->delete_item($item_number);

        return $this->_reload();    // TODO: Hungarian notation
    }

    /**
     * @throws ReflectionException
     */
    public function postDelete(int $receiving_id = -1, bool $update_inventory = true): ResponseInterface
    {
        $employee_id   = $this->employee->get_logged_in_employee_info()->person_id;
        $receiving_ids = $receiving_id == -1 ? $this->request->getPost('ids', FILTER_SANITIZE_NUMBER_INT) : [$receiving_id];    // TODO: Replace -1 with constant

        if ($this->outsideBranchScope(...array_map('intval', (array) $receiving_ids))) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        if ($this->receiving->delete_list($receiving_ids, $employee_id, $update_inventory)) {    // TODO: Likely need to surround this block of code in a try-catch to catch the ReflectionException
            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Receivings.successfully_deleted') . ' ' . count($receiving_ids) . ' ' . lang('Receivings.one_or_multiple'),
                'ids'     => $receiving_ids,
            ]);
        }

        return $this->response->setJSON(['success' => false, 'message' => $this->accountingFailureMessage() ?? lang('Receivings.cannot_be_deleted')]);
    }

    /**
     * Removes a supplier from a receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function getRemoveSupplier(): string
    {
        $this->receiving_lib->clear_reference();
        $this->receiving_lib->remove_supplier();

        return $this->_reload();    // TODO: Hungarian notation
    }

    /**
     * Complete and finalize receiving.  Used in app/Views/receivings/receiving.php
     *
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postComplete(): string
    {
        $data = [];

        $data['cart'] = $this->receiving_lib->get_cart();

        $problem = $this->cartBranchProblem($data['cart']);

        $origin = $this->receiving_lib->get_return_origin();

        if ($problem === null && $origin !== null && $this->outsideBranchScope($origin)) {
            $problem = $this->scopeMessage();
        }

        if ($problem !== null) {
            return $this->_reload(['error' => $problem]);
        }

        $data['total']                = $this->receiving_lib->get_total();
        $data['tax_total']            = $this->receiving_lib->get_tax_total();
        $data['total_with_tax']       = $this->receiving_lib->get_total_with_tax();
        $data['transaction_time']     = to_datetime(time());
        $data['mode']                 = $this->receiving_lib->get_mode();
        $data['comment']              = $this->receiving_lib->get_comment();
        $data['reference']            = $this->receiving_lib->get_reference();
        $data['payment_type']         = $this->request->getPost('payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $data['show_stock_locations'] = $this->stock_location->show_locations('receivings');
        $data['stock_location']       = $this->receiving_lib->get_stock_source();

        $refusal = is_string($data['payment_type']) ? (new PaymentMethods())->refusal($data['payment_type']) : null;

        if ($refusal !== null) {
            return $this->_reload(['error' => $refusal]);
        }

        $data['invoiceDiscount']       = $this->receiving_lib->getInvoiceDiscount();
        $data['invoiceDiscountAmount'] = $this->receiving_lib->getInvoiceDiscountAmount();

        $amountPaid = $this->amountTendered($data['payment_type'], $data['mode'], $data['total_with_tax']);

        if (is_array($amountPaid)) {
            return $this->_reload(['error' => $amountPaid['error']]);
        }

        $employeeId       = $this->employee->get_logged_in_employee_info()->person_id;
        $employeeInfo     = $this->employee->get_info($employeeId);
        $data['employee'] = $employeeInfo->first_name . ' ' . $employeeInfo->last_name;

        $supplierId = $this->receiving_lib->get_supplier();
        if ($supplierId != -1) {
            $supplierInfo             = $this->supplier->get_info($supplierId);
            $data['supplier']         = $supplierInfo->company_name;    // TODO: duplicated code
            $data['first_name']       = $supplierInfo->first_name;
            $data['last_name']        = $supplierInfo->last_name;
            $data['supplier_email']   = $supplierInfo->email;
            $data['supplier_address'] = $supplierInfo->address_1;
            $data += $this->supplierNumbersOf($supplierInfo);
            if (! empty($supplierInfo->zip) || ! empty($supplierInfo->city)) {
                $data['supplier_location'] = $supplierInfo->zip . ' ' . $supplierInfo->city;
            } else {
                $data['supplier_location'] = '';
            }
        }

        // SAVE receiving to database
        $saved = $this->receiving->save_value($data['cart'], $supplierId, $employeeId, $data['comment'], $data['reference'], $data['payment_type'], $data['stock_location'], $origin, $data['mode'] === 'receive' ? $data['invoiceDiscount'] : null, $this->receiving_lib->getInvoiceDate(), $this->receiving_lib->getPaymentTerms(), $this->receiving_lib->getOrderSource(), $amountPaid);

        if ($saved === ACCOUNTING_FAILED) {
            return $this->_reload(['error' => $this->accountingFailureMessage() ?? lang('Receivings.transaction_failed')]);
        }

        if ($saved === -1) {    // TODO: Replace -1 with a constant
            return $this->_reload(['error' => lang('Receivings.transaction_failed')]);
        }

        $data['receiving_id'] = 'RECV ' . $saved;
        $data['barcode']      = $this->barcode_lib->generate_receipt_barcode($data['receiving_id']);
        $data += $this->documentNumberOf((int) $saved);
        $data += $this->tenderedFigures($amountPaid, $data['total_with_tax']);

        $timing               = $this->receiving->getInvoiceTiming((int) $saved);
        $data['invoice_date'] = $timing['invoice_date'] === null ? null : to_date(strtotime($timing['invoice_date']));
        $data['due_date']     = $timing['payment_terms_days'] === null || $timing['due_date'] === null ? null : to_date(strtotime($timing['due_date']));

        $data['print_after_sale'] = $this->receiving_lib->is_print_after_sale();

        $view = view('receivings/receipt', $data);

        $this->receiving_lib->clear_all();

        return $view;
    }

    /**
     * What was typed in the box of the amount tendered, which is what the purchase is paid with: null when
     * the box was left empty or the purchase is left on account, so that the whole purchase is paid with
     * the way of payment chosen, as it always was. A refund of a return is received whole or not at all.
     * An amount that cannot be taken is returned as the error to show.
     *
     * @return array{error: string}|numeric-string|null
     */
    private function amountTendered(?string $paymentType, string $mode, string $totalWithTax): array|string|null
    {
        $typed = trim((string) $this->request->getPost('amount_tendered'));
        $way   = $paymentType === null ? null : PaymentType::fromLabel($paymentType);

        if ($typed === '' || $way === null || ! $way->usesTreasury()) {
            return null;
        }

        $parsed = parse_decimals($typed);

        if ($parsed === false) {
            return ['error' => lang('Sales.must_enter_numeric')];
        }

        if ((float) $parsed < 0) {
            return ['error' => lang('Sales.negative_amount_tendered')];
        }

        $amount = number_format((float) $parsed, 4, '.', '');
        $owed   = ltrim(SaleAmounts::round($totalWithTax, 2), '-');

        if ($mode === 'return' && bccomp($amount, '0', 2) > 0 && bccomp($amount, $owed, 2) < 0) {
            return ['error' => lang('Receivings.part_refund_not_supported')];
        }

        return $amount;
    }

    /**
     * What the receipt shows of the amount tendered: what was paid and either the change that goes back or
     * the part of the purchase that is still owed.
     *
     * @param numeric-string|null $amountPaid
     *
     * @return array<string, float|string>
     */
    private function tenderedFigures(?string $amountPaid, string $totalWithTax): array
    {
        if ($amountPaid === null) {
            return [];
        }

        $owed       = ltrim(SaleAmounts::round($totalWithTax, 2), '-');
        $difference = bcsub($amountPaid, $owed, 2);
        $figures    = ['amount_tendered' => (float) $amountPaid];

        return bccomp($difference, '0', 2) >= 0
            ? $figures + ['amount_change' => to_currency((float) $difference)]
            : $figures + ['amount_due' => to_currency((float) ltrim($difference, '-'))];
    }

    /**
     * Complete a receiving requisition. Used in app/Views/receivings/receiving.php.
     *
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postRequisitionComplete(): string
    {
        if (! $this->stock_location->is_allowed_location($this->receiving_lib->get_stock_source(), 'receivings')
            || ! $this->stock_location->is_allowed_location((int) $this->receiving_lib->get_stock_destination(), 'receivings')
        ) {
            return $this->_reload(['error' => lang('Receivings.location_not_allowed')]);
        }

        if ($this->receiving_lib->get_stock_source() != $this->receiving_lib->get_stock_destination()) {
            $unavailable = $this->unavailableRequisitionItem();

            if ($unavailable !== null) {
                return $this->_reload(['error' => lang('Receivings.unable_to_add_item') . ' ' . $unavailable]);
            }

            $this->restoreRequisitionCart();

            foreach ($this->receiving_lib->get_cart() as $item) {
                $this->receiving_lib->delete_item($item['line']);
                $this->receiving_lib->add_item($item['item_id'], $item['quantity'], $this->receiving_lib->get_stock_destination(), $item['discount_type']);
                $this->receiving_lib->add_item($item['item_id'], -$item['quantity'], $this->receiving_lib->get_stock_source(), $item['discount_type']);
            }

            return $this->postComplete();
        }
        $data['error'] = lang('Receivings.error_requisition');

        return $this->_reload($data);    // TODO: Hungarian notation
    }

    /**
     * The name of the first item of the cart that can no longer be added to a receiving because it is
     * inactive, suspended or deleted, so that a requisition is refused whole and not completed without
     * the line the register would drop.
     */
    private function unavailableRequisitionItem(): ?string
    {
        foreach ($this->receiving_lib->get_cart() as $line) {
            if ($this->item->get_info_by_id_or_number((string) $line['item_id'], false) === '') {
                return (string) $line['name'];
            }
        }

        return null;
    }

    /**
     * A requisition that was refused leaves its cart split into what goes in and what comes out. The next
     * attempt starts again from what was entered, the lines that go in put back at the source, so that it
     * does not take the two halves for lines of its own and turn them into goods that come from nowhere.
     */
    private function restoreRequisitionCart(): void
    {
        $cart = $this->receiving_lib->get_cart();

        if (array_filter($cart, static fn (array $item): bool => (float) $item['quantity'] < 0) === []) {
            return;
        }

        foreach ($cart as $item) {
            $this->receiving_lib->delete_item($item['line']);
        }

        foreach ($cart as $item) {
            if ((float) $item['quantity'] > 0) {
                $this->receiving_lib->add_item($item['item_id'], $item['quantity'], $this->receiving_lib->get_stock_source(), $item['discount_type']);
            }
        }
    }

    /**
     * Gets the receipt for a receiving. Used in app/Views/receivings/form.php
     *
     * @noinspection PhpUnused
     *
     * @param mixed $receiving_id
     *
     * @return string
     */
    public function getReceipt($receiving_id): ResponseInterface|string
    {
        if ($this->outsideViewScope((int) $receiving_id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->receivingData((int) $receiving_id);

        $data['print_after_sale'] = false;

        $view = view('receivings/receipt', $data);

        $this->receiving_lib->clear_all();

        return $view;
    }

    /**
     * What the receipt and the documents of a receiving show: its lines, its totals, its supplier and the
     * numbers it goes by. It leaves the lines of the receiving in the register of the library, which the
     * caller clears.
     *
     * @return array<string, mixed>
     */
    private function receivingData(int $receivingId): array
    {
        $receivingInfo = $this->receiving->get_info($receivingId)->getRowArray();
        $this->receiving_lib->copy_entire_receiving($receivingId);
        $data['cart']                 = $this->receiving_lib->get_cart();
        $data['total']                = $this->receiving_lib->get_total();
        $data['tax_total']            = $this->receiving_lib->get_tax_total();
        $data['total_with_tax']       = $this->receiving_lib->get_total_with_tax();
        $data['mode']                 = $this->receiving_lib->get_mode();
        $timing                       = $this->receiving->getInvoiceTiming((int) $receivingId);
        $data['transaction_time']     = to_datetime(strtotime($receivingInfo['receiving_time']));
        $data['invoice_date']         = $timing['invoice_date'] === null ? null : to_date(strtotime($timing['invoice_date']));
        $data['due_date']             = $timing['payment_terms_days'] === null || $timing['due_date'] === null ? null : to_date(strtotime($timing['due_date']));
        $data['show_stock_locations'] = $this->stock_location->show_locations('receivings');
        $data['payment_type']         = $receivingInfo['payment_type'];
        $data['reference']            = $this->receiving_lib->get_reference();
        $data['receiving_id']         = 'RECV ' . $receivingId;
        $data['barcode']              = $this->barcode_lib->generate_receipt_barcode($data['receiving_id']);
        $data += $this->documentNumberOf((int) $receivingId);

        $data['invoiceDiscount']       = $this->receiving_lib->getInvoiceDiscount();
        $data['invoiceDiscountAmount'] = $this->receiving_lib->getInvoiceDiscountAmount();

        $employeeInfo     = $this->employee->get_info($receivingInfo['employee_id']);
        $data['employee'] = $employeeInfo->first_name . ' ' . $employeeInfo->last_name;

        $supplierId = $this->receiving_lib->get_supplier();    // TODO: Duplicated code
        if ($supplierId != -1) {
            $supplierInfo             = $this->supplier->get_info($supplierId);
            $data['supplier']         = $supplierInfo->company_name;
            $data['first_name']       = $supplierInfo->first_name;
            $data['last_name']        = $supplierInfo->last_name;
            $data['supplier_email']   = $supplierInfo->email;
            $data['supplier_address'] = $supplierInfo->address_1;
            $data += $this->supplierNumbersOf($supplierInfo);
            if (! empty($supplierInfo->zip) || ! empty($supplierInfo->city)) {
                $data['supplier_location'] = $supplierInfo->zip . ' ' . $supplierInfo->city;
            } else {
                $data['supplier_location'] = '';
            }
        }

        return $data;
    }

    /**
     * The purchase invoice of a receiving, or its debit note when it sent goods back, ready to print.
     * Used in app/Views/receivings/receipt.php
     *
     * @noinspection PhpUnused
     */
    public function getPrint(int $receivingId): string
    {
        $data = $this->purchaseDocument($receivingId);

        return view('documents/screen', [
            'inner'     => 'documents/purchase_invoice',
            'data'      => ['receiving' => $data],
            'pdfUrl'    => 'receivings/pdf/' . $receivingId,
            'backUrl'   => 'receivings',
            'backLabel' => lang('Receivings.register'),
            'backIcon'  => 'bi-box-arrow-in-down',
        ]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function getPdf(int $receivingId): ResponseInterface
    {
        $data    = $this->purchaseDocument($receivingId);
        $profile = purchaseDocumentProfile($data);

        return $this->pdfResponse($this->response, view('documents/purchase_invoice_pdf', ['receiving' => $data]), $profile['title'], $profile['number']);
    }

    /**
     * The receipt of a payment made to a supplier, ready to print. Linked from the payments of app/Views/receivings/form.php
     *
     * @noinspection PhpUnused
     */
    public function getPaymentReceipt(int $paymentId): string
    {
        $voucher = $this->supplierReceipt($paymentId);

        return view('documents/screen', [
            'inner'     => 'documents/voucher',
            'data'      => ['voucher' => $voucher],
            'pdfUrl'    => 'receivings/paymentReceiptPdf/' . $paymentId,
            'backUrl'   => 'receivings',
            'backLabel' => lang('Receivings.register'),
            'backIcon'  => 'bi-box-arrow-in-down',
        ]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function getPaymentReceiptPdf(int $paymentId): ResponseInterface
    {
        $voucher = $this->supplierReceipt($paymentId);

        return $this->pdfResponse($this->response, view('documents/voucher_pdf', ['voucher' => $voucher]), $voucher->title, $voucher->number);
    }

    private function supplierReceipt(int $paymentId): VoucherDocument
    {
        $voucher = (new VoucherDocuments())->supplierPayment($paymentId, (int) $this->session->get('person_id'));

        if ($voucher === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $voucher;
    }

    /**
     * What the purchase invoice of a receiving shows: the data of its receipt, what has been paid of it,
     * and what kind of document it is. A receiving the employee may not see, one that does not exist and a
     * stock requisition are not purchase invoices.
     *
     * @return array<string, mixed>
     */
    private function purchaseDocument(int $receivingId): array
    {
        if ($this->outsideViewScope($receivingId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $info = $this->receiving->get_info($receivingId)->getRowArray();
        $type = ReceivingType::tryFrom((string) ($info['receiving_type'] ?? ''));

        if ($type === null || $type === ReceivingType::Requisition) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->receivingData($receivingId);
        $this->receiving_lib->clear_all();

        $settled = $type === ReceivingType::Return
            ? ['paid' => '0', 'due' => '0']
            : (new PurchasePaidAmounts())->read(
                $receivingId,
                ($info['supplier_id'] ?? null) === null ? null : (int) $info['supplier_id'],
                $info['payment_type'] ?? null,
                ($info['currency_code'] ?? null) !== null,
                bcadd((string) $data['total_with_tax'], '0', 4),
            );

        $data['purchase_type']       = $type->value;
        $data['supplier_id']         = ($info['supplier_id'] ?? null) === null ? null : (int) $info['supplier_id'];
        $data['item_printed_fields'] = (new CustomFields())->printableFor('item', array_map(static fn (array $line): int => (int) $line['item_id'], array_values($data['cart'])));
        $data['paid']                = $settled['paid'];
        $data['due']                 = $settled['due'];
        $data['receiving_num']       = $receivingId;

        return $data;
    }

    private function postedText(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Two locations of different branches can carry the same name, so someone who works in more than one
     * branch is told which branch each one belongs to.
     *
     * @param array<int|string, string> $locations The names by the number of the location.
     *
     * @return array<int|string, string>
     */
    private function locationsWithBranch(array $locations): array
    {
        if ($locations === [] || ! (new BranchGuard())->seesBranches((int) $this->session->get('person_id'))) {
            return $locations;
        }

        foreach ($this->receiving->branchCodesOfLocations(array_keys($locations)) as $locationId => $code) {
            $locations[$locationId] .= ' (' . $code . ')';
        }

        return $locations;
    }

    private function _reload(array $data = []): string    // TODO: Hungarian notation
    {
        $data['cart']                 = $this->receiving_lib->get_cart();
        $data['unit_choices']         = $this->receiving_lib->unitChoices($data['cart']);
        $data['modes']                = ['receive' => lang('Receivings.receiving'), 'return' => lang('Receivings.return')];
        $data['mode']                 = $this->receiving_lib->get_mode();
        $data['stock_locations']      = $this->locationsWithBranch($this->stock_location->get_allowed_locations('receivings', true));
        $data['show_stock_locations'] = count($data['stock_locations']) > 1;
        if ($data['show_stock_locations']) {
            $data['modes']['requisition'] = lang('Receivings.requisition');
            $data['stock_source']         = $this->receiving_lib->get_stock_source();
            $data['stock_destination']    = $this->receiving_lib->get_stock_destination();

            if ($data['mode'] === 'requisition') {
                $data['between_branches'] = $this->receiving->branchesBetweenLocations((int) $data['stock_source'], (int) $data['stock_destination']);
            }
        }

        $data['total']                 = $this->receiving_lib->get_total();
        $data['tax_total']             = $this->receiving_lib->get_tax_total();
        $data['total_with_tax']        = $this->receiving_lib->get_total_with_tax();
        $data['return_origin']         = $this->receiving_lib->get_return_origin();
        $data['order_source']          = $this->orderSourceLabel();
        $data['items_module_allowed']  = $this->employee->has_grant('items', $this->employee->get_logged_in_employee_info()->person_id);
        $data['comment']               = $this->receiving_lib->get_comment();
        $data['reference']             = $this->receiving_lib->get_reference();
        $data['payment_options']       = $this->receiving->get_payment_options();
        $data['accounting_enabled']    = (new AccountingSettings())->isEnabled();
        $data['invoice_date']          = $this->receiving_lib->getInvoiceDate();
        $data['payment_terms']         = $this->receiving_lib->getPaymentTerms();
        $data['due_date_label']        = $this->registerDueDateLabel();
        $data['can_type_invoice_date'] = $this->mayTypeInvoiceDate();
        $data['today']                 = date('Y-m-d');

        $data['invoiceDiscount']       = $this->receiving_lib->getInvoiceDiscount();
        $data['invoiceDiscountAmount'] = $this->receiving_lib->getInvoiceDiscountAmount();

        $discountProblem = $this->receiving_lib->invoiceDiscountProblem();

        if ($discountProblem !== null && ! isset($data['error'])) {
            $data['error'] = lang("Receivings.{$discountProblem->value}");
        }

        $supplier_id = $this->receiving_lib->get_supplier();

        if ($supplier_id != -1) {    // TODO: Duplicated Code... replace -1 with a constant
            $supplier_info            = $this->supplier->get_info($supplier_id);
            $data['supplier']         = $supplier_info->company_name;
            $data['first_name']       = $supplier_info->first_name;
            $data['last_name']        = $supplier_info->last_name;
            $data['supplier_email']   = $supplier_info->email;
            $data['supplier_address'] = $supplier_info->address_1;
            $data += $this->supplierNumbersOf($supplier_info);
            if (! empty($supplier_info->zip) || ! empty($supplier_info->city)) {
                $data['supplier_location'] = $supplier_info->zip . ' ' . $supplier_info->city;
            } else {
                $data['supplier_location'] = '';
            }
        }

        $data['print_after_sale'] = $this->receiving_lib->is_print_after_sale();

        return view('receivings/receiving', $data);
    }

    /**
     * @throws ReflectionException
     */
    public function postSave(int $receiving_id = -1): ResponseInterface    // TODO: Replace -1 with a constant
    {
        if ($this->outsideBranchScope($receiving_id)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        $newDate = $this->request->getPost('date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $dateFormatter = date_create_from_format($this->config['dateformat'] . ' ' . $this->config['timeformat'], $newDate);

        if ($dateFormatter === false) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Accounting.error_invalid_date'), 'id' => $receiving_id]);
        }

        $receivingTime = $dateFormatter->format('Y-m-d H:i:s');

        $current_employee_id   = $this->employee->get_logged_in_employee_info()->person_id;
        $submitted_employee_id = $this->request->getPost('employee_id', FILTER_SANITIZE_NUMBER_INT);

        if (! $this->employee->has_grant('employees', $current_employee_id)) {
            $existing_receiving = $this->receiving->get_info($receiving_id)->getRowArray();
            $employee_id        = $existing_receiving['employee_id'];
        } else {
            $employee_id = $submitted_employee_id;
        }

        $receiving_data = [
            'receiving_time' => $receivingTime,
            'supplier_id'    => $this->request->getPost('supplier_id') ? $this->request->getPost('supplier_id', FILTER_SANITIZE_NUMBER_INT) : null,
            'employee_id'    => $employee_id,
            'comment'        => $this->request->getPost('comment', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'reference'      => $this->request->getPost('reference') != '' ? $this->request->getPost('reference', FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null,
            'changed_by'     => (int) $current_employee_id,
        ];

        $postedTerms = $this->request->getPost('payment_terms_days');

        if (is_string($postedTerms)) {
            $receiving_data['payment_terms_days'] = $postedTerms;
        }

        $payments = $this->postedPayments((int) $current_employee_id);
        $stored   = [];

        foreach ($this->receiving->get_payments($receiving_id) as $payment) {
            $stored[(int) $payment->payment_id] = (string) $payment->payment_type;
        }

        $refusal = (new PaymentMethods())->refusalOfPayments($payments, $stored);

        if ($refusal !== null) {
            return $this->response->setJSON(['success' => false, 'message' => $refusal, 'id' => $receiving_id]);
        }

        if ($payments !== []) {
            $receiving_data['payments'] = $payments;
        }

        if ($this->receiving->update($receiving_id, $receiving_data)) {
            $this->inventory->update('RECV ' . $receiving_id, ['trans_date' => $receivingTime]);

            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Receivings.successfully_updated'),
                'id'      => $receiving_id,
            ]);
        }

        return $this->response->setJSON([
            'success' => false,
            'message' => $this->accountingFailureMessage() ?? lang('Receivings.unsuccessfully_updated'),
            'id'      => $receiving_id,
        ]);
    }

    /**
     * Corrects the lines of a saved receiving: the quantity, the price, the discount, the tax and the
     * text of each line, or takes a line out. The branch of the receiving and the stock location of every
     * line must be the employee's own, and the tax of a line is only taken from whoever may edit costs, as
     * it is in the register. Used in app/Views/receivings/form.php
     *
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postSaveLines(int $receivingId = -1): ResponseInterface
    {
        if ($this->outsideBranchScope($receivingId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        if (! $this->mayCorrectLocationsOf($this->receiving->get_receiving_items($receivingId)->getResultArray())) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Receivings.location_not_allowed')]);
        }

        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        $edits      = $this->postedLineEdits($this->employee->has_grant('items', $employeeId));

        if ($edits === null) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of(new AccountingException(AccountingError::PurchaseEditLineInvalid)), 'id' => $receivingId]);
        }

        if ($this->receiving->updateLines($receivingId, $edits, $employeeId)) {
            return $this->response->setJSON(['success' => true, 'message' => lang('Receivings.lines_updated'), 'id' => $receivingId]);
        }

        return $this->response->setJSON(['success' => false, 'message' => $this->accountingFailureMessage() ?? lang('Receivings.lines_not_updated'), 'id' => $receivingId]);
    }

    /**
     * Whether the stock location of every line is one the employee has the receivings grant for, in the
     * branches the employee works in, and one that is kept.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function mayCorrectLocationsOf(array $lines): bool
    {
        foreach (array_unique(array_column($lines, 'item_location')) as $locationId) {
            if (! $this->stock_location->is_allowed_location((int) $locationId, 'receivings', true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the lines of the form ask for, or null when one of them is not a line that can be saved.
     *
     * @return list<ReceivingLineEdit>|null
     */
    private function postedLineEdits(bool $mayEditTax): ?array
    {
        $posted = $this->request->getPost('lines');
        $edits  = [];

        foreach (is_array($posted) ? $posted : [] as $fields) {
            if (! is_array($fields) || ! isset($fields['item_id'], $fields['line'])) {
                return null;
            }

            $itemId = (int) $fields['item_id'];
            $line   = (int) $fields['line'];

            if (! empty($fields['remove'])) {
                $edits[] = ReceivingLineEdit::removing($itemId, $line);

                continue;
            }

            $edit = ReceivingLineEdit::changing($itemId, $line, [
                'quantity'      => $this->parsedLineNumber($fields, 'quantity', true),
                'price'         => $this->parsedLineNumber($fields, 'price'),
                'discount'      => $this->parsedLineNumber($fields, 'discount'),
                'discount_type' => $this->unchangedAs($fields, 'discount_type') ? null : ($fields['discount_type'] ?? null),
                'tax_percent'   => $mayEditTax ? $this->parsedLineNumber($fields, 'tax_percent') : null,
                'description'   => isset($fields['description']) && is_string($fields['description']) ? $fields['description'] : null,
                'serialnumber'  => isset($fields['serialnumber']) && is_string($fields['serialnumber']) ? $fields['serialnumber'] : null,
            ]);

            if ($edit === null) {
                return null;
            }

            $edits[] = $edit;
        }

        return $edits;
    }

    /**
     * Whether a field of a line of the form is as it was shown. The form shows numbers to the decimals of the
     * screen, which can be fewer than the line keeps, so a field that was not touched is not read back as a change.
     *
     * @param array<string, mixed> $fields
     */
    private function unchangedAs(array $fields, string $name): bool
    {
        return isset($fields[$name], $fields[$name . '_was']) && $fields[$name] === $fields[$name . '_was'];
    }

    /**
     * A number of a line of the form as the locale of the screen writes it, nothing when it is as it was shown.
     * What is not a number is kept as it was written, so that the line is refused instead of being read as nothing.
     *
     * @param array<string, mixed> $fields
     */
    private function parsedLineNumber(array $fields, string $name, bool $quantity = false): mixed
    {
        $raw = $fields[$name] ?? null;

        if (! is_string($raw) || trim($raw) === '' || $this->unchangedAs($fields, $name)) {
            return null;
        }

        $parsed = $quantity ? parse_quantity(trim($raw)) : ($name === 'tax_percent' ? parse_tax(trim($raw)) : parse_decimals(trim($raw)));

        return $parsed === false ? $raw : $parsed;
    }

    /**
     * Cancel an in-process receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postCancelReceiving(): string
    {
        $this->receiving_lib->clear_all();

        return $this->_reload();    // TODO: Hungarian Notation
    }

    /**
     * Why the books refused the last change, in the words of the language in use, or null when
     * they did not.
     */
    /**
     * Prepares the register for the debit note of a purchase: a return of what it brought in and
     * has not been given back yet, naming the purchase. Used in app/Views/receivings/form.php
     *
     * @noinspection PhpUnused
     */
    public function postDebitNote(int $receivingId = NEW_ENTRY): ResponseInterface
    {
        if ($this->outsideBranchScope($receivingId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        $origin = $this->receiving->get_info($receivingId)->getRowArray();

        if ($origin === null || $origin['receiving_type'] !== ReceivingType::Receive->value) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Receivings.return_origin_invalid')]);
        }

        if ($origin['supplier_id'] === null) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Receivings.debit_note_needs_supplier')]);
        }

        if ($this->nothingLeftToReturn($receivingId)) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Receivings.debit_note_nothing_left')]);
        }

        $this->receiving_lib->clear_all();
        $this->receiving_lib->set_mode('return');
        $this->receiving_lib->return_remaining_of_receiving($receivingId);

        return $this->response->setJSON(['success' => true, 'redirect' => site_url('receivings')]);
    }

    /**
     * The lines of a saved receiving as the edit form shows them, each with the name of its item and of its
     * stock location.
     *
     * @return list<array<string, mixed>>
     */
    private function editableLines(int $receivingId): array
    {
        $lines = [];

        foreach ($this->receiving->get_receiving_items($receivingId)->getResultArray() as $row) {
            $row['name']          = $this->item->get_info((int) $row['item_id'])->name;
            $row['location_name'] = $this->stock_location->get_location_name((int) $row['item_location']);
            $lines[]              = $row;
        }

        usort($lines, static fn (array $a, array $b): int => (int) $a['line'] <=> (int) $b['line']);

        return $lines;
    }

    /**
     * What the lines of a saved receiving took of the discount on the whole receiving.
     */
    private function invoiceDiscountOf(int $receivingId): string
    {
        $document = (new ReceivingLoader())->load($receivingId, false);
        $given    = '0';

        foreach ($document?->lines ?? [] as $line) {
            $given = bcadd($given, ReceivingAmounts::invoiceDiscount($line), ReceivingAmounts::SCALE);
        }

        return $given;
    }

    private function nothingLeftToReturn(int $receivingId): bool
    {
        $left = [];

        foreach ($this->receiving->getReturnableQuantities($receivingId) as $itemId => $quantities) {
            $left[$itemId] = $quantities['bought'] - $quantities['returned'];
        }

        foreach ($left as $remaining) {
            if ($remaining > 0.0005) {
                return false;
            }
        }

        return true;
    }

    /**
     * The payments of the edit form: the ones the purchase has, as they now stand in the form,
     * and a new one when a type and an amount were given.
     *
     * @return list<array<string, mixed>>
     */
    private function postedPayments(int $employeeId): array
    {
        $payments = [];
        $number   = (int) $this->request->getPost('number_of_payments', FILTER_SANITIZE_NUMBER_INT);

        for ($i = 0; $i < $number; $i++) {
            $payments[] = [
                'payment_id'     => (int) $this->request->getPost("payment_id_{$i}", FILTER_SANITIZE_NUMBER_INT),
                'payment_type'   => $this->request->getPost("payment_type_{$i}", FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'payment_amount' => number_format((float) parse_decimals($this->request->getPost("payment_amount_{$i}")), 2, '.', ''),
                'payment_status' => $this->request->getPost("payment_status_{$i}", FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'reference_code' => (string) $this->request->getPost("reference_code_{$i}", FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'employee_id'    => $employeeId,
                ...$this->postedDate("payment_date_{$i}"),
                ...$this->postedTreasury("treasury_id_{$i}"),
            ];
        }

        $type   = $this->request->getPost('payment_type_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $amount = $this->request->getPost('payment_amount_new');

        if ($type !== null && $type !== PAYMENT_TYPE_UNASSIGNED && ! empty($amount)) {
            $payments[] = [
                'payment_id'     => NEW_ENTRY,
                'payment_type'   => $type,
                'payment_amount' => number_format((float) parse_decimals($amount), 2, '.', ''),
                'payment_status' => $this->request->getPost('payment_status_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: PaymentStatus::Completed->value,
                'reference_code' => (string) $this->request->getPost('reference_code_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'employee_id'    => $employeeId,
                ...$this->postedDate('payment_date_new'),
                ...$this->postedTreasury('treasury_id_new'),
            ];
        }

        return $payments;
    }

    /**
     * The date a payment of the form names, when the form has the field: without it a new payment
     * is dated today and one that exists keeps its date. Whether it is a date at all is asked by
     * the model, which gets whatever was sent.
     *
     * @return array{payment_date?: mixed}
     */
    private function postedDate(string $field): array
    {
        $posted = $this->request->getPost($field);

        return $posted === null ? [] : ['payment_date' => $posted];
    }

    /**
     * The treasury a payment of the form names, when the form has the field: without it the
     * payment keeps the treasury it has.
     *
     * @return array{treasury_id?: int}
     */
    private function postedTreasury(string $field): array
    {
        $posted = $this->request->getPost($field, FILTER_SANITIZE_NUMBER_INT);

        return $posted === null ? [] : ['treasury_id' => (int) $posted];
    }

    /**
     * The treasuries a payment of a purchase can be made from, when there is a choice: the active
     * ones of the branch of the purchase that the employee may withdraw from, those of the other
     * branches they work in when they work in the branch of the purchase, and any the payments of
     * the purchase already carry.
     *
     * @param list<object> $payments
     *
     * @return array<int, string>
     */
    private function treasuryOptions(int $branchId, array $payments): array
    {
        $carried    = array_map(static fn (object $payment): int => (int) ($payment->treasury_id ?? 0), $payments);
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        $options    = (new TreasuryService())->choicesFor($branchId, TreasuryScopes::WITHDRAW, $employeeId, $carried);

        return count($options) < 2 ? [] : [0 => lang('Receivings.treasury_default')] + $options;
    }

    /**
     * The ways a supplier can be paid: the ways a purchase can be paid at once, less leaving it
     * on credit.
     *
     * @return array<string, string>
     */
    private function supplierPaymentOptions(array $keep = []): array
    {
        $options = $this->receiving->get_payment_options($keep);

        unset($options[lang('Sales.due')]);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function paymentStatusOptions(): array
    {
        $options = [];

        foreach (PaymentStatus::cases() as $status) {
            $options[$status->value] = lang($status->languageKey());
        }

        return $options;
    }

    /**
     * The number of a purchase or of a return to the supplier, with the name of its kind, for the
     * screens that show the document. Nothing when it has none, so it shows what it always showed.
     *
     * @return array{documentLabel?: string, documentNumber?: string}
     */
    private function documentNumberOf(int $receivingId): array
    {
        $numbering = new DocumentNumbering();

        foreach ([DocumentType::Purchase, DocumentType::DebitNote] as $type) {
            $number = $numbering->numbers($type, 'receivings', [$receivingId])[$receivingId] ?? null;

            if ($number !== null) {
                return ['documentLabel' => lang($type->labelKey()), 'documentNumber' => $number];
            }
        }

        return [];
    }

    /**
     * What was applied to or taken from a purchase, for the edit form.
     *
     * @return list<object>
     */
    private function applicationsOf(int $receivingId, int $supplierId): array
    {
        return array_values(array_filter(
            (new SupplierLedger())->applications($supplierId),
            static fn (object $application): bool => (int) $application->invoice_receiving_id === $receivingId || ($application->source_type === 'debit_note' && (int) $application->source_id === $receivingId),
        ));
    }

    /**
     * The tax number and the commercial registry number of a supplier, for the screens and the documents of a purchase.
     *
     * @return array{supplier_tax_id: string, supplier_commercial_registry: string}
     */
    private function supplierNumbersOf(object $supplier): array
    {
        return [
            'supplier_tax_id'              => (string) ($supplier->tax_id ?? ''),
            'supplier_commercial_registry' => (string) ($supplier->commercial_registry ?? ''),
        ];
    }

    private function orderSourceLabel(): ?string
    {
        $orderId = $this->receiving_lib->getOrderSource();

        return $orderId === null ? null : (new DocumentNumbering())->label(DocumentType::PurchaseOrder, 'purchase_orders', $orderId);
    }

    private function accountingFailureMessage(): ?string
    {
        $failure = $this->receiving->accountingFailure();

        return $failure instanceof AccountingException || $failure instanceof BranchException || $failure instanceof PostingException ? AccountingMessage::of($failure) : null;
    }

    /**
     * A receiving works in the stock locations of one branch, and only a branch the employee
     * works in. A transfer between locations may go from one branch to another, and then the
     * employee must work in both.
     */
    private function cartBranchProblem(array $cart): ?string
    {
        $guard     = new BranchGuard();
        $personId  = (int) $this->session->get('person_id');
        $between   = $this->receiving->transferBetweenBranches($cart);
        $branchIds = $between === null ? $this->receiving->branchIdsOfItems($cart) : [$between['from'], $between['to']];

        if ($between === null && count($branchIds) > 1) {
            return lang('Receivings.mixed_branches');
        }

        foreach ($branchIds as $branchId) {
            if (! $guard->canAccess($branchId, $personId)) {
                return lang('Receivings.branch_not_allowed');
            }
        }

        return null;
    }

    /**
     * Whether the employee does not work in every branch of a receiving, which is what changing or
     * deleting a transfer between branches asks of them.
     */
    private function outsideBranchScope(int ...$receivingIds): bool
    {
        $guard    = new BranchGuard();
        $personId = (int) $this->session->get('person_id');

        foreach ($receivingIds as $receivingId) {
            if ($this->receiving->outsideRecordScope($receivingId, $personId)) {
                return true;
            }

            foreach ($this->receiving->getBranchIds($receivingId) as $branchId) {
                if (! $guard->canAccess($branchId, $personId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What an employee is told when a receiving is outside their reach: for one who sees their own
     * records only the plain refusal, that says nothing of whether the receiving exists.
     */
    private function scopeMessage(): string
    {
        return (new RecordScope())->ownOnly((int) $this->session->get('person_id')) ? lang('Roles.record_unavailable') : lang('Receivings.branch_not_allowed');
    }

    /**
     * Whether the employee works in none of the branches of a receiving: the employees of either
     * branch of a transfer between branches may look at it.
     */
    private function outsideViewScope(int $receivingId): bool
    {
        $guard    = new BranchGuard();
        $personId = (int) $this->session->get('person_id');

        if ($this->receiving->outsideRecordScope($receivingId, $personId)) {
            return true;
        }

        foreach ($this->receiving->getBranchIds($receivingId) as $branchId) {
            if ($guard->canAccess($branchId, $personId)) {
                return false;
            }
        }

        return $this->receiving->getBranchIds($receivingId) !== [];
    }

    /**
     * The id of the receiving a receipt number ("RECV 12") or a reference names.
     */
    private function receivingIdOf(string $receipt): int
    {
        $pieces = explode(' ', trim($receipt));

        if (count($pieces) === 2 && preg_match('/^(RECV|KIT)$/', $pieces[0])) {
            return (int) $pieces[1];
        }

        $row = $this->receiving->get_receiving_by_reference($receipt)->getRow();

        return $row === null ? 0 : (int) $row->receiving_id;
    }
}
