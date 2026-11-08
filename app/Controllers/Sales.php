<?php

namespace App\Controllers;

use App\Libraries\Barcode_lib;
use App\Libraries\Email_lib;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\Currency;
use App\Libraries\Accounting\CurrencyDisplay;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\DraftInvoice;
use App\Libraries\Accounting\InvoiceTiming;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\QuoteLifecycle;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\SaleDocumentList;
use App\Libraries\Accounting\SalePosting;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Documents\CustomFields;
use App\Libraries\Documents\CustomFieldType;
use App\Libraries\Documents\DocumentException;
use App\Libraries\Documents\RendersPdf;
use App\Libraries\Documents\VoucherDocuments;
use App\Libraries\HeaderCharges;
use App\Libraries\InvoiceDiscount;
use App\Libraries\InvoiceDiscountProblem;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Pdf_lib;
use App\Libraries\Pricing\PriceControls;
use App\Libraries\Pricing\SalesControlSettings;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Sale_lib;
use App\Libraries\ShippingTaxOption;
use App\Libraries\Tax_lib;
use App\Libraries\Token_lib;
use App\Models\Customer;
use App\Models\Customer_rewards;
use App\Models\Dinner_table;
use App\Models\Employee;
use App\Models\Giftcard;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_kit;
use App\Models\Price_list;
use App\Models\Sale;
use App\Models\Stock_location;
use App\Models\Tokens\Token_invoice_count;
use App\Models\Tokens\Token_customer;
use App\Models\Tokens\Token_invoice_sequence;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Config\OSPOS;
use ReflectionException;
use Throwable;
use stdClass;

class Sales extends Secure_Controller
{
    use RendersPdf;

    protected $helpers = ['file'];
    private Barcode_lib $barcode_lib;
    private Email_lib $email_lib;
    private Sale_lib $sale_lib;
    private Tax_lib $tax_lib;
    private Token_lib $token_lib;
    private Customer $customer;
    private Customer_rewards $customer_rewards;
    private Dinner_table $dinner_table;
    protected Employee $employee;
    private Item $item;
    private Item_kit $item_kit;
    private Price_list $priceList;
    private Sale $sale;
    private Stock_location $stock_location;
    private array $config;

    public function __construct()
    {
        parent::__construct('sales');

        $this->session = session();
        $this->barcode_lib = new Barcode_lib();
        $this->email_lib = new Email_lib();
        $this->sale_lib = new Sale_lib();
        $this->tax_lib = new Tax_lib();
        $this->token_lib = new Token_lib();
        $this->config = config(OSPOS::class)->settings;

        $this->customer = model(Customer::class);
        $this->sale = model(Sale::class);
        $this->item = model(Item::class);
        $this->item_kit = model(Item_kit::class);
        $this->priceList = model(Price_list::class);
        $this->stock_location = model(Stock_location::class);
        $this->customer_rewards = model(Customer_rewards::class);
        $this->dinner_table = model(Dinner_table::class);
        $this->employee = model(Employee::class);
    }

    public function getIndex(): ResponseInterface|string
    {
        if ($this->stock_location->get_allowed_locations('sales', true) === []) {
            return redirect()->to('no_access/sales/sales_stock');
        }

        $this->session->set('allow_temp_items', 1);
        return $this->reload();
    }

    /**
     * Items offered as one tap tiles in the register. The number of tiles is the quick_items_count setting, 0 turns them off.
     *
     * @return list<object>
     */
    private function quickItems(): array
    {
        $count = (int) ($this->config['quick_items_count'] ?? DEFAULT_QUICK_ITEMS);

        if ($count < 1) {
            return [];
        }

        return cache()->remember("sales_quick_items_$count", 300, fn (): array => $this->item->get_top_sellers($count));
    }

    /**
     * Load the sale edit modal. Used in app/Views/sales/register.php.
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function getManage(): ResponseInterface|string
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        } else {
            $data['table_headers'] = get_sales_manage_table_headers();

            $data['filters'] = [
                'only_cash'         => lang('Sales.cash_filter'),
                'only_due'          => lang('Sales.due_filter'),
                'only_check'        => lang('Sales.check_filter'),
                'only_creditcard'   => lang('Sales.credit_filter'),
                'only_debit'        => lang('Sales.debit'),
                'only_bank_transfer'=> lang('Sales.bank_transfer'),
                'only_wallet'       => lang('Sales.wallet'),
                'only_invoices'     => lang('Sales.invoice_filter'),
                'selected_customer' => lang('Sales.selected_customer')
            ];

            if ($this->sale_lib->get_customer() != -1) {
                $selectedFilters = ['selected_customer'];
                $data['customer_selected'] = true;
            } else {
                $data['customer_selected'] = false;
                $selectedFilters = [];
            }

            // Restore filters from URL query string
            $filters = restoreTableFilters($this->request);
            if (!empty($filters['selected_filters'])) {
                $selectedFilters = array_merge($selectedFilters, $filters['selected_filters']);
            }
            if (isset($filters['start_date'])) {
                $data['start_date'] = $filters['start_date'];
            }
            if (isset($filters['end_date'])) {
                $data['end_date'] = $filters['end_date'];
            }
            $data['selected_filters'] = $selectedFilters;

            return view('sales/manage', $data);
        }
    }

    /**
     * @param int $rowId
     * @return ResponseInterface
     */
    public function getRow(int $rowId): ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $saleInfo = $this->sale->get_info($rowId)->getRow();

        if ($saleInfo === null) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $dataRow = getSaleDataRow($saleInfo);

