<?php

namespace app\Libraries;

use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\InvoiceDiscount;
use App\Libraries\InvoiceDiscountPlan;
use App\Libraries\InvoiceDiscountProblem;
use App\Models\Attribute;
use App\Models\Customer;
use App\Models\Dinner_table;
use App\Models\Item;
use App\Models\Item_kit_items;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Enums\Rounding_mode;
use App\Models\Sale;
use CodeIgniter\Session\Session;
use App\Models\Stock_location;
use Config\OSPOS;
use ReflectionException;

/**
 * Sale library
 *
 * Library with utilities to manage sales
 */
class Sale_lib
{
    private const KEY_SHORTCUT_DEFAULTS = [
        'cancel'    => ['value' => '27 | ESC', 'code' => 27, 'label' => 'ESC'],
        'items'     => ['value' => '49 | ALT + 1', 'code' => 49, 'label' => 'ALT + 1'],
        'customers' => ['value' => '50 | ALT + 2', 'code' => 50, 'label' => 'ALT + 2'],
        'suspend'   => ['value' => '51 | ALT + 3', 'code' => 51, 'label' => 'ALT + 3'],
        'suspended' => ['value' => '52 | ALT + 4', 'code' => 52, 'label' => 'ALT + 4'],
        'amount'    => ['value' => '53 | ALT + 5', 'code' => 53, 'label' => 'ALT + 5'],
        'payment'   => ['value' => '54 | ALT + 6', 'code' => 54, 'label' => 'ALT + 6'],
        'complete'  => ['value' => '55 | ALT + 7', 'code' => 55, 'label' => 'ALT + 7'],
        'finish'    => ['value' => '56 | ALT + 8', 'code' => 56, 'label' => 'ALT + 8'],
        'help'      => ['value' => '57 | ALT + 9', 'code' => 57, 'label' => 'ALT + 9'],
    ];

    private Attribute $attribute;
    private Customer $customer;
    private Dinner_table $dinner_table;
    private Item $item;
    private Item_kit_items $item_kit_items;
    private Item_quantity $item_quantity;
    private Item_taxes $item_taxes;
    private Sale $sale;
    private Stock_location $stock_location;
    private Session $session;
    private array $config;
    private ?Tax_lib $taxLib = null;

    /** @var array<string, InvoiceDiscountPlan|InvoiceDiscountProblem> */
    private array $invoiceDiscountPlans = [];

    public function __construct()
    {
        $this->session = session();

        $this->attribute = model(Attribute::class);
        $this->customer = model(Customer::class);
        $this->dinner_table = model(Dinner_table::class);
        $this->item = model(Item::class);
        $this->item_kit_items = model(Item_kit_items::class);
        $this->item_quantity = model(Item_quantity::class);
        $this->item_taxes = model(Item_taxes::class);
        $this->sale = model(Sale::class);
        $this->stock_location = model(Stock_location::class);
        $this->config = config(OSPOS::class)->settings;
    }

    /**
     * @return array
     */
    public function get_line_sequence_options(): array
    {
        return [
            '0' => lang('Sales.entry'),
            '1' => lang('Sales.group_by_type'),
            '2' => lang('Sales.group_by_category')
        ];
    }

    /**
     * @return array
     */
    public function get_register_mode_options(): array
    {
        $register_modes = [];

        if (!$this->config['invoice_enable']) {
            $register_modes['sale'] = lang('Sales.sale');
        } else {
            $register_modes['sale'] = lang('Sales.receipt');
            $register_modes['sale_quote'] = lang('Sales.quote');

            if ($this->config['work_order_enable']) {
                $register_modes['sale_work_order'] = lang('Sales.work_order');
            }

            $register_modes['sale_invoice'] = lang('Sales.invoice');
        }

        $register_modes['return'] = lang('Sales.return');

        return $register_modes;
    }

    private const ALLOWED_INVOICE_TYPES = [
        'invoice',
        'tax_invoice',
        'custom_invoice',
        'custom_tax_invoice'
    ];

    private const ALLOWED_RECEIPT_TEMPLATES = [
        'receipt_default',
        'receipt_short',
        'receipt_thermal_80',
        'receipt_thermal_58'
    ];

    public function get_invoice_type_options(): array
    {
        $invoice_types = [];
        $invoice_types['invoice'] = lang('Sales.invoice_type_invoice');
        $invoice_types['tax_invoice'] = lang('Sales.invoice_type_tax_invoice');
        $invoice_types['custom_invoice'] = lang('Sales.invoice_type_custom_invoice');
        $invoice_types['custom_tax_invoice'] = lang('Sales.invoice_type_custom_tax_invoice');
        return $invoice_types;
    }

    /**
     * Returns the available keyboard shortcut choices for the configuration screen.
     *
     * @return array<string, string>
     */
    public function getKeyShortcutsOptions(): array
    {
        $keyShortcuts = [];

        foreach (self::KEY_SHORTCUT_DEFAULTS as $shortcut) {
            $keyShortcuts[$shortcut['value']] = $shortcut['label'];
        }

        return $keyShortcuts;
    }

    /**
     * Returns parsed shortcut bindings from app_config with sensible defaults.
     *
     * @return array<string, array{value:string,code:int,label:string}>
     */
    public function getKeyShortcuts(): array
    {
        $keyboardShortcuts = [];

        foreach (self::KEY_SHORTCUT_DEFAULTS as $name => $default) {
            $value = $this->config["key_$name"] ?? $default['value'];
            $parts = array_map('trim', explode('|', $value, 2));
            $keyboardShortcuts[$name] = [
                'value' => $value,
                'code'  => (int)($parts[0] ?? $default['code']),
                'label' => $parts[1] ?? $default['label']
            ];
        }

        return $keyboardShortcuts;
    }

    public static function isValidInvoiceType(string $invoice_type): bool
    {
        return in_array($invoice_type, self::ALLOWED_INVOICE_TYPES, true);
    }

    public static function isValidReceiptTemplate(string $receipt_template): bool
    {
        return in_array($receipt_template, self::ALLOWED_RECEIPT_TEMPLATES, true);
    }

    /**
     * @return array
     */
    public function get_cart(): array
    {
        if (!$this->session->get('sales_cart')) {
            $this->set_cart([]);
        }

        return $this->withInvoiceDiscount($this->session->get('sales_cart'));
    }

    /**
     * What was entered as the discount on the whole of the sale in the register: its type and value.
     *
     * @return array{type: int, value: string}|null
     */
    public function getInvoiceDiscount(): ?array
    {
        $discount = $this->session->get('sales_invoice_discount');

        return is_array($discount) ? $discount : null;
    }

    public function setInvoiceDiscount(int $type, string $value): void
    {
        $this->session->set('sales_invoice_discount', ['type' => $type, 'value' => $value]);
    }

    public function clearInvoiceDiscount(): void
    {
        $this->session->remove('sales_invoice_discount');
    }

    /**
     * What the discount on the whole of the sale comes to: the shares of its lines, which carry the
     * sign of their quantity.
     */
    public function getInvoiceDiscountTotal(): string
    {
        $total = '0.0';

        foreach ($this->get_cart() as $item) {
            $total = bcadd($total, $item['invoice_discount_share'] ?? '0');
        }

        return $total;
    }

    /**
     * Why the discount entered cannot be taken off what the lines come to now, or null when it can.
     */
    public function invoiceDiscountProblem(): ?InvoiceDiscountProblem
    {
        $cart = $this->get_cart();

        if ($cart === [] || $this->is_return_mode() || $this->getInvoiceDiscount() === null) {
            return null;
        }

        $plan = $this->invoiceDiscountPlan($cart);

        return $plan instanceof InvoiceDiscountProblem ? $plan : null;
    }

