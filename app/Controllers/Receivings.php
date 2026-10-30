<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\InvoiceTiming;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLoader;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Barcode_lib;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Receiving_lib;
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
        $suggestions = $this->item->get_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false], true);
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
        $suggestions = $this->item->get_stock_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false], true);
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

        try {
            $date = InvoiceTiming::date((string) $this->request->getPost('recv_invoice_date'), date('Y-m-d'), $settings->startDate());
        } catch (AccountingException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal)]);
        }

        $this->receiving_lib->set_invoice_date($date);

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

        try {
            $terms = InvoiceTiming::terms((string) $this->request->getPost('recv_payment_terms'));
        } catch (AccountingException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal)]);
        }

        $this->receiving_lib->set_payment_terms($terms === null ? null : (string) $terms);

        return $this->response->setJSON(['success' => true, 'due_date_label' => $this->registerDueDateLabel()]);
    }

    /**
     * The day the purchase being made falls due, from the day it is dated and its payment terms,
     * written for the screen, or empty when it has no terms.
     */
    private function registerDueDateLabel(): string
    {
        $terms = InvoiceTiming::terms($this->receiving_lib->get_payment_terms());

        return $terms === null ? '' : to_date(strtotime(InvoiceTiming::dueDate($this->receiving_lib->get_invoice_date() ?? date('Y-m-d'), $terms)));
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
            return $this->response->setJSON(['success' => false, 'message' => lang('Receivings.branch_not_allowed')]);
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
                $data['error'] = lang('Receivings.branch_not_allowed');
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
            $this->receiving_lib->edit_item($item_id, $description, $serialnumber, $quantity, $discount, $discount_type, $price, $receiving_quantity, $taxPercent);
        } else {
            $data['error'] = lang('Receivings.error_editing_item');
        }

        return $this->_reload($data);    // TODO: Hungarian notation
    }

    /**
     * Edit a receiving. Used in app/Controllers/Receivings.php
     *
     * @return string
     *
     * @noinspection PhpUnused
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
            $storedEmployee                        = $this->employee->get_info($storedEmployeeId);
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
        $data['paymentNumbers']         = (new DocumentNumbering())->numbers(DocumentType::SupplierPayment, 'receivings_payments', array_map(static fn (object $payment): int => (int) $payment->payment_id, $data['payments']));
        $data += $this->documentNumberOf((int) $receiving_id);
        $data['treasury_options']       = [];
        $data['new_treasury_options']   = [];

        $data['invoiceDiscount']      = InvoiceDiscount::stored($receivingInfo['invoice_discount_type'] === null ? null : (int) $receivingInfo['invoice_discount_type'], $receivingInfo['invoice_discount'] === null ? null : (string) $receivingInfo['invoice_discount']);
        $data['invoiceDiscountShare'] = $this->invoiceDiscountOf((int) $receiving_id);
        $data['invoice_timing']       = null;

        if ((new AccountingSettings())->isEnabled() && $receivingInfo['receiving_type'] === ReceivingType::Receive->value) {
            $timing = $this->receiving->getInvoiceTiming((int) $receiving_id);
            $date   = $timing['invoice_date'] ?? substr((string) $receivingInfo['receiving_time'], 0, 10);

            $data['invoice_timing'] = ['date' => $date, 'terms' => $timing['payment_terms_days'], 'due' => $timing['due_date'] ?? $date];
        }

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
                $position                = (new SupplierLedger())->position((int) $receivingInfo['supplier_id']);
                $data['payable']         = $position->purchase((int) $receiving_id);
                $data['credit']          = $position->credit();
                $data['applications']    = $this->applicationsOf((int) $receiving_id, (int) $receivingInfo['supplier_id']);
                $data['can_debit_note']  = $data['payable'] !== null && ! $this->nothingLeftToReturn((int) $receiving_id);
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
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Receivings.branch_not_allowed')]);
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
            $problem = lang('Receivings.branch_not_allowed');
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

        $data['invoiceDiscount']       = $this->receiving_lib->getInvoiceDiscount();
        $data['invoiceDiscountAmount'] = $this->receiving_lib->getInvoiceDiscountAmount();

        if ($this->request->getPost('amount_tendered') != null) {
            $data['amount_tendered'] = parse_decimals($this->request->getPost('amount_tendered'));
            $data['amount_change']   = to_currency($data['amount_tendered'] - $data['total_with_tax']);
        }

        $employeeId      = $this->employee->get_logged_in_employee_info()->person_id;
        $employeeInfo    = $this->employee->get_info($employeeId);
        $data['employee'] = $employeeInfo->first_name . ' ' . $employeeInfo->last_name;

        $supplierId = $this->receiving_lib->get_supplier();
        if ($supplierId != -1) {
            $supplierInfo            = $this->supplier->get_info($supplierId);
            $data['supplier']         = $supplierInfo->company_name;    // TODO: duplicated code
            $data['first_name']       = $supplierInfo->first_name;
            $data['last_name']        = $supplierInfo->last_name;
            $data['supplier_email']   = $supplierInfo->email;
            $data['supplier_address'] = $supplierInfo->address_1;
            if (! empty($supplierInfo->zip) || ! empty($supplierInfo->city)) {
                $data['supplier_location'] = $supplierInfo->zip . ' ' . $supplierInfo->city;
            } else {
                $data['supplier_location'] = '';
            }
        }

        // SAVE receiving to database
        $saved = $this->receiving->save_value($data['cart'], $supplierId, $employeeId, $data['comment'], $data['reference'], $data['payment_type'], $data['stock_location'], $origin, $data['mode'] === 'receive' ? $data['invoiceDiscount'] : null, $this->receiving_lib->get_invoice_date(), $this->receiving_lib->get_payment_terms());

        if ($saved === ACCOUNTING_FAILED) {
            return $this->_reload(['error' => $this->accountingFailureMessage() ?? lang('Receivings.transaction_failed')]);
        }

        if ($saved === -1) {    // TODO: Replace -1 with a constant
            return $this->_reload(['error' => lang('Receivings.transaction_failed')]);
        }

        $data['receiving_id'] = 'RECV ' . $saved;
        $data['barcode']      = $this->barcode_lib->generate_receipt_barcode($data['receiving_id']);
        $data += $this->documentNumberOf((int) $saved);

        $timing               = $this->receiving->getInvoiceTiming((int) $saved);
        $data['invoice_date'] = $timing['invoice_date'] === null ? null : to_date(strtotime($timing['invoice_date']));
        $data['due_date']     = $timing['payment_terms_days'] === null || $timing['due_date'] === null ? null : to_date(strtotime($timing['due_date']));

        $data['print_after_sale'] = $this->receiving_lib->is_print_after_sale();

        $view = view('receivings/receipt', $data);

        $this->receiving_lib->clear_all();

        return $view;
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
     * @return string
     *
     * @noinspection PhpUnused
     */
    public function getReceipt($receiving_id): ResponseInterface|string
    {
        if ($this->outsideViewScope((int) $receiving_id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $receivingInfo = $this->receiving->get_info($receiving_id)->getRowArray();
        $this->receiving_lib->copy_entire_receiving($receiving_id);
        $data['cart']                 = $this->receiving_lib->get_cart();
        $data['total']                = $this->receiving_lib->get_total();
        $data['tax_total']            = $this->receiving_lib->get_tax_total();
        $data['total_with_tax']       = $this->receiving_lib->get_total_with_tax();
        $data['mode']                 = $this->receiving_lib->get_mode();
        $timing                       = $this->receiving->getInvoiceTiming((int) $receiving_id);
        $data['transaction_time']     = to_datetime(strtotime($receivingInfo['receiving_time']));
        $data['invoice_date']         = $timing['invoice_date'] === null ? null : to_date(strtotime($timing['invoice_date']));
        $data['due_date']             = $timing['payment_terms_days'] === null || $timing['due_date'] === null ? null : to_date(strtotime($timing['due_date']));
        $data['show_stock_locations'] = $this->stock_location->show_locations('receivings');
        $data['payment_type']         = $receivingInfo['payment_type'];
        $data['reference']            = $this->receiving_lib->get_reference();
        $data['receiving_id']         = 'RECV ' . $receiving_id;
        $data['barcode']              = $this->barcode_lib->generate_receipt_barcode($data['receiving_id']);
        $data += $this->documentNumberOf((int) $receiving_id);

        $data['invoiceDiscount']       = $this->receiving_lib->getInvoiceDiscount();
        $data['invoiceDiscountAmount'] = $this->receiving_lib->getInvoiceDiscountAmount();

        $employeeInfo     = $this->employee->get_info($receivingInfo['employee_id']);
        $data['employee'] = $employeeInfo->first_name . ' ' . $employeeInfo->last_name;

        $supplierId = $this->receiving_lib->get_supplier();    // TODO: Duplicated code
        if ($supplierId != -1) {
            $supplierInfo            = $this->supplier->get_info($supplierId);
            $data['supplier']         = $supplierInfo->company_name;
            $data['first_name']       = $supplierInfo->first_name;
            $data['last_name']        = $supplierInfo->last_name;
            $data['supplier_email']   = $supplierInfo->email;
            $data['supplier_address'] = $supplierInfo->address_1;
            if (! empty($supplierInfo->zip) || ! empty($supplierInfo->city)) {
                $data['supplier_location'] = $supplierInfo->zip . ' ' . $supplierInfo->city;
            } else {
                $data['supplier_location'] = '';
            }
        }

        $data['print_after_sale'] = false;

        $view = view('receivings/receipt', $data);

        $this->receiving_lib->clear_all();

        return $view;
    }

    private function _reload(array $data = []): string    // TODO: Hungarian notation
    {
        $data['cart']                 = $this->receiving_lib->get_cart();
        $data['modes']                = ['receive' => lang('Receivings.receiving'), 'return' => lang('Receivings.return')];
        $data['mode']                 = $this->receiving_lib->get_mode();
        $data['stock_locations']      = $this->stock_location->get_allowed_locations('receivings', true);
        $data['show_stock_locations'] = count($data['stock_locations']) > 1;
        if ($data['show_stock_locations']) {
            $data['modes']['requisition'] = lang('Receivings.requisition');
            $data['stock_source']         = $this->receiving_lib->get_stock_source();
            $data['stock_destination']    = $this->receiving_lib->get_stock_destination();

            if ($data['mode'] === 'requisition') {
                $data['between_branches'] = $this->receiving->branchesBetweenLocations((int) $data['stock_source'], (int) $data['stock_destination']);
            }
        }

        $data['total']                = $this->receiving_lib->get_total();
        $data['tax_total']            = $this->receiving_lib->get_tax_total();
        $data['total_with_tax']       = $this->receiving_lib->get_total_with_tax();
        $data['return_origin']        = $this->receiving_lib->get_return_origin();
        $data['items_module_allowed'] = $this->employee->has_grant('items', $this->employee->get_logged_in_employee_info()->person_id);
        $data['comment']              = $this->receiving_lib->get_comment();
        $data['reference']            = $this->receiving_lib->get_reference();
        $data['payment_options']      = $this->receiving->get_payment_options();
        $data['accounting_enabled']   = (new AccountingSettings())->isEnabled();
        $data['invoice_date']         = $this->receiving_lib->get_invoice_date();
        $data['payment_terms']        = $this->receiving_lib->get_payment_terms();
        $data['due_date_label']       = $this->registerDueDateLabel();
        $data['can_type_invoice_date'] = $this->mayTypeInvoiceDate();
        $data['today']                = date('Y-m-d');

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
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Receivings.branch_not_allowed')]);
        }

        $newdate = $this->request->getPost('date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);    // TODO: newdate does not follow naming conventions

        $date_formatter = date_create_from_format($this->config['dateformat'] . ' ' . $this->config['timeformat'], $newdate);
        $receiving_time = $date_formatter->format('Y-m-d H:i:s');

        $current_employee_id   = $this->employee->get_logged_in_employee_info()->person_id;
        $submitted_employee_id = $this->request->getPost('employee_id', FILTER_SANITIZE_NUMBER_INT);

        if (! $this->employee->has_grant('employees', $current_employee_id)) {
            $existing_receiving = $this->receiving->get_info($receiving_id)->getRowArray();
            $employee_id        = $existing_receiving['employee_id'];
        } else {
            $employee_id = $submitted_employee_id;
        }

        $receiving_data = [
            'receiving_time' => $receiving_time,
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

        if ($payments !== []) {
            $receiving_data['payments'] = $payments;
        }

        if ($this->receiving->update($receiving_id, $receiving_data)) {
            $this->inventory->update('RECV ' . $receiving_id, ['trans_date' => $receiving_time]);

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
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Receivings.branch_not_allowed')]);
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

        for ($i = 0; $i < $number; ++$i) {
            $payments[] = [
                'payment_id'     => (int) $this->request->getPost("payment_id_{$i}", FILTER_SANITIZE_NUMBER_INT),
                'payment_type'   => $this->request->getPost("payment_type_{$i}", FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'payment_amount' => number_format((float) parse_decimals($this->request->getPost("payment_amount_{$i}")), 2, '.', ''),
                'payment_status' => $this->request->getPost("payment_status_{$i}", FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'reference_code' => (string) $this->request->getPost("reference_code_{$i}", FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'employee_id'    => $employeeId,
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
                ...$this->postedTreasury('treasury_id_new'),
            ];
        }

        return $payments;
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
    private function supplierPaymentOptions(): array
    {
        $options = $this->receiving->get_payment_options();

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
            foreach ($this->receiving->getBranchIds($receivingId) as $branchId) {
                if (! $guard->canAccess($branchId, $personId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the employee works in none of the branches of a receiving: the employees of either
     * branch of a transfer between branches may look at it.
     */
    private function outsideViewScope(int $receivingId): bool
    {
        $guard    = new BranchGuard();
        $personId = (int) $this->session->get('person_id');

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