        return $this->response->setJSON($dataRow);
    }

    /**
     * @return ResponseInterface
     */
    public function getSearch(): ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $search = $this->request->getGet('search', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $limit = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort = $this->sanitizeSortColumn(salesHeaders(), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'sale_id');
        $order = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $filters = [
            'sale_type'         => 'all',
            'location_id'       => 'all',
            'start_date'        => $this->request->getGet('start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'end_date'          => $this->request->getGet('end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'only_cash'         => false,
            'only_due'          => false,
            'only_check'        => false,
            'selected_customer' => false,
            'only_creditcard'   => false,
            'only_debit'        => false,
            'only_bank_transfer'=> false,
            'only_wallet'       => false,
            'only_invoices'     => $this->config['invoice_enable'] && $this->request->getGet('only_invoices', FILTER_SANITIZE_NUMBER_INT),
            'is_valid_receipt'  => $this->sale->isValidReceipt($search)
        ];

        // Check if any filter is set in the multiselect dropdown
        $requestFilters = array_fill_keys($this->request->getGet('filters', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? [], true);
        $filters = array_merge($filters, $requestFilters);

        $sales = $this->sale->search($search, $filters, $limit, $offset, $sort, $order);
        $totalRows = $this->sale->get_found_rows($search, $filters);
        $payments = $this->sale->getPaymentsSummary($search, $filters);
        $paymentSummary = getSalesManagePaymentsSummary($payments);

        $dataRows = [];
        foreach ($sales->getResult() as $sale) {
            $dataRows[] = getSaleDataRow($sale);
        }

        if ($totalRows > 0) {
            $dataRows[] = getSaleDataLastRow($sales);
        }

        return $this->response->setJSON(['total' => $totalRows, 'rows' => $dataRows, 'payment_summary' => $paymentSummary]);
    }

    /**
     * Gets search suggestions for an item or item kit. Used in app/Views/sales/register.php.
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getItemSearch(): ResponseInterface
    {
        $suggestions = [];
        $receipt = $search = $this->request->getGet('term') != ''
            ? $this->request->getGet('term')
            : null;

        if ($this->sale_lib->get_mode() == 'return' && $this->sale->isValidReceipt($receipt)) {
            // If a valid receipt or invoice was found the search term will be replaced with a receipt number (POS #)
            $suggestions[] = $receipt;
        }
        $suggestions = array_merge($suggestions, $this->item->get_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false], true));
        $suggestions = array_merge($suggestions, $this->item_kit->get_search_suggestions($search));

        return $this->response->setJSON($suggestions);
    }

    /**
     * @return ResponseInterface
     */
    public function suggest_search(): ResponseInterface
    {
        $search = $this->request->getPost('term') != ''
            ? $this->request->getPost('term')
            : null;

        $suggestions = $this->sale->get_search_suggestions($search);

        return $this->response->setJSON($suggestions);
    }

    /**
     * Set a given customer. Used in app/Views/sales/register.php.
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSelectCustomer(): ResponseInterface|string
    {
        $customer_id = (int)$this->request->getPost('customer', FILTER_SANITIZE_NUMBER_INT);
        if ($this->customer->exists($customer_id)) {
            $this->sale_lib->set_customer($customer_id);
            $discount = $this->customer->get_info($customer_id)->discount;
            $discount_type = $this->customer->get_info($customer_id)->discount_type;

            // Apply customer default discount to items that have 0 discount
            if ($discount != '') {
                $this->sale_lib->apply_customer_discount($discount, $discount_type);
            }

            $this->sale_lib->repriceFromList();
        }

        return $this->reload();
    }

    /**
     * Changes the sale mode in the register to carry out different types of sales
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postChangeMode(): ResponseInterface|string
    {
        $mode = $this->request->getPost('mode', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if ($mode !== $this->sale_lib->get_mode()) {
            if ($mode === 'return' && $this->sale_lib->getCurrency() !== null) {
                if ($this->sale_lib->get_cart() !== []) {
                    return $this->reload(['error' => lang('Sales.currency_return_mode')]);
                }

                $this->sale_lib->clearCurrency();
            }

            $this->sale_lib->clearInvoiceDiscount();
            $this->sale_lib->clearHeaderCharges();
            $this->sale_lib->clearReturnDocument();
            $this->sale_lib->clearQuoteSource();
            $this->sale_lib->clearOrderSource();
        }

        $this->sale_lib->set_mode($mode);

        if ($mode == 'sale') {
            $this->sale_lib->set_sale_type(SALE_TYPE_POS);
        } elseif ($mode == 'sale_quote') {
            $this->sale_lib->set_sale_type(SALE_TYPE_QUOTE);
        } elseif ($mode == 'sale_work_order') {
            $this->sale_lib->set_sale_type(SALE_TYPE_WORK_ORDER);
        } elseif ($mode == 'sale_invoice') {
            $this->sale_lib->set_sale_type(SALE_TYPE_INVOICE);
        } else {
            $this->sale_lib->set_sale_type(SALE_TYPE_RETURN);
        }

        if ($this->config['dinner_table_enable']) {
            $occupied_dinner_table = $this->request->getPost('dinner_table', FILTER_SANITIZE_NUMBER_INT);
            $released_dinner_table = $this->sale_lib->get_dinner_table();
            $occupied = $this->dinner_table->is_occupied($released_dinner_table);

            if ($occupied && ($occupied_dinner_table != $released_dinner_table)) {
                $this->dinner_table->swap_tables($released_dinner_table, $occupied_dinner_table);
            }

            $this->sale_lib->set_dinner_table($occupied_dinner_table);
        }

        $stock_location = $this->request->getPost('stock_location', FILTER_SANITIZE_NUMBER_INT);

        if (!$stock_location || $stock_location == $this->sale_lib->get_sale_location()) {
            // TODO: The code below was removed in 2017 by @steveireland. We either need to reinstate some of it or remove this entire if block but we can't leave an empty if block
            //            $dinner_table = $this->request->getPost('dinner_table');
            //            $this->sale_lib->set_dinner_table($dinner_table);
        } elseif ($this->stock_location->is_allowed_location($stock_location, 'sales', true)) {
            $this->sale_lib->set_sale_location($stock_location);
        }

        $this->sale_lib->empty_payments();

        return $this->reload();
    }

    /**
     * @param int $sale_type
     * @return ResponseInterface|string
     */
    public function change_register_mode(int $sale_type): ResponseInterface|string
    {
        $mode = match ($sale_type) {
            SALE_TYPE_QUOTE => 'sale_quote',
            SALE_TYPE_WORK_ORDER => 'sale_work_order',
            SALE_TYPE_INVOICE => 'sale_invoice',
            SALE_TYPE_RETURN => 'return',
            default => 'sale' // SALE_TYPE_POS
        };

        $this->sale_lib->set_mode($mode);
        return $this->reload();
    }


    /**
     * Sets the sales comment. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetComment(): ResponseInterface
    {
        $this->sale_lib->set_comment($this->request->getPost('comment', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Keeps the values typed for the extra fields of the invoice. A value that does not fit its field
     * is kept as typed and reported, and the invoice cannot be completed until it is right. Used in
     * app/Views/sales/register.php
     *
     * @noinspection PhpUnused
     */
    public function postSetCustomFields(): ResponseInterface
    {
        $posted = $this->request->getPost('custom');
        $posted = is_array($posted) ? $posted : [];
        $fields = new CustomFields();
        $kept   = [];

        foreach ($fields->definitions('sale') as $definition) {
            $raw = $posted[$definition->id] ?? '';

            if (is_scalar($raw)) {
                $kept[$definition->id] = mb_substr(trim((string) $raw), 0, CustomFieldType::MAX_TEXTAREA);
            }
        }

        $this->sale_lib->setCustomFields($kept);

        return $this->response->setJSON(['success' => true, 'problem' => $fields->problem('sale', $kept, false, (int) $this->session->get('sale_id'))]);
    }

    /**
     * The extra fields of the invoice and of its items that print on its documents.
     *
     * @param array<int|string, array<string, mixed>> $cart
     *
     * @return array<string, mixed>
     */
    private function printedFieldsOf(mixed $saleId, array $cart): array
    {
        if (! is_int($saleId) && ! is_numeric($saleId)) {
            return [];
        }

        $fields = new CustomFields();

        return [
            'printed_fields'      => (int) $saleId > 0 ? $fields->printable('sale', (int) $saleId) : [],
            'item_printed_fields' => $fields->printableFor('item', array_map(static fn (array $line): int => (int) $line['item_id'], array_values($cart))),
        ];
    }

    /**
     * Keeps the extra fields of the invoice that was just saved. The values were checked before the
     * invoice was saved, so a failure here is only a change made in between: it is logged and leaves the
     * invoice as it was saved.
     */
    private function keepCustomFields(mixed $saleId, bool $requireValues = true): void
    {
        if (! is_int($saleId) || $saleId <= 0) {
            return;
        }

        try {
            (new CustomFields())->save('sale', $saleId, $this->sale_lib->getCustomFields(), $requireValues);
        } catch (DocumentException $exception) {
            log_message('error', 'The extra fields of sale ' . $saleId . ' were not kept: ' . $exception->error->value);
        }
    }

    /**
     * Sets the discount on the whole of the sale, a percentage or an amount taken off after the
     * discounts of the lines, or removes it. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSetInvoiceDiscount(): ResponseInterface|string
    {
        if (!$this->mayGiveInvoiceDiscount()) {
            return $this->reload(['error' => lang('Sales.not_authorized')]);
        }

        if ($this->sale_lib->is_return_mode()) {
            return $this->reload(['error' => lang(InvoiceDiscountProblem::OnReturn->languageKey())]);
        }

        $postedType = $this->request->getPost('invoice_discount_type');
        $postedValue = $this->request->getPost('invoice_discount');
        $type = is_string($postedType) ? (int) $postedType : -1;
        $value = is_string($postedValue) ? trim($postedValue) : 'not a number';
        $value = $value === '' ? '0' : str_replace(',', '.', $value);

        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1 && bccomp($value, '0', InvoiceDiscount::SCALE) === 0) {
            $this->sale_lib->clearInvoiceDiscount();
        } else {
            $cart = $this->sale_lib->get_cart();
            $problem = InvoiceDiscount::problem($type, $value, InvoiceDiscount::baseOf($cart, totals_decimals()), totals_decimals());

            if ($problem === InvoiceDiscountProblem::ExceedsTotal && $cart === []) {
                $problem = null;
            }

            if ($problem !== null) {
                return $this->reload(['error' => lang($problem->languageKey())]);
            }

            $this->sale_lib->setInvoiceDiscount($type, InvoiceDiscount::normalise($value));
        }

        $this->sale_lib->empty_payments();

        return $this->reload();
    }

    /**
     * Sets the adjustment and the shipping of the document in the register: a label and a signed
     * amount for the adjustment, an amount and whether to tax it for the shipping, and what the shipping
     * block shows of the address. Each is taken only where its setting is on, and only on an invoice, a
     * quote or a credit note. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSetHeaderCharges(): ResponseInterface|string
    {
        $settings = new AccountingSettings();

        if (!$this->headerChargesAllowed()) {
            return $this->reload(['error' => lang('Accounting.error_header_charges_not_allowed')]);
        }

        $posted = fn (string $name): string => is_string($this->request->getPost($name)) ? trim(str_replace(',', '.', (string) $this->request->getPost($name))) : '';
        $current = $this->sale_lib->getHeaderCharges();

        $adjustment = $settings->salesAdjustmentEnabled() ? ($posted('adjustment') === '' ? '0' : $posted('adjustment')) : '0';
        $label = $settings->salesAdjustmentEnabled() && is_string($this->request->getPost('adjustment_label')) ? trim((string) $this->request->getPost('adjustment_label')) : '';
        $shipping = $settings->salesShippingEnabled() ? ($posted('shipping') === '' ? '0' : $posted('shipping')) : '0';
        $taxed = $settings->salesShippingEnabled() && $this->request->getPost('shipping_taxed') === '1' && bccomp($settings->shippingTaxPercent(), '0', 4) > 0;
        $option = $settings->salesShippingEnabled() && $posted('shipping_options') !== '' ? (int) $posted('shipping_options') : null;

        $charges = new HeaderCharges(
            $label,
            $adjustment,
            $shipping,
            $taxed ? ($this->config['tax_included'] ? ShippingTaxOption::Included : ShippingTaxOption::Added) : ShippingTaxOption::None,
            $taxed ? $settings->shippingTaxPercent() : '0',
            $option,
        );

        if (!$this->mayGiveHeaderCharges($charges, $this->sale_lib->is_return_mode())) {
            return $this->reload(['error' => lang('Sales.not_authorized')]);
        }

        $this->sale_lib->setHeaderCharges($charges);

        $cart = $this->sale_lib->get_cart();
        $problem = $this->sale_lib->headerChargesProblem($this->tax_lib->get_taxes($cart)[0]);

        if ($problem !== null) {
            $this->sale_lib->setHeaderCharges($current);

            return $this->reload(['error' => lang($problem->languageKey())]);
        }

        $this->sale_lib->empty_payments();

        return $this->reload();
    }

    /**
     * The block that says where the goods are sent, as the shipping details of the document ask for: the
     * address of the customer or the second one, nothing at all when they say so, and when they leave it to
     * the document (automatic) the address of the customer for a document that carries shipping.
     *
     * @param array<string, mixed> $data
     */
    private function shipTo(array $data): string
    {
        $charges = $data['header_charges'] ?? [];
        $option  = $charges['shipping_options'] ?? null;

        if (!isset($data['customer_id']) || $option === 1 || ($option === null && bccomp((string) ($charges['shipping'] ?? '0'), '0', 4) === 0)) {
            return '';
        }

        $second = $option === 3;
        $lines  = [(string) ($data['customer'] ?? ''), (string) ($second ? ($data['customer_second_address'] ?? '') : ($data['customer_address'] ?? '')), $second ? '' : (string) ($data['customer_location'] ?? '')];

        return trim(implode("\n", array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '')));
    }

    private function mayGiveHeaderCharges(HeaderCharges $charges, bool $creditNote): bool
    {
        return !$charges->givesAway($creditNote) || $this->mayGiveInvoiceDiscount();
    }

    private function headerChargesAllowed(): bool
    {
        return in_array($this->sale_lib->get_mode(), ['sale_invoice', 'sale_quote', 'return'], true);
    }

    /**
     * The charges of the header as the register, the receipt and the documents show them, from the totals
     * of the cart.
     *
     * @param array<string, mixed> $totals
     *
     * @return array<string, mixed>
     */
    private function headerChargesView(array $totals): array
    {
        $rows = [
            'shipping'              => (string) $totals['shipping'],
            'shipping_tax'          => (string) $totals['shipping_tax'],
            'shipping_tax_included' => (bool) $totals['shipping_tax_included'],
            'adjustment'            => (string) $totals['adjustment'],
            'adjustment_label'      => (string) $totals['adjustment_label'],
            'shipping_options'      => $totals['shipping_options'],
        ];

        $rows['has'] = bccomp($rows['shipping'], '0', 4) !== 0 || bccomp($rows['adjustment'], '0', 4) !== 0;

        return $rows;
    }

    /**
     * Changes the price list a sale is priced from, when the shop lets a sale change its list. The
     * lines that were not typed over are priced again from it. Used in app/Views/sales/register.php
     *
     * @noinspection PhpUnused
     */
    public function postSetPriceList(): ResponseInterface|string
    {
        $settings = new SalesControlSettings();

        if (!$settings->priceListsEnabled() || !$settings->changePriceListOnInvoice() || !$this->mayChangePriceList()) {
            return $this->reload(['error' => lang('Sales.not_authorized')]);
        }

        $listId = (int) $this->request->getPost('price_list_id');

        if ($listId !== 0 && !$this->priceList->isActive($listId)) {
            return $this->reload(['error' => lang('Sales.price_list_inactive')]);
        }

        $this->sale_lib->setPriceListChoice($listId);

        $this->sale_lib->repriceFromList();
        $this->sale_lib->empty_payments();

        return $this->reload();
    }

    /**
     * Chooses the currency of the sale in the register: a foreign currency that is switched on and has a rate,
     * or the base currency. The choice is made while the cart is empty, because the prices in it are in the
     * currency chosen, and a return takes the currency of the sale it comes from. Used in app/Views/sales/register.php
     *
     * @noinspection PhpUnused
     */
    public function postSetCurrency(): ResponseInterface|string
    {
        $code = strtoupper(trim((string) $this->request->getPost('currency_code')));

        if (!(new AccountingSettings())->isEnabled()) {
            return $this->reload(['error' => lang('Sales.not_authorized')]);
        }

        if ($this->sale_lib->is_return_mode()) {
            return $this->reload(['error' => lang('Sales.currency_return_mode')]);
        }

        if ($this->sale_lib->get_cart() !== []) {
            return $this->reload(['error' => lang('Sales.currency_cart_not_empty')]);
        }

        $currencies = new Currencies();

        if ($code === '' || $currencies->isBase($code)) {
            $this->sale_lib->clearCurrency();

            return $this->reload();
        }

        try {
            $currencies->documentCurrency($code, date('Y-m-d'));
        } catch (AccountingException $refusal) {
            return $this->reload(['error' => AccountingMessage::of($refusal)]);
        }

        $this->sale_lib->setCurrency($code);
        $this->sale_lib->empty_payments();

        return $this->reload();
    }

    /**
     * The currency the register shows its amounts in: that of the sale, or of the sale a return takes back.
     */
    private function registerDisplayCurrency(): ?Currency
    {
        $origin = $this->sale_lib->get_return_origin();
        $code   = $this->sale_lib->is_return_mode() ? ($origin === null ? null : $this->sale->getCurrencyCode($origin)) : $this->sale_lib->getCurrency();

        return $code === null ? null : (new Currencies())->find($code);
    }

    /**
     * The tiles of the best sellers, priced in the currency of the sale.
     *
     * @return list<object>
     */
    private function quickItemsIn(?Currency $currency): array
    {
        $items = $this->quickItems();

        if ($currency === null || $this->sale_lib->is_return_mode()) {
            return $items;
        }

        try {
            return array_map(function (object $item): object {
                $priced             = clone $item;
                $priced->unit_price = $this->sale_lib->catalogPrice((string) $item->unit_price);

                return $priced;
            }, $items);
        } catch (AccountingException) {
            return [];
        }
    }

    /**
     * The currency the sale is saved in. A return takes the currency of the sale it takes back, so what the
     * register holds does not count for it.
     */
    private function registerCurrency(): ?string
    {
        return $this->sale_lib->is_return_mode() ? null : $this->sale_lib->getCurrency();
    }

    /**
     * The currencies the register offers, or null when it offers none: the books are off, the sale is a
     * return, or no foreign currency is switched on.
     *
     * @return array{options: array<string, string>, selected: string}|null
     */
    private function currencySelector(): ?array
    {
        if (!(new AccountingSettings())->isEnabled() || $this->sale_lib->is_return_mode()) {
            return null;
        }

        $currencies = new Currencies();
        $arabic     = str_starts_with($this->request->getLocale(), 'ar');
        $options    = [];

        foreach ($currencies->all(true) as $currency) {
            if ($currency->decimals > Currencies::DOCUMENT_DECIMALS) {
                continue;
            }

            $options[$currency->code] = $currency->code . ' - ' . ($arabic ? $currency->nameAr : $currency->nameEn);
        }

        return count($options) < 2 ? null : ['options' => $options, 'selected' => $this->sale_lib->getCurrency() ?? $currencies->baseCode()];
    }

    /**
     * The lists a sale may be priced from, for the employee who may change them, or null when the
     * register offers no choice.
     *
     * @return array{options: array<int, string>, selected: int}|null
     */
    private function priceListSelector(): ?array
    {
        $settings = new SalesControlSettings();

        if (!$settings->priceListsEnabled() || !$settings->changePriceListOnInvoice() || !$this->mayChangePriceList() || $this->sale_lib->is_return_mode()) {
            return null;
        }

        $options = [0 => lang('Sales.price_list_none')];

        foreach ($this->priceList->allLists(true) as $list) {
            $options[(int) $list->price_list_id] = esc($list->name);
        }

        return ['options' => $options, 'selected' => $this->sale_lib->effectivePriceListId() ?? 0];
    }

    private function mayChangePriceList(): bool
    {
        return $this->employee->has_grant('sales_change_price', (int) $this->employee->get_logged_in_employee_info()->person_id);
    }

    /**
     * Sets the invoice number. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetInvoiceNumber(): ResponseInterface|string
    {
        $this->sale_lib->set_invoice_number(DocumentNumbering::typed((string) $this->request->getPost('sales_invoice_number')));
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * @return ResponseInterface
     */
    public function postSetPaymentType(): ResponseInterface|string    // TODO: This function does not appear to be called anywhere in the code.
    {
        $this->sale_lib->set_payment_type($this->request->getPost('selected_payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        return $this->reload();
    }

    /**
     * Sets PrintAfterSale flag. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSetPrintAfterSale(): ResponseInterface
    {
        $this->sale_lib->set_print_after_sale($this->request->getPost('sales_print_after_sale') != 'false');
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets whether the goods of the invoice leave with it. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetDelivered(): ResponseInterface
    {
        $this->sale_lib->set_delivered($this->request->getPost('sales_delivered') != 'false');

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets the day typed on the invoice being made, for an employee who may type it, or takes it
     * back when it is sent empty. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetInvoiceDate(): ResponseInterface
    {
        $settings = new AccountingSettings();

        if (!$settings->isEnabled() || !$this->mayTypeInvoiceDate()) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $typed = $this->request->getPost('sales_invoice_date');

        try {
            $date = InvoiceTiming::date(is_array($typed) ? 'not a day' : (string) $typed, date('Y-m-d'), $settings->startDate());
        } catch (AccountingException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal)]);
        }

        $this->sale_lib->setInvoiceDate($date);

        return $this->response->setJSON(['success' => true, 'due_date' => $this->registerDueDate(), 'due_date_label' => $this->registerDueDateLabel()]);
    }

    /**
     * Sets the payment terms typed on the invoice being made, in days, or takes them back when they
     * are sent empty. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetPaymentTerms(): ResponseInterface
    {
        if (!(new AccountingSettings())->isEnabled()) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $typed = $this->request->getPost('sales_payment_terms');

        try {
            $terms = InvoiceTiming::terms(is_array($typed) ? 'not a number' : (string) $typed);
        } catch (AccountingException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal)]);
        }

        $this->sale_lib->setPaymentTerms($terms === null ? null : (string) $terms);

        return $this->response->setJSON(['success' => true, 'due_date' => $this->registerDueDate(), 'due_date_label' => $this->registerDueDateLabel()]);
    }

    /**
     * The day the invoice being made falls due, from the day it is dated and its payment terms, or
     * null when it has no terms.
     */
    private function registerDueDate(): ?string
    {
        $terms = InvoiceTiming::terms($this->sale_lib->getPaymentTerms());

        return $terms === null ? null : InvoiceTiming::dueDate($this->sale_lib->getInvoiceDate() ?? date('Y-m-d'), $terms);
    }

    private function registerDueDateLabel(): string
    {
        $due = $this->registerDueDate();

        return $due === null ? '' : to_date(strtotime($due));
    }

    private function mayTypeInvoiceDate(): bool
    {
        return $this->employee->has_grant('sales_invoice_date', (int) $this->employee->get_logged_in_employee_info()->person_id);
    }

    /**
     * Names the sale a return takes its goods back from. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSetReturnOrigin(): ResponseInterface|string
    {
        $number = preg_replace('/\D+/', '', (string) $this->request->getPost('return_origin'));

        if ($number === '') {
            $this->sale_lib->set_return_origin(null);

            return $this->reload();
        }

        $saleId  = (int) $number;
        $problem = $this->returnOriginProblem($saleId, $this->sale_lib->getReturnDocument());

        if ($problem !== null) {
            return $this->reload(['error' => $problem]);
        }

        $this->sale_lib->set_return_origin($saleId);
        $this->sale_lib->clearReturnOverride();

        $customerId = $this->sale->getSummary($saleId)->customer_id;

        if ($customerId !== null && $this->sale_lib->get_customer() == NEW_ENTRY) {
            $this->sale_lib->set_customer((int) $customerId);
        }

        return $this->reload();
    }

    /**
     * Lets a manager take a return of stock with no sale to take it back from, with the reason why.
     * An empty reason takes the permission back out of the register. Used in app/Views/sales/register.php
     *
     * @noinspection PhpUnused
     */
    public function postSetReturnOverride(): ResponseInterface|string
    {
        $reason = trim((string) $this->request->getPost('return_override_reason'));

        if ($reason === '') {
            $this->sale_lib->clearReturnOverride();

            return $this->reload();
        }

        if (! $this->mayOverrideReturn()) {
            return $this->reload(['error' => lang('Sales.not_authorized')]);
        }

        if ($this->sale_lib->get_return_origin() !== null) {
            return $this->reload(['error' => lang('Sales.return_override_has_origin')]);
        }

        if (mb_strlen($reason) > 255) {
            return $this->reload(['error' => lang('Accounting.error_reason_required')]);
        }

        $this->sale_lib->setReturnOverride($reason);

        return $this->reload();
    }

    private function mayOverrideReturn(): bool
    {
        return $this->employee->has_grant(AccountingGuard::RETURN_OVERRIDE, (int) $this->employee->get_logged_in_employee_info()->person_id);
    }

    /**
     * Confirms that the goods of an issued invoice have left. Used in app/Views/sales/form.php
     *
     * @param int $saleId
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postConfirmDelivery(int $saleId = NEW_ENTRY): ResponseInterface
    {
        if ($this->outsideBranchScope($saleId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        $result     = $this->sale->confirm_delivery($saleId, $employeeId);

        if ($result === ACCOUNTING_FAILED) {
            return $this->response->setJSON(['success' => false, 'message' => $this->accountingFailureMessage(), 'id' => $saleId]);
        }

        if ($result === NEW_ENTRY) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Sales.delivery_not_possible'), 'id' => $saleId]);
        }

        return $this->response->setJSON(['success' => true, 'message' => lang('Sales.delivery_confirmed'), 'id' => $saleId]);
    }

    /**
     * The answer that refuses an action on a draft or a quote: the employee may not open sales
     * documents, or the document belongs to a branch the employee does not work in. Null when
     * the action may go on.
     */
    private function documentRefusal(int $saleId): ?ResponseInterface
    {
        if (!$this->employee->has_grant('reports_sales', (int) $this->session->get('person_id'))) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        if ($this->outsideBranchScope($saleId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        return null;
    }

    /**
     * Lists the draft invoices and the quotes that are waiting, of the branches the employee works
     * in. Used in app/Views/sales/register.php
     *
     * @noinspection PhpUnused
     */
    public function getDocuments(): ResponseInterface|string
    {
        $personId = (int) $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        $guard     = new BranchGuard();
        $lists     = new SaleDocumentList();
        $branchIds = $guard->allowedBranchIds($personId);
        $raw       = $this->request->getGet('quote_status');
        $only      = is_string($raw) && preg_match('/^-?[0-9]$/', $raw) === 1 ? QuoteStatus::tryFrom((int) $raw) : null;

        return view('sales/documents', [
            'drafts'       => $lists->drafts($branchIds, $personId),
            'quotes'       => $lists->quotes($branchIds, $only, $personId),
            'quote_filter' => $only,
            'statuses'     => QuoteStatus::cases(),
            'show_branch'  => $guard->seesBranches($personId),
        ]);
    }

    /**
     * The page of a draft invoice or a quote: what it holds, what it comes to now, and what may be
     * done with it. Used in app/Views/sales/documents.php
     *
     * @noinspection PhpUnused
     */
    public function getDocument(int $saleId): ResponseInterface|string
    {
        $personId = (int) $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        if ($this->outsideBranchScope($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $drafts      = new DraftInvoice();
        $quotes      = new QuoteLifecycle();
        $isDraft     = $drafts->isDraft($saleId);
        $quoteStatus = $quotes->statusOf($saleId);
        $header      = $this->sale->getDocumentHeader($saleId);

        if ((!$isDraft && $quoteStatus === null) || $header === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $preview      = $drafts->preview($saleId);
        $accountingOn = (new AccountingSettings())->isEnabled();
        $number       = $isDraft ? (string) ($header->invoice_number ?? '') : (string) ($header->quote_number ?? '');

        if ($number === '') {
            $number = $isDraft || $quoteStatus === QuoteStatus::Draft ? DraftInvoice::label($saleId) : '#' . $saleId;
        }

        $invoiceId = $quotes->invoiceOf($saleId);

        return view('sales/document', [
            'kind'            => $isDraft ? 'draft' : 'quote',
            'header'          => $header,
            'number'          => $number,
            'lines'           => $this->sale->get_sale_items_ordered($saleId)->getResult(),
            'figures'         => $preview === null ? null : $drafts->figures($preview),
            'quote_status'    => $quoteStatus,
            'next_statuses'   => $quoteStatus?->next() ?? [],
            'invoice_id'      => $invoiceId,
            'invoice_number'  => $invoiceId === null ? null : $this->sale->getInvoiceNumber($invoiceId),
            'accounting_on'   => $accountingOn,
            'can_type_date'   => $accountingOn && $this->mayTypeInvoiceDate(),
            'can_delete'      => $this->employee->has_grant('sales_delete', $personId),
            'invoice_enabled' => (bool) $this->config['invoice_enable'],
            'today'           => date('Y-m-d'),
        ]);
    }

    /**
     * Issues a draft invoice: it takes the next number of the invoice sequence, the day typed on it
     * and the terms, and is posted. Only a POST changes anything, and the token of the page goes with
     * it. Used in app/Views/sales/document.php
     *
     * @noinspection PhpUnused
     */
    public function postIssue(int $saleId = NEW_ENTRY): ResponseInterface
    {
        $refusal = $this->documentRefusal($saleId);

        if ($refusal !== null) {
            return $refusal;
        }

        $personId = (int) $this->session->get('person_id');

        if (((new DraftInvoice())->carriesInvoiceDiscount($saleId) || $this->sale->getHeaderCharges($saleId)->givesAway(false)) && !$this->mayGiveInvoiceDiscount()) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized'), 'id' => $saleId]);
        }

        $date  = $this->request->getPost('invoice_date');
        $terms = $this->request->getPost('payment_terms');
        $date  = is_array($date) ? 'not a day' : trim((string) $date);
        $terms = is_array($terms) ? 'not a number' : trim((string) $terms);

        $invoiceNumber = null;
        $format        = (string) ($this->config['sales_invoice_format'] ?? '');

        if ($format !== '' && !(new DocumentNumbering())->isEnabled(DocumentType::SalesInvoice) && ($this->sale->getInvoiceNumber($saleId) ?? '') === '') {
            $invoiceNumber = $this->token_lib->render($format);

            if ($this->sale->check_invoice_number_exists($invoiceNumber)) {
                return $this->response->setJSON(['success' => false, 'message' => lang('Sales.invoice_number_duplicate', [$invoiceNumber]), 'id' => $saleId]);
            }
        }

        $result = $this->sale->issueDraft($saleId, $personId, $invoiceNumber, $date === '' ? null : $date, $terms === '' ? null : $terms);

        $message = match (true) {
            $result === ACCOUNTING_FAILED        => $this->accountingFailureMessage(),
            $result === INVOICE_DISCOUNT_INVALID => $this->invoiceDiscountMessage(),
            $result === INSUFFICIENT_STOCK       => lang('Sales.item_out_of_stock'),
            $result < 1                          => lang('Sales.draft_issue_failed'),
            default                              => null,
        };

        if ($message !== null) {
            return $this->response->setJSON(['success' => false, 'message' => $message, 'id' => $saleId]);
        }

        return $this->response->setJSON(['success' => true, 'message' => lang('Sales.draft_issued'), 'id' => $saleId, 'redirect' => site_url('sales/invoice/' . $saleId)]);
    }

    /**
     * Moves a quote to another status. The transition is checked by the books of quotes and not by
     * the page. Used in app/Views/sales/document.php
     *
     * @noinspection PhpUnused
     */
    public function postQuoteStatus(int $saleId = NEW_ENTRY): ResponseInterface
    {
        $refusal = $this->documentRefusal($saleId);

        if ($refusal !== null) {
            return $refusal;
        }

        $personId = (int) $this->session->get('person_id');

        $raw = $this->request->getPost('quote_status');
        $to  = is_string($raw) && preg_match('/^-?[0-9]$/', $raw) === 1 ? QuoteStatus::tryFrom((int) $raw) : null;

        if ($to === null) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Sales.quote_status_invalid'), 'id' => $saleId]);
        }

        try {
            (new QuoteLifecycle())->change($saleId, $to, $personId);
        } catch (AccountingException|BranchException $refusal) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($refusal), 'id' => $saleId]);
        }

        return $this->response->setJSON(['success' => true, 'message' => lang('Sales.quote_status_changed'), 'id' => $saleId]);
    }

    /**
     * Fills the register with an invoice made from a quote. Nothing is saved and nothing is posted:
     * the quote is converted when the invoice is saved. Used in app/Views/sales/document.php
     *
     * @noinspection PhpUnused
     */
    public function postConvertQuote(int $saleId = NEW_ENTRY): ResponseInterface
    {
        $refusal = $this->documentRefusal($saleId);

        if ($refusal !== null) {
            return $refusal;
        }

        $personId = (int) $this->session->get('person_id');

        if (!$this->config['invoice_enable']) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Sales.quote_convert_unavailable'), 'id' => $saleId]);
        }

        $quotes = new QuoteLifecycle();
        $status = $quotes->statusOf($saleId);

        if ($status === null) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Accounting.error_quote_not_found'), 'id' => $saleId]);
        }

        if (!$status->canConvert()) {
            $reason = $status->isLocked() ? lang('Accounting.error_quote_locked') : lang('Accounting.error_quote_not_convertible', [lang($status->languageKey())]);

            return $this->response->setJSON(['success' => false, 'message' => $reason, 'id' => $saleId]);
        }

        $this->sale_lib->prepareInvoiceFromQuote($saleId);

        return $this->response->setJSON(['success' => true, 'message' => lang('Sales.quote_convert_prepared'), 'redirect' => site_url('sales')]);
    }

    /**
     * Sets the flag to include prices in the work order. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetPriceWorkOrders(): ResponseInterface
    {
        $price_work_orders = parse_decimals($this->request->getPost('price_work_orders'));
        $this->sale_lib->set_price_work_orders($price_work_orders);
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Sets the flag to email receipt to the customer. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postSetEmailReceipt(): ResponseInterface
    {
        $this->sale_lib->set_email_receipt($this->request->getPost('email_receipt', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Add a payment to the sale. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postAddPayment(): ResponseInterface|string
    {
        $data = [];
        $giftcard = model(Giftcard::class);
        $paymentType = $this->request->getPost('payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!is_string($paymentType) || $paymentType === '') {
            $data['error'] = lang('Sales.must_enter_numeric');

            return $this->reload($data);
        }

        if (
            $paymentType !== lang('Sales.giftcard')
            && $paymentType !== lang('Sales.rewards')
            && (str_contains($paymentType, lang('Sales.giftcard')) || str_contains($paymentType, lang('Sales.rewards')))
        ) {
            $data['error'] = lang('Sales.must_enter_numeric');

            return $this->reload($data);
        }

        if ($this->sale_lib->getCurrency() !== null && ($paymentType === lang('Sales.giftcard') || $paymentType === lang('Sales.rewards'))) {
            return $this->reload(['error' => lang('Sales.currency_stored_value')]);
        }

        $isReturnMode = $this->sale_lib->get_mode() === 'return';
        $amountTenderedRule = $isReturnMode ? 'trim|required|decimal_locale' : 'trim|required|decimal_locale|nonNegativeDecimal';

        if ($paymentType === lang('Sales.giftcard')) {
            $rules    = ['amount_tendered' => 'trim|required|integer'];
            $messages = ['amount_tendered' => lang('Sales.must_enter_numeric_giftcard')];
        } elseif (in_array($paymentType, get_reference_code_payment_types())) {
            $min      = (int)($this->config['payment_reference_code_min'] ?? 3);
            $max      = (int)($this->config['payment_reference_code_max'] ?? 20);
            $rules    = [
                'amount_tendered' => $amountTenderedRule,
                'reference_code'  => "trim|required|alpha_numeric|min_length[$min]|max_length[$max]",
            ];
            $messages = [
                'amount_tendered' => [
                    'required'           => lang('Sales.must_enter_numeric'),
                    'decimal_locale'     => lang('Sales.must_enter_numeric'),
                    'nonNegativeDecimal' => lang('Sales.negative_amount_tendered'),
                ],
                'reference_code'  => [
                    'required'      => lang('Sales.must_enter_reference_code'),
                    'alpha_numeric' => lang('Sales.reference_code_invalid_characters'),
                    'min_length'    => lang('Sales.reference_code_length_error'),
                    'max_length'    => lang('Sales.reference_code_length_error'),
                ],
            ];
        } else {
            $rules    = ['amount_tendered' => $amountTenderedRule];
            $messages = [
                'amount_tendered' => [
                    'required'           => lang('Sales.must_enter_numeric'),
                    'decimal_locale'     => lang('Sales.must_enter_numeric'),
                    'nonNegativeDecimal' => lang('Sales.negative_amount_tendered'),
                ],
            ];
        }

        if (!$this->validate($rules, $messages)) {
            $errors = $this->validator->getErrors();
            $data['error'] = $errors ? reset($errors) : lang('Sales.must_enter_numeric');
        } else {
            if ($paymentType === lang('Sales.giftcard')) {
                // For giftcard payments, the register input amount_tendered becomes the giftcard number
                $amountTendered = parse_decimals($this->request->getPost('amount_tendered'));
                $giftcardNumber = $amountTendered;

                $payments = $this->sale_lib->getPayments();
                $paymentType = $paymentType . ':' . $giftcardNumber;
                $currentPaymentsWithGiftcard = isset($payments[$paymentType]) ? $payments[$paymentType]['payment_amount'] : 0;
                $currentGiftcardValue = $giftcard->get_giftcard_value($giftcardNumber);
                $currentGiftcardCustomer = $giftcard->get_giftcard_customer($giftcardNumber);
                $customerId = $this->sale_lib->get_customer();

                if (isset($currentGiftcardCustomer) && $currentGiftcardCustomer != $customerId && $currentGiftcardCustomer != null) {
                    $data['error'] = lang('Giftcards.cannot_use', [$giftcardNumber]);
                } elseif (($currentGiftcardValue - $currentPaymentsWithGiftcard) <= 0 && $this->sale_lib->get_mode() === 'sale') {
                    $data['error'] = lang('Giftcards.remaining_balance', [$giftcardNumber, $currentGiftcardValue]);
                } else {
                    $newGiftcardValue = $giftcard->get_giftcard_value($giftcardNumber) - $this->sale_lib->get_amount_due();
                    $newGiftcardValue = max($newGiftcardValue, 0);
                    $this->sale_lib->set_giftcard_remainder($newGiftcardValue);
                    $newGiftcardValue = to_currency($newGiftcardValue);
                    $data['warning'] = lang('Giftcards.remaining_balance', [$giftcardNumber, $newGiftcardValue]);
                    $amountTendered = min($this->sale_lib->get_amount_due(), $giftcard->get_giftcard_value($giftcardNumber));

                    $this->sale_lib->addPayment($paymentType, $amountTendered);
                }
            } elseif ($paymentType === lang('Sales.rewards')) {
                $customerId = $this->sale_lib->get_customer();
                $packageId = $this->customer->get_info($customerId)->package_id;
                if (!empty($packageId)) {
                    $points = $this->customer->get_info($customerId)->points;
                    $points = ($points == null ? 0 : $points);

                    $payments = $this->sale_lib->getPayments();
                    $currentPaymentsWithRewards = isset($payments[$paymentType]) ? $payments[$paymentType]['payment_amount'] : 0;
                    $curRewardsValue = $points;

                    if (($curRewardsValue - $currentPaymentsWithRewards) <= 0) {
                        $data['error'] = lang('Sales.rewards_remaining_balance') . to_currency($curRewardsValue);
                    } else {
                        $newRewardValue = $points - $this->sale_lib->get_amount_due();
                        $newRewardValue = max($newRewardValue, 0);
                        $this->sale_lib->set_rewards_remainder($newRewardValue);
                        $newRewardValue = str_replace('$', '\$', to_currency($newRewardValue));
                        $data['warning'] = lang('Sales.rewards_remaining_balance') . $newRewardValue;
                        $amountTendered = min($this->sale_lib->get_amount_due(), $points);

                        $this->sale_lib->addPayment($paymentType, $amountTendered);
                    }
                }
            } elseif (is_string($treasuryId = $this->registerTreasury($paymentType))) {
                $data['error'] = $treasuryId;
            } elseif ($paymentType === lang('Sales.cash')) {
                $amountDue = $this->sale_lib->get_total();
                $salesTotal = $this->sale_lib->get_total(false);
                $amountTendered = parse_decimals($this->request->getPost('amount_tendered'));
                $this->sale_lib->addPayment($paymentType, $amountTendered, null, CASH_ADJUSTMENT_FALSE, $treasuryId);
                $cashAdjustmentAmount = $amountDue - $salesTotal;
                if ($cashAdjustmentAmount <> 0) {
                    $this->session->set('cash_mode', CASH_MODE_TRUE);
                    $this->sale_lib->addPayment(lang('Sales.cash_adjustment'), $cashAdjustmentAmount, null, CASH_ADJUSTMENT_TRUE);
                }
            } else {
                $amountTendered = parse_decimals($this->request->getPost('amount_tendered'));
                $referenceCode = $this->request->getPost('reference_code');
                $this->sale_lib->addPayment($paymentType, $amountTendered, $referenceCode, CASH_ADJUSTMENT_FALSE, $treasuryId);
            }
        }

        return $this->reload($data);
    }

    /**
     * Multiple Payments. Used in app/Views/sales/register.php
     *
     * @param string $payment_id
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getDeletePayment(string $payment_id): ResponseInterface|string
    {
        helper('url');

        $this->sale_lib->delete_payment(base64url_decode($payment_id));

        return $this->reload();
    }

    /**
     * Add an item to the sale. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postAdd(): ResponseInterface|string
    {
        $data = [];
        $cartBefore = $this->sale_lib->get_cart();

        $discount = $this->config['default_sales_discount'];
        $discount_type = $this->config['default_sales_discount_type'];

        // Check if any discount is assigned to the selected customer
        $customer_id = $this->sale_lib->get_customer();
        if ($customer_id != NEW_ENTRY) {
            // Load the customer discount if any
            $customer_discount = $this->customer->get_info($customer_id)->discount;
            $customer_discount_type = $this->customer->get_info($customer_id)->discount_type;
            if ($customer_discount != '') {
                $discount = $customer_discount;
                $discount_type = $customer_discount_type;
            }
        }

        $item_id_or_number_or_item_kit_or_receipt = $this->request->getPost('item', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $this->token_lib->parse_barcode($quantity, $price, $item_id_or_number_or_item_kit_or_receipt);
        $mode = $this->sale_lib->get_mode();
        $quantity = ($mode == 'return') ? -$quantity : $quantity;
        $item_location = $this->sale_lib->get_sale_location();

        if ($mode == 'return' && $this->sale->isValidReceipt($item_id_or_number_or_item_kit_or_receipt)) {
            $originId = (int) (explode(' ', trim($item_id_or_number_or_item_kit_or_receipt))[1] ?? 0);

            if ($this->outsideBranchScope($originId)) {
                $data['error'] = $this->scopeMessage();
            } else {
                $this->sale_lib->return_entire_sale($item_id_or_number_or_item_kit_or_receipt);
            }
        } elseif ($this->item_kit->is_valid_item_kit($item_id_or_number_or_item_kit_or_receipt)) {
            // Add kit item to order if one is assigned
            $pieces = explode(' ', $item_id_or_number_or_item_kit_or_receipt);

            $item_kit_id = (count($pieces) > 1) ? $pieces[1] : $item_id_or_number_or_item_kit_or_receipt;
            $item_kit_info = $this->item_kit->get_info($item_kit_id);
            $kit_item_id = $item_kit_info->kit_item_id;
            $kit_price_option = $item_kit_info->price_option;
            $kit_print_option = $item_kit_info->print_option; // 0-all, 1-priced, 2-kit-only

            if ($discount_type == $item_kit_info->kit_discount_type) {
                if ($item_kit_info->kit_discount > $discount) {
                    $discount = $item_kit_info->kit_discount;
                }
            } else {
                $discount = $item_kit_info->kit_discount;
                $discount_type = $item_kit_info->kit_discount_type;
            }

            $print_option = PRINT_ALL; // Always include in list of items on invoice // TODO: This variable is never used in the code

            if (!empty($kit_item_id)) {
                if (!$this->sale_lib->add_item($kit_item_id, $item_location, $quantity, $discount, $discount_type, PRICE_MODE_KIT, $kit_price_option, $kit_print_option, $price)) {
                    $data['error'] = lang('Sales.unable_to_add_item');
                } else {
                    $data['warning'] = $this->sale_lib->out_of_stock($item_kit_id, $item_location);
                }
            }

            // Add item kit items to order
            $stock_warning = null;
            if (!$this->sale_lib->add_item_kit($item_id_or_number_or_item_kit_or_receipt, $item_location, $discount, $discount_type, $kit_price_option, $kit_print_option, $stock_warning)) {
                $data['error'] = lang('Sales.unable_to_add_item');
            } elseif ($stock_warning != null) {
                $data['warning'] = $stock_warning;
            }
        } else {
            if ($item_id_or_number_or_item_kit_or_receipt == '' || !$this->sale_lib->add_item($item_id_or_number_or_item_kit_or_receipt, $item_location, $quantity, $discount, $discount_type, PRICE_MODE_STANDARD, null, null, $price)) {
                $data['error'] = lang('Sales.unable_to_add_item');
            } else {
                $data['warning'] = $this->sale_lib->out_of_stock($item_id_or_number_or_item_kit_or_receipt, $item_location);
            }
        }

        $currencyFailure = $this->sale_lib->currencyFailure();

        if ($currencyFailure !== null) {
            unset($data['warning']);
            $data['error'] = AccountingMessage::of($currencyFailure);
        }

        $priceProblem = $this->refusedLinePrices($cartBefore);

        if ($priceProblem !== null) {
            unset($data['warning']);
            $data['error'] = $priceProblem;
        }

        return $this->reload($data);
    }

    /**
     * Takes back the lines the register just added or changed when one of them sells under the
     * minimum price of its item or gives more discount than is allowed, unless the employee may
     * override prices. A quote, a work order and a return are not held to these controls.
     *
     * @param array<int|string, array<string, mixed>> $cartBefore
     *
     * @return string|null The refusal in words, or null when the lines stand.
     */
    private function refusedLinePrices(array $cartBefore): ?string
    {
        if ($this->sale_lib->is_return_mode() || $this->sale_lib->is_quote_mode() || $this->sale_lib->is_work_order_mode()) {
            return null;
        }

        $priceFields = static fn (array $line): array => [$line['price'], $line['quantity'], $line['discount'], $line['discount_type']];
        $changed = [];

        foreach ($this->sale_lib->get_cart() as $key => $line) {
            if (!isset($cartBefore[$key]) || $priceFields($cartBefore[$key]) !== $priceFields($line)) {
                $changed[$key] = $line;
            }
        }

        $controls = new PriceControls();

        try {
            $violations = $controls->isActive() && $changed !== [] ? $controls->violationsOf($changed, $this->sale_lib->currencyRate()) : [];
        } catch (AccountingException $refusal) {
            $this->sale_lib->set_cart($cartBefore);

            return AccountingMessage::of($refusal);
        }

        if ($violations === [] || $controls->mayOverride((int) $this->employee->get_logged_in_employee_info()->person_id)) {
            return null;
        }

        $this->sale_lib->set_cart($cartBefore);

        return AccountingMessage::of($violations[0]->toException());
    }

    /**
     * Edit an item in the sale. Used in app/Views/sales/register.php
     *
     * @param string $line
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postEditItem(string $line): ResponseInterface|string
    {
        $data = [];

        $rules = [
            'price'    => 'trim|required|decimal_locale|nonNegativeDecimal',
            'quantity' => 'trim|required|decimal_locale',
            'discount' => 'trim|permit_empty|decimal_locale|nonNegativeDecimal',
        ];

        $messages = [
            'price' => [
                'nonNegativeDecimal' => lang('Sales.negative_price_invalid'),
            ],
            'discount' => [
                'nonNegativeDecimal' => lang('Sales.negative_discount_invalid'),
            ],
        ];

        if ($this->validate($rules, $messages)) {
            $description = $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $serialnumber = $this->request->getPost('serialnumber', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $price = parse_decimals($this->request->getPost('price'));
            $price = $price !== false ? number_format((float) $price, totals_decimals(), '.', '') : $price;
            $quantity = parse_decimals($this->request->getPost('quantity'));
            $discount_type = $this->request->getPost('discount_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $discount = $discount_type
                ? parse_quantity($this->request->getPost('discount'))
                : parse_decimals($this->request->getPost('discount'));
            $discount = $discount ?: 0;

            // Return mode legitimately uses negative quantities for refunds
            if ($this->sale_lib->get_mode() != 'return' && $quantity < 0) {
                $data['error'] = lang('Sales.negative_quantity_invalid');
                return $this->reload($data);
            }

            // Business logic: discount bounds depend on discount_type and item values
            if ($discount_type == PERCENT && $discount > 100) {
                $data['error'] = lang('Sales.discount_percent_exceeds_100');
                return $this->reload($data);
            }

            $precision = totals_decimals();
            if ($discount_type == FIXED && bccomp((string)$discount, bcmul((string)abs($quantity), (string)$price, $precision), $precision) > 0) {
                $data['error'] = lang('Sales.discount_exceeds_item_total');
                return $this->reload($data);
            }

            // sales_change_price grant is enforced server-side here; the UI-only "change_price" flag must not be trusted
            $current_price = $this->sale_lib->get_cart()[$line]['price'] ?? null;
            $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
            if ($current_price !== null && bccomp((string)$price, (string)$current_price, $precision) != 0 && !$this->employee->has_grant('sales_change_price', $employee_id)) {
                $data['error'] = lang('Sales.not_authorized');
                return $this->reload($data);
            }

            $item_location = $this->request->getPost('location', FILTER_SANITIZE_NUMBER_INT);
            $discounted_total = $this->request->getPost('discounted_total') != ''
                ? parse_decimals($this->request->getPost('discounted_total') ?? '')
                : null;

            $cartBefore = $this->sale_lib->get_cart();

            $this->sale_lib->edit_item($line, $description, $serialnumber, $quantity, $discount, $discount_type, $price, $discounted_total);

            $priceProblem = $this->refusedLinePrices($cartBefore);

            if ($priceProblem !== null) {
                $data['error'] = $priceProblem;

                return $this->reload($data);
            }

            $this->sale_lib->empty_payments();

            $data['warning'] = $this->sale_lib->out_of_stock($this->sale_lib->get_item_id($line), $item_location);
        } else {
            $errors = $this->validator->getErrors();
            $data['error'] = $errors ? reset($errors) : lang('Sales.error_editing_item');
        }

        return $this->reload($data);
    }

    /**
     * Deletes an item specified in the parameter from the shopping cart. Used in app/Views/sales/register.php
     *
     * @param int $item_id
     * @return ResponseInterface
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function getDeleteItem(int $item_id): ResponseInterface|string
    {
        $this->sale_lib->delete_item($item_id);

        $this->sale_lib->empty_payments();

        return $this->reload();
    }

    /**
     * Remove the current customer from the sale. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getRemoveCustomer(): ResponseInterface|string
    {
        $this->sale_lib->clear_giftcard_remainder();
        $this->sale_lib->clear_rewards_remainder();
        $this->sale_lib->delete_payment(lang('Sales.rewards'));
        $this->sale_lib->clear_invoice_number();
        $this->sale_lib->clear_quote_number();
        $this->sale_lib->remove_customer();
        $this->sale_lib->repriceFromList();

        return $this->reload();
    }

    /**
     * Complete and finalize a sale. Used in app/Views/sales/register.php
     *
     * @return string
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postComplete(): string    // TODO: this function is huge.  Probably should be refactored.
    {
        $this->sale->useCurrency($this->registerCurrency());

        $saleId = $this->sale_lib->get_sale_id();
        $data = [];
        $data['dinner_table'] = $this->sale_lib->get_dinner_table();

        $data['cart'] = $this->sale_lib->get_cart();

        $branchProblem = $this->cartBranchProblem($data['cart']);

        if ($branchProblem !== null) {
            return $this->reload(['error' => $branchProblem]);
        }

        $invoiceDiscount = $this->registerInvoiceDiscount();

        if (($invoiceDiscount !== null && !$this->mayGiveInvoiceDiscount()) || !$this->mayGiveHeaderCharges($this->sale_lib->getHeaderCharges(), $this->sale_lib->is_return_mode())) {
            return $this->reload(['error' => lang('Sales.not_authorized')]);
        }

        $data['include_hsn'] = (bool)$this->config['include_hsn'];
        $time = time();
        $data['transaction_time'] = to_datetime($time);
        $data['transaction_date'] = to_date($time);
        $data['show_stock_locations'] = $this->stock_location->show_locations('sales', true);
        $data['comments'] = $this->sale_lib->get_comment();
        $employeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $employeeInfo = $this->employee->get_info($employeeId);
        $data['employee'] = $employeeInfo->first_name . ' ' . mb_substr($employeeInfo->last_name, 0, 1);

        $data['company_info'] = implode("\n", [$this->config['address'], $this->config['phone']]);

        if ($this->config['account_number']) {
            $data['company_info'] .= "\n" . lang('Sales.account_number') . ": " . $this->config['account_number'];
        }

        if ($this->config['tax_id'] != '') {
            $data['company_info'] .= "\n" . lang('Sales.tax_id') . ": " . $this->config['tax_id'];
        }

        $data['invoice_number_enabled'] = $this->sale_lib->is_invoice_mode();
        $data['cur_giftcard_value'] = $this->sale_lib->get_giftcard_remainder();
        $data['cur_rewards_value'] = $this->sale_lib->get_rewards_remainder();
        $data['print_after_sale'] = $this->session->get('sales_print_after_sale');
        $data['price_work_orders'] = $this->sale_lib->is_price_work_orders();
        $data['email_receipt'] = $this->sale_lib->is_email_receipt();
        $customerId = $this->sale_lib->get_customer();
        $invoiceNumber = $this->sale_lib->get_invoice_number();
        $data["invoice_number"] = $invoiceNumber;
        $workOrderNumber = $this->sale_lib->get_work_order_number();
        $data["work_order_number"] = $workOrderNumber;
        $quoteNumber = $this->sale_lib->get_quote_number();
        $data["quote_number"] = $quoteNumber;
        $customerInfo = $this->_load_customer_data($customerId, $data);

        if ($customerInfo != null) {
            $data["customer_comments"] = $customerInfo->comments;
            $data['tax_id'] = $customerInfo->tax_id;
        }
        $taxDetails = $this->tax_lib->get_taxes($data['cart']);    // TODO: Duplicated code
        $data['taxes'] = $taxDetails[0];
        $data['discount'] = $this->sale_lib->get_discount();
        $data['invoice_discount'] = $this->sale_lib->getInvoiceDiscountTotal();
        $data['invoice_discount_entry'] = $this->sale_lib->getInvoiceDiscount();
        $data['payments'] = $this->sale_lib->getPayments();

        // Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
        $totals = $this->sale_lib->get_totals($taxDetails[0]);
        $data['subtotal'] = $totals['subtotal'];
        $data['header_charges'] = $this->headerChargesView($totals);
        $data['ship_to'] = $this->shipTo($data);
        $data['total'] = $totals['total'];
        $data['payments_total'] = $totals['payment_total'];
        $data['payments_cover_total'] = $totals['payments_cover_total'];
        $data['cash_rounding'] = $this->session->get('cash_rounding');
        $data['cash_mode'] = $this->session->get('cash_mode');    // TODO: Duplicated code
        $data['prediscount_subtotal'] = $totals['prediscount_subtotal'];
        $data['cash_total'] = $totals['cash_total'];
        $data['non_cash_total'] = $totals['total'];
        $data['cash_amount_due'] = $totals['cash_amount_due'];
        $data['non_cash_amount_due'] = $totals['amount_due'];

        // Prevent negative total sales (fraud/theft vector) - returns can have negative totals for legitimate refunds
        if ($this->sale_lib->get_mode() != 'return' && bccomp($totals['total'], '0') < 0) {
            $data['error'] = lang('Sales.negative_total_invalid');
            return $this->reload($data);
        }

        $customProblem = (new CustomFields())->problem('sale', $this->sale_lib->getCustomFields(), entityId: (int) $saleId);

        if ($customProblem !== null) {
            $data['error'] = $customProblem;

            return $this->reload($data);
        }

        if (!$totals['payments_cover_total'] && !$this->sale_lib->is_invoice_mode() && !$this->sale_lib->is_quote_mode()) {
            $data['error'] = lang('Sales.amount_due_not_covered');
            return $this->reload($data);
        }

        if ($data['cash_mode']) {    // TODO: Convert this to ternary notation
            $data['amount_due'] = $totals['cash_amount_due'];
        } else {
            $data['amount_due'] = $totals['amount_due'];
        }

        $data['amount_change'] = $data['amount_due'] * -1;

        if ($data['amount_change'] > 0) {
            // Save cash refund to the cash payment transaction if found, if not then add as new Cash transaction

            if (array_key_exists(lang('Sales.cash'), $data['payments'])) {
                $data['payments'][lang('Sales.cash')]['cash_refund'] = $data['amount_change'];
            } else {
                $payment = [
                    lang('Sales.cash') => [
                        'payment_type'   => lang('Sales.cash'),
                        'payment_amount' => 0,
                        'cash_refund'    => $data['amount_change']
                    ]
                ];

                $data['payments'] += $payment;
            }
        }

        $data['print_price_info'] = true;

        if ($this->sale_lib->is_invoice_mode()) {
            $invoiceFormat = $this->config['sales_invoice_format'];
            $invoiceBySequence = (new DocumentNumbering())->isEnabled(DocumentType::SalesInvoice);

            // Generate final invoice number (if using the invoice in sales by receipt mode then the invoice number can be manually entered or altered in some way
            if (!$invoiceBySequence && !empty($invoiceFormat) && $invoiceNumber == null) {
                // The user can retain the default encoded format or can manually override it.  It still passes through the rendering step.
                $invoiceNumber = $this->token_lib->render($invoiceFormat);
            }


            if ($saleId == NEW_ENTRY && $invoiceNumber !== null && $this->sale->check_invoice_number_exists($invoiceNumber)) {
                $data['error'] = lang('Sales.invoice_number_duplicate', [$invoiceNumber]);
                return $this->reload($data);
            } else {
                $data['invoice_number'] = $invoiceNumber;
                $data['sale_status'] = COMPLETED;
                $saleType = SALE_TYPE_INVOICE;

                $invoiceType = $this->config['invoice_type'];
                if (!Sale_lib::isValidInvoiceType($invoiceType)) {
                    $invoiceType = 'invoice';
                }
                $invoiceView = $invoiceType;

                // Save the data to the sales table
                $data['sale_id_num'] = $this->sale->save_value($saleId, $data['sale_status'], $data['cart'], $customerId, $employeeId, $data['comments'], $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $data['payments'], $data['dinner_table'], $taxDetails, $this->sale_lib->is_delivered(), null, $invoiceDiscount, invoiceDate: $this->sale_lib->getInvoiceDate(), paymentTerms: $this->sale_lib->getPaymentTerms(), quoteSourceId: $this->sale_lib->getQuoteSource(), headerCharges: $this->sale_lib->getHeaderCharges(), priceListId: $this->sale_lib->effectivePriceListId(), orderSourceId: $this->sale_lib->getOrderSource());
                $this->keepCustomFields($data['sale_id_num']);
                $data = [...$data, ...$this->printedFieldsOf($data['sale_id_num'], $data['cart'])];
                $data['sale_id'] = 'POS ' . $data['sale_id_num'];

                // Resort and filter cart lines for printing
                $data['cart'] = $this->sale_lib->sortAndFilterCart($data['cart']);

                if ($data['sale_id_num'] === INSUFFICIENT_GIFTCARD_BALANCE) {
                    $data['error_message'] = lang('Sales.insufficient_giftcard_balance');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INSUFFICIENT_REWARD_POINTS) {
                    $data['error_message'] = lang('Sales.insufficient_reward_points');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === ACCOUNTING_FAILED) {
                    $data['error'] = $this->accountingFailureMessage();
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INVOICE_DISCOUNT_INVALID) {
                    $data['error'] = $this->invoiceDiscountMessage();
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] == NEW_ENTRY) {
                    $data['error_message'] = lang('Sales.transaction_failed');
                    return $this->reload($data);
                } else {
                    if ($invoiceBySequence) {
                        $data['invoice_number'] = $this->sale->getInvoiceNumber($data['sale_id_num']);
                    }

                    $timing = $this->sale->getInvoiceTiming((int) $data['sale_id_num']);

                    if ($timing['invoice_date'] !== null) {
                        $data['transaction_date'] = to_date(strtotime($timing['invoice_date']));
                    }

                    $data['sale_time_raw'] = $this->sale->getSaleTime((int) $data['sale_id_num']);
                    $data['invoice_date_raw'] = $timing['invoice_date'];
                    $data['currency_code'] = $this->sale->getCurrencyCode((int) $data['sale_id_num']);

                    $data['due_date'] = $timing['payment_terms_days'] === null || $timing['due_date'] === null ? null : to_date(strtotime($timing['due_date']));

                    $data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
                    $this->sale_lib->clear_all();
                    return view('sales/' . $invoiceView, $data);
                }
            }
        } elseif ($this->sale_lib->is_work_order_mode()) {

            if (!($data['price_work_orders'] == 1)) {
                $data['print_price_info'] = false;
            }

            $data['sales_work_order'] = lang('Sales.work_order');
            $data['work_order_number_label'] = lang('Sales.work_order_number');

            if ($workOrderNumber == null) {
                // Generate work order number
                $workOrderFormat = $this->config['work_order_format'];
                $workOrderNumber = $this->token_lib->render($workOrderFormat);
            }

            if ($saleId == NEW_ENTRY && $this->sale->check_work_order_number_exists($workOrderNumber)) {
                $data['error'] = lang('Sales.work_order_number_duplicate');
                return $this->reload($data);
            } else {
                $data['work_order_number'] = $workOrderNumber;
                $data['sale_status'] = SUSPENDED;
                $saleType = SALE_TYPE_WORK_ORDER;

                $data['sale_id_num'] = $this->sale->save_value($saleId, $data['sale_status'], $data['cart'], $customerId, $employeeId, $data['comments'], $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $data['payments'], $data['dinner_table'], $taxDetails, true, null, $invoiceDiscount, headerCharges: $this->sale_lib->getHeaderCharges(), priceListId: $this->sale_lib->effectivePriceListId());
                $this->keepCustomFields($data['sale_id_num']);
                $data = [...$data, ...$this->printedFieldsOf($data['sale_id_num'], $data['cart'])];

                if ($data['sale_id_num'] === INSUFFICIENT_GIFTCARD_BALANCE) {
                    $data['error_message'] = lang('Sales.insufficient_giftcard_balance');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INSUFFICIENT_REWARD_POINTS) {
                    $data['error_message'] = lang('Sales.insufficient_reward_points');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === ACCOUNTING_FAILED) {
                    $data['error'] = $this->accountingFailureMessage();
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INVOICE_DISCOUNT_INVALID) {
                    $data['error'] = $this->invoiceDiscountMessage();
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] == NEW_ENTRY) {
                    $data['error_message'] = lang('Sales.transaction_failed');
                    return $this->reload($data);
                }

                $this->sale_lib->set_suspended_id($data['sale_id_num']);

                $data['cart'] = $this->sale_lib->sortAndFilterCart($data['cart']);

                $data['barcode'] = null;

                $this->sale_lib->clear_all();
                return view('sales/work_order', $data);
            }
        } elseif ($this->sale_lib->is_quote_mode()) {
            $data['sales_quote'] = lang('Sales.quote');
            $data['quote_number_label'] = lang('Sales.quote_number');

            $quoteBySequence = (new DocumentNumbering())->isEnabled(DocumentType::SalesQuote);

            if ($quoteNumber == null && !$quoteBySequence) {
                // Generate quote number
                $quoteFormat = $this->config['sales_quote_format'];
                $quoteNumber = $this->token_lib->render($quoteFormat);
            }

            if ($saleId == NEW_ENTRY && $quoteNumber !== null && $this->sale->check_quote_number_exists($quoteNumber)) {
                $data['error'] = lang('Sales.quote_number_duplicate');
                return $this->reload($data);
            } else {
                $data['quote_number'] = $quoteNumber;
                $data['sale_status'] = SUSPENDED;
                $saleType = SALE_TYPE_QUOTE;

                $data['sale_id_num'] = $this->sale->save_value($saleId, $data['sale_status'], $data['cart'], $customerId, $employeeId, $data['comments'], $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $data['payments'], $data['dinner_table'], $taxDetails, true, null, $invoiceDiscount, headerCharges: $this->sale_lib->getHeaderCharges(), priceListId: $this->sale_lib->effectivePriceListId());
                $this->keepCustomFields($data['sale_id_num']);
                $data = [...$data, ...$this->printedFieldsOf($data['sale_id_num'], $data['cart'])];

                if ($data['sale_id_num'] === INSUFFICIENT_GIFTCARD_BALANCE) {
                    $data['error_message'] = lang('Sales.insufficient_giftcard_balance');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INSUFFICIENT_REWARD_POINTS) {
                    $data['error_message'] = lang('Sales.insufficient_reward_points');
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === ACCOUNTING_FAILED) {
                    $data['error'] = $this->accountingFailureMessage();
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] === INVOICE_DISCOUNT_INVALID) {
                    $data['error'] = $this->invoiceDiscountMessage();
                    return $this->reload($data);
                } elseif ($data['sale_id_num'] == NEW_ENTRY) {
                    $data['error_message'] = lang('Sales.transaction_failed');
                    return $this->reload($data);
                }

                $this->sale_lib->set_suspended_id($data['sale_id_num']);

                if ($quoteBySequence) {
                    $data['quote_number'] = $this->sale->get_quote_number($data['sale_id_num']);
                }

                $data['cart'] = $this->sale_lib->sortAndFilterCart($data['cart']);
                $data['barcode'] = null;

                $this->sale_lib->clear_all();
                return view('sales/quote', $data);
            }
        } else {
            // Save the data to the sales table
            $data['sale_status'] = COMPLETED;
            if ($this->sale_lib->is_return_mode()) {
                $saleType = SALE_TYPE_RETURN;
            } else {
                $saleType = SALE_TYPE_POS;
            }

            $data['sale_id_num'] = $this->sale->save_value($saleId, $data['sale_status'], $data['cart'], $customerId, $employeeId, $data['comments'], $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $data['payments'], $data['dinner_table'], $taxDetails, true, $this->sale_lib->get_return_origin(), $invoiceDiscount, $this->sale_lib->getReturnDocument(), returnOverrideReason: $this->sale_lib->getReturnOverride(), headerCharges: $this->sale_lib->getHeaderCharges(), priceListId: $this->sale_lib->effectivePriceListId());
            $this->keepCustomFields($data['sale_id_num']);
            $data = [...$data, ...$this->printedFieldsOf($data['sale_id_num'], $data['cart'])];

            $data['sale_id'] = 'POS ' . $data['sale_id_num'];

            $data['cart'] = $this->sale_lib->sortAndFilterCart($data['cart']);

            if ($data['sale_id_num'] === INSUFFICIENT_GIFTCARD_BALANCE) {
                $data['error_message'] = lang('Sales.insufficient_giftcard_balance');
                return $this->reload($data);
            } elseif ($data['sale_id_num'] === INSUFFICIENT_REWARD_POINTS) {
                $data['error_message'] = lang('Sales.insufficient_reward_points');
                return $this->reload($data);
            } elseif ($data['sale_id_num'] === ACCOUNTING_FAILED) {
                $data['error'] = $this->accountingFailureMessage();
                return $this->reload($data);
            } elseif ($data['sale_id_num'] === INVOICE_DISCOUNT_INVALID) {
                $data['error'] = $this->invoiceDiscountMessage();
                return $this->reload($data);
            } elseif ($data['sale_id_num'] == NEW_ENTRY) {
                $data['error_message'] = lang('Sales.transaction_failed');
                return $this->reload($data);
            } else {
                $data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
                $data = [...$data, ...$this->returnDocumentNumbersOf((int) $data['sale_id_num'])];

                // Validate receipt template to prevent path traversal
                $receiptTemplate = $this->config['receipt_template'] ?? '';
                if (!Sale_lib::isValidReceiptTemplate($receiptTemplate)) {
                    $receiptTemplate = 'receipt_default';
                }
                $data['receipt_template_view'] = $receiptTemplate;

                $this->sale_lib->clear_all();
                return view('sales/receipt', $data);
            }
        }
    }

    /**
     * The number a return carries under the name of the document it was issued as. A credit note
     * shows only the number its sequence gave it; a refund invoice always shows its number, or the
     * receipt number it goes by while it has none.
     *
     * @return array{creditNoteNumber: ?string, refundInvoiceNumber: ?string}
     */
    private function returnDocumentNumbersOf(int $saleId): array
    {
        $document  = $this->sale->getReturnDocument($saleId);
        $numbering = new DocumentNumbering();

        return [
            'creditNoteNumber'    => $document === ReturnDocument::CreditNote ? ($numbering->numbers(DocumentType::CreditNote, 'sales', [$saleId])[$saleId] ?? null) : null,
            'refundInvoiceNumber' => $document === ReturnDocument::RefundInvoice ? $numbering->label(DocumentType::RefundInvoice, 'sales', $saleId) : null,
        ];
    }

    /**
     * Streams the sale as a PDF document. Used in app/Views/sales/form.php, invoice.php, quote.php, tax_invoice.php and work_order.php
     *
     * @param int $saleId
     * @param string $type
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getPdf(int $saleId, string $type = 'invoice'): ResponseInterface
    {
        if ($this->outsideBranchScope($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        if (! in_array($type, [...documentTypes(), ...slipTypes(), 'receipt'], true) || ! $this->sale->exists($saleId)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $saleData = $this->_load_sale_data($saleId);
        $this->sale_lib->clear_all();

        if ($this->documentRefused($type, $saleData)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $number = $type === 'receipt' ? '' : (in_array($type, slipTypes(), true) ? (string) ($saleData['invoice_number'] ?? '') : documentProfile($type, $saleData)['number']);

        try {
            $pdf = $this->renderPdf($type, $saleData);
        } catch (Throwable $exception) {
            log_message('error', 'PDF generation failed: ' . $exception->getMessage());

            return $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => lang('Sales.transaction_failed')]);
        }

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . rawurlencode($this->documentFilename($this->documentTitle($type, $saleData), $number)) . '"')
            ->setBody($pdf);
    }

    /**
     * Email PDF invoice to customer. Used in app/Views/sales/form.php, invoice.php, quote.php, tax_invoice.php and work_order.php
     *
     * @param int $saleId
     * @param string $type
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSendPdf(int $saleId, string $type = 'invoice'): ResponseInterface
    {
        if ($this->outsideBranchScope($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        if (! in_array($type, [...documentTypes(), 'receipt'], true) || ! $this->sale->exists($saleId)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $saleData = $this->_load_sale_data($saleId);

        if ($this->documentRefused($type, $saleData)) {
            $this->sale_lib->clear_all();

            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $result = false;
        $message = lang('Sales.invoice_no_email');

        if (!empty($saleData['customer_email'])) {
            $to = $saleData['customer_email'];
            $number = $type === 'receipt' ? '' : documentProfile($type, $saleData)['number'];
            $subject = $this->documentTitle($type, $saleData) . ' ' . $number;

            $text = $this->config['invoice_email_message'];
            $tokens = [
                new Token_invoice_sequence($number),
                new Token_invoice_count('POS ' . $saleData['sale_id']),
                new Token_customer((array)$saleData)
            ];
            $text = $this->token_lib->render($text, $tokens);

            $filename = sys_get_temp_dir() . '/' . $this->documentFilename($this->documentTitle($type, $saleData), $number);

            try {
                if (file_put_contents($filename, $this->renderPdf($type, $saleData)) !== false) {
                    $result = $this->email_lib->sendEmail($to, $subject, $text, $filename);
                }
            } catch (Throwable $exception) {
                log_message('error', 'PDF generation failed: ' . $exception->getMessage());
            } finally {
                if (is_file($filename)) {
                    unlink($filename);
                }
            }

            $message = lang($result ? "Sales." . $type . "_sent" : "Sales." . $type . "_unsent") . ' ' . $to;
        }

        $this->sale_lib->clear_all();

        return $this->response->setJSON(['success' => $result, 'message' => $message, 'id' => $saleId]);
    }

    /**
     * The receipt of a payment of a customer, ready to print. Linked from the payments of app/Views/sales/form.php
     *
     * @noinspection PhpUnused
     */
    public function getPaymentReceipt(int $paymentId): ResponseInterface|string
    {
        $personId = (int) $this->session->get('person_id');

        if (! $this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        $voucher = (new VoucherDocuments())->customerPayment($paymentId, $personId);

        if ($voucher === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('documents/screen', [
            'inner'     => 'documents/voucher',
            'data'      => ['voucher' => $voucher],
            'pdfUrl'    => 'sales/paymentReceiptPdf/' . $paymentId,
            'backUrl'   => 'sales/manage',
            'backLabel' => lang('Sales.takings'),
            'backIcon'  => 'bi-card-list',
        ]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function getPaymentReceiptPdf(int $paymentId): ResponseInterface
    {
        $personId = (int) $this->session->get('person_id');

        if (! $this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $voucher = (new VoucherDocuments())->customerPayment($paymentId, $personId);

        if ($voucher === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->pdfResponse($this->response, view('documents/voucher_pdf', ['voucher' => $voucher]), $voucher->title, $voucher->number);
    }

    /**
     * The printable page of a document of a sale: its credit note or any other of its documents.
     * Used in app/Views/sales/receipt.php
     *
     * @noinspection PhpUnused
     */
    public function getPrint(int $saleId, string $type = 'credit_note'): ResponseInterface|string
    {
        if ($this->outsideBranchScope($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (! $this->employee->has_grant('reports_sales', $this->session->get('person_id'))) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        if (! in_array($type, [...documentTypes(), ...slipTypes()], true) || ! $this->sale->exists($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->_load_sale_data($saleId);
        $this->sale_lib->clear_all();

        if ($this->documentRefused($type, $data)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('sales/document_print', ['type' => $type, 'sale' => $data, 'sale_id_num' => $saleId, 'customer_email' => in_array($type, slipTypes(), true) ? '' : ($data['customer_email'] ?? '')]);
    }

    /**
     * Whether a document is refused for a sale. A credit note is the print of a return, and the slips that
     * go with the goods are for a completed sale or invoice of goods: not for a return, a quote or a work order.
     *
     * @param array<string, mixed> $saleData
     */
    private function documentRefused(string $type, array $saleData): bool
    {
        if ($type === 'credit_note') {
            return empty($saleData['is_return']);
        }

        if (in_array($type, slipTypes(), true)) {
            return ! in_array((int) ($saleData['sale_type'] ?? -1), [SALE_TYPE_POS, SALE_TYPE_INVOICE], true) || (int) ($saleData['sale_status'] ?? -1) !== COMPLETED;
        }

        return false;
    }

    private function renderPdf(string $type, array $saleData): string
    {
        $pdfLib = new Pdf_lib();
        $title  = $this->documentTitle($type, $saleData);

        if ($type === 'receipt') {
            $saleData['pdf'] = true;

            return $pdfLib->render(view('sales/receipt_email', $saleData), ['format' => 'A5', 'title' => $title]);
        }

        if (in_array($type, slipTypes(), true)) {
            return $pdfLib->render(view('documents/slip_pdf', ['type' => $type, 'sale' => $saleData]), ['title' => $title]);
        }

        return $pdfLib->render(view('documents/pdf', ['type' => $type, 'sale' => $saleData]), ['title' => $title]);
    }

    private function documentTitle(string $type, array $saleData): string
    {
        if ($type === 'receipt') {
            return lang('Sales.receipt');
        }

        return in_array($type, slipTypes(), true) ? lang('Documents.' . $type) : documentProfile($type, $saleData)['title'];
    }

    private function documentFilename(string $title, string $number): string
    {
        return preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u', '-', trim($title . ' ' . $number)) . '.pdf';
    }

    /**
     * Emails sales receipt to customer. Used in app/Views/sales/receipt.php
     *
     * @param int $saleId
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSendReceipt(int $saleId): ResponseInterface
    {
        if ($this->outsideBranchScope($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $saleData = $this->_load_sale_data($saleId);

        $result = false;
        $message = lang('Sales.receipt_no_email');

        if (!empty($saleData['customer_email'])) {
            $saleData['barcode'] = $this->barcode_lib->generate_receipt_barcode($saleData['sale_id']);
            $saleData['img_tag'] = $this->email_lib->buildLogoImgTag();

            $to = $saleData['customer_email'];
            $subject = lang('Sales.receipt');

            $view = Services::renderer();
            $text = $view->setData($saleData)->render('sales/receipt_email');

            $result = $this->email_lib->sendEmail($to, $subject, $text);

            $message = lang($result ? 'Sales.receipt_sent' : 'Sales.receipt_unsent') . ' ' . $to;
        }

        $this->sale_lib->clear_all();

        return $this->response->setJSON(['success' => $result, 'message' => $message, 'id' => $saleId]);
    }

    /**
     * @param int $customer_id
     * @param array $data
     * @param bool $stats
     * @return array|stdClass|string|null
     */
    private function _load_customer_data(int $customer_id, array &$data, bool $stats = false): array|string|stdClass|null    // TODO: Hungarian notation
    {
        $customer_info = '';

        if ($customer_id != NEW_ENTRY) {
            $customer_info = $this->customer->get_info($customer_id);
            $data['customer_id'] = $customer_id;

            if (!empty($customer_info->company_name)) {
                $data['customer'] = $customer_info->company_name;
            } else {
                $data['customer'] = $customer_info->first_name . ' ' . $customer_info->last_name;
            }

            $data['first_name'] = $customer_info->first_name;
            $data['last_name'] = $customer_info->last_name;
            $data['customer_email'] = $customer_info->email;
            $data['customer_address'] = $customer_info->address_1;
            $data['customer_second_address'] = $customer_info->address_2;

            if (!empty($customer_info->zip) || !empty($customer_info->city)) {
                $data['customer_location'] = $customer_info->zip . ' ' . $customer_info->city . "\n" . $customer_info->state;
            } else {
                $data['customer_location'] = '';
            }

            $data['customer_account_number'] = $customer_info->account_number;
            $data['customer_discount'] = $customer_info->discount;
            $data['customer_discount_type'] = $customer_info->discount_type;
            $package_id = $this->customer->get_info($customer_id)->package_id;

            if ($package_id != null) {
                $package_name = $this->customer_rewards->get_name($package_id);
                $points = $this->customer->get_info($customer_id)->points;
                $data['customer_rewards']['package_id'] = $package_id;
                $data['customer_rewards']['points'] = empty($points) ? 0 : $points;
                $data['customer_rewards']['package_name'] = $package_name;
            }

            if ($stats) {
                $cust_stats = $this->customer->get_stats($customer_id);
                $data['customer_total'] = empty($cust_stats) ? 0 : $cust_stats->total;
            }

            $data['customer_info'] = implode("\n", [
                $data['customer'],
                $data['customer_address'],
                $data['customer_location']
            ]);

            if ($data['customer_account_number']) {
                $data['customer_info'] .= "\n" . lang('Sales.account_number') . ": " . $data['customer_account_number'];
            }

            if ($customer_info->tax_id != '') {
                $data['customer_info'] .= "\n" . lang('Sales.tax_id') . ": " . $customer_info->tax_id;
            }
            $data['tax_id'] = $customer_info->tax_id;
        }

        return $customer_info;
    }

    private function _load_sale_data($saleId): array    // TODO: Hungarian notation
    {
        $this->sale_lib->clear_all();
        $cashRounding = $this->sale_lib->reset_cash_rounding();
        $data['cash_rounding'] = $cashRounding;

        $saleInfo = $this->sale->get_info($saleId)->getRowArray();
        $this->sale_lib->copy_entire_sale($saleId);
        $data = [];
        $data['cart'] = $this->sale_lib->get_cart();
        $data = [...$data, ...$this->printedFieldsOf($saleId, $data['cart'])];
        $data['payments'] = $this->sale_lib->getPayments();
        $data['selected_payment_type'] = $this->sale_lib->get_payment_type();

        $taxDetails = $this->tax_lib->get_taxes($data['cart'], $saleId);
        $data['taxes'] = $this->sale->get_sales_taxes($saleId);
        $data['discount'] = $this->sale_lib->get_discount();
        $data['invoice_discount'] = $this->sale_lib->getInvoiceDiscountTotal();
        $data['invoice_discount_entry'] = $this->sale_lib->getInvoiceDiscount();
        $timing = $this->sale->getInvoiceTiming((int) $saleId);
        $data['transaction_time'] = to_datetime(strtotime($saleInfo['sale_time']));
        $data['transaction_date'] = to_date(strtotime($timing['invoice_date'] ?? $saleInfo['sale_time']));
        $data['sale_time_raw'] = (string) $saleInfo['sale_time'];
        $data['invoice_date_raw'] = $timing['invoice_date'] ?? null;
        $data['currency_code'] = $this->sale->getCurrencyCode((int) $saleId);
        $data['is_return'] = (int) ($saleInfo['sale_type'] ?? SALE_TYPE_POS) === SALE_TYPE_RETURN;
        $data['sale_type'] = (int) ($saleInfo['sale_type'] ?? SALE_TYPE_POS);
        $data['due_date'] = $timing['payment_terms_days'] === null || $timing['due_date'] === null ? null : to_date(strtotime($timing['due_date']));
        $data['show_stock_locations'] = $this->stock_location->show_locations('sales', true);

        $data['include_hsn'] = (bool)$this->config['include_hsn'];

        // Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
        $totals = $this->sale_lib->get_totals($taxDetails[0]);
        $this->session->set('cash_adjustment_amount', $totals['cash_adjustment_amount']);
        $data['subtotal'] = $totals['subtotal'];
        $data['header_charges'] = $this->headerChargesView($totals);
        $data['payments_total'] = $totals['payment_total'];
        $data['payments_cover_total'] = $totals['payments_cover_total'];
        $data['cash_mode'] = $this->session->get('cash_mode');    // TODO: Duplicated code.
        $data['prediscount_subtotal'] = $totals['prediscount_subtotal'];
        $data['cash_total'] = $totals['cash_total'];
        $data['non_cash_total'] = $totals['total'];
        $data['cash_amount_due'] = $totals['cash_amount_due'];
        $data['non_cash_amount_due'] = $totals['amount_due'];

        if ($data['cash_mode'] && ($data['selected_payment_type'] === lang('Sales.cash') || $data['payments_total'] > 0)) {
            $data['total'] = $totals['cash_total'];
            $data['amount_due'] = $totals['cash_amount_due'];
        } else {
            $data['total'] = $totals['total'];
            $data['amount_due'] = $totals['amount_due'];
        }

        $data['amount_change'] = $data['amount_due'] * -1;

        $employeeInfo = $this->employee->get_info($this->sale_lib->get_employee());
        $data['employee'] = $employeeInfo->first_name . ' ' . mb_substr($employeeInfo->last_name, 0, 1);
        $this->_load_customer_data($this->sale_lib->get_customer(), $data);
        $data['ship_to'] = $this->shipTo($data);

        $data['sale_id_num'] = $saleId;
        $data['sale_id'] = 'POS ' . $saleId;
        $data['comments'] = $saleInfo['comment'];
        $data['invoice_number'] = $saleInfo['invoice_number'];
        $data['quote_number'] = $saleInfo['quote_number'];
        $data = [...$data, ...$this->returnDocumentNumbersOf((int) $saleId)];
        $data['work_order_number'] = $this->sale_lib->get_work_order_number();
        $data['sale_status'] = $saleInfo['sale_status'];

        $data['company_info'] = implode("\n", [$this->config['address'], $this->config['phone']]);    // TODO: Duplicated code.

        if ($this->config['account_number']) {
            $data['company_info'] .= "\n" . lang('Sales.account_number') . ": " . $this->config['account_number'];
        }
        if ($this->config['tax_id'] != '') {
            $data['company_info'] .= "\n" . lang('Sales.tax_id') . ": " . $this->config['tax_id'];
        }

        $data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['sale_id']);
        $data['print_after_sale'] = false;
        $data['price_work_orders'] = false;

        if ($this->sale_lib->get_mode() == 'sale_invoice') {    // TODO: Duplicated code.
            $data['mode_label'] = lang('Sales.invoice');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'sale_quote') {
            $data['mode_label'] = lang('Sales.quote');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'sale_work_order') {
            $data['mode_label'] = lang('Sales.work_order');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'return') {
            $data['mode_label'] = lang('Sales.return');
            $data['customer_required'] = lang('Sales.customer_optional');
        } else {
            $data['mode_label'] = lang('Sales.receipt');
            $data['customer_required'] = lang('Sales.customer_optional');
        }

        $invoiceType = $this->config['invoice_type'];
        if (!Sale_lib::isValidInvoiceType($invoiceType)) {
            $invoiceType = 'invoice';
        }
        $data['invoice_view'] = $invoiceType;

        // Validate receipt template to prevent path traversal
        $receiptTemplate = $this->config['receipt_template'] ?? '';
        if (!Sale_lib::isValidReceiptTemplate($receiptTemplate)) {
            $receiptTemplate = 'receipt_default';
        }
        $data['receipt_template_view'] = $receiptTemplate;

        return $data;
    }

    /**
     * Why a sale cannot be the one a return is taken from, or null when it can. The return must
     * be of a sale that was completed and delivered in a branch the employee works in.
     */
    /**
     * Prepares the register for a credit note of an issued invoice: a return of what has not
     * come back yet, naming the invoice and its customer. Used in app/Views/sales/form.php
     *
     * @param int $saleId
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postCreditNote(int $saleId = NEW_ENTRY): ResponseInterface
    {
        return $this->prepareReturnOf($saleId, ReturnDocument::CreditNote);
    }

    /**
     * Prepares the register for a refund invoice of an issued invoice: the same return as a credit
     * note, issued as the other document. Used in app/Views/sales/form.php
     *
     * @noinspection PhpUnused
     */
    public function postRefundInvoice(int $saleId = NEW_ENTRY): ResponseInterface
    {
        return $this->prepareReturnOf($saleId, ReturnDocument::RefundInvoice);
    }

    /**
     * Changes the document the return in the register is issued as. Used in app/Views/sales/register.php
     *
     * @noinspection PhpUnused
     */
    public function postSetReturnDocument(): ResponseInterface|string
    {
        $document = ReturnDocument::fromStored((string) $this->request->getPost('return_document'));
        $origin   = $this->sale_lib->get_return_origin();
        $problem  = $origin === null ? null : $this->returnOriginProblem($origin, $document);

        if ($problem !== null) {
            return $this->reload(['error' => $problem]);
        }

        $this->sale_lib->setReturnDocument($document);

        return $this->reload();
    }

    private function prepareReturnOf(int $saleId, ReturnDocument $document): ResponseInterface
    {
        if ($this->outsideBranchScope($saleId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        $problem = $this->returnOriginProblem($saleId, $document);

        if ($problem === null && (int) $this->sale->getSummary($saleId)->sale_type !== SALE_TYPE_INVOICE) {
            $problem = lang('Sales.credit_note_invoice_only');
        }

        if ($problem === null && $this->nothingLeftToReturn($saleId)) {
            $problem = lang('Sales.credit_note_nothing_left');
        }

        if ($problem !== null) {
            return $this->response->setJSON(['success' => false, 'message' => $problem]);
        }

        $this->sale_lib->clear_all();
        $this->sale_lib->set_mode('return');
        $this->sale_lib->set_sale_type(SALE_TYPE_RETURN);
        $this->sale_lib->setReturnDocument($document);
        $this->sale_lib->return_entire_sale('POS ' . $saleId);

        return $this->response->setJSON(['success' => true, 'redirect' => site_url('sales')]);
    }

    private function nothingLeftToReturn(int $saleId): bool
    {
        $left = $this->sale->getReturnedQuantities($saleId);

        foreach ($this->sale->get_sale_items_ordered($saleId)->getResult() as $row) {
            $remaining = (float) $row->quantity_purchased - ($left[(int) $row->item_id] ?? 0.0);

            if ($remaining > 0) {
                return false;
            }

            $left[(int) $row->item_id] = -$remaining;
        }

        return true;
    }

    /**
     * Whether a payment added to the sale is kept whole when it is more than the sale came to.
     * It is for an invoice of a customer that the books follow, where the rest stays with the
     * customer as credit. Anywhere else the change is given back.
     *
     * @param array<string, mixed> $saleInfo
     */
    private function keepsOverpayment(int $saleId, array $saleInfo): bool
    {
        return $saleInfo['customer_id'] !== null
            && (new AccountingSettings())->isEnabled()
            && (new SalePosting())->isPosted($saleId);
    }

    /**
     * The names of the payment types that settle nothing in a treasury, in the languages they are
     * stored in, so that the register does not ask for a treasury for them.
     *
     * @return list<string>
     */
    private function labelsWithoutTreasury(): array
    {
        $labels = [];

        foreach (PaymentType::cases() as $type) {
            if (! $type->usesTreasury()) {
                array_push($labels, ...$type->labels());
            }
        }

        return $labels;
    }

    /**
     * The treasury a payment added at the register goes into: 0 for the one of the employee, or
     * the text that says why the one named cannot be used.
     */
    private function registerTreasury(string $paymentType): int|string
    {
        $type   = PaymentType::fromLabel($paymentType);
        $posted = (int) $this->request->getPost('treasury_id', FILTER_SANITIZE_NUMBER_INT);

        if ($type === null || ! $type->usesTreasury() || $posted <= 0) {
            return 0;
        }

        if (! isset($this->registerTreasuries()[$posted])) {
            return lang('Accounting.error_treasury_not_found');
        }

        $inCart = $this->sale_lib->paymentTreasury($paymentType);

        return $inCart !== null && $inCart !== $posted ? lang('Sales.treasury_payment_conflict') : $posted;
    }

    /**
     * The treasuries a payment at the register can go into, when there is a choice: those of the
     * branch the cart sells from.
     *
     * @return array<int, string>
     */
    private function registerTreasuries(): array
    {
        if (! (new AccountingSettings())->isEnabled()) {
            return [];
        }

        $branches = $this->sale->branchIdsOfItems($this->sale_lib->get_cart()) ?: $this->sale->branchIdsOfItems([['item_location' => $this->sale_lib->get_sale_location()]]);

        return $branches === [] ? [] : $this->treasuryOptions($branches[0], []);
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
     * The treasuries a payment of a sale can go into, when there is a choice: the active ones of
     * the branch of the sale that the employee may deposit into, those of the other branches they
     * work in when they work in the branch of the sale, and any the payments of the sale already carry.
     *
     * @param list<object> $payments
     *
     * @return array<int, string>
     */
    private function treasuryOptions(int $branchId, array $payments): array
    {
        $carried    = array_map(static fn (object $payment): int => (int) ($payment->treasury_id ?? 0), $payments);
        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        $options    = (new TreasuryService())->choicesFor($branchId, TreasuryScopes::DEPOSIT, $employeeId, $carried);

        return count($options) < 2 ? [] : [0 => lang('Sales.treasury_default')] + $options;
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
     * What was applied to or taken from a sale, for the edit form.
     *
     * @return list<object>
     */
    private function applicationsOf(int $saleId, int $customerId): array
    {
        return array_values(array_filter(
            (new CustomerLedger())->applications($customerId),
            static fn (object $application): bool => (int) $application->invoice_sale_id === $saleId || ($application->source_type === 'credit_note' && (int) $application->source_id === $saleId),
        ));
    }

    private function returnOriginProblem(int $saleId, ReturnDocument $document): ?string
    {
        if ($this->sale->outsideRecordScope($saleId, (int) $this->session->get('person_id'))) {
            return $this->scopeMessage();
        }

        $origin = $this->sale->getSummary($saleId);

        if ($origin === null || (int) $origin->sale_status !== COMPLETED || (int) $origin->sale_type === SALE_TYPE_RETURN) {
            return lang('Sales.return_origin_invalid');
        }

        if ((int) $origin->delivered !== 1 && $document === ReturnDocument::RefundInvoice) {
            return lang('Sales.return_origin_undelivered');
        }

        if ($this->outsideBranchScope($saleId)) {
            return $this->scopeMessage();
        }

        return null;
    }

    private function invoiceDiscountMessage(): string
    {
        return lang(($this->sale->invoiceDiscountProblem() ?? InvoiceDiscountProblem::UnknownType)->languageKey());
    }

    /**
     * A discount on the whole of a sale is given by whoever holds the permission, and whoever saves
     * or completes a document that carries one gives it, so it is checked wherever one is saved.
     */
    private function mayGiveInvoiceDiscount(): bool
    {
        return $this->employee->has_grant('sales_invoice_discount', (int) $this->employee->get_logged_in_employee_info()->person_id);
    }

    /**
     * What the register holds as the discount on the whole of the sale, or null for a return, whose
     * discount is the share of the sale it takes its goods back from.
     *
     * @return array{type: int, value: string}|null
     */
    private function registerInvoiceDiscount(): ?array
    {
        return $this->sale_lib->is_return_mode() ? null : $this->sale_lib->getInvoiceDiscount();
    }

    /**
     * What the books said about the sale that was just refused, in words for the cashier.
     */
    private function accountingFailureMessage(): string
    {
        $failure = $this->sale->accountingFailure();

        return $failure instanceof AccountingException || $failure instanceof PostingException || $failure instanceof BranchException
            ? AccountingMessage::of($failure)
            : lang('Sales.transaction_failed');
    }

    /**
     * A sale sells from the stock locations of one branch, and only a branch the employee works in.
     */
    private function cartBranchProblem(array $cart): ?string
    {
        $branchIds = $this->sale->branchIdsOfItems($cart);

        if (count($branchIds) > 1) {
            return lang('Sales.mixed_branches');
        }

        if ($branchIds !== [] && !(new BranchGuard())->canAccess($branchIds[0], (int)$this->session->get('person_id'))) {
            return lang('Sales.branch_not_allowed');
        }

        return null;
    }

    /**
     * Whether any of the sales belongs to a branch the employee does not work in, or to another
     * employee when the employee sees their own records only. For such an employee a sale that
     * does not exist is outside as well, so that they cannot tell the two apart.
     */
    private function outsideBranchScope(int ...$saleIds): bool
    {
        $guard    = new BranchGuard();
        $personId = (int)$this->session->get('person_id');

        foreach ($saleIds as $saleId) {
            if ($this->sale->outsideRecordScope($saleId, $personId)) {
                return true;
            }

            $branchId = $this->sale->getBranchId($saleId);

            if ($branchId !== null && !$guard->canAccess($branchId, $personId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What an employee is told when a sale is outside their reach: for one who sees their own
     * records only the plain refusal, that says nothing of whether the sale exists.
     */
    private function scopeMessage(): string
    {
        return (new RecordScope())->ownOnly((int) $this->session->get('person_id')) ? lang('Sales.not_authorized') : lang('Sales.branch_not_allowed');
    }

    /**
     * @param array $data
     * @return void
     */
    private function reload(array $data = []): ResponseInterface|string
    {
        $saleId = $this->session->get('sale_id');    // TODO: This variable is never used

        if ($saleId == '') {
            $saleId = NEW_ENTRY;
            $this->session->set('sale_id', NEW_ENTRY);
        }
        $cashRounding = $this->sale_lib->reset_cash_rounding();

        // cash_rounding indicates only that the site is configured for cash rounding
        $data['cash_rounding'] = $cashRounding;

        $data['cart'] = $this->sale_lib->get_cart();
        $customerInfo = $this->_load_customer_data($this->sale_lib->get_customer(), $data, true);

        $displayCurrency = $this->registerDisplayCurrency();

        $data['quick_items'] = $this->quickItemsIn($displayCurrency);
        $data['modes'] = $this->sale_lib->get_register_mode_options();
        $data['mode'] = $this->sale_lib->get_mode();
        $data['selected_table'] = $this->sale_lib->get_dinner_table();
        $data['empty_tables'] = $this->sale_lib->get_empty_tables($data['selected_table']);
        $data['stock_locations'] = $this->stock_location->get_allowed_locations('sales', true);
        $data['stock_location'] = $this->sale_lib->get_sale_location();
        $data['tax_exclusive_subtotal'] = $this->sale_lib->get_subtotal(true, true);
        $taxDetails = $this->tax_lib->get_taxes($data['cart']);    // TODO: Duplicated code.
        $data['taxes'] = $taxDetails[0];
        $data['discount'] = $this->sale_lib->get_discount();
        $data['invoice_discount'] = $this->sale_lib->getInvoiceDiscountTotal();
        $data['invoice_discount_entry'] = $this->sale_lib->getInvoiceDiscount();
        $data['can_invoice_discount'] = !$this->sale_lib->is_return_mode() && $this->mayGiveInvoiceDiscount();

        $chargeSettings = new AccountingSettings();
        $allowed = $this->headerChargesAllowed();
        $data['can_adjustment'] = $allowed && $chargeSettings->salesAdjustmentEnabled();
        $data['can_shipping'] = $allowed && $chargeSettings->salesShippingEnabled();
        $data['shipping_tax_percent'] = $chargeSettings->shippingTaxPercent();
        $data['header_charges_entry'] = $this->sale_lib->getHeaderCharges();
        $data['shipping_taxed'] = $data['header_charges_entry']->shippingTaxOption !== ShippingTaxOption::None;

        $chargesProblem = $this->sale_lib->headerChargesProblem($this->tax_lib->get_taxes($data['cart'])[0]);

        if ($chargesProblem !== null && !isset($data['error'])) {
            $data['warning'] = lang($chargesProblem->languageKey());
        }

        $discountProblem = $this->sale_lib->invoiceDiscountProblem();

        if ($discountProblem !== null && !isset($data['error'])) {
            $data['warning'] = lang($discountProblem->languageKey());
        }
        $data['payments'] = $this->sale_lib->getPayments();

        // Returns 'subtotal', 'total', 'cash_total', 'payment_total', 'amount_due', 'cash_amount_due', 'payments_cover_total'
        $totals = $this->sale_lib->get_totals($taxDetails[0]);

        $data['item_count'] = $totals['item_count'];
        $data['total_units'] = $totals['total_units'];
        $data['subtotal'] = $totals['subtotal'];
        $data['header_charges'] = $this->headerChargesView($totals);
        $data['total'] = $totals['total'];
        $data['payments_total'] = $totals['payment_total'];
        $data['payments_cover_total'] = $totals['payments_cover_total'];

        // cash_mode indicates whether this sale is going to be processed using cash_rounding
        $cashMode = $this->session->get('cash_mode');
        $data['cash_mode'] = $cashMode;
        $data['prediscount_subtotal'] = $totals['prediscount_subtotal'];    // TODO: Duplicated code.
        $data['cash_total'] = $totals['cash_total'];
        $data['non_cash_total'] = $totals['total'];
        $data['cash_amount_due'] = $totals['cash_amount_due'];
        $data['non_cash_amount_due'] = $totals['amount_due'];

        $data['selected_payment_type'] = $this->sale_lib->get_payment_type();

        if ($data['cash_mode'] && ($data['selected_payment_type'] == lang('Sales.cash') || $data['payments_total'] > 0)) {
            $data['total'] = $totals['cash_total'];
            $data['amount_due'] = $totals['cash_amount_due'];
        } else {
            $data['total'] = $totals['total'];
            $data['amount_due'] = $totals['amount_due'];
        }

        $data['amount_change'] = $data['amount_due'] * -1;

        $data['comment'] = $this->sale_lib->get_comment();
        $data['custom_fields'] = (new CustomFields())->definitions('sale');
        $data['custom_values'] = $this->sale_lib->getCustomFields();
        $data['email_receipt'] = $this->sale_lib->is_email_receipt();

        if ($customerInfo && $this->config['customer_reward_enable']) {
            $data['payment_options'] = $this->sale->get_payment_options(true, true);
        } else {
            $data['payment_options'] = $this->sale->get_payment_options();
        }

        $data['reference_code_payment_types'] = get_reference_code_payment_types();
        $data['treasury_options'] = $this->registerTreasuries();
        $data['treasury_free_types'] = $this->labelsWithoutTreasury();

        $data['items_module_allowed'] = $this->employee->has_grant('items', $this->employee->get_logged_in_employee_info()->person_id);
        $data['change_price'] = $this->employee->has_grant('sales_change_price', $this->employee->get_logged_in_employee_info()->person_id);

        $tempInvoiceNumber = $this->sale_lib->get_invoice_number();
        $invoiceFormat = $this->config['sales_invoice_format'];
        $invoiceNumberPlaceholder = '';

        if ($tempInvoiceNumber == null || $tempInvoiceNumber == '') {
            $nextInvoiceNumber = (new DocumentNumbering())->peek(DocumentType::SalesInvoice);

            if ($nextInvoiceNumber === null) {
                $tempInvoiceNumber = $this->token_lib->render($invoiceFormat, [], false);
            } else {
                $tempInvoiceNumber = '';
                $invoiceNumberPlaceholder = $nextInvoiceNumber;
            }
        }

        $data['invoice_number'] = $tempInvoiceNumber;
        $data['invoiceNumberPlaceholder'] = $invoiceNumberPlaceholder;

        $data['print_after_sale'] = $this->sale_lib->is_print_after_sale();
        $data['price_work_orders'] = $this->sale_lib->is_price_work_orders();
        $data['accounting_enabled'] = (new AccountingSettings())->isEnabled();
        $data['delivered'] = $this->sale_lib->is_delivered();
        $data['invoice_date'] = $this->sale_lib->getInvoiceDate();
        $data['payment_terms'] = $this->sale_lib->getPaymentTerms();
        $data['due_date'] = $this->registerDueDate();
        $data['can_type_invoice_date'] = $this->mayTypeInvoiceDate();
        $data['today']                 = date('Y-m-d');
        $data['return_origin']         = $this->sale_lib->get_return_origin();
        $data['return_document']       = $this->sale_lib->getReturnDocument()->value;
        $data['can_override_return']   = $this->mayOverrideReturn();
        $data['return_override']       = $this->sale_lib->getReturnOverride();
        $data['priceListSelector']     = $this->priceListSelector();
        $data['currencySelector']      = $this->currencySelector();
        $data['has_cart_items']        = $this->sale_lib->get_cart() !== [];

        $data['pos_mode'] = $data['mode'] == 'sale' || $data['mode'] == 'return';

        $data['quote_number'] = $this->sale_lib->get_quote_number();
        $data['work_order_number'] = $this->sale_lib->get_work_order_number();
        $data['keyboardShortcuts'] = $this->sale_lib->getKeyShortcuts();

        // TODO: the if/else set below should be converted to a switch
        if ($this->sale_lib->get_mode() == 'sale_invoice') {    // TODO: Duplicated code.
            $data['mode_label'] = lang('Sales.invoice');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'sale_quote') {
            $data['mode_label'] = lang('Sales.quote');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'sale_work_order') {
            $data['mode_label'] = lang('Sales.work_order');
            $data['customer_required'] = lang('Sales.customer_required');
        } elseif ($this->sale_lib->get_mode() == 'return') {
            $data['mode_label'] = lang('Sales.return');
            $data['customer_required'] = lang('Sales.customer_optional');
        } else {
            $data['mode_label'] = lang('Sales.receipt');
            $data['customer_required'] = lang('Sales.customer_optional');
        }

        if ($displayCurrency !== null) {
            $data['config'] = CurrencyDisplay::settingsFor($displayCurrency);
        }

        return CurrencyDisplay::within($displayCurrency, static fn (): string => view('sales/register', $data));
    }

    /**
     * Load the sales receipt for a sale. Used in app/Views/sales/form.php
     *
     * @param int $saleId
     * @return string
     * @noinspection PhpUnused
     */
    public function getReceipt(int $saleId): string|ResponseInterface
    {
        if ($this->outsideBranchScope($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        $data = $this->_load_sale_data($saleId);
        $this->sale_lib->clear_all();

        return view('sales/receipt', $data);
    }

    /**
     * Loads the sales invoice for a sale. Used in app/Views/sales/form.php
     *
     * @param int $saleId
     * @return string
     * @noinspection PhpUnused
     */
    public function getInvoice(int $saleId): string|ResponseInterface
    {
        if ($this->outsideBranchScope($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        $data = $this->_load_sale_data($saleId);
        $this->sale_lib->clear_all();

        return view('sales/' . $data['invoice_view'], $data);
    }

    /**
     * Edits an existing sale or work order. Used in app/Views/sales/form.php
     *
     * @param int $saleId
     * @return string
     * @throws ReflectionException
     */
    public function getEdit(int $saleId): string|ResponseInterface
    {
        if ($this->outsideBranchScope($saleId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return redirect()->to('no_access/sales/reports_sales');
        }

        $data = [];

        $saleInfo = $this->sale->get_info($saleId)->getRowArray();
        $data['selected_customer_id'] = $saleInfo['customer_id'];
        $data['selected_customer_name'] = $saleInfo['customer_name'];
        $employeeInfo = $this->employee->get_info($saleInfo['employee_id']);
        $data['selected_employee_id'] = $saleInfo['employee_id'];
        $data['selected_employee_name'] = $employeeInfo->first_name . ' ' . $employeeInfo->last_name;
        $data['sale_info'] = $saleInfo;
        $balanceDue = round($saleInfo['amount_due'] - $saleInfo['amount_tendered'] + $saleInfo['cash_refund'], totals_decimals(), PHP_ROUND_HALF_UP);

        if (!$this->sale_lib->reset_cash_rounding() && $balanceDue < 0) {
            $balanceDue = 0;
        }

        $data['payments'] = [];

        foreach ($this->sale->get_sale_payments($saleId)->getResult() as $payment) {
            foreach (get_object_vars($payment) as $property => $value) {
                $payment->$property = $value;
            }
            $payment->uses_treasury = (PaymentType::fromLabel((string) $payment->payment_type)?->usesTreasury() ?? false) && ! $payment->cash_adjustment;
            $data['payments'][] = $payment;
        }

        $numbering                = new DocumentNumbering();
        $data['paymentNumbers']   = $numbering->numbers(DocumentType::CustomerPayment, 'sales_payments', array_map(static fn (object $payment): int => (int) $payment->payment_id, $data['payments']));
        $data = [...$data, ...$this->returnDocumentNumbersOf($saleId)];

        $vouchers           = new VoucherDocuments();
        $data['receiptIds'] = array_values(array_filter(
            array_map(static fn (object $payment): int => (int) $payment->payment_id, $data['payments']),
            static fn (int $paymentId): bool => $vouchers->customerPayment($paymentId, (int) $personId) !== null,
        ));

        $data['treasury_options']     = [];
        $data['new_treasury_options'] = [];

        $data['journals']         = [];
        $data['accounting_on']    = false;
        $data['issued_lock']      = false;
        $data['receivable']       = null;
        $data['advance_numbers']  = [];
        $data['credit_note_help'] = null;
        $data['payment_statuses'] = [];
        $showPaymentRow           = false;

        if ((new AccountingSettings())->isEnabled()) {
            $posting                  = new SalePosting();
            $posted                   = $posting->isPosted($saleId);
            $data['accounting_on']    = true;
            $data['payment_statuses'] = $this->paymentStatusOptions();

            $branchId                     = (int) $this->sale->getBranchId($saleId);
            $data['treasury_options']     = $this->treasuryOptions($branchId, $data['payments']);
            $data['new_treasury_options'] = $this->treasuryOptions($branchId, []);

            if ($this->employee->has_grant('accounting_journals', $personId)) {
                $data['journals'] = $posting->journalsOf($saleId);
            }

            if ($posted) {
                $data['issued_lock'] = in_array((int) $saleInfo['sale_type'], [SALE_TYPE_INVOICE, SALE_TYPE_RETURN], true);
                $customerId          = $saleInfo['customer_id'] === null ? null : (int) $saleInfo['customer_id'];

                if ($customerId !== null) {
                    $position                = (new CustomerLedger())->position($customerId);
                    $data['receivable']      = $position->invoice($saleId);
                    $data['credit']          = $position->credit();
                    $showPaymentRow          = $data['receivable'] !== null;
                    $data['applications']    = $this->applicationsOf($saleId, $customerId);
                    $data['advance_numbers'] = (new CustomerAdvances())->numbersOf(array_map(
                        static fn (object $application): int => (int) $application->source_id,
                        array_filter($data['applications'], static fn (object $application): bool => $application->source_type === CreditSource::CUSTOMER_ADVANCE),
                    ));
                }
            }

            if ((int) $saleInfo['sale_type'] === SALE_TYPE_INVOICE && $posted && (int) ($saleInfo['delivered'] ?? 1) !== 1) {
                $data['credit_note_help'] = lang('Sales.credit_note_undelivered_help');
            }

            foreach ($data['payments'] as $payment) {
                if (! PaymentStatus::fromStored($payment->payment_status ?? null)->posts()) {
                    $balanceDue = round($balanceDue + (float) $payment->payment_amount - (float) $payment->cash_refund, totals_decimals(), PHP_ROUND_HALF_UP);
                }
            }
        }

        $data['invoice_timing'] = null;

        if ($data['accounting_on'] && (int) $saleInfo['sale_type'] === SALE_TYPE_INVOICE && (int) $saleInfo['sale_status'] === COMPLETED) {
            $timing = $this->sale->getInvoiceTiming($saleId);
            $date   = $timing['invoice_date'] ?? substr((string) $saleInfo['sale_time'], 0, 10);

            $data['invoice_timing'] = ['date' => $date, 'terms' => $timing['payment_terms_days'], 'due' => $timing['due_date'] ?? $date];
        }

        $data['payment_type_new'] = PAYMENT_TYPE_UNASSIGNED;
        $data['payment_amount_new'] = max($balanceDue, 0);

        $data['balance_due'] = $balanceDue != 0 || $showPaymentRow;

        // Don't allow gift card to be a payment option in a sale transaction edit because it's a complex change
        $paymentOptions = $this->sale->get_payment_options(false);

        if ($this->sale_lib->reset_cash_rounding()) {
            $paymentOptions[lang('Sales.cash_adjustment')] = lang('Sales.cash_adjustment');
        }

        $data['payment_options'] = $paymentOptions;
        $data['reference_code_payment_types'] = get_reference_code_payment_types();

        // Set up a slightly modified list of payment types for new payment entry
        $paymentOptions["--"] = lang('Common.none_selected_text');

        $data['new_payment_options'] = $paymentOptions;

        return view('sales/form', $data);
    }

    /**
     * @param int $sale_id
     * @return ResponseInterface
     * @throws ReflectionException
     */
    public function postDelete(int $sale_id = NEW_ENTRY, bool $update_inventory = true): ResponseInterface
    {
        $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $has_grant = $this->employee->has_grant('sales_delete', $employee_id);

        if (!$has_grant) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        } else {
            $sale_ids = $sale_id == NEW_ENTRY ? $this->request->getPost('ids', FILTER_SANITIZE_NUMBER_INT) : [$sale_id];

            if ($this->outsideBranchScope(...array_map('intval', (array) $sale_ids))) {
                return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
            }

            if ($this->sale->delete_list($sale_ids, $employee_id, $update_inventory)) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Sales.successfully_deleted') . ' ' . count($sale_ids) . ' ' . lang('Sales.one_or_multiple'),
                    'ids'     => $sale_ids
                ]);
            } else {
                $message = $this->sale->accountingFailure() === null ? lang('Sales.unsuccessfully_deleted') : $this->accountingFailureMessage();

                return $this->response->setJSON(['success' => false, 'message' => $message]);
            }
        }
    }

    /**
     * @param int $sale_id
     * @param bool $update_inventory
     * @return ResponseInterface
     */
    public function restore(int $sale_id = NEW_ENTRY, bool $update_inventory = true): ResponseInterface
    {
        $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $has_grant = $this->employee->has_grant('sales_delete', $employee_id);

        if (!$has_grant) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        } else {
            $sale_ids = $sale_id == NEW_ENTRY ? $this->request->getPost('ids', FILTER_SANITIZE_NUMBER_INT) : [$sale_id];

            if ($this->outsideBranchScope(...array_map('intval', (array) $sale_ids))) {
                return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
            }

            if ($this->sale->restore_list($sale_ids, $employee_id, $update_inventory)) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Sales.successfully_restored') . ' ' . count($sale_ids) . ' ' . lang('Sales.one_or_multiple'),
                    'ids'     => $sale_ids
                ]);
            } else {
                return $this->response->setJSON(['success' => false, 'message' => lang('Sales.unsuccessfully_restored')]);
            }
        }
    }

    /**
     * This saves the sale from the update sale view (sales/form).
     * It only updates the sales table and payments.
     * @param int $saleId
     * @return ResponseInterface
     * @throws ReflectionException
     */
    public function postSave(int $saleId = NEW_ENTRY): ResponseInterface
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        if ($this->outsideBranchScope($saleId)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => $this->scopeMessage()]);
        }

        $newdate = $this->request->getPost('date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $employeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $inventory = model(Inventory::class);
        $dateFormatter = date_create_from_format($this->config['dateformat'] . ' ' . $this->config['timeformat'], $newdate);
        $saleTime = $dateFormatter->format('Y-m-d H:i:s');

        $saleData = [
            'sale_time'      => $saleTime,
            'customer_id'    => $this->request->getPost('customer_id') != '' ? $this->request->getPost('customer_id', FILTER_SANITIZE_NUMBER_INT) : null,
            'employee_id'    => $this->request->getPost('employee_id') != '' ? $this->request->getPost('employee_id', FILTER_SANITIZE_NUMBER_INT) : null,
            'comment'        => $this->request->getPost('comment', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'invoice_number' => $this->request->getPost('invoice_number') != '' ? $this->request->getPost('invoice_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null,
            'changed_by'     => $employeeId,
        ];

        $postedTerms = $this->request->getPost('payment_terms_days');

        if (is_string($postedTerms)) {
            $saleData['payment_terms_days'] = $postedTerms;
        }

        // Validate reference_code for the new payment if applicable
        $paymentTypeNewCheck = $this->request->getPost('payment_type_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $paymentAmountNewCheck = $this->request->getPost('payment_amount_new');
        if ($paymentTypeNewCheck != PAYMENT_TYPE_UNASSIGNED && !empty($paymentAmountNewCheck)
            && in_array($paymentTypeNewCheck, get_reference_code_payment_types())) {
            $min = (int)($this->config['payment_reference_code_min'] ?? 3);
            $max = (int)($this->config['payment_reference_code_max'] ?? 40);
            $rules = [
                'reference_code_new' => "trim|required|alpha_numeric|min_length[$min]|max_length[$max]",
            ];
            $messages = [
                'reference_code_new' => [
                    'required'      => lang('Sales.must_enter_reference_code'),
                    'alpha_numeric' => lang('Sales.reference_code_invalid_characters'),
                    'min_length'    => lang('Sales.reference_code_length_error'),
                    'max_length'    => lang('Sales.reference_code_length_error'),
                ],
            ];
            if (!$this->validate($rules, $messages)) {
                $errors = $this->validator->getErrors();
                return $this->response->setJSON(['success' => false, 'message' => reset($errors), 'id' => $saleId]);
            }
        }

        // In order to maintain tradition the only element that can change on prior payments is the payment type
        $amountTendered = 0;
        $numberOfPayments = $this->request->getPost('number_of_payments', FILTER_SANITIZE_NUMBER_INT);
        for ($i = 0; $i < $numberOfPayments; ++$i) {
            $paymentId = $this->request->getPost("payment_id_$i", FILTER_SANITIZE_NUMBER_INT);
            $paymentType = $this->request->getPost("payment_type_$i", FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $paymentAmount = parse_decimals($this->request->getPost("payment_amount_$i"));
            $refundType = $this->request->getPost("refund_type_$i", FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $cashRefund = parse_decimals($this->request->getPost("refund_amount_$i"));
            $referenceCode = $this->request->getPost("reference_code_$i", FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: null;
            $paymentStatus = $this->request->getPost("payment_status_$i", FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $cashAdjustment = $paymentType == lang('Sales.cash_adjustment') ? CASH_ADJUSTMENT_TRUE : CASH_ADJUSTMENT_FALSE;

            if (!$cashAdjustment) {
                $amountTendered += $paymentAmount - $cashRefund;
            }

            // Non-cash positive refund amounts
            if (empty(strstr($refundType, lang('Sales.cash'))) && $cashRefund > 0) {    // TODO: This if and the one below can be combined.
                // Change it to be a new negative payment (a "non-cash refund")
                $paymentType = $refundType;
                $paymentAmount = $paymentAmount - $cashRefund;
                $cashRefund = 0.00;
            }

            $saleData['payments'][] = [
                'payment_id'      => $paymentId,
                'payment_type'    => $paymentType,
                'payment_amount'  => $paymentAmount,
                'cash_refund'     => $cashRefund,
                'cash_adjustment' => $cashAdjustment,
                'employee_id'     => $employeeId,
                'reference_code'  => $referenceCode,
                'payment_status'  => $paymentStatus,
                ...$this->postedTreasury("treasury_id_$i"),
            ];
        }

        $paymentId = NEW_ENTRY;
        $paymentAmountNew = $this->request->getPost('payment_amount_new');
        $paymentType = $this->request->getPost('payment_type_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $referenceCodeNew = $this->request->getPost('reference_code_new', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: null;

        if ($paymentType != PAYMENT_TYPE_UNASSIGNED && !empty($paymentAmountNew)) {
            $paymentAmount = parse_decimals($paymentAmountNew);
            $cashRefund = 0;
            if ($paymentType == lang('Sales.cash_adjustment')) {
                $cashAdjustment = CASH_ADJUSTMENT_TRUE;
            } else {
                $cashAdjustment = CASH_ADJUSTMENT_FALSE;
                $amountTendered += $paymentAmount;
                $saleInfo = $this->sale->get_info($saleId)->getRowArray();

                if ($amountTendered > $saleInfo['amount_due'] && ! $this->keepsOverpayment($saleId, $saleInfo)) {
                    $cashRefund = $amountTendered - $saleInfo['amount_due'];
                }
            }

            $saleData['payments'][] = [
                'payment_id'      => $paymentId,
                'payment_type'    => $paymentType,
                'payment_amount'  => $paymentAmount,
                'cash_refund'     => $cashRefund,
                'cash_adjustment' => $cashAdjustment,
                'employee_id'     => $employeeId,
                'reference_code'  => $referenceCodeNew,
                ...$this->postedTreasury('treasury_id_new'),
            ];
        }

        if ($this->sale->update($saleId, $saleData)) {
            $inventory->update('POS ' . $saleId, ['trans_date' => $saleTime]);    // TODO: Reflection Exception

            return $this->response->setJSON(['success' => true, 'message' => lang('Sales.successfully_updated'), 'id' => $saleId]);
        } else {
            $message = $this->sale->accountingFailure() === null ? lang('Sales.unsuccessfully_updated') : $this->accountingFailureMessage();

            return $this->response->setJSON(['success' => false, 'message' => $message, 'id' => $saleId]);
        }
    }

    /**
     * This is used to cancel a suspended pos sale, quote.
     * Completed sales (POS Sales or Invoiced Sales) can not be removed from the system
     * Work orders can be canceled but are not physically removed from the sales history.
     * Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postCancel(): ResponseInterface|string
    {
        $sale_id = $this->sale_lib->get_sale_id();
        if ($sale_id != NEW_ENTRY && $sale_id != '') {
            $sale_type = $this->sale_lib->getSaleType();

            if ($this->config['dinner_table_enable']) {
                $dinner_table = $this->sale_lib->get_dinner_table();
                $this->dinner_table->release($dinner_table);
            }

            if ($sale_type == SALE_TYPE_WORK_ORDER) {
                $this->sale->update_sale_status($sale_id, CANCELED);
            } else {
                $this->sale->delete($sale_id, false, true, (int) $this->employee->get_logged_in_employee_info()->person_id);
                $this->session->set('sale_id', NEW_ENTRY);
            }
        } else {
            $this->sale_lib->remove_temp_items();
        }

        $this->sale_lib->clear_all();
        return $this->reload();
    }

    /**
     * Discards the suspended sale. Used in app/Views/sales/quote.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getDiscardSuspendedSale(): ResponseInterface|string
    {
        $suspended_id = $this->sale_lib->get_suspended_id();
        $this->sale_lib->clear_all();
        $this->sale->delete_suspended_sale($suspended_id);
        return $this->reload();
    }

    /**
     * Suspend the current sale.
     * If the current sale is already suspended then update the existing suspended sale otherwise create
     * it as a new suspended sale. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface|string
     * @noinspection PhpUnused
     */
    public function postSuspend(): ResponseInterface|string
    {
        $this->sale->useCurrency($this->registerCurrency());

        $saleId = $this->sale_lib->get_sale_id();
        $dinnerTable = $this->sale_lib->get_dinner_table();
        $cart = $this->sale_lib->get_cart();
        $payments = $this->sale_lib->getPayments();
        $employeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $customerId = $this->sale_lib->get_customer();
        $invoiceNumber = $this->sale_lib->get_invoice_number();
        $workOrderNumber = $this->sale_lib->get_work_order_number();
        $quoteNumber = $this->sale_lib->get_quote_number();
        $saleType = $this->sale_lib->getSaleType();

        if ($saleType == '') {
            $saleType = SALE_TYPE_POS;
        }

        $comment = $this->sale_lib->get_comment();
        $saleStatus = SUSPENDED;

        $data = [];
        $salesTaxes = [[], []];

        $branchProblem = $this->cartBranchProblem($cart);

        if ($branchProblem !== null) {
            $data['error'] = $branchProblem;

            return $this->reload($data);
        }

        $invoiceDiscount = $this->registerInvoiceDiscount();

        if (($invoiceDiscount !== null && !$this->mayGiveInvoiceDiscount()) || !$this->mayGiveHeaderCharges($this->sale_lib->getHeaderCharges(), $this->sale_lib->is_return_mode())) {
            return $this->reload(['error' => lang('Sales.not_authorized')]);
        }

        $customProblem = (new CustomFields())->problem('sale', $this->sale_lib->getCustomFields(), false, (int) $saleId);

        if ($customProblem !== null) {
            $data['error'] = $customProblem;

            return $this->reload($data);
        }

        $saleIdNum = $this->sale->save_value($saleId, $saleStatus, $cart, $customerId, $employeeId, $comment, $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $payments, $dinnerTable, $salesTaxes, $this->sale_lib->is_delivered(), null, $invoiceDiscount, $this->sale_lib->getReturnDocument(), $this->sale_lib->getInvoiceDate(), $this->sale_lib->getPaymentTerms(), $saleType === SALE_TYPE_QUOTE ? QuoteStatus::Draft : null, $saleType === SALE_TYPE_INVOICE ? $this->sale_lib->getQuoteSource() : null, headerCharges: $this->sale_lib->getHeaderCharges(), priceListId: $this->sale_lib->effectivePriceListId(), orderSourceId: $saleType === SALE_TYPE_INVOICE ? $this->sale_lib->getOrderSource() : null);
        $this->keepCustomFields($saleIdNum, false);

        if ($saleIdNum === INSUFFICIENT_GIFTCARD_BALANCE) {
            $data['error'] = lang('Sales.insufficient_giftcard_balance');
        } elseif ($saleIdNum === INSUFFICIENT_REWARD_POINTS) {
            $data['error'] = lang('Sales.insufficient_reward_points');
        } elseif ($saleIdNum === ACCOUNTING_FAILED) {
            $data['error'] = $this->accountingFailureMessage();
        } elseif ($saleIdNum === INVOICE_DISCOUNT_INVALID) {
            $data['error'] = $this->invoiceDiscountMessage();
        } elseif ($saleIdNum === NEW_ENTRY) {
            $data['error'] = lang('Sales.unsuccessfully_suspended_sale');
            $this->sale_lib->clear_all();
        } else {
            $data['success'] = lang('Sales.successfully_suspended_sale');
            $this->sale_lib->clear_all();
        }

        return $this->reload($data);
    }

    /**
     * List suspended sales
     * @return string
     */
    public function getSuspended(): string
    {
        $data = [];
        $customer_id = $this->sale_lib->get_customer();
        $allowed = (new BranchGuard())->allowedBranchIds((int) $this->session->get('person_id'));
        $data['suspended_sales'] = array_values(array_filter(
            $this->sale->get_all_suspended($customer_id),
            static fn (array $sale): bool => $allowed === null || in_array((int) $sale['branch_id'], $allowed, true),
        ));
        return view('sales/suspended', $data);
    }

    /**
     * Unsuspended sales are now left in the tables and are only removed
     * when they are intentionally cancelled. Used in app/Views/sales/suspended.php.
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postUnsuspend(): ResponseInterface|string
    {
        $personId = $this->session->get('person_id');

        if (!$this->employee->has_grant('reports_sales', $personId)) {
            return $this->response->setStatusCode(403)
                ->setJSON(['success' => false, 'message' => lang('Sales.not_authorized')]);
        }

        $saleId = $this->request->getPost('suspended_sale_id', FILTER_SANITIZE_NUMBER_INT);

        if ($saleId > 0 && $this->outsideBranchScope((int) $saleId)) {
            return $this->reload(['error' => $this->scopeMessage()]);
        }

        if ($saleId > 0 && (new QuoteLifecycle())->statusOf((int) $saleId)?->isLocked() === true) {
            return $this->reload(['error' => lang('Accounting.error_quote_locked')]);
        }

        if ($saleId > 0 && $this->sale->getSaleStatus($saleId) == SUSPENDED) {
            $this->sale_lib->clear_all();
            $this->sale_lib->copy_entire_sale($saleId);
        }

        // Set current register mode to reflect that of unsuspended order type
        $this->change_register_mode($this->sale_lib->getSaleType());

        return $this->reload();
    }

    /**
     * Show Keyboard shortcut modal. Used in app/Views/sales/register.php
     *
     * @return string
     * @noinspection PhpUnused
     */
    public function getSalesKeyboardHelp(): string
    {
        return view('sales/help', [
            'keyboardShortcuts' => $this->sale_lib->getKeyShortcuts()
        ]);
    }

    /**
     * Check the validity of an invoice number. Used in app/Views/sales/form.php.
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postCheckInvoiceNumber(): ResponseInterface
    {
        $sale_id = $this->request->getPost('sale_id', FILTER_SANITIZE_NUMBER_INT);
        $invoice_number = $this->request->getPost('invoice_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $exists = !empty($invoice_number) && $this->sale->check_invoice_number_exists($invoice_number, $sale_id);
        return $this->response->setJSON(!$exists ? 'true' : 'false');
    }

    /**
     * @param array $cart
     * @return array
     */
    public function get_filtered(array $cart): array
    {
        $filtered_cart = [];
        foreach ($cart as $id => $item) {
            if ($item['print_option'] == PRINT_ALL) // Always include
            {
                $filtered_cart[$id] = $item;
            } elseif ($item['print_option'] == PRINT_PRICED && $item['price'] != 0)  // Include only if the price is not zero
            {
                $filtered_cart[$id] = $item;
            }
            // print_option 2 is never included
        }

        return $filtered_cart;
    }

    /**
     * Update the item number in the register. Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postChangeItemNumber(): ResponseInterface
    {
        $item_id = $this->request->getPost('item_id', FILTER_SANITIZE_NUMBER_INT);
        $item_number = $this->request->getPost('item_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $this->item->update_item_number($item_id, $item_number);
        $cart = $this->sale_lib->get_cart();
        $x = $this->search_cart_for_item_id($item_id, $cart);
        if ($x !== null) {
            $cart[$x]['item_number'] = $item_number;
        }
        $this->sale_lib->set_cart($cart);
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Change a given item name. Used in app/Views/sales/register.php.
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postChangeItemName(): ResponseInterface
    {
        $item_id = $this->request->getPost('item_id', FILTER_SANITIZE_NUMBER_INT);
        $name = $this->request->getPost('item_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $this->item->update_item_name($item_id, $name);

        $cart = $this->sale_lib->get_cart();
        $x = $this->search_cart_for_item_id($item_id, $cart);

        if ($x !== null) {
            $cart[$x]['name'] = $name;
        }

        $this->sale_lib->set_cart($cart);
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Update the given item description.  Used in app/Views/sales/register.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postChangeItemDescription(): ResponseInterface
    {
        $item_id = $this->request->getPost('item_id', FILTER_SANITIZE_NUMBER_INT);
        $description = $this->request->getPost('item_description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $this->item->update_item_description($item_id, $description);

        $cart = $this->sale_lib->get_cart();
        $x = $this->search_cart_for_item_id($item_id, $cart);

        if ($x !== null) {
            $cart[$x]['description'] = $description;
        }

        $this->sale_lib->set_cart($cart);
        return $this->response->setJSON(['success' => true]);
    }

    /**
     * @param int $search_item_id
     * @param array $shopping_cart
     * @return int|string|null
     */
    public function search_cart_for_item_id(int $search_item_id, array $shopping_cart): int|string|null
    {
        foreach ($shopping_cart as $key => $val) {
            if ($val['item_id'] === $search_item_id) {
                return $key;
            }
        }

        return null;
    }
}