    /**
     * The lines of the cart with the share of the discount on the whole of the sale that each
     * carries. A completed sale that was loaded keeps the shares it was saved with. A return takes
     * its share from the sale it comes from, and a discount that cannot be taken off the lines is not
     * applied to any of them.
     *
     * @param array<int|string, array<string, mixed>> $cart
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function withInvoiceDiscount(array $cart): array
    {
        foreach ($cart as $item) {
            if (!empty($item['invoice_discount_frozen'])) {
                return $cart;
            }
        }

        $plan = $this->invoiceDiscountPlan($cart);

        return ($plan instanceof InvoiceDiscountPlan ? $plan : InvoiceDiscount::none())->applyTo($cart);
    }

    /**
     * @param array<int|string, array<string, mixed>> $cart
     */
    private function invoiceDiscountPlan(array $cart): InvoiceDiscountPlan|InvoiceDiscountProblem
    {
        $discount = $this->getInvoiceDiscount();
        $origin = $this->get_return_origin();
        $returning = $this->is_return_mode();

        if ($cart === [] || $returning && $origin === null || !$returning && $discount === null) {
            return InvoiceDiscount::none();
        }

        $key = md5(json_encode([$returning, $origin, $discount, array_map(static fn (array $item): array => [$item['item_id'], $item['quantity'], $item['price'], $item['discount'], $item['discount_type']], $cart)]));

        if (!isset($this->invoiceDiscountPlans[$key])) {
            $decimals = totals_decimals();
            $this->invoiceDiscountPlans[$key] = $returning
                ? InvoiceDiscount::forReturn($cart, $this->sale->invoiceDiscountOrigin($origin), $decimals)
                : InvoiceDiscount::forSale($cart, (int) $discount['type'], (string) $discount['value'], $decimals);
        }

        return $this->invoiceDiscountPlans[$key];
    }

    /**
     * @param array $cart
     * @return array
     */
    public function sortAndFilterCart(array $cart): array
    {
        if (empty($cart)) {
            return $cart;
        }

        $filteredCart = [];

        foreach ($cart as $k => $v) {    // TODO: We should not be using single-letter variable names for readability.  Several of these foreach loops should be refactored.
            if ($v['print_option'] == PRINT_YES) {
                if ($v['price'] == 0.0) {
                    $v['discount'] = 0.0;
                }
                $filteredCart[] = $v;
            }
        }

        if (empty($filteredCart)) {
            return $filteredCart;
        }

        // TODO: This set of if/elseif/else needs to be converted to a switch statement
        // Entry sequence (this will render kits in the expected sequence)
        if ($this->config['line_sequence'] == '0') {
            $sort = [];
            foreach ($filteredCart as $k => $v) {
                $sort['line'][$k] = $v['line'];
            }
            array_multisort($sort['line'], SORT_ASC, $filteredCart);
        }
        // Group by Stock Type (nonstock first - type 1, stock next - type 0)
        elseif ($this->config['line_sequence'] == '1') {    // TODO: Need to change these to constants
            $sort = [];
            foreach ($filteredCart as $k => $v) {
                $sort['stock_type'][$k] = $v['stock_type'];
                $sort['description'][$k] = $v['description'];
                $sort['name'][$k] = $v['name'];
            }
            array_multisort($sort['stock_type'], SORT_DESC, $sort['description'], SORT_ASC, $sort['name'], SORT_ASC, $filteredCart);
        }
        // Group by Item Category
        elseif ($this->config['line_sequence'] == '2') {    // TODO: Need to change these to constants
            $sort = [];
            foreach ($filteredCart as $k => $v) {
                $sort['category'][$k] = $v['stock_type'];
                $sort['description'][$k] = $v['description'];
                $sort['name'][$k] = $v['name'];
            }
            array_multisort($sort['category'], SORT_DESC, $sort['description'], SORT_ASC, $sort['name'], SORT_ASC, $filteredCart);
        }
        // Group by entry sequence in descending sequence (the Standard)
        else {
            $sort = [];
            foreach ($filteredCart as $k => $v) {
                $sort['line'][$k] = $v['line'];
            }
            array_multisort($sort['line'], SORT_ASC, $filteredCart);
        }

        return $filteredCart;
    }

    /**
     * @param array $cart_data
     * @return void
     */
    public function set_cart(array $cart_data): void
    {
        $this->session->set('sales_cart', $cart_data);
    }

    /**
     * @return void
     */
    public function empty_cart(): void
    {
        $this->session->remove('sales_cart');
    }

    /**
     * @return void
     */
    public function remove_temp_items(): void
    {
        // Loop through the cart items and delete temporary items specific to this sale
        $cart = $this->get_cart();
        foreach ($cart as $line => $item) {
            if ($item['item_type'] == ITEM_TEMP) {    // TODO: === ?
                $this->item->delete($item['item_id']);
            }
        }
    }

    /**
     * @return string
     */
    public function get_comment(): string
    {
        // Avoid returning a null that results in a 0 in the comment if nothing is set/available
        $comment = $this->session->get('sales_comment');

        return empty($comment) ? '' : $comment;
    }

    /**
     * @param string $comment
     * @return void
     */
    public function set_comment(string $comment): void
    {
        $this->session->set('sales_comment', $comment);
    }

    /**
     * @return void
     */
    public function clear_comment(): void
    {
        $this->session->remove('sales_comment');
    }

    /**
     * @return string|null
     */
    public function get_invoice_number(): ?string
    {
        return $this->session->get('sales_invoice_number');
    }

    /**
     * @return string|null
     */
    public function get_quote_number(): ?string
    {
        return $this->session->get('sales_quote_number');
    }

    /**
     * @return string|null
     */
    public function get_work_order_number(): ?string
    {
        return $this->session->get('sales_work_order_number');
    }

    /**
     * @return int|null
     */
    public function getSaleType(): ?int
    {
        return $this->session->get('sale_type') ?? 0;
    }

    public function set_invoice_number(string $invoice_number, bool $keep_custom = false): void
    {
        $currentInvoiceNumber = $this->session->get('sales_invoice_number');

        if (!$keep_custom || empty($currentInvoiceNumber)) {
            if ($invoice_number === '') {
                $this->session->remove('sales_invoice_number');
            } else {
                $this->session->set('sales_invoice_number', $invoice_number);
            }
        }
    }

    /**
     * @param string|null $quote_number
     * @param bool $keep_custom
     * @return void
     */
    public function set_quote_number(?string $quote_number, bool $keep_custom = false): void
    {
        $current_quote_number = $this->session->get('sales_quote_number');

        if (!$keep_custom || empty($current_quote_number)) {
            $this->session->set('sales_quote_number', $quote_number);
        }
    }

    /**
     * @param string|null $work_order_number
     * @param bool $keep_custom
     * @return void
     */
    public function set_work_order_number(?string $work_order_number, bool $keep_custom = false): void
    {
        $current_work_order_number = $this->session->get('sales_work_order_number');

        if (!$keep_custom || empty($current_work_order_number)) {
            $this->session->set('sales_work_order_number', $work_order_number);
        }
    }

    /**
     * @param int $sale_type
     * @param bool $keep_custom
     * @return void
     */
    public function set_sale_type(int $sale_type, bool $keep_custom = false): void
    {
        $current_sale_type = $this->session->get('sale_type');

        if (!$keep_custom || empty($current_sale_type)) {
            $this->session->set('sale_type', $sale_type);
        }
    }

    /**
     * @return void
     */
    public function clear_invoice_number(): void
    {
        $this->session->remove('sales_invoice_number');
    }

    /**
     * Whether the goods of the invoice being made leave with it. They do unless the cashier says
     * otherwise.
     */
    public function is_delivered(): bool
    {
        return (int) ($this->session->get('sales_delivered') ?? 1) === 1;
    }

    public function set_delivered(bool $delivered): void
    {
        $this->session->set('sales_delivered', $delivered ? 1 : 0);
    }

    public function clear_delivered(): void
    {
        $this->session->remove('sales_delivered');
    }

    /**
     * The day typed on the invoice being made, or null when it is dated the day it is saved.
     */
    public function getInvoiceDate(): ?string
    {
        $date = $this->session->get('sales_invoice_date');

        return $date === null || $date === '' ? null : (string) $date;
    }

    public function setInvoiceDate(?string $date): void
    {
        $date === null || $date === '' ? $this->session->remove('sales_invoice_date') : $this->session->set('sales_invoice_date', $date);
    }

    public function clearInvoiceDate(): void
    {
        $this->session->remove('sales_invoice_date');
    }

    /**
     * The payment terms typed on the invoice being made, as the days they were typed in, or null
     * when it has none.
     */
    public function getPaymentTerms(): ?string
    {
        $terms = $this->session->get('sales_payment_terms');

        return $terms === null || $terms === '' ? null : (string) $terms;
    }

    public function setPaymentTerms(?string $terms): void
    {
        $terms === null || $terms === '' ? $this->session->remove('sales_payment_terms') : $this->session->set('sales_payment_terms', $terms);
    }

    public function clearPaymentTerms(): void
    {
        $this->session->remove('sales_payment_terms');
    }

    /**
     * The quote the invoice being made in the register was converted from, or null when it was not.
     */
    public function getQuoteSource(): ?int
    {
        $quoteId = $this->session->get('sales_quote_source');

        return $quoteId === null ? null : (int) $quoteId;
    }

    public function setQuoteSource(?int $quoteId): void
    {
        $quoteId === null ? $this->session->remove('sales_quote_source') : $this->session->set('sales_quote_source', $quoteId);
    }

    public function clearQuoteSource(): void
    {
        $this->session->remove('sales_quote_source');
    }

    /**
     * Fills the register with a new invoice that has the lines, the customer, the comment and the
     * discount of a quote, and remembers the quote. Nothing is saved: the quote is converted when
     * the invoice is.
     */
    public function prepareInvoiceFromQuote(int $quoteId): void
    {
        $this->clear_all();
        $this->copy_entire_sale($quoteId);
        $this->set_mode('sale_invoice');
        $this->clear_quote_number();
        $this->clear_sale_type();
        $this->set_sale_type(SALE_TYPE_INVOICE);
        $this->session->set('sale_id', NEW_ENTRY);
        $this->setQuoteSource($quoteId);
    }

    /**
     * The sale a return in the register takes its goods back from.
     */
    public function get_return_origin(): ?int
    {
        $origin = $this->session->get('sales_return_origin');

        return $origin === null ? null : (int) $origin;
    }

    public function set_return_origin(?int $saleId): void
    {
        $saleId === null ? $this->session->remove('sales_return_origin') : $this->session->set('sales_return_origin', $saleId);
    }

    public function clear_return_origin(): void
    {
        $this->session->remove('sales_return_origin');
    }

    /**
     * The reason a manager gave for taking a return of stock with no sale to take it back from.
     */
    public function getReturnOverride(): ?string
    {
        $reason = $this->session->get('sales_return_override');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function setReturnOverride(string $reason): void
    {
        $this->session->set('sales_return_override', $reason);
    }

    public function clearReturnOverride(): void
    {
        $this->session->remove('sales_return_override');
    }

    /**
     * The document a return in the register is issued as: a credit note unless it was asked to be a
     * refund invoice.
     */
    public function getReturnDocument(): ReturnDocument
    {
        return ReturnDocument::fromStored($this->session->get('sales_return_document'));
    }

    public function setReturnDocument(ReturnDocument $document): void
    {
        $document === ReturnDocument::CreditNote ? $this->session->remove('sales_return_document') : $this->session->set('sales_return_document', $document->value);
    }

    public function clearReturnDocument(): void
    {
        $this->session->remove('sales_return_document');
    }

    /**
     * @return void
     */
    public function clear_quote_number(): void
    {
        $this->session->remove('sales_quote_number');
    }

    /**
     * @return void
     */
    public function clear_work_order_number(): void
    {
        $this->session->remove('sales_work_order_number');
    }

    /**
     * @return void
     */
    public function clear_sale_type(): void
    {
        $this->session->remove('sale_type');
    }

    /**
     * @param int $suspended_id
     * @return void
     */
    public function set_suspended_id(int $suspended_id): void
    {
        $this->session->set('suspended_id', $suspended_id);
    }

    /**
     * @return int
     */
    public function get_suspended_id(): int
    {
        return $this->session->get('suspended_id');
    }

    /**
     * @return bool
     */
    public function is_invoice_mode(): bool
    {
        return ($this->session->get('sales_mode') == 'sale_invoice'    && $this->config['invoice_enable']);
    }

    /**
     * @return bool
     */
    public function is_sale_by_receipt_mode(): bool    // TODO: This function is not called anywhere in the code.
    {
        return ($this->session->get('sales_mode') == 'sale');    // TODO: === ?
    }

    /**
     * @return bool
     */
    public function is_quote_mode(): bool
    {
        return ($this->session->get('sales_mode') == 'sale_quote');    // TODO: === ?
    }

    /**
     * @return bool
     */
    public function is_return_mode(): bool
    {
        return ($this->session->get('sales_mode') == 'return');    // TODO: === ?
    }

    /**
     * @return bool
     */
    public function is_work_order_mode(): bool
    {
        return ($this->session->get('sales_mode') == 'sale_work_order');    // TODO: === ?
    }

    /**
     * @param string $price_work_orders
     * @return void
     */
    public function set_price_work_orders(string $price_work_orders): void
    {
        $this->session->set('sales_price_work_orders', $price_work_orders);
    }

    /**
     * @return bool
     */
    public function is_price_work_orders(): bool
    {
        return ($this->session->get('sales_price_work_orders') == 'true'    // TODO: === ?
            || $this->session->get('sales_price_work_orders') == '1');    // TODO: === ?
    }

    /**
     * @param bool $print_after_sale
     * @return void
     */
    public function set_print_after_sale(bool $print_after_sale): void
    {
        $this->session->set('sales_print_after_sale', $print_after_sale);
    }

    /**
     * @return bool
     */
    public function is_print_after_sale(): bool
    {   // TODO: this needs to be converted to a switch statement
        if ($this->config['print_receipt_check_behaviour'] == 'always') {    // TODO: 'behaviour' is the british spelling, but the rest of the code is in American English.  Not a big deal, but noticed. Also ===
            return true;
        } elseif ($this->config['print_receipt_check_behaviour'] == 'never') {    // TODO: === ?
            return false;
        } else { // Remember last setting, session based though
            return ($this->session->get('sales_print_after_sale') == 'true'    // TODO: === ?
                || $this->session->get('sales_print_after_sale') == '1');    // TODO: === ?
        }
    }

    /**
     * @param string $email_receipt
     * @return void
     */
    public function set_email_receipt(string $email_receipt): void
    {
        $this->session->set('sales_email_receipt', $email_receipt);
    }

    /**
     * @return void
     */
    public function clear_email_receipt(): void
    {
        $this->session->remove('sales_email_receipt');
    }

    /**
     * @return bool
     */
    public function is_email_receipt(): bool
    {   // TODO: this needs to be converted to a switch statement
        if ($this->config['email_receipt_check_behaviour'] == 'always') {    // TODO: 'behaviour' is the british spelling, but the rest of the code is in American English.  Not a big deal, but noticed. Also ===
            return true;
        } elseif ($this->config['email_receipt_check_behaviour'] == 'never') {    // TODO: === ?
            return false;
        } else { // Remember last setting, session based though
            return ($this->session->get('sales_email_receipt') == 'true'    // TODO: === ?
                || $this->session->get('sales_email_receipt') == '1');    // TODO: === ?
        }
    }

    /**
     * Multiple Payments
     */
    public function getPayments(): array
    {
        if (!$this->session->get('sales_payments')) {
            $this->setPayments([]);
        }

        return $this->session->get('sales_payments');
    }

    /**
     * Multiple Payments
     */
    public function setPayments(array $payments_data): void
    {
        $this->session->set('sales_payments', $payments_data);
    }

    /**
     * Adds a new payment to the payment array or updates an existing one.
     * It will also disable cash_mode if a non-qualifying payment type is added.
     * @param string $paymentId
     * @param string $paymentAmount
     * @param string|null $referenceCode
     * @param int $cashAdjustment
     * @param int $treasuryId The treasury the payment goes into, or 0 for the one of the employee.
     */
    public function addPayment(string $paymentId, string $paymentAmount, ?string $referenceCode = null, int $cashAdjustment = CASH_ADJUSTMENT_FALSE, int $treasuryId = 0): void
    {
        $payments = $this->getPayments();
        if (isset($payments[$paymentId])) {
            // payment_method already exists, add to payment_amount
            $payments[$paymentId]['payment_amount'] = bcadd($payments[$paymentId]['payment_amount'], $paymentAmount);
        } else {
            // Add to existing array
            $payment = [
                $paymentId => [
                    'payment_type'    => $paymentId,
                    'payment_amount'  => $paymentAmount,
                    'cash_refund'     => 0,
                    'cash_adjustment' => $cashAdjustment,
                    'reference_code'  => $referenceCode,
                    'treasury_id'     => $treasuryId,
                ]
            ];

            $payments += $payment;
        }

        if ($this->session->get('cash_mode')) {
            if ($this->session->get('cash_rounding') && $paymentId != lang('Sales.cash') && $paymentId != lang('Sales.cash_adjustment')) {
                $this->session->set('cash_mode', CASH_MODE_FALSE);
            }
        }

        $this->setPayments($payments);
    }

    /**
     * The treasury a payment in the cart goes into (0 for the one of the employee), or null when
     * the cart has no payment of that type.
     */
    public function paymentTreasury(string $paymentId): ?int
    {
        $payments = $this->getPayments();

        return isset($payments[$paymentId]) ? (int) ($payments[$paymentId]['treasury_id'] ?? 0) : null;
    }

    /**
     * Multiple Payments
     */
    public function edit_payment(string $payment_id, float $payment_amount): bool
    {
        $payments = $this->getPayments();
        if (isset($payments[$payment_id])) {
            $payments[$payment_id]['payment_type'] = $payment_id;
            $payments[$payment_id]['payment_amount'] = $payment_amount;
            $this->setPayments($payments);

            return true;
        }

        return false;
    }

    /**
     * Delete the selected payment from the payment array and if cash rounding is enabled
     * and the payment type is one of the cash types then automatically delete the other
     * @param string $payment_id
     */
    public function delete_payment(string $payment_id): void
    {
        $payments = $this->getPayments();
        $decoded_payment_id = urldecode($payment_id);

        unset($payments[$decoded_payment_id]);

        $cash_rounding = $this->reset_cash_rounding();

        if ($cash_rounding) {
            if ($decoded_payment_id == lang('Sales.cash')) {    // TODO: === ?
                unset($payments[lang('Sales.cash_adjustment')]);
            }

            if ($decoded_payment_id == lang('Sales.cash_adjustment')) {    // TODO: === ?
                unset($payments[lang('Sales.cash')]);
            }
        }
        $this->setPayments($payments);
    }

    /**
     * Multiple Payments
     */
    public function empty_payments(): void    // TODO: function verbs are very inconsistent in these libraries.
    {
        $this->session->remove('sales_payments');
    }

    /**
     * Retrieve the total payments made, excluding any cash adjustments
     * and establish if cash_mode is in play
     */
    public function get_payments_total(): string
    {
        $subtotal = '0.0';
        $cash_mode_eligible = CASH_MODE_TRUE;

        foreach ($this->getPayments() as $payments) {
            if (!$payments['cash_adjustment']) {
                $subtotal = bcadd($payments['payment_amount'], $subtotal);
            }
            if (lang('Sales.cash') != $payments['payment_type'] && lang('Sales.cash_adjustment') != $payments['payment_type']) {
                $cash_mode_eligible = CASH_MODE_FALSE;
            }
        }

        if ($cash_mode_eligible && $this->session->get('cash_rounding')) {    // TODO: $cache_mode_eligible will always evaluate to true
            $this->session->set('cash_mode', CASH_MODE_TRUE);
        }

        return $subtotal;
    }

    /**
     * Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'paid_in_full'
     * 'subtotal', 'discounted_subtotal', 'tax_exclusive_subtotal', 'item_count', 'total_units', 'cash_adjustment_amount'
     */
    public function get_totals(array $taxes): array
    {
        $totals = [];

        $prediscountSubtotal = '0.0';
        $subtotal = '0.0';
        $total = '0.0';
        $totalDiscount = '0.0';
        $invoiceDiscount = '0.0';
        $itemCount = 0;
        $totalUnits = 0.0;

        foreach ($this->get_cart() as $item) {
            if ($item['stock_type'] == HAS_STOCK) {
                $itemCount++;
                $totalUnits += $item['quantity'];
            }
            $discountAmount = $this->get_item_discount($item['quantity'], $item['price'], $item['discount'], $item['discount_type']);
            $totalDiscount = bcadd($totalDiscount, $discountAmount);

            $extendedAmount = $this->get_extended_amount($item['quantity'], $item['price']);
            $extendedDiscountedAmount = $this->get_extended_amount($item['quantity'], $item['price'], $discountAmount);
            $invoiceDiscountShare = $item['invoice_discount_share'] ?? '0';

            if ($invoiceDiscountShare !== '0') {
                $extendedDiscountedAmount = bcsub($extendedDiscountedAmount, $invoiceDiscountShare);
                $invoiceDiscount = bcadd($invoiceDiscount, $invoiceDiscountShare);
            }

            $prediscountSubtotal = bcadd($prediscountSubtotal, $extendedAmount);
            $total = bcadd($total, $extendedDiscountedAmount);

            $subtotal = bcadd($subtotal, $extendedDiscountedAmount);
        }

        $totals['prediscount_subtotal'] = $prediscountSubtotal;
        $totals['total_discount'] = $totalDiscount;
        $totals['invoice_discount'] = $invoiceDiscount;
        $salesTax = '0';

        foreach ($taxes as $tax) {
            if ($tax['tax_type'] === Tax_lib::TAX_TYPE_EXCLUDED) {
                $total = bcadd($total, $tax['sale_tax_amount']);
                $salesTax = bcadd($salesTax, $tax['sale_tax_amount']);
            } else {
                $subtotal = bcsub($subtotal, $tax['sale_tax_amount']);
            }
        }

        $totals['subtotal'] = $subtotal;
        $totals['total'] = $total;
        $totals['tax_total'] = $salesTax;

        $paymentTotal = $this->get_payments_total();
        $totals['payment_total'] = $paymentTotal;
        $cashRounding = $this->session->get('cash_rounding');
        $cashMode = $this->session->get('cash_mode');

        if ($cashRounding) {
            $cashTotal = $this->check_for_cash_rounding($total);
            $totals['cash_total'] = $cashTotal;
        } else {
            $cashTotal = $total;
            $totals['cash_total'] = $cashTotal;
        }

        $amountDue = bcsub($total, $paymentTotal);
        $totals['amount_due'] = $amountDue;

        $cashAmountDue = bcsub($cashTotal, $paymentTotal);
        $totals['cash_amount_due'] = $cashAmountDue;

        if ($cashMode) {    // TODO: Convert to ternary notation
            $currentDue = $cashAmountDue;
        } else {
            $currentDue = $amountDue;
        }

        // 0 decimal -> 1 / 2 = 0.5, 1 decimals -> 0.1 / 2 = 0.05, 2 decimals -> 0.01 / 2 = 0.005
        $threshold = bcpow('10', (string)-totals_decimals()) / 2;

        if ($this->get_mode() == 'return') {    // TODO: Convert to ternary notation.
            $totals['payments_cover_total'] = $currentDue > -$threshold;
        } else {
            $totals['payments_cover_total'] = $currentDue < $threshold;
        }

        $totals['item_count'] = $itemCount;
        $totals['total_units'] = $totalUnits;
        $totals['cash_adjustment_amount'] = 0.0;

        if ($totals['payments_cover_total']) {
            $totals['cash_adjustment_amount'] = round($cashTotal - $totals['total'], totals_decimals(), PHP_ROUND_HALF_UP);
        }

        $cashMode = $this->session->get('cash_mode');    // TODO: This variable is never used.

        return $totals;
    }

    /**
     * Multiple Payments
     */
    public function get_amount_due(): string
    {
        // Payment totals need to be identified first so that we know whether or not there is a non-cash payment involved
        $payment_total = $this->get_payments_total();
        $sales_total = $this->get_total();
        $amount_due = bcsub($sales_total, $payment_total);
        $precision = totals_decimals();
        $rounded_due = bccomp((string)round((float)$amount_due, $precision, PHP_ROUND_HALF_UP), '0', $precision);    // TODO: Is round() currency safe?

        // Take care of rounding error introduced by round tripping payment amount to the browser
        return $rounded_due == 0 ? '0' : $amount_due;    // TODO: ===
    }

    /**
     * @return int
     */
    public function get_customer(): int
    {
        if (!$this->session->get('sales_customer')) {
            $this->set_customer(-1);    // TODO: Replace -1 with a constant
        }

        return $this->session->get('sales_customer');
    }

    /**
     * @param int $customer_id
     * @return void
     */
    public function set_customer(int $customer_id): void
    {
        $this->session->set('sales_customer', $customer_id);
    }

    /**
     * @return void
     */
    public function remove_customer(): void
    {
        $this->session->remove('sales_customer');
    }

    /**
     * @return int
     */
    public function get_employee(): int
    {
        if (!$this->session->get('sales_employee')) {
            $this->set_employee(-1);    // TODO: Replace -1 with a constant
        }

        return $this->session->get('sales_employee');
    }

    /**
     * @param int $employee_id
     * @return void
     */
    public function set_employee(int $employee_id): void
    {
        $this->session->set('sales_employee', $employee_id);
    }

    /**
     * @return void
     */
    public function remove_employee(): void
    {
        $this->session->remove('sales_employee');
    }

    /**
     * @return string
     */
    public function get_mode(): string
    {
        if (!$this->session->get('sales_mode')) {
            $this->set_mode('sale');
        }
        return $this->session->get('sales_mode');
    }

    /**
     * @param string $mode
     * @return void
     */
    public function set_mode(string $mode): void
    {
        $this->session->set('sales_mode', $mode);
    }

    /**
     * @return void
     */
    public function clear_mode(): void
    {
        $this->session->remove('sales_mode');
    }

    /**
     * @return int|null
     */
    public function get_dinner_table(): ?int
    {
        if (!$this->session->get('dinner_table')) {
            if ($this->config['dinner_table_enable']) {
                $this->set_dinner_table(1);    // TODO: Replace 1 with constant
            }
        }

        return $this->session->get('dinner_table');
    }

    /**
     * @param int|null $dinner_table
     * @return void
     */
    public function set_dinner_table(?int $dinner_table): void
    {
        $this->session->set('dinner_table', $dinner_table);
    }

    /**
     * @return void
     */
    public function clear_table(): void
    {
        $this->session->remove('dinner_table');
    }

    /**
     * @return int
     */
    public function get_sale_location(): int
    {
        if (!$this->session->get('sales_location')) {
            $this->set_sale_location($this->stock_location->get_default_location_id('sales', true));
        }

        return $this->session->get('sales_location');
    }

    /**
     * @param int $location
     * @return void
     */
    public function set_sale_location(int $location): void
    {
        $this->session->set('sales_location', $location);
    }

    /**
     * @param string $payment_type
     * @return void
     */
    public function set_payment_type(string $payment_type): void
    {
        $this->session->set('payment_type', $payment_type);
    }

    /**
     * @return string|null
     */
    public function get_payment_type(): ?string
    {
        return $this->session->get('payment_type');
    }

    /**
     * @return void
     */
    public function clear_sale_location(): void
    {
        $this->session->remove('sales_location');
    }

    /**
     * @param string $value
     * @return void
     */
    public function set_giftcard_remainder(string $value): void
    {
        $this->session->set('sales_giftcard_remainder', $value);
    }

    /**
     * @return string|null
     */
    public function get_giftcard_remainder(): ?string
    {
        return $this->session->get('sales_giftcard_remainder');
    }

    /**
     * @return void
     */
    public function clear_giftcard_remainder(): void
    {
        $this->session->remove('sales_giftcard_remainder');
    }

    /**
     * @param string $value
     * @return void
     */
    public function set_rewards_remainder(string $value): void
    {
        $this->session->set('sales_rewards_remainder', $value);
    }

    /**
     * @return string|null
     */
    public function get_rewards_remainder(): ?string
    {
        return $this->session->get('sales_rewards_remainder');
    }

    /**
     * @return void
     */
    public function clear_rewards_remainder(): void
    {
        $this->session->remove('sales_rewards_remainder');
    }

    // TODO: this function needs to be reworked... way too many parameters.  Also, optional parameters must go after mandatory parameters.

    /**
     * @param string $item_id
     * @param int $item_location
     * @param string $quantity
     * @param string $discount
     * @param int $discount_type
     * @param int $price_mode
     * @param int|null $kit_price_option
     * @param int|null $kit_print_option
     * @param string|null $price_override
     * @param string|null $description
     * @param string|null $serialnumber
     * @param int|null $sale_id
     * @param bool $include_deleted
     * @param bool|null $print_option
     * @param bool|null $line
     * @return bool
     */
    public function add_item(string &$item_id, int $item_location, string $quantity = '1', string &$discount = '0.0', int $discount_type = 0, int $price_mode = PRICE_MODE_STANDARD, ?int $kit_price_option = null, ?int $kit_print_option = null, ?string $price_override = null, ?string $description = null, ?string $serialnumber = null, ?int $sale_id = null, bool $include_deleted = false, ?bool $print_option = null, ?bool $line = null): bool
    {
        $item_info = $this->item->get_info_by_id_or_number($item_id, $include_deleted);

        // Make sure item exists
        if (empty($item_info)) {
            $item_id = NEW_ENTRY;
            return false;
        }

        $applied_discount = $discount;
        $item_id = $item_info->item_id;
        $item_type = $item_info->item_type;
        $stock_type = $item_info->stock_type;

        $price = $item_info->unit_price;
        $cost_price = $item_info->cost_price;
        if ($price_override != null) {
            $price = $price_override;
        }

        if ($price_mode == PRICE_MODE_KIT) {
            if (!($kit_price_option == PRICE_OPTION_ALL
                || $kit_price_option == PRICE_OPTION_KIT  && $item_type == ITEM_KIT
                || $kit_price_option == PRICE_OPTION_KIT_STOCK && $stock_type == HAS_STOCK))    // TODO: === ?
            {
                $price = '0.00';
                $applied_discount = '0.00';
            }

            // If price is zero do not include a discount regardless of type
            if ($price == '0.00') {    // TODO: === ?
                $applied_discount = '0.00';
            }

            // If fixed discount then apply no more than the item price
            if ($discount_type == FIXED) {    // TODO: === ?
                if ($applied_discount > $price) {
                    $applied_discount = $price;
                    $discount -= $applied_discount;
                } else {
                    $discount = 0;
                }
            }
        }

        // Serialization and Description

        // Get all items in the cart so far...
        $items = $this->get_cart();

        // We need to loop through all items in the cart.
        // If the item is already there, get it's key($updatekey).
        // We also need to get the next key that we are going to use in case we need to add the
        // item to the cart. Since items can be deleted, we can't use a count. we use the highest key + 1.

        $maxkey = 0;                   // Highest key so far
        $itemalreadyinsale = false;    // We did not find the item yet. // TODO: variable naming here does not match the convention
        $insertkey = 0;                // Key to use for new entry.     // TODO: $insertkey is never used
        $updatekey = 0;                // Key to use to update(quantity)

        foreach ($items as $item) {
            // We primed the loop so maxkey is 0 the first time.
            // Also, we have stored the key in the element itself so we can compare.

            if ($maxkey <= $item['line']) {    // TODO: variable naming here does not match the convention
                $maxkey = $item['line'];
            }

            if ($item['item_id'] == $item_id && $item['item_location'] == $item_location) {    // TODO: === ?
                $itemalreadyinsale = true;
                $updatekey = $item['line'];
                if (!$item_info->is_serialized) {
                    $quantity = bcadd($quantity, $items[$updatekey]['quantity']);
                }
            }
        }

        $insertkey = $maxkey + 1; // TODO: Does not follow naming conventions.
        // Array/cart records are identified by $insertkey and item_id is just another field.

        if ($price_mode == PRICE_MODE_KIT) {    // TODO: === ?
            if ($kit_print_option == PRINT_ALL) {    // TODO: === ?
                $print_option_selected = PRINT_YES;
            } elseif ($kit_print_option == PRINT_KIT && $item_type == ITEM_KIT) {    // TODO: === ?
                $print_option_selected = PRINT_YES;
            } elseif ($kit_print_option == PRINT_PRICED && $price > 0) {    // TODO: === ?
                $print_option_selected = PRINT_YES;
            } else {
                $print_option_selected = PRINT_NO;
            }
        } else {    // TODO: Convert this to ternary notation
            if ($print_option != null) {    // TODO: === ?
                $print_option_selected = $print_option;
            } else {
                $print_option_selected = PRINT_YES;
            }
        }

        $total = $this->get_item_total($quantity, $price, $applied_discount, $discount_type);
        $discounted_total = $this->get_item_total($quantity, $price, $applied_discount, $discount_type, true);

        if ($this->config['multi_pack_enabled']) {
            $item_info->name .= NAME_SEPARATOR . $item_info->pack_name;
        }

        $attribute_links = $this->attribute->get_link_values($item_id, 'sale_id', $sale_id, Attribute::SHOW_IN_SALES)->getRowObject();

        // Item already exists and is not serialized, add to quantity
        if (!$itemalreadyinsale || $item_info->is_serialized) {
            $item = [
                $insertkey => [
                    'item_id'               => $item_id,
                    'item_location'         => $item_location,
                    'stock_name'            => $this->stock_location->get_location_name($item_location),
                    'line'                  => $insertkey,
                    'name'                  => $item_info->name,
                    'item_number'           => $item_info->item_number,
                    'attribute_values'      => $attribute_links->attribute_values,
                    'attribute_dtvalues'    => $attribute_links->attribute_dtvalues,
                    'description'           => $description != null ? $description : $item_info->description,
                    'serialnumber'          => $serialnumber != null ? $serialnumber : '',
                    'allow_alt_description' => $item_info->allow_alt_description,
                    'is_serialized'         => $item_info->is_serialized,
                    'quantity'              => $quantity,
                    'discount'              => $applied_discount,
                    'discount_type'         => $discount_type,
                    'in_stock'              => $this->item_quantity->get_item_quantity($item_id, $item_location)->quantity,
                    'price'                 => $price,
                    'cost_price'            => $cost_price,
                    'total'                 => $total,
                    'discounted_total'      => $discounted_total,
                    'print_option'          => $print_option_selected,
                    'stock_type'            => $stock_type,
                    'item_type'             => $item_type,
                    'hsn_code'              => $item_info->hsn_code,
                    'tax_category_id'       => $item_info->tax_category_id
                ]
            ];

            // Add to existing array
            $items += $item;
        } else {
            $line = &$items[$updatekey];
            $line['quantity'] = $quantity;
            $line['total'] = $total;
            $line['discounted_total'] = $discounted_total;
        }

        $this->set_cart($items);

        return true;
    }

    /**
     * @param int $item_id
     * @param int $item_location
     * @return string
     */
    public function out_of_stock(int $item_id, int $item_location): string
    {
        // Make sure item exists
        if ($item_id != -1) {    // TODO: !== ?.  Also Replace -1 with a constant
            $item_info = $this->item->get_info_by_id_or_number($item_id);

            if ($item_info->stock_type == HAS_STOCK) {    // TODO: === ?
                $item_quantity = $this->item_quantity->get_item_quantity($item_id, $item_location)->quantity;
                $quantity_added = $this->get_quantity_already_added($item_id, $item_location);

                if ($item_quantity - $quantity_added < 0) {
                    return lang('Sales.quantity_less_than_zero');
                } elseif ($item_quantity - $quantity_added < $item_info->reorder_level) {
                    return lang('Sales.quantity_less_than_reorder_level');
                }
            }
        }

        return '';
    }

    /**
     * @param int $item_id
     * @param int $item_location
     * @return string
     */
    public function get_quantity_already_added(int $item_id, int $item_location): string
    {
        $items = $this->get_cart();
        $quantity_already_added = '0.0';
        foreach ($items as $item) {
            if ($item['item_id'] == $item_id && $item['item_location'] == $item_location) {    // TODO: === ?
                $quantity_already_added += $item['quantity'];    // TODO: for precision we likely need to use bcadd() since we are using that everywhere else for quantity
            }
        }

        return $quantity_already_added;
    }

    /**
     * @param string $line_to_get
     * @return int
     */
    public function get_item_id(string $line_to_get): int
    {
        $items = $this->get_cart();

        foreach ($items as $line => $item) {
            if ($line == $line_to_get) {
                return $item['item_id'];
            }
        }

        return -1;    // TODO: Replace -1 with constant
    }

    /* @param string $line
     * @param string $description
     * @param string $serialnumber
     * @param string $quantity
     * @param string $discount
     * @param string|null $discount_type
     * @param string|null $price
     * @param string|null $discounted_total
     * @return bool
     */
    public function edit_item(string $line, string $description, string $serialnumber, string $quantity, string $discount, ?string $discount_type, ?string $price, ?string $discounted_total = null): bool
    {
        $items = $this->get_cart();
        if (isset($items[$line])) {
            $line = &$items[$line];
            if ($discounted_total != null && $discounted_total != $line['discounted_total']) {
                // Note when entered the "discounted_total" is expected to be entered without a discount
                $quantity = $this->get_quantity_sold($discounted_total, $price);
            }
            $line['description'] = $description;
            $line['serialnumber'] = $serialnumber;
            $line['quantity'] = $quantity;
            $line['discount'] = $discount;

            if ($discount_type != null) {
                $line['discount_type'] = $discount_type;
            }

            $line['price'] = $price;
            $line['total'] = $this->get_item_total($quantity, $price, $discount, $line['discount_type']);
            $line['discounted_total'] = $this->get_item_total($quantity, $price, $discount, $line['discount_type'], true);
            $this->set_cart($items);
        }

        return false;    // TODO: This function will always return false.
    }

    /**
     * @param int $line
     * @return void
     */
    public function delete_item(int $line): void
    {
        $items = $this->get_cart();
        $item_type = $items[$line]['item_type'];

        if ($item_type == ITEM_TEMP) {
            $item_id = $items[$line]['item_id'];
            $this->item->delete($item_id);
        }

        unset($items[$line]);
        $this->set_cart($items);
    }

    /**
     * @param string $receipt_sale_id
     * @return void
     */
    public function return_entire_sale(string $receipt_sale_id): void
    {
        // POS #
        $pieces = explode(' ', $receipt_sale_id);
        $sale_id = $pieces[1];

        $this->empty_cart();
        $this->remove_customer();

        $left = $this->sale->getReturnedQuantities((int) $sale_id);

        foreach ($this->sale->get_sale_items_ordered($sale_id)->getResult() as $row) {
            $quantity = (float) $row->quantity_purchased - ($left[(int) $row->item_id] ?? 0.0);

            if ($quantity <= 0) {
                $left[(int) $row->item_id] = ($left[(int) $row->item_id] ?? 0.0) - (float) $row->quantity_purchased;

                continue;
            }

            $left[(int) $row->item_id] = 0.0;

            $this->add_item($row->item_id, $row->item_location, -$quantity, $row->discount, $row->discount_type, PRICE_MODE_STANDARD, null, null, $row->item_unit_price, $row->description, $row->serialnumber, null, true);
        }

        $this->set_customer($this->sale->get_customer($sale_id)->person_id);
        $this->set_return_origin((int) $sale_id);
    }

    /**
     * @param string $external_item_kit_id
     * @param int $item_location
     * @param float $discount
     * @param string $discount_type
     * @param bool $kit_price_option
     * @param bool $kit_print_option
     * @param string $stock_warning
     * @return bool
     */
    public function add_item_kit(string $external_item_kit_id, int $item_location, float $discount, string $discount_type, bool $kit_price_option, bool $kit_print_option, ?string &$stock_warning): bool
    {
        // KIT #
        $pieces = explode(' ', $external_item_kit_id);
        $item_kit_id = (count($pieces) > 1) ? $pieces[1] : $external_item_kit_id;
        $result = true;
        $applied_discount = $discount;

        foreach ($this->item_kit_items->get_info($item_kit_id) as $item_kit_item) {
            $result &= $this->add_item($item_kit_item['item_id'], $item_location, $item_kit_item['quantity'], $discount, $discount_type, PRICE_MODE_KIT, $kit_price_option, $kit_print_option);

            if ($stock_warning == null) {
                $stock_warning = $this->out_of_stock($item_kit_item['item_id'], $item_location);
            }
        }

        return $result;
    }

    public function copy_entire_sale(int $saleId): void
    {
        $this->empty_cart();
        $this->remove_customer();
        $this->clearInvoiceDiscount();

        $storedShares = [];

        foreach ($this->sale->get_sale_items_ordered($saleId)->getResult() as $row) {
            $linesBefore = count($this->session->get('sales_cart') ?? []);

            $this->add_item($row->item_id, $row->item_location, $row->quantity_purchased, $row->discount, $row->discount_type, PRICE_MODE_STANDARD, null, null, $row->item_unit_price, $row->description, $row->serialnumber, $saleId, true, $row->print_option);

            if ($row->invoice_discount_share !== null) {
                $line = $this->cartLineOf($linesBefore, (int) $row->item_id, (int) $row->item_location);
                $storedShares[$line] = bcadd($storedShares[$line] ?? '0', (string) $row->invoice_discount_share, InvoiceDiscount::SCALE);
            }
        }

        $this->restoreInvoiceDiscount($saleId, $storedShares);

        $this->session->set('cash_mode', CASH_MODE_FALSE);

        // Establish cash_mode for this sale by inspecting the payments
        if ($this->session->get('cash_rounding')) {
            $cashTypesOnly = true;
            foreach ($this->sale->get_sale_payments($saleId)->getResult() as $row) {
                if ($row->payment_type != lang('Sales.cash') && $row->payment_type != lang('Sales.cash_adjustment')) {
                    $cashTypesOnly = false;
                }
            }
            // TODO: Consider converting to ternary notation.
            //$cashTypesOnly
            // ? $this->session->set('cash_mode', CASH_MODE_TRUE)
            // : $this->session->set('cash_mode', CASH_MODE_FALSE);
            if ($cashTypesOnly) {
                $this->session->set('cash_mode', CASH_MODE_TRUE);
            } else {
                $this->session->set('cash_mode', CASH_MODE_FALSE);
            }
        }

        // Now load payments
        foreach ($this->sale->get_sale_payments($saleId)->getResult() as $row) {
            $this->addPayment($row->payment_type, $row->payment_amount, $row->reference_code ?? null, $row->cash_adjustment, (int) ($row->treasury_id ?? 0));
        }

        $this->set_customer($this->sale->get_customer($saleId)->person_id);
        $this->set_employee($this->sale->get_employee($saleId)->person_id);
        $this->set_quote_number($this->sale->get_quote_number($saleId));
        $this->set_work_order_number($this->sale->get_work_order_number($saleId));
        $this->set_sale_type($this->sale->get_sale_type($saleId));
        $this->set_comment($this->sale->get_comment($saleId));
        $this->set_dinner_table($this->sale->get_dinner_table($saleId));
        $this->set_delivered($this->sale->isDelivered($saleId));
        $this->set_return_origin($this->sale->getReturnOrigin($saleId));
        $this->setReturnDocument($this->sale->getReturnDocument($saleId));
        $this->setQuoteSource($this->sale->getSourceQuote($saleId));

        $timing = $this->sale->getInvoiceTiming($saleId);
        $this->setInvoiceDate($timing['invoice_date']);
        $this->setPaymentTerms($timing['payment_terms_days'] === null ? null : (string) $timing['payment_terms_days']);
        $this->session->set('sale_id', $saleId);
    }

    /**
     * The line of the cart that the item added last went to: a new line, or the one of the same item
     * that already held it.
     */
    private function cartLineOf(int $linesBefore, int $itemId, int $itemLocation): int|string
    {
        $cart = $this->session->get('sales_cart') ?? [];

        if (count($cart) > $linesBefore) {
            return array_key_last($cart);
        }

        foreach ($cart as $line => $item) {
            if ($item['item_id'] == $itemId && $item['item_location'] == $itemLocation) {
                return $line;
            }
        }

        return array_key_last($cart);
    }

    /**
     * Gives the register back the discount a saved sale was given. A sale that is completed is a
     * document and keeps the shares of its lines as they were saved, so that what it shows is what
     * the books hold. One that was only suspended is still being made, and its shares are worked out
     * again as the lines change.
     *
     * @param array<int|string, string> $storedShares By the line of the cart.
     */
    private function restoreInvoiceDiscount(int $saleId, array $storedShares): void
    {
        $discount = $this->sale->getInvoiceDiscount($saleId);

        if ($discount === null) {
            return;
        }

        $this->setInvoiceDiscount($discount['type'], InvoiceDiscount::normalise($discount['value']));

        if ($this->sale->getSaleStatus($saleId) !== COMPLETED) {
            return;
        }

        $cart = $this->session->get('sales_cart') ?? [];

        foreach ($cart as $line => $item) {
            $cart[$line]['invoice_discount_share'] = $storedShares[$line] ?? '0.0000';
            $cart[$line]['invoice_discount_frozen'] = true;
        }

        $this->set_cart($cart);
    }

    /**
     * @return int
     */
    public function get_sale_id(): int
    {
        return $this->session->get('sale_id');
    }

    /**
     * @return void
     */
    public function clear_all(): void
    {
        $this->session->set('sale_id', -1);    // TODO: Replace -1 with constant
        $this->clear_mode();
        $this->clear_table();
        $this->empty_cart();
        $this->clear_comment();
        $this->clear_email_receipt();
        $this->clear_invoice_number();
        $this->clear_delivered();
        $this->clearInvoiceDate();
        $this->clearPaymentTerms();
        $this->clear_return_origin();
        $this->clearReturnOverride();
        $this->clearReturnDocument();
        $this->clearQuoteSource();
        $this->clearInvoiceDiscount();
        $this->clear_quote_number();
        $this->clear_work_order_number();
        $this->clear_sale_type();
        $this->clear_giftcard_remainder();
        $this->empty_payments();
        $this->remove_customer();
        $this->clear_cash_flags();
    }

    /**
     * @return void
     */
    public function clear_cash_flags(): void
    {
        $this->session->remove('cash_rounding');
        $this->session->remove('cash_mode');
        $this->session->remove('payment_type');
    }

    /**
     * Determines if cash rounding should be a consideration for this site
     * It also set resets the cash mode to disabled which will then be re-evaluated when
     * retrieving payments.
     */
    public function reset_cash_rounding(): int
    {
        $cash_rounding_code = $this->config['cash_rounding_code'];

        if (cash_decimals() < totals_decimals() || $cash_rounding_code == Rounding_mode::HALF_FIVE) {    // TODO: convert to ternary notation.
            $cash_rounding = 1;    // TODO: Replace with constant
        } else {
            $cash_rounding = 0;    // TODO: Replace with constant
        }
        $this->session->set('cash_rounding', $cash_rounding);
        $this->session->set('cash_mode', CASH_MODE_FALSE);

        return $cash_rounding;
    }

    /**
     * @return bool
     */
    public function is_customer_taxable(): bool    // TODO: This function is never called in the code
    {
        $customer_id = $this->get_customer();
        $customer = $this->customer->get_info($customer_id);

        // Do not charge sales tax if we have a customer that is not taxable
        return $customer->taxable or $customer_id == -1;    // TODO: Replace with constant.  Also, I'm not sure we should be using the or operator instead of || here. $a || $b guarantees that the result of those two get returned.  It's possible that return $a or $b could return just the result of $a since `or` has a lower precedence.
    }

    /**
     * @param string $discount
     * @param int $discount_type
     * @return void
     */
    public function apply_customer_discount(string $discount, int $discount_type): void
    {
        // Get all items in the cart so far...
        $items = $this->get_cart();

        foreach ($items as &$item) {
            $quantity = $item['quantity'];
            $price = $item['price'];

            // Set a new discount only if the current one is 0
            if ($item['discount'] == 0.0) {    // TODO: === ?
                $item['discount'] = $discount;
                $item['total'] = $this->get_item_total($quantity, $price, $discount, $discount_type);
                $item['discounted_total'] = $this->get_item_total($quantity, $price, $discount, $discount_type, true);
            }
        }

        $this->set_cart($items);
    }

    /**
     * @return string
     */
    public function get_discount(): string
    {
        $discount = '0.0';
        foreach ($this->get_cart() as $item) {
            if ($item['discount'] > '0.0') {
                $item_discount = $this->get_item_discount($item['quantity'], $item['price'], $item['discount'], $item['discount_type']);
                $discount = bcadd($discount, $item_discount);
            }
        }

        return $discount;
    }

    /**
     * @param bool $include_discount
     * @param bool $exclude_tax
     * @return string
     */
    public function get_subtotal(bool $include_discount = false, bool $exclude_tax = false): string
    {
        return $this->calculate_subtotal($include_discount, $exclude_tax);
    }

    /**
     * @param int $item_id
     * @param string $quantity
     * @param string $price
     * @param string $discount
     * @param int $discount_type
     * @param bool $include_discount
     * @return string
     */
    public function get_item_total_tax_exclusive(int $item_id, string $quantity, string $price, string $discount, int $discount_type, bool $include_discount = false, string $invoiceDiscountShare = '0'): string
    {
        $itemTotal = $this->get_item_total($quantity, $price, $discount, $discount_type, $include_discount, $invoiceDiscountShare);

        foreach ($this->taxLib()->taxesOfItem($item_id, $quantity, $price, $discount, $discount_type, $invoiceDiscountShare) as $tax) {
            if ($tax['type'] === Tax_lib::TAX_TYPE_INCLUDED) {
                $itemTotal = bcsub($itemTotal, $tax['amount']);
            }
        }

        return $itemTotal;
    }

    // TODO: This function doesn't seem to be called anywhere in the code.

    /**
     * @param int $item_id
     * @param string $discounted_extended_amount
     * @param string $quantity
     * @param string $price
     * @param string $discount
     * @param int $discount_type
     * @return string
     */
    public function get_extended_total_tax_exclusive(int $item_id, string $discounted_extended_amount, string $quantity, string $price, string $discount = '0.0', int $discount_type = 0): string
    {
        $tax_info = $this->item_taxes->get_info($item_id);

        // Only additive tax here
        foreach ($tax_info as $tax) {
            $tax_percentage = $tax['percent'];
            $discounted_extended_amount = bcsub($discounted_extended_amount, $this->get_item_tax($quantity, $price, $discount, $discount_type, $tax_percentage));
        }

        return $discounted_extended_amount;
    }

    /**
     * @param string $quantity
     * @param string $price
     * @param string $discount
     * @param int $discount_type
     * @param bool $include_discount
     * @return string
     */
    public function get_item_total(string $quantity, string $price, string $discount, int $discount_type, bool $include_discount = false, string $invoiceDiscountShare = '0'): string
    {
        $total = bcmul($quantity, $price);
        if ($include_discount) {
            $discountAmount = $this->get_item_discount($quantity, $price, $discount, $discount_type);
            $discountedTotal = bcsub($total, $discountAmount);

            return $invoiceDiscountShare === '0' ? $discountedTotal : bcsub($discountedTotal, $invoiceDiscountShare);
        }

        return $total;
    }

    /**
     * Derive the quantity sold based on the new total entered, returning the quanitity rounded to the
     * appropriate decimal positions.
     * @param string $total
     * @param string $price
     * @return string
     */
    public function get_quantity_sold(string $total, string $price): string
    {
        return bcdiv($total, $price, quantity_decimals());
    }

    /**
     * @param string $quantity
     * @param string $price
     * @param string $discount_amount
     * @return string
     */
    public function get_extended_amount(string $quantity, string $price, string $discount_amount = '0.0'): string
    {
        $extended_amount = bcmul($quantity, $price);

        return bcsub($extended_amount, $discount_amount);
    }

    /**
     * @param string $quantity
     * @param string $price
     * @param string $discount
     * @param int $discount_type
     * @return string
     */
    public function get_item_discount(string $quantity, string $price, string $discount, int $discount_type): string
    {
        $total = bcmul($quantity, $price);
        if ($discount_type == PERCENT) {    // TODO: === ?.  Also, ternary notation
            $discount = bcmul($total, bcdiv($discount, '100'));
        } else {
            $discount = bcmul($quantity, $discount);
        }

        return (string)round((float)$discount, totals_decimals(), PHP_ROUND_HALF_UP);    // TODO: is this safe with monetary amounts?
    }

    /**
     * @param string $quantity
     * @param string $price
     * @param string $discount
     * @param int $discount_type
     * @param string $tax_percentage
     * @return string
     */
    public function get_item_tax(string $quantity, string $price, string $discount, int $discount_type, string $tax_percentage, string $invoiceDiscountShare = '0', ?bool $included = null): string
    {
        $itemTotal = $this->get_item_total($quantity, $price, $discount, $discount_type, true, $invoiceDiscountShare);

        if ($included ?? $this->config['tax_included']) {
            $taxFraction = bcdiv(bcadd('100', $tax_percentage), '100');
            $priceTaxExcl = bcdiv($itemTotal, $taxFraction);

            return bcsub($itemTotal, $priceTaxExcl);
        }

        $taxFraction = bcdiv($tax_percentage, '100');

        return bcmul($itemTotal, $taxFraction);
    }

    /**
     * @param bool $include_discount
     * @param bool $exclude_tax
     * @return string
     */
    public function calculate_subtotal(bool $include_discount = false, bool $exclude_tax = false): string
    {
        $subtotal = '0.0';
        foreach ($this->get_cart() as $item) {
            $invoiceDiscountShare = $include_discount ? ($item['invoice_discount_share'] ?? '0') : '0';

            if ($exclude_tax) {
                $subtotal = bcadd($subtotal, $this->get_item_total_tax_exclusive($item['item_id'], $item['quantity'], $item['price'], $item['discount'], $item['discount_type'], $include_discount, $invoiceDiscountShare));
            } else {
                $subtotal = bcadd($subtotal, $this->get_item_total($item['quantity'], $item['price'], $item['discount'], $item['discount_type'], $include_discount, $invoiceDiscountShare));
            }
        }

        return $subtotal;
    }

    /**
     * Calculates the total sales amount with the default option to include cash rounding
     * @param bool $include_cash_rounding
     * @return string
     */
    public function get_total(bool $include_cash_rounding = true): string
    {
        $total = $this->calculate_subtotal(true);

        $cash_mode = $this->session->get('cash_mode');

        $cart = $this->get_cart();

        foreach ($this->taxLib()->get_taxes($cart)[0] as $tax) {
            if ((string) $tax['tax_type'] === Tax_lib::TAX_TYPE_EXCLUDED) {
                $total = bcadd($total, $tax['sale_tax_amount']);
            }
        }

        if ($include_cash_rounding && $cash_mode) {
            $total = $this->check_for_cash_rounding($total);
        }

        return $total;
    }

    /**
     * @param int|null $current_dinner_table_id
     * @return array
     */
    public function get_empty_tables(?int $current_dinner_table_id): array
    {
        return $this->dinner_table->get_empty_tables($current_dinner_table_id);
    }

    private function taxLib(): Tax_lib
    {
        return $this->taxLib ??= new Tax_lib();
    }

    /**
     * @param string $total
     * @return string
     */
    public function check_for_cash_rounding(string $total): string
    {
        $cash_decimals = cash_decimals();
        $cash_rounding_code = $this->config['cash_rounding_code'];

        return Rounding_mode::round_number($cash_rounding_code, (float)$total, $cash_decimals);
    }
}
