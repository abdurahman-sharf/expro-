<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\CreditLimit;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\CustomerLock;
use App\Libraries\Accounting\DraftInvoice;
use App\Libraries\Accounting\InvoiceTiming;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\QuoteLifecycle;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\ReturnLimit;
use App\Libraries\Accounting\ReturnOverride;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SaleLoader;
use App\Libraries\Accounting\SalePosting;
use App\Libraries\Accounting\ShippingCompanies;
use App\Libraries\Accounting\SalesTaxSettings;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\UndeliveredReturns;
use App\Libraries\Database\ReadCommittedStatement;
use App\Libraries\HeaderCharges;
use App\Libraries\HeaderChargesProblem;
use App\Libraries\InvoiceDiscount;
use App\Libraries\InvoiceDiscountPlan;
use App\Libraries\InvoiceDiscountProblem;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Pricing\PriceControls;
use App\Libraries\Roles\RecordScope;
use App\Libraries\SalesOrders\SalesOrders;
use App\Libraries\ShippingTaxOption;
use App\Libraries\Stock\NegativeStockGuard;
use App\Libraries\Stock\StockTracking;
use App\Libraries\Stock\TrackingEntry;
use App\Libraries\Stock\UnitLines;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use App\Libraries\Sale_lib;
use Config\OSPOS;
use ReflectionException;
use Throwable;

/**
 * Sale class
 */
class Sale extends Model
{
    protected $table = 'sales';
    protected $primaryKey = 'sale_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'sale_time',
        'customer_id',
        'employee_id',
        'comment',
        'quote_number',
        'sale_status',
        'invoice_number',
        'dinner_table_id',
        'work_order_number',
        'sale_type'
    ];

    private ?Throwable $accountingFailure                   = null;
    private bool $datedBySchedule                           = false;
    private ?InvoiceDiscountProblem $invoiceDiscountProblem = null;

    private ?RecordScope $recordScope = null;

    /** @var array{string, ?string}|null The currency the next sale saved is in, and the rate typed for it. */
    private ?array $currencyChoice = null;

    /** @var array{string, ?string}|null */
    private ?array $savingCurrency = null;

    public function __construct()
    {
        parent::__construct();
        helper('text');
    }

    /**
     * Says which currency the next sale that is saved is in. The choice is for that one save and is
     * forgotten after it, so that a sale saved later is in the base currency unless it is said again. The
     * rate is the one of the table for the day of the sale unless one is typed, which asks the permission
     * to manage currencies. A return takes the currency and the rate of the sale it takes back whatever is said.
     */
    public function useCurrency(?string $code, ?string $typedRate = null): void
    {
        $this->currencyChoice = $code === null || $code === '' ? null : [$code, $typedRate];
    }

    /**
     * Why the discount on the whole of the last sale saved was refused, or null when it was not.
     */
    public function invoiceDiscountProblem(): ?InvoiceDiscountProblem
    {
        return $this->invoiceDiscountProblem;
    }

    /**
     * Takes a line of a tracked item out by its serial numbers or batches, or puts it back when it is a
     * return, and changes the shelf with it.
     *
     * @param array<string, mixed> $line
     *
     * @throws AccountingException
     */
    private function moveTrackedStock(StockTracking $tracking, int $saleId, array $line, ?int $originSaleId, string $date, ?int $employeeId): void
    {
        $quantity = sprintf('%.3F', abs((float) $line['quantity']));
        $entry    = TrackingEntry::ofLine($line);

        if ((float) $line['quantity'] > 0) {
            $tracking->issue('sale', $saleId, (int) $line['line'], (int) $line['item_id'], (int) $line['item_location'], $quantity, $entry, $date, StockTracking::SALE, $employeeId);

            return;
        }

        $tracking->putBack('sale', $saleId, (int) $line['line'], (int) $line['item_id'], (int) $line['item_location'], $quantity, $entry, $originSaleId);
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
            $columns[$key] = $units->columnsOf($itemData, (int) ($itemData['line'] ?? 0), 'quantity', 'price', '', 'unit_price_entered');
        }

        return $columns;
    }

    /**
     * Whether the settings ask for a tax on every line and a line of this sale has none. Only a sale
     * that is completed as a sale or an invoice is held to it, for a customer who is charged tax; a
     * return takes back what its sale charged.
     *
     * @param array<int|string, array<string, mixed>> $items
     */
    private function taxOneRefusal(int $saleType, string $saleStatus, int $customerId, array $items, ?array $salesTaxes): ?AccountingException
    {
        if ($saleStatus != COMPLETED || !in_array($saleType, [SALE_TYPE_POS, SALE_TYPE_INVOICE], true) || (config(OSPOS::class)->settings['use_destination_based_tax'] ?? false)) {
            return null;
        }

        if (!(new SalesTaxSettings($this->db))->requireTaxOne()) {
            return null;
        }

        if ($customerId != NEW_ENTRY && !model(Customer::class)->get_info($customerId)->taxable) {
            return null;
        }

        $line = SalesTaxSettings::lineWithoutTax($items, $salesTaxes[1] ?? []);

        return $line === null ? null : new AccountingException(AccountingError::TaxOneRequired, "Line {$line} of the sale has no tax.", ['line' => $line]);
    }

    /**
     * Runs a change in which the day of the invoice is the one a schedule gave it. The permission to type
     * the date of an invoice is the employee's own to hold; a schedule that was made by somebody who may
     * manage it dates what it makes with the day it was due, and the day must still be a day up to today
     * and not before the books start.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function datedBySchedule(callable $work): mixed
    {
        $before                = $this->datedBySchedule;
        $this->datedBySchedule = true;

        try {
            return $work();
        } finally {
            $this->datedBySchedule = $before;
        }
    }

    /**
     * Why the last change to a sale was refused by the books, or null when it was not.
     */
    public function accountingFailure(): ?Throwable
    {
        return $this->accountingFailure;
    }

    /**
     * The sales an employee who sees their own records only may not open, change or cancel are the
     * ones another employee made, and the ones that do not exist, which they cannot tell apart.
     */
    public function outsideRecordScope(int $saleId, ?int $viewerId = null): bool
    {
        $viewer = $viewerId ?? RecordScope::sessionEmployee();
        $scope  = $this->recordScope ??= new RecordScope($this->db);

        if (! $scope->ownOnly($viewer)) {
            return false;
        }

        $row = $this->db->table('sales')->select('employee_id')->where('sale_id', $saleId)->get()->getRow();

        return ! $scope->allows($viewer, $row?->employee_id);
    }

    private function viewerCut(?int $viewerId): ?int
    {
        return ($this->recordScope ??= new RecordScope($this->db))->limit($viewerId ?? RecordScope::sessionEmployee());
    }

    /**
     * Get sale info
     */
    public function get_info(int $saleId, ?int $viewerId = null): ResultInterface
    {
        $cut = $this->viewerCut($viewerId);
        $this->create_temp_table(['sale_id' => $saleId]);

        $decimals = totals_decimals();
        $salesTax = 'IFNULL(SUM(sales_items_taxes.sales_tax), 0)';
        $cashAdjustment = 'IFNULL(SUM(payments.sale_cash_adjustment), 0)';
        $salePrice = 'CASE WHEN sales_items.discount_type = ' . PERCENT
            . " THEN sales_items.quantity_purchased * sales_items.item_unit_price - ROUND(sales_items.quantity_purchased * sales_items.item_unit_price * sales_items.discount / 100, $decimals) "
            . 'ELSE sales_items.quantity_purchased * (sales_items.item_unit_price - sales_items.discount) END - ' . InvoiceDiscount::sqlShare('sales_items');

        $saleTotal = "ROUND(SUM($salePrice), $decimals) + $salesTax + $cashAdjustment";
        $saleTotal = $this->withHeaderCharges($saleTotal, $decimals);

        $sql = 'sales.sale_id AS sale_id,
                MAX(DATE(sales.sale_time)) AS sale_date,
                MAX(sales.sale_time) AS sale_time,
                MAX(sales.comment) AS comment,
                MAX(sales.sale_status) AS sale_status,
                MAX(sales.sale_type) AS sale_type,
                MAX(sales.delivered) AS delivered,
                MAX(sales.return_of_sale_id) AS return_of_sale_id,
                MAX(sales.invoice_number) AS invoice_number,
                MAX(sales.quote_number) AS quote_number,
                MAX(sales.employee_id) AS employee_id,
                MAX(sales.customer_id) AS customer_id,
                MAX(CONCAT(customer_p.first_name, " ", customer_p.last_name)) AS customer_name,
                MAX(customer_p.first_name) AS first_name,
                MAX(customer_p.last_name) AS last_name,
                MAX(customer_p.email) AS email,
                MAX(customer_p.comments) AS comments,
                MAX(IFnull(payments.sale_cash_adjustment, 0)) AS cash_adjustment,
                MAX(IFnull(payments.sale_cash_refund, 0)) AS cash_refund,
                ' . "
                $saleTotal AS amount_due,
                MAX(IFnull(payments.sale_payment_amount, 0)) AS amount_tendered,
                (MAX(payments.sale_payment_amount)) - ($saleTotal) AS change_due,
                " . '
                MAX(payments.payment_type) AS payment_type,
                MAX(payments.reference_code) AS reference_code';

        $builder = $this->db->table('sales_items AS sales_items');
        $builder->select($sql);

        $builder->join('sales AS sales', 'sales_items.sale_id = sales.sale_id', 'inner');
        $builder->join('people AS customer_p', 'sales.customer_id = customer_p.person_id', 'LEFT');
        $builder->join('customers AS customer', 'sales.customer_id = customer.person_id', 'LEFT');
        $builder->join('sales_payments_temp AS payments', 'sales.sale_id = payments.sale_id', 'LEFT OUTER');
        $builder->join(
            'sales_items_taxes_temp AS sales_items_taxes',
            'sales_items.sale_id = sales_items_taxes.sale_id AND sales_items.item_id = sales_items_taxes.item_id AND sales_items.line = sales_items_taxes.line',
            'LEFT OUTER'
        );

        $builder->where('sales.sale_id', $saleId);

        if ($cut !== null) {
            $builder->where('sales.employee_id', $cut);
        }

        $builder->groupBy('sales.sale_id, payments.reference_code');
        $builder->orderBy('sales.sale_time', 'asc');

        return $builder->get();
    }

    /**
     * Get number of rows for the takings (sales/manage) view
     */
    public function get_found_rows(?string $search, array $filters, ?int $viewerId = null): int
    {
        return $this->search($search, $filters, 0, 0, 'sales.sale_time', 'desc', true, $viewerId);
    }

    /**
     * Get the sales data for the takings (sales/manage) view
     */
    public function search(?string $search, array $filters, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'sales.sale_time', ?string $order = 'desc', ?bool $count_only = false, ?int $viewerId = null)
    {
        // Set default values
        if ($rows == null) $rows = 0;
        if ($limit_from == null) $limit_from = 0;
        if ($sort == null) $sort = 'sales.sale_time';
        if ($order == null) $order = 'desc';
        if ($count_only == null) $count_only = false;

        $config = config(OSPOS::class)->settings;
        $db_prefix = $this->db->getPrefix();
        $decimals = totals_decimals();

        // Only non-suspended records
        $where = 'sales.sale_status = 0 AND ';
        $where .= empty($config['date_or_time_format'])
            ? 'DATE(' . $db_prefix . 'sales.sale_time) BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date'])
            : 'sales.sale_time BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date']));

        $cut = $this->viewerCut($viewerId);

        if ($cut !== null) {
            $where .= ' AND sales.employee_id = ' . $cut;
        }

        $this->create_temp_table_sales_payments_data($where);

        $sale_price = 'CASE WHEN `sales_items`.`discount_type` = ' . PERCENT
            . " THEN `sales_items`.`quantity_purchased` * `sales_items`.`item_unit_price` - ROUND(`sales_items`.`quantity_purchased` * `sales_items`.`item_unit_price` * `sales_items`.`discount` / 100, $decimals) "
            . 'ELSE `sales_items`.`quantity_purchased` * (`sales_items`.`item_unit_price` - `sales_items`.`discount`) END - ' . InvoiceDiscount::sqlShare('`sales_items`');

        $sale_cost = 'SUM(`sales_items`.`item_cost_price` * `sales_items`.`quantity_purchased`)';

        $tax = 'IFNULL(SUM(`sales_items_taxes`.`tax`), 0)';
        $sales_tax = 'IFNULL(SUM(`sales_items_taxes`.`sales_tax`), 0)';
        $internal_tax = 'IFNULL(SUM(`sales_items_taxes`.`internal_tax`), 0)';
        $cash_adjustment = 'IFNULL(SUM(`payments`.`sale_cash_adjustment`), 0)';

        $sale_subtotal = "ROUND(SUM($sale_price), $decimals) - $internal_tax";
        $sale_total = "ROUND(SUM($sale_price), $decimals) + $sales_tax + $cash_adjustment";

        $this->create_temp_table_sales_items_taxes_data($where);

        $builder = $this->db->table('sales_items AS sales_items');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(DISTINCT `' . $db_prefix . 'sales`.`sale_id`) AS count');
        } else {
            $builder->select([
                '`' . $db_prefix . 'sales`.`sale_id` AS sale_id',
                'MAX(DATE(`' . $db_prefix . 'sales`.`sale_time`)) AS sale_date',
                'MAX(`' . $db_prefix . 'sales`.`sale_time`) AS sale_time',
                'MAX(`' . $db_prefix . 'sales`.`invoice_number`) AS invoice_number',
                'MAX(`' . $db_prefix . 'sales`.`quote_number`) AS quote_number',
                'SUM(`sales_items`.`quantity_purchased`) AS items_purchased',
                'MAX(CONCAT(`customer_p`.`first_name`, " ", `customer_p`.`last_name`)) AS customer_name',
                'MAX(`customer`.`company_name`) AS company_name',
                $sale_subtotal . ' AS subtotal',
                $tax . ' AS tax',
                $sale_total . ' AS total',
                $sale_cost . ' AS cost',
                '(' . $sale_total . ' - ' . $sale_cost . ') AS profit',
                $sale_total . ' AS amount_due',
                'MAX(`payments`.`sale_payment_amount`) AS amount_tendered',
                '(MAX(`payments`.`sale_payment_amount`)) - (' . $sale_total . ') AS change_due',
                'MAX(`payments`.`payment_type`) AS payment_type'
            ], false);
        }

        $builder->join('sales', '`sales_items`.`sale_id` = `' . $db_prefix . 'sales`.`sale_id`', 'inner');
        $builder->join('people AS customer_p', '`' . $db_prefix . 'sales`.`customer_id` = `customer_p`.`person_id`', 'LEFT');
        $builder->join('customers AS customer', '`' . $db_prefix . 'sales`.`customer_id` = `customer`.`person_id`', 'LEFT');
        $builder->join('sales_search_payments_temp AS payments', '`' . $db_prefix . 'sales`.`sale_id` = `payments`.`sale_id`', 'LEFT OUTER');
        $builder->join(
            'sales_items_taxes_temp AS sales_items_taxes',
            'sales_items.sale_id = sales_items_taxes.sale_id AND sales_items.item_id = sales_items_taxes.item_id AND sales_items.line = sales_items_taxes.line',
            'LEFT OUTER'
        );

        $builder->where($where);

        $this->add_filters_to_query($search, $filters, $builder);

        // get_found_rows
        if ($count_only) {
            return $builder->get()->getRow()->count;
        }

        $builder->groupBy('sales.sale_id');

        // Order by sale time by default
        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Get the payment summary for the takings (sales/manage) view
     */
    public function getPaymentsSummary(?string $search, array $filters, ?int $viewerId = null): array
    {
        $cut    = $this->viewerCut($viewerId);
        $config = config(OSPOS::class)->settings;

        // Get payment summary
        $builder = $this->db->table('sales AS sales');

        if ($cut !== null) {
            $builder->where('sales.employee_id', $cut);
        }

        $builder->select('payment_type, COUNT(payment_amount) AS count, SUM(payment_amount - cash_refund) AS payment_amount');
        $builder->join('sales_payments', 'sales_payments.sale_id = sales.sale_id');
        $builder->join('people AS customer_p', 'sales.customer_id = customer_p.person_id', 'LEFT');
        $builder->join('customers AS customer', 'sales.customer_id = customer.person_id', 'LEFT');

        // TODO: This needs to be replaced with Ternary notation
        if (empty($config['date_or_time_format'])) {    // TODO: duplicated code.  We should think about refactoring out a method.
            $builder->where('DATE(sales.sale_time) BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        } else {
            $builder->where('sales.sale_time BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        if (!empty($search)) {    // TODO: duplicated code.  We should think about refactoring out a method.
            if ($filters['is_valid_receipt']) {
                $pieces = explode(' ', $search);
                $builder->where('sales.sale_id', $pieces[1]);
            } else {
                $builder->groupStart();
                $builder->like('customer_p.last_name', $search);    // Customer last name
                $builder->orLike('customer_p.first_name', $search);    // Customer first name
                $builder->orLike('CONCAT(customer_p.first_name, " ", customer_p.last_name)', $search);    // Customer first and last name
                $builder->orLike('customer.company_name', $search);    // Customer company name
                if (ctype_digit($search)) {
                    $builder->orWhere('sales.sale_id', $search);    // Sale ID
                }
                $builder->groupEnd();
            }
        }

        // TODO: This needs to be converted to a switch statement
        if ($filters['sale_type'] == 'sales') {    // TODO: we need to think about refactoring this block to a switch statement.
            $builder->where('sales.sale_status = ' . COMPLETED . ' AND payment_amount > 0');
        } elseif ($filters['sale_type'] == 'quotes') {
            $builder->where('sales.sale_status = ' . SUSPENDED . ' AND sales.quote_number IS NOT NULL');
        } elseif ($filters['sale_type'] == 'returns') {
            $builder->where('sales.sale_status = ' . COMPLETED . ' AND payment_amount < 0');
        } elseif ($filters['sale_type'] == 'all') {
            $builder->where('sales.sale_status = ' . COMPLETED);
        }

        // TODO: Avoid the double negatives
        if ($filters['only_invoices']) {
            $builder->where('invoice_number IS NOT NULL');
        }

        if ($filters['only_cash']) {
            $builder->like('payment_type', lang('Sales.cash'));
        }

        if ($filters['only_due']) {
            $builder->like('payment_type', lang('Sales.due'));
        }

        if ($filters['only_check']) {
            $builder->like('payment_type', lang('Sales.check'));
        }

        if ($filters['only_creditcard']) {
            $builder->like('payment_type', lang('Sales.credit'));
        }

        if ($filters['only_debit']) {
            $builder->like('payment_type', lang('Sales.debit'));
        }

        if ($filters['only_bank_transfer']) {
            $builder->like('payment_type', lang('Sales.bank_transfer'));
        }

        if ($filters['only_wallet']) {
            $builder->like('payment_type', lang('Sales.wallet'));
        }

        $builder->groupBy('payment_type');

        $payments = $builder->get()->getResultArray();

        // Consider Gift Card as only one type of payment and do not show "Gift Card: 1, Gift Card: 2, etc." in the total
        $gift_card_count = 0;
        $gift_card_amount = 0;

        foreach ($payments as $key => $payment) {
            if (strstr($payment['payment_type'], lang('Sales.giftcard'))) {
                $gift_card_count  += $payment['count'];
                $gift_card_amount += $payment['payment_amount'];

                // Remove the "Gift Card: 1", "Gift Card: 2", etc. payment string
                unset($payments[$key]);
            }
        }

        if ($gift_card_count > 0) {
            $payments[] = ['payment_type' => lang('Sales.giftcard'), 'count' => $gift_card_count, 'payment_amount' => $gift_card_amount];
        }

        return $payments;
    }

    /**
     * Gets total of rows
     */
    public function get_total_rows(): int
    {
        $builder = $this->db->table('sales');

        return $builder->countAllResults();
    }

    /**
     * Gets search suggestions
     */
    public function get_search_suggestions(?string $search, int $limit = 25): array    // TODO: $limit is never used.
    {
        $suggestions = [];

        if (!$this->isValidReceipt($search)) {
            $builder = $this->db->table('sales');
            $builder->distinct()->select('first_name, last_name');
            $builder->join('people', 'people.person_id = sales.customer_id');
            $builder->like('last_name', $search);
            $builder->orLike('first_name', $search);
            $builder->orLike('CONCAT(first_name, " ", last_name)', $search);
            $builder->orLike('company_name', $search);
            $builder->orderBy('last_name', 'asc');

            foreach ($builder->get()->getResultArray() as $result) {
                $suggestions[] = ['label' => $result['first_name'] . ' ' . $result['last_name']];
            }
        } else {
            $suggestions[] = ['label' => $search];
        }

        return $suggestions;
    }

    /**
     * Gets total of invoice rows
     */
    public function get_invoice_count(): int
    {
        $builder = $this->db->table('sales');
        $builder->where('invoice_number IS NOT NULL');

        return $builder->countAllResults();
    }

    /**
     * Gets sale by invoice number
     */
    public function get_sale_by_invoice_number(string $invoice_number): ResultInterface
    {
        $builder = $this->db->table('sales');
        $builder->where('invoice_number', $invoice_number);

        return $builder->get();
    }

    /**
     * @param string $year
     * @param int $start_from
     * @return int
     */
    public function get_invoice_number_for_year(string $year = '', int $start_from = 0): int
    {
        return $this->get_number_for_year('invoice_number', $year, $start_from);
    }

    /**
     * @param string $year
     * @param int $start_from
     * @return int
     */
    public function get_quote_number_for_year(string $year = '', int $start_from = 0): int
    {
        return $this->get_number_for_year('quote_number', $year, $start_from);
    }

    /**
     * Gets invoice number by year
     */
    private function get_number_for_year(string $field, string $year = '', int $start_from = 0): int
    {
        $year = $year == '' ? date('Y') : $year;

        $builder = $this->db->table('sales');
        $builder->select('COUNT( 1 ) AS number_year');
        $builder->where('DATE_FORMAT(sale_time, "%Y" ) = ', $year);
        $builder->where("$field IS NOT NULL");
        $result = $builder->get()->getRowArray();

        return ($start_from + $result['number_year']);
    }

    /**
     * Checks if valid receipt
     */
    public function isValidReceipt(string|null &$receiptSaleId): bool    // TODO: like the others, maybe this should be an array rather than a delimited string... either that or the parameter name needs to be changed. $receipt_sale_id implies that it's an int.
    {
        $config = config(OSPOS::class)->settings;

        if (!empty($receiptSaleId)) {
            // POS #
            $pieces = explode(' ', trim($receiptSaleId));

            if (count($pieces) == 2 && strtoupper($pieces[0]) === 'POS' && ctype_digit($pieces[1])) {
                return $this->exists((int) $pieces[1]) && ! $this->outsideRecordScope((int) $pieces[1]);
            } elseif ($config['invoice_enable']) {
                $saleInfo = $this->get_sale_by_invoice_number($receiptSaleId);

                if ($saleInfo->getNumRows() > 0 && ! $this->outsideRecordScope((int) $saleInfo->getRow()->sale_id)) {
                    $receiptSaleId = 'POS ' . $saleInfo->getRow()->sale_id;

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks if sale exists
     */
    public function exists(int $sale_id): bool
    {
        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * The branch a sale belongs to, or null when there is no such sale
     */
    public function getBranchId(int $saleId): ?int
    {
        $row = $this->db->table('sales')->select('branch_id')->where('sale_id', $saleId)->get()->getRow();

        return $row === null ? null : (int) $row->branch_id;
    }

    /**
     * The few facts of a sale that decide whether a return can be taken from it, or null when
     * there is no such sale.
     */
    public function getSummary(int $saleId): ?object
    {
        return $this->db->table('sales')->select('sale_id, sale_status, sale_type, delivered, customer_id, branch_id')->where('sale_id', $saleId)->get()->getRow();
    }

    /**
     * The day typed on a sale, its payment terms and its due date, each empty when the sale has none.
     *
     * @return array{invoice_date: ?string, payment_terms_days: ?int, due_date: ?string}
     */
    public function getInvoiceTiming(int $saleId): array
    {
        $row = $this->db->table('sales')->select('invoice_date, payment_terms_days, due_date')->where('sale_id', $saleId)->get()->getRow();

        return [
            'invoice_date'       => $row === null || $row->invoice_date === null ? null : (string) $row->invoice_date,
            'payment_terms_days' => $row === null || $row->payment_terms_days === null ? null : (int) $row->payment_terms_days,
            'due_date'           => $row === null || $row->due_date === null ? null : (string) $row->due_date,
        ];
    }

    /**
     * Whether the goods of a sale have left. A sale that does not exist counts as delivered.
     */
    public function isDelivered(int $saleId): bool
    {
        $row = $this->db->table('sales')->select('delivered')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || (int) $row->delivered === 1;
    }

    /**
     * The sale a return takes its goods back from, or null when it names none.
     */
    public function getReturnOrigin(int $saleId): ?int
    {
        $row = $this->db->table('sales')->select('return_of_sale_id')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || $row->return_of_sale_id === null ? null : (int) $row->return_of_sale_id;
    }

    /**
     * Whether a return was issued as a credit note or as a refund invoice. A return that was saved
     * before there were two documents, and anything that is not a return, is a credit note.
     */
    public function getReturnDocument(int $saleId): ReturnDocument
    {
        $row = $this->db->table('sales')->select('return_document')->where('sale_id', $saleId)->get()->getRow();

        return ReturnDocument::fromStored($row?->return_document);
    }

    /**
     * How much of each item of a sale has been taken back by completed returns, by item id.
     *
     * @return array<int, float>
     */
    public function getReturnedQuantities(int $saleId): array
    {
        $rows = $this->db->query(
            'SELECT i.item_id, SUM(ABS(i.quantity_purchased)) AS returned
             FROM ' . $this->db->prefixTable('sales') . ' s
             JOIN ' . $this->db->prefixTable('sales_items') . ' i ON i.sale_id = s.sale_id
             WHERE s.return_of_sale_id = ? AND s.sale_status = ? AND s.sale_type = ?
             GROUP BY i.item_id',
            [$saleId, COMPLETED, SALE_TYPE_RETURN],
        )->getResult();

        $returned = [];

        foreach ($rows as $row) {
            $returned[(int) $row->item_id] = (float) $row->returned;
        }

        return $returned;
    }

    /**
     * The branches of the stock locations the lines of a cart sell from, without repeats
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
     * A sale belongs to the branch of the stock location it sells from
     */
    private function branchIdOfItems(array $items): int
    {
        return $this->branchIdsOfItems($items)[0] ?? (new BranchGuard($this->db))->companyDefaultBranchId();
    }

    /**
     * Update sale. When accounting is on, a sale that has been posted keeps its customer and
     * its date, and every payment that changes is posted again with the change, all in the
     * transaction of the update.
     */
    public function update($sale_id = null, $sale_data = null): bool
    {
        if ($this->outsideRecordScope((int) $sale_id, $this->employeeOfEdit($sale_data))) {
            return false;
        }

        try {
            return (new CustomerLock($this->db))->run($this->customerOf((int) $sale_id), fn (): bool => $this->updateLocked($sale_id, $sale_data));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::CustomerBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return false;
        }
    }

    private function updateLocked($saleId, $saleData): bool
    {
        $this->accountingFailure = null;

        $accounting = (new AccountingSettings($this->db))->isEnabled() ? new SalePosting($this->db) : null;
        $updateData = $this->keepNumbersOfTheSequences((int) $saleId, $saleData);
        unset($updateData['payments']);

        $typedDate  = $updateData['invoice_date'] ?? null;
        $hasDate    = array_key_exists('invoice_date', $updateData);
        $typedTerms = $updateData['payment_terms_days'] ?? null;
        $hasTerms   = array_key_exists('payment_terms_days', $updateData);

        unset($updateData['invoice_date'], $updateData['payment_terms_days'], $updateData['due_date'], $updateData['changed_by']);

        $customerId = null;
        $openBefore = null;

        $this->db->transStart();
        $this->db->query('SELECT sale_id FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$saleId]);

        try {
            (new QuoteLifecycle($this->db))->assertUnlocked((int) $saleId);
        } catch (AccountingException $refusal) {
            $this->db->transRollback();
            $this->accountingFailure = $refusal;

            return false;
        }

        try {
            if ($accounting !== null) {
                $changes = [];

                if (array_key_exists('customer_id', $updateData)) {
                    $changes['customer_id'] = $updateData['customer_id'] === null ? null : (int) $updateData['customer_id'];
                }

                if (isset($updateData['sale_time'])) {
                    $changes['date'] = substr((string) $updateData['sale_time'], 0, 10);
                }

                if ($hasDate) {
                    $changes['invoice_date'] = (string) $typedDate;
                }

                foreach (['currency_code', 'currency_rate'] as $column) {
                    if (array_key_exists($column, $updateData)) {
                        $changes[$column] = $updateData[$column] === null ? null : (string) $updateData[$column];
                    }
                }

                $accounting->assertEditable((int) $saleId, $changes);

                if ($hasTerms) {
                    $updateData += $this->paymentTermsChange((int) $saleId, $typedTerms, $this->employeeOfEdit($saleData));
                }

                $customerId = $this->customerOf((int) $saleId);
                $credit     = new CreditLimit($this->db);

                if ($customerId !== null && ! empty($saleData['payments']) && $credit->watches($customerId)) {
                    $openBefore = $credit->openOf($customerId, (int) $saleId);
                }
            }

            $builder = $this->db->table('sales');
            $builder->where('sale_id', $saleId);
            $success = $updateData === [] ? true : $builder->update($updateData);

            // Touch payment only if update sale is successful and there is a payments object otherwise the result would be to delete all the payments associated to the sale
            if ($success && !empty($saleData['payments'])) {
                $builder = $this->db->table('sales_payments');
                $amountsEdited = false;
                $isReturn = (int) ($this->db->table('sales')->select('sale_type')->where('sale_id', $saleId)->get()->getRow()->sale_type ?? SALE_TYPE_POS) === SALE_TYPE_RETURN;

                // Add new payments
                foreach ($saleData['payments'] as $payment) {
                    $paymentId = $payment['payment_id'];
                    $paymentType = $payment['payment_type'];
                    $paymentAmount = $payment['payment_amount'];
                    $cashRefund = $payment['cash_refund'];
                    $cashAdjustment = $payment['cash_adjustment'];
                    $employeeId = $payment['employee_id'];

                    if ($paymentId == NEW_ENTRY && $paymentAmount != 0) {
                        $amountsEdited = true;
                        $this->assertAmountCoversChange($payment, $isReturn);
                        [$treasuryId, $treasuryBranchId] = $this->treasuryOfPayment($payment, $employeeId === null ? null : (int) $employeeId, (int) $this->getBranchId((int) $saleId));

                        // Add a new payment transaction
                        $salesPaymentsData = [
                            'sale_id'            => $saleId,
                            'payment_type'       => $paymentType,
                            'payment_amount'     => $paymentAmount,
                            'payment_time'       => date('Y-m-d H:i:s'),
                            'cash_refund'        => $cashRefund,
                            'cash_adjustment'    => $cashAdjustment,
                            'employee_id'        => $employeeId,
                            'reference_code'     => $payment['reference_code'] ?? '',
                            'payment_status'     => PaymentStatus::fromStored($payment['payment_status'] ?? null)->value,
                            'treasury_id'        => $treasuryId,
                            'treasury_branch_id' => $treasuryBranchId,
                        ];
                        $success      = $builder->insert($salesPaymentsData);
                        $newPaymentId = (int) $this->db->insertID();

                        if ($success && $this->isPaymentDocument($payment) && $this->getSaleStatus((int) $saleId) == COMPLETED) {
                            (new DocumentNumbering($this->db))->assign(DocumentType::CustomerPayment, 'sales_payments', $newPaymentId, (int) $this->getBranchId((int) $saleId));
                        }
                    } elseif ($paymentId != NEW_ENTRY) {
                        $stored = $this->db->table('sales_payments')->select('sale_id, payment_amount, cash_refund')->where('payment_id', $paymentId)->get()->getRow();

                        if ($stored === null || (int) $stored->sale_id !== (int) $saleId) {
                            throw new AccountingException(AccountingError::SalePaymentNotOnSale, 'The payment does not belong to this sale.', ['sale_id' => (int) $saleId]);
                        }

                        if ($paymentAmount == 0 || bccomp((string) $stored->payment_amount, (string) $paymentAmount, 4) !== 0 || bccomp((string) $stored->cash_refund, (string) $cashRefund, 4) !== 0) {
                            $amountsEdited = true;
                        }

                        if ($paymentAmount != 0) {
                            $this->assertAmountCoversChange($payment, $isReturn);

                            // Update existing payment transactions (payment_type only)
                            $salesPaymentsData = [
                                'payment_type'    => $paymentType,
                                'payment_amount'  => $paymentAmount,
                                'cash_refund'     => $cashRefund,
                                'cash_adjustment' => $cashAdjustment
                            ];

                            if (isset($payment['payment_status']) && PaymentStatus::tryFrom((string) $payment['payment_status']) !== null) {
                                $salesPaymentsData['payment_status'] = $payment['payment_status'];
                            }

                            [$treasuryId, $treasuryBranchId] = $this->treasuryAfterChange($payment, (int) $paymentId, $employeeId === null ? null : (int) $employeeId, (int) $this->getBranchId((int) $saleId));

                            if (array_key_exists('treasury_id', $payment)) {
                                $salesPaymentsData['treasury_id']        = $treasuryId;
                                $salesPaymentsData['treasury_branch_id'] = $treasuryBranchId;
                            }

                            $builder->where(['payment_id' => $paymentId, 'sale_id' => $saleId]);
                            $success = $builder->update($salesPaymentsData);
                        } else {
                            // Remove existing payment transactions with a payment amount of zero
                            $accounting?->reversePayment((int) $paymentId, $employeeId === null ? null : (int) $employeeId);
                            $success = $builder->delete(['payment_id' => $paymentId, 'sale_id' => $saleId]);
                        }
                    }
                }

                if ($amountsEdited && ! $isReturn) {
                    $this->assertChangeStaysCovered((int) $saleId, $this->employeeOfEdit($saleData));
                }

                $accounting?->syncPayments((int) $saleId, isset($employeeId) ? (int) $employeeId : null);

                if ($openBefore !== null) {
                    $creditRefusal = $this->creditRefusalOf((int) $saleId, $customerId, (int) $this->employeeOfEdit($saleData), (int) $this->getBranchId((int) $saleId), $openBefore);

                    if ($creditRefusal !== null) {
                        throw $creditRefusal;
                    }
                }
            }
        } catch (Throwable $exception) {
            $this->db->transRollback();

            $failure = $this->asAccountingFailure($exception, (int) $saleId);

            $this->recordAccountingFailure((int) $saleId, $failure, $this->employeeOfPayments($saleData), $this->getBranchId((int) $saleId));

            if ($failure instanceof AccountingException && in_array($failure->error, [AccountingError::CreditLimitExceeded, AccountingError::CreditPeriodExceeded], true)) {
                (new CreditLimit($this->db))->recordRefusal((int) $customerId, (int) $this->getBranchId((int) $saleId), $this->employeeOfEdit($saleData), $failure);
            }

            return false;
        }

        $this->db->transComplete();

        return (bool) $success && $this->db->transStatus();
    }

    /**
     * The change handed back to the customer is part of the payment that took it: a payment cannot be
     * edited to less than the change it gave.
     *
     * @param array<string, mixed> $payment
     *
     * @throws AccountingException
     */
    private function assertAmountCoversChange(array $payment, bool $isReturn): void
    {
        if ($isReturn || ! empty($payment['cash_adjustment'])) {
            return;
        }

        $change = (string) ($payment['cash_refund'] ?? 0);

        if (bccomp($change, '0', 4) > 0 && bccomp((string) $payment['payment_amount'], $change, 4) < 0) {
            throw new AccountingException(AccountingError::SalePaymentBelowChange, 'A payment cannot be less than the change already given back.', ['payment_id' => (int) $payment['payment_id']]);
        }
    }

    /**
     * After payments of a sale were edited, the change given back may not be more than what was paid above
     * what the sale comes to, as the register gives it: otherwise the sale would stand paid for less than
     * its payments show and the customer would be left owing or crediting what was never due.
     *
     * @throws AccountingException
     */
    private function assertChangeStaysCovered(int $saleId, ?int $viewerId): void
    {
        foreach (['sales_items_temp', 'sales_payments_temp', 'sales_items_taxes_temp'] as $table) {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->db->prefixTable($table));
        }

        $sale = $this->get_info($saleId, $viewerId)->getRowArray();

        if ($sale === [] || $sale === null) {
            return;
        }

        $decimals  = max(totals_decimals(), 0);
        $paidAbove = bcsub((string) $sale['amount_tendered'], (string) $sale['amount_due'], 4);
        $covered   = bccomp($paidAbove, '0', 4) > 0 ? $paidAbove : '0';
        $excess    = bcsub((string) $sale['cash_refund'], $covered, 4);

        if (bccomp($excess, bcdiv('1', bcpow('10', (string) $decimals, 0), 4), 4) > 0) {
            throw new AccountingException(AccountingError::SalePaymentBelowChange, 'The change given back is more than what was paid above the total of the sale.', ['sale_id' => $saleId]);
        }
    }

    /**
     * The treasury a payment goes into: the one it names, or else the treasury its employee works
     * from when it belongs to the branch of the sale. A payment that settles nothing in cash, or
     * has no such treasury, goes to the account of its type as it always did. A payment that is
     * refused by the scope of the treasury is refused with the sale.
     *
     * The treasury may be one of another branch for an employee who works in both, which the second
     * value then names.
     *
     * @param array<string, mixed> $payment
     *
     * @return array{?int, ?int} The treasury and the branch it belongs to when that is not the branch of the sale.
     */
    private function treasuryOfPayment(array $payment, ?int $employeeId, int $branchId): array
    {
        $type = PaymentType::fromLabel((string) ($payment['payment_type'] ?? ''));

        if ($type === null || ! $type->usesTreasury() || ! empty($payment['cash_adjustment'])) {
            return [null, null];
        }

        return (new TreasuryService($this->db))->placeOfPayment((int) ($payment['treasury_id'] ?? 0), $type, $this->directionOf($payment), $employeeId, $branchId);
    }

    /**
     * The treasury an existing payment is moved to, or none for one that goes where its type does.
     * A change of the treasury, of the way of payment or of the amount asks the scope of the
     * treasuries involved, whether or not the edit names a treasury.
     *
     * @param array<string, mixed> $payment
     *
     * @return array{?int, ?int} The treasury and the branch it belongs to when that is not the branch of the sale.
     */
    private function treasuryAfterChange(array $payment, int $paymentId, ?int $employeeId, int $branchId): array
    {
        $treasuries     = new TreasuryService($this->db);
        $stored         = $this->db->table('sales_payments')->select('treasury_id, treasury_branch_id, payment_type, payment_amount, cash_refund')->where('payment_id', $paymentId)->get()->getRow();
        $storedId       = $stored === null || $stored->treasury_id === null ? 0 : (int) $stored->treasury_id;
        $named          = array_key_exists('treasury_id', $payment);
        $treasuryId     = $named ? (int) $payment['treasury_id'] : $storedId;
        $treasuryBranch = $stored === null || $stored->treasury_branch_id === null ? null : (int) $stored->treasury_branch_id;

        if ($named && $treasuryId > 0 && $treasuryId !== $storedId) {
            $treasuryBranch = $treasuries->otherBranchOf($treasuryId, $branchId, $employeeId);
        }

        $type = PaymentType::fromLabel((string) ($payment['payment_type'] ?? ''));

        if ($type !== null && $type->usesTreasury() && $stored !== null) {
            $net = (float) ($payment['payment_amount'] ?? 0) - (float) ($payment['cash_refund'] ?? 0);

            $treasuries->assertMayChangePayment(
                $treasuryId > 0 ? $treasuryId : null,
                $storedId > 0 ? $storedId : null,
                abs($net - ((float) $stored->payment_amount - (float) $stored->cash_refund)) > 0.00001,
                $type,
                $net < 0 ? TreasuryScopes::WITHDRAW : TreasuryScopes::DEPOSIT,
                $employeeId,
                PaymentType::fromLabel((string) $stored->payment_type),
            );
        }

        return $treasuryId > 0 ? [$treasuryId, $treasuryBranch] : [null, null];
    }

    /**
     * Money that comes into the treasury is a deposit, and a payment that gives money back to the
     * customer is a withdrawal.
     *
     * @param array<string, mixed> $payment
     */
    private function directionOf(array $payment): string
    {
        return (float) ($payment['payment_amount'] ?? 0) - (float) ($payment['cash_refund'] ?? 0) < 0 ? TreasuryScopes::WITHDRAW : TreasuryScopes::DEPOSIT;
    }

    /**
     * The customer a sale is stored for, read before its transaction starts so that the lock of
     * the customer is taken before the transaction reads anything.
     */
    private function customerOf(int $saleId): ?int
    {
        $row = $this->db->table('sales')->select('customer_id')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || $row->customer_id === null ? null : (int) $row->customer_id;
    }

    /**
     * The payment terms of an issued invoice and the due date they give from the day the invoice is
     * dated, which are the only part of its timing that can change after it is issued, and change
     * nothing in its journals. Nothing is returned when the terms are not new or the sale is not an
     * issued invoice.
     *
     * @return array{payment_terms_days?: ?int, due_date?: ?string}
     *
     * @throws AccountingException
     */
    private function paymentTermsChange(int $saleId, int|string|null $typed, ?int $employeeId): array
    {
        $terms = InvoiceTiming::terms($typed);
        $sale  = $this->db->table('sales')->select('sale_type, sale_status, sale_time, invoice_date, payment_terms_days, due_date, branch_id')->where('sale_id', $saleId)->get()->getRow();

        if ($sale === null || (int) $sale->sale_type !== SALE_TYPE_INVOICE || (int) $sale->sale_status !== COMPLETED) {
            return [];
        }

        $before = $sale->payment_terms_days === null ? null : (int) $sale->payment_terms_days;

        if ($before === $terms) {
            return [];
        }

        $date = $sale->invoice_date === null ? substr((string) $sale->sale_time, 0, 10) : (string) $sale->invoice_date;
        $due  = $terms === null ? null : InvoiceTiming::dueDate($date, $terms);

        (new AuditLog($this->db))->write('sale', $saleId, 'payment_terms_changed', $employeeId, JournalOrigin::User, ['from' => $before, 'to' => $terms, 'due_date' => $due ?? $date], (int) $sale->branch_id);

        return ['payment_terms_days' => $terms, 'due_date' => $due];
    }

    private function employeeOfEdit(?array $saleData): ?int
    {
        $employeeId = $saleData['changed_by'] ?? $saleData['payments'][0]['employee_id'] ?? null;

        return $employeeId === null || $employeeId === '' ? null : (int) $employeeId;
    }

    /**
     * The employee who entered the payments of an update, for the record of a failure.
     */
    private function employeeOfPayments(?array $saleData): ?int
    {
        $employeeId = $saleData['payments'][0]['employee_id'] ?? null;

        return $employeeId === null ? null : (int) $employeeId;
    }

    /**
     * Save the sale information after the sales is complete but before the final document is printed
     * The sales_taxes variable needs to be initialized to an empty array before calling
     * @throws ReflectionException
     */
    public function save_value(
        int     $saleId,
        string  &$saleStatus,
        array   &$items,
        int     $customerId,
        int     $employeeId,
        string  $comment,
        ?string $invoiceNumber,
        ?string $workOrderNumber,
        ?string $quoteNumber,
        int     $saleType,
        ?array  $payments,
        ?int    $dinnerTableId,
        ?array  &$salesTaxes,
        bool    $delivered = true,
        ?int    $returnOfSaleId = null,
        ?array  $invoiceDiscount = null,
        ReturnDocument $returnDocument = ReturnDocument::CreditNote,
        ?string $invoiceDate = null,
        int|string|null $paymentTerms = null,
        ?QuoteStatus $quoteStatus = null,
        ?int $quoteSourceId = null,
        ?string $draftFingerprint = null,
        ?string $returnOverrideReason = null,
        ?HeaderCharges $headerCharges = null,
        ?int $priceListId = null,
        ?int $orderSourceId = null,
    ): int {    // TODO: this method returns the sale_id but the override is expecting it to return a bool. The signature needs to be reworked.  Generally when there are more than 3 maybe 4 parameters, there's a good chance that an object needs to be passed rather than so many params.
        if ($saleId !== NEW_ENTRY && $this->outsideRecordScope($saleId, $employeeId)) {
            return NEW_ENTRY;
        }

        if ($saleType === SALE_TYPE_RETURN && $returnOfSaleId !== null && $this->outsideRecordScope($returnOfSaleId, $employeeId)) {
            return NEW_ENTRY;
        }

        $this->savingCurrency = $this->currencyChoice;
        $this->currencyChoice = null;

        try {
            return (new CustomerLock($this->db))->run(
                $customerId,
                function () use (&$saleStatus, &$items, &$salesTaxes, $saleId, $customerId, $employeeId, $comment, $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $payments, $dinnerTableId, $delivered, $returnOfSaleId, $invoiceDiscount, $returnDocument, $invoiceDate, $paymentTerms, $quoteStatus, $quoteSourceId, $draftFingerprint, $returnOverrideReason, $headerCharges, $priceListId, $orderSourceId): int {
                    return $this->saveValueLocked($saleId, $saleStatus, $items, $customerId, $employeeId, $comment, $invoiceNumber, $workOrderNumber, $quoteNumber, $saleType, $payments, $dinnerTableId, $salesTaxes, $delivered, $returnOfSaleId, $invoiceDiscount, $returnDocument, $invoiceDate, $paymentTerms, $quoteStatus, $quoteSourceId, $draftFingerprint, $returnOverrideReason, $headerCharges, $priceListId, $orderSourceId);
                },
            );
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::CustomerBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return ACCOUNTING_FAILED;
        } finally {
            $this->savingCurrency = null;
        }
    }

    private function saveValueLocked(
        int     $saleId,
        string  &$saleStatus,
        array   &$items,
        int     $customerId,
        int     $employeeId,
        string  $comment,
        ?string $invoiceNumber,
        ?string $workOrderNumber,
        ?string $quoteNumber,
        int     $saleType,
        ?array  $payments,
        ?int    $dinnerTableId,
        ?array  &$salesTaxes,
        bool    $delivered,
        ?int    $returnOfSaleId,
        ?array  $invoiceDiscount,
        ReturnDocument $returnDocument,
        ?string $invoiceDate,
        int|string|null $paymentTerms,
        ?QuoteStatus $quoteStatus,
        ?int $quoteSourceId,
        ?string $draftFingerprint,
        ?string $returnOverrideReason,
        ?HeaderCharges $headerCharges,
        ?int $priceListId,
        ?int $orderSourceId,
    ): int {
        $config = config(OSPOS::class)->settings;
        $attribute = model(Attribute::class);
        $customer = model(Customer::class);
        $giftcard = model(Giftcard::class);
        $inventory = model('Inventory');
        $item = model(Item::class);

        $itemQuantity = model(Item_quantity::class);

        if (count($items) == 0) {    // TODO: ===
            return -1;    // TODO: Replace -1 with a constant
        }

        $this->accountingFailure = null;
        $this->invoiceDiscountProblem = null;

        $taxRefusal = $this->taxOneRefusal($saleType, $saleStatus, $customerId, $items, $salesTaxes);

        if ($taxRefusal !== null) {
            $this->accountingFailure = $taxRefusal;

            return ACCOUNTING_FAILED;
        }

        try {
            $unitColumns = $this->unitColumnsOf($items);
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        // Only an invoice can be issued before its goods leave
        $delivered = $delivered || $saleType !== SALE_TYPE_INVOICE;

        $tracking = new StockTracking($this->db);

        if ($saleStatus == COMPLETED && $saleType === SALE_TYPE_INVOICE && ! $delivered) {
            try {
                $tracking->requireUntracked(array_map(static fn (array $line): int => (int) $line['item_id'], $items));
            } catch (AccountingException $refusal) {
                $this->accountingFailure = $refusal;

                return ACCOUNTING_FAILED;
            }
        }

        try {
            $timing = $this->timingOf($saleId, $saleType, $saleStatus, $invoiceDate, $paymentTerms, $employeeId);
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        try {
            $overrideReason = $this->returnOverrideOf($saleType, (int) $saleStatus, $returnOfSaleId, $items, $returnOverrideReason, $employeeId);
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        try {
            $currencyColumns = $this->currencyColumns($saleType, $returnOfSaleId, $timing['invoice_date'] ?? date('Y-m-d'), $employeeId);

            if ($currencyColumns['currency_code'] !== null && $this->paysWithStoredValue($payments ?? [])) {
                throw new AccountingException(AccountingError::StoredValueInForeignCurrency, 'A gift card or reward points cannot pay a sale in a foreign currency: their value is kept in the base currency.');
            }
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        $storedDate = $saleId === NEW_ENTRY ? null : $this->storedInvoiceDate($saleId);

        $salesData = [
            'sale_time'         => date('Y-m-d H:i:s'),
            'customer_id'       => $customer->exists($customerId) ? $customerId : null,
            'employee_id'       => $employeeId,
            'comment'           => $comment,
            'sale_status'       => $saleStatus,
            'invoice_number'    => $invoiceNumber,
            'quote_number'      => $quoteNumber,
            'work_order_number' => $workOrderNumber,
            'dinner_table_id'   => $dinnerTableId,
            'sale_type'         => $saleType,
            'branch_id'         => $this->branchIdOfItems($items),
            'delivered'         => $delivered ? 1 : 0,
            'return_of_sale_id' => $saleType === SALE_TYPE_RETURN ? $returnOfSaleId : null,
            'return_document'   => $saleType === SALE_TYPE_RETURN ? $returnDocument->storedValue() : null,
            'adjustment_label'     => null,
            'adjustment_amount'    => null,
            'shipping_amount'      => null,
            'shipping_options'     => null,
            'shipping_company_id'  => null,
            'shipping_tax_option'  => null,
            'shipping_tax_percent' => null,
            'shipping_tax_amount'  => null,
            'price_list_id'        => $saleType === SALE_TYPE_RETURN ? null : $priceListId,
            ...$currencyColumns,
            ...$timing,
        ];

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        try {
            $stored = $saleId === NEW_ENTRY ? null : $this->lockStoredSale($saleId, $draftFingerprint);
        } catch (AccountingException $refusal) {
            $this->db->transRollback();
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        $quoteStatusChange = null;

        if ($saleType === SALE_TYPE_QUOTE) {
            $storedStatus = $stored !== null && (int) $stored->sale_type === SALE_TYPE_QUOTE ? QuoteStatus::fromStored($stored->quote_status) : null;
            $savedStatus  = QuoteStatus::afterSave($storedStatus, $quoteStatus ?? QuoteStatus::Open);

            $salesData['quote_status'] = $savedStatus->value;

            if ($storedStatus !== null && $storedStatus !== $savedStatus) {
                $quoteStatusChange = [$storedStatus, $savedStatus];
            }
        } else {
            $salesData['quote_status'] = null;
        }

        if ($quoteSourceId !== null) {
            $salesData['source_quote_id'] = $quoteSourceId;
        }

        if ($salesData['return_of_sale_id'] !== null) {
            // The sale a return comes from is locked while the return is saved, so that two returns of it never run side by side
            $locked = $this->db->query('SELECT sale_status, sale_type FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$salesData['return_of_sale_id']]);
            $origin = $locked === false ? null : $locked->getRow();

            // A return staged while its sale stood is refused if the sale was cancelled meanwhile: the cancel already put the goods and the books back
            if ($saleStatus == COMPLETED && ($origin === null || (int) $origin->sale_status !== COMPLETED || (int) $origin->sale_type === SALE_TYPE_RETURN)) {
                $this->db->transRollback();
                $this->accountingFailure = new PostingException(PostingError::ReturnOriginInvalid, 'A return can only be taken from a completed sale.', ['origin_id' => (int) $salesData['return_of_sale_id']]);

                return ACCOUNTING_FAILED;
            }
        }

        $discountPlan = $this->invoiceDiscountPlan($items, $saleType, $salesData['return_of_sale_id'], $invoiceDiscount, $saleId, $saleStatus == COMPLETED);

        if ($discountPlan instanceof InvoiceDiscountProblem) {
            $this->db->transRollback();
            $this->invoiceDiscountProblem = $discountPlan;

            return INVOICE_DISCOUNT_INVALID;
        }

        $items = $discountPlan->applyTo($items);
        $salesData['invoice_discount_type'] = $discountPlan->type;
        $salesData['invoice_discount'] = $discountPlan->value;

        $priceViolations = $saleStatus == COMPLETED && in_array($saleType, [SALE_TYPE_POS, SALE_TYPE_INVOICE], true) ? (new PriceControls($this->db))->violationsOf($items, $currencyColumns['currency_rate'] ?? '1', $salesTaxes[1] ?? []) : [];

        if ($priceViolations !== [] && !(new PriceControls($this->db))->mayOverride($employeeId)) {
            $this->db->transRollback();
            $this->accountingFailure = $priceViolations[0]->toException();
            (new PriceControls($this->db))->recordRefusal((int) $salesData['branch_id'], $employeeId, $priceViolations);

            return ACCOUNTING_FAILED;
        }

        if ($saleId != NEW_ENTRY) {
            $this->clear_suspended_sale_detail($saleId);
        }

        $builder = $this->db->table('sales');
        if ($saleId == NEW_ENTRY) {
            $builder->insert($salesData);
            $saleId = $this->db->insertID();
        } else {
            $builder->where('sale_id', $saleId);
            $builder->update($salesData);
        }

        $goodsNeverLeft = $this->goodsNeverLeft($saleType, $returnDocument, $salesData['return_of_sale_id']);

        $totalAmount = 0;
        $totalAmountUsed = 0;
        $paymentIds = [];

        foreach ($payments as $paymentId => $payment) {
            $splitPayment = explode(':', $payment['payment_type'], 2);
            $paymentType = PaymentType::fromLabel($payment['payment_type']);

            if ($paymentType === PaymentType::Giftcard) {
                if (empty($splitPayment[1]) || ! $giftcard->decrementGiftcardValue($splitPayment[1], (float) $payment['payment_amount'])) {
                    $this->db->transRollback();

                    return INSUFFICIENT_GIFTCARD_BALANCE;
                }
            } elseif ($paymentType === PaymentType::Rewards) {
                if (! $customer->adjustRewardPoints($customerId, -(float) $payment['payment_amount'])) {
                    $this->db->transRollback();

                    return INSUFFICIENT_REWARD_POINTS;
                }

                $totalAmountUsed = floatval($totalAmountUsed) + floatval($payment['payment_amount']);
            }

            try {
                [$treasuryId, $treasuryBranchId] = $this->treasuryOfPayment($payment, $employeeId === null ? null : (int) $employeeId, (int) $salesData['branch_id']);
            } catch (AccountingException $refusal) {
                $this->db->transRollback();
                $this->recordAccountingFailure($saleId, $refusal, $employeeId, (int) $salesData['branch_id']);

                return ACCOUNTING_FAILED;
            }

            $salesPaymentsData = [
                'sale_id'            => $saleId,
                'payment_type'       => $payment['payment_type'],
                'payment_amount'     => $payment['payment_amount'],
                'payment_time'       => date('Y-m-d H:i:s'),
                'cash_refund'        => $payment['cash_refund'],
                'cash_adjustment'    => $payment['cash_adjustment'],
                'employee_id'        => $employeeId,
                'reference_code'     => $payment['reference_code'] ?? '',
                'treasury_id'        => $treasuryId,
                'treasury_branch_id' => $treasuryBranchId,
            ];

            $builder = $this->db->table('sales_payments');
            $builder->insert($salesPaymentsData);

            if ($this->isPaymentDocument($payment)) {
                $paymentIds[] = (int) $this->db->insertID();
            }

            $totalAmount = floatval($totalAmount) + floatval($payment['payment_amount']) - floatval($payment['cash_refund']);
        }

        if ($saleStatus == COMPLETED) {
            $this->save_customer_rewards($customerId, $saleId, $currencyColumns['currency_rate'] === null ? $totalAmount : (float) bcmul((string) $totalAmount, $currencyColumns['currency_rate'], 4), $totalAmountUsed);
        }

        $customer = $customer->get_info($customerId);

        foreach ($items as $line => $itemData) {
            $curItemInfo = $item->get_info($itemData['item_id']);

            if ($itemData['price'] == 0.00) {
                $itemData['discount'] = 0.00;
            }

            $salesItemsData = [
                'sale_id'                => $saleId,
                'item_id'                => $itemData['item_id'],
                'line'                   => $itemData['line'],
                'description'            => character_limiter($itemData['description'], 255),
                'serialnumber'           => character_limiter($itemData['serialnumber'], 30),
                'quantity_purchased'     => $itemData['quantity'],
                'discount'               => $itemData['discount'],
                'discount_type'          => $itemData['discount_type'],
                'item_cost_price'        => $itemData['cost_price'],
                'item_unit_price'        => $itemData['price'],
                'item_location'          => $itemData['item_location'],
                'print_option'           => $itemData['print_option'],
                'invoice_discount_share' => $itemData['invoice_discount_share'] ?? null,
                ...$unitColumns[$line],
            ];

            $builder = $this->db->table('sales_items');
            $builder->insert($salesItemsData);

            if ($curItemInfo->stock_type == HAS_STOCK && $saleStatus == COMPLETED && $delivered && ! $goodsNeverLeft) {    // TODO: === ?
                // Update stock quantity if item type is a standard stock item and the sale is a standard sale
                try {
                    if ($tracking->handles((int) $itemData['item_id'])) {
                        $this->moveTrackedStock($tracking, $saleId, $itemData, $salesData['return_of_sale_id'], $timing['invoice_date'] ?? date('Y-m-d'), $employeeId);
                    } else {
                        (new NegativeStockGuard($this->db))->assertSale((int) $itemData['item_id'], (int) $itemData['item_location'], (string) $itemData['quantity']);
                    }
                } catch (AccountingException $refusal) {
                    $this->db->transRollback();
                    $this->accountingFailure = $refusal;

                    return ACCOUNTING_FAILED;
                }

                if (! $tracking->handles((int) $itemData['item_id']) && ! $itemQuantity->changeQuantity(
                    $itemData['item_id'],
                    $itemData['item_location'],
                    -(float) $itemData['quantity'],
                )) {
                    $this->db->transRollback();

                    return INSUFFICIENT_STOCK;
                }

                // If an items was deleted but later returned it's restored with this rule
                if ($itemData['quantity'] < 0) {
                    $item->undelete($itemData['item_id']);
                }

                // Inventory Count Details
                $saleRemarks = 'POS ' . $saleId;    // TODO: Use string interpolation here.
                $invData = [
                    'trans_date'      => date('Y-m-d H:i:s'),
                    'trans_items'     => $itemData['item_id'],
                    'trans_user'      => $employeeId,
                    'trans_location'  => $itemData['item_location'],
                    'trans_comment'   => $saleRemarks,
                    'trans_inventory' => -$itemData['quantity']
                ];

                $inventory->insert($invData, false);
            }

            $attribute->copy_attribute_links($itemData['item_id'], 'sale_id', $saleId);
        }

        if ($customerId == NEW_ENTRY || $customer->taxable) {
            $this->save_sales_tax($saleId, $salesTaxes[0]);
            $this->save_sales_items_taxes($saleId, $salesTaxes[1]);
        }

        $chargesRefusal = $this->storeHeaderCharges($saleId, $saleType, $headerCharges, (bool) $config['tax_included']);

        if ($chargesRefusal !== null) {
            $this->db->transRollback();
            $this->accountingFailure = $chargesRefusal;

            return ACCOUNTING_FAILED;
        }

        if ($config['dinner_table_enable']) {
            $dinnerTable = model(Dinner_table::class);
            if ($saleStatus == COMPLETED) {    // TODO: === ?
                $dinnerTable->release($dinnerTableId);
            } else {
                $dinnerTable->occupy($dinnerTableId);
            }
        }

        if ($saleStatus == COMPLETED && $salesData['return_of_sale_id'] !== null) {
            try {
                (new ReturnLimit($this->db))->assertSaleReturnWithin($saleId);
            } catch (PostingException $failure) {
                $this->db->transRollback();

                if ((new AccountingSettings($this->db))->isEnabled()) {
                    $this->recordAccountingFailure($saleId, $failure, $employeeId, (int) $salesData['branch_id']);
                } else {
                    $this->accountingFailure = $failure;
                }

                return ACCOUNTING_FAILED;
            }
        }

        if ($overrideReason !== null) {
            (new ReturnOverride($this->db))->record($saleId, (int) $salesData['branch_id'], $employeeId, $overrideReason);
        }

        if ($priceViolations !== []) {
            (new PriceControls($this->db))->recordOverride($saleId, (int) $salesData['branch_id'], $employeeId, $priceViolations);
        }

        try {
            $this->numberDocuments($saleId, $saleType, $saleStatus == COMPLETED, $invoiceNumber, $quoteNumber, $paymentIds, (int) $salesData['branch_id'], $returnDocument, $salesData['quote_status'] === QuoteStatus::Draft->value);
        } catch (AccountingException $failure) {
            log_message('error', 'Numbering sale ' . $saleId . ' failed: ' . $failure->getMessage());
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            return ACCOUNTING_FAILED;
        }

        if ($saleType === SALE_TYPE_INVOICE) {
            $orders         = new SalesOrders($this->db);
            $orderOfInvoice = $orderSourceId ?? $orders->orderOfSale($saleId);

            if ($orderOfInvoice !== null) {
                try {
                    $saleStatus == COMPLETED
                        ? $orders->recordInvoice($saleId, $orderOfInvoice, $items, $customerId, $employeeId, (int) $salesData['branch_id'])
                        : $orders->link($saleId, $orderOfInvoice);
                } catch (AccountingException|BranchException $refusal) {
                    $this->db->transRollback();
                    $this->accountingFailure = $refusal;

                    return ACCOUNTING_FAILED;
                }
            }
        }

        if ($quoteStatusChange !== null) {
            [$statusBefore, $statusAfter] = $quoteStatusChange;

            (new QuoteLifecycle($this->db))->recordChange($saleId, $statusBefore, $statusAfter, $employeeId, (int) $salesData['branch_id'], $statusBefore === QuoteStatus::Draft ? $this->get_quote_number($saleId) : null);
        }

        if ($saleStatus == COMPLETED) {
            $failure = $this->postToAccounting($saleId, $employeeId);

            if ($failure !== null) {
                $this->db->transRollback();
                $this->recordAccountingFailure($saleId, $failure, $employeeId, (int) $salesData['branch_id']);

                return ACCOUNTING_FAILED;
            }

            $creditRefusal = $this->creditRefusalOf($saleId, $salesData['customer_id'], $employeeId, (int) $salesData['branch_id']);

            if ($creditRefusal !== null) {
                $this->db->transRollback();
                $this->accountingFailure = $creditRefusal;
                (new CreditLimit($this->db))->recordRefusal((int) $salesData['customer_id'], (int) $salesData['branch_id'], $employeeId, $creditRefusal);

                return ACCOUNTING_FAILED;
            }

            if ($saleType !== SALE_TYPE_RETURN && ! $discountPlan->isEmpty()) {
                $this->auditInvoiceDiscount($saleId, $employeeId, (int) $salesData['branch_id'], $discountPlan);
            }

            $this->auditHeaderCharges($saleId, $employeeId, (int) $salesData['branch_id']);

            if ($saleType === SALE_TYPE_INVOICE && $stored !== null && (int) $stored->sale_type === SALE_TYPE_INVOICE && (int) $stored->sale_status === SUSPENDED) {
                $this->auditDraftIssued($saleId, $employeeId, (int) $salesData['branch_id'], $timing['invoice_date']);
            }

            $quoteOfInvoice = $quoteSourceId ?? ($stored === null || $stored->source_quote_id === null ? null : (int) $stored->source_quote_id);

            if ($saleType === SALE_TYPE_INVOICE && $quoteOfInvoice !== null) {
                try {
                    (new QuoteLifecycle($this->db))->markConverted($quoteOfInvoice, $saleId, $employeeId);
                } catch (AccountingException|BranchException $refusal) {
                    $this->db->transRollback();
                    $this->accountingFailure = $refusal;

                    return ACCOUNTING_FAILED;
                }
            }
        }

        if ($timing['invoice_date'] !== null && $timing['invoice_date'] !== $storedDate) {
            $this->auditInvoiceDate($saleId, $employeeId, (int) $salesData['branch_id'], $timing['invoice_date']);
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $saleId : -1;
    }

    /**
     * The time a stored sale was saved, read from the sale alone: no temporary table is built, and an empty
     * text stands for a sale that does not exist.
     */
    public function getSaleTime(int $saleId): string
    {
        $row = $this->db->table('sales')->select('sale_time')->where('sale_id', $saleId)->get()->getRow();

        return $row === null ? '' : (string) $row->sale_time;
    }

    /**
     * The currency a stored sale is in, or null when it is in the base currency.
     */
    public function getCurrencyCode(int $saleId): ?string
    {
        $row = $this->db->table('sales')->select('currency_code')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || $row->currency_code === null ? null : (string) $row->currency_code;
    }

    /**
     * @param array<int|string, array<string, mixed>> $payments
     */
    private function paysWithStoredValue(array $payments): bool
    {
        foreach ($payments as $payment) {
            $type = PaymentType::fromLabel((string) ($payment['payment_type'] ?? ''));

            if ($type === PaymentType::Giftcard || $type === PaymentType::Rewards) {
                return true;
            }
        }

        return false;
    }

    /**
     * The currency and the rate a sale is saved with. A return is in the currency and at the rate of the
     * sale it takes back: it mirrors it, and is never re-rated. Anything else is in the base currency
     * unless a currency was chosen, which takes the rate of the day of the sale. A foreign currency needs
     * the books to be on, because without them there is no base to convert to.
     *
     * @return array{currency_code: ?string, currency_rate: ?string}
     *
     * @throws AccountingException
     */
    private function currencyColumns(int $saleType, ?int $returnOfSaleId, string $date, int $employeeId): array
    {
        $none   = ['currency_code' => null, 'currency_rate' => null];
        $choice = $this->savingCurrency;
        $origin = $saleType === SALE_TYPE_RETURN && $returnOfSaleId !== null
            ? $this->db->table('sales')->select('currency_code, currency_rate')->where('sale_id', $returnOfSaleId)->get()->getRow()
            : null;

        if ($origin !== null) {
            $code = $origin->currency_code;

            if ($choice !== null && $choice[0] !== (string) $code) {
                throw new AccountingException(AccountingError::DocumentCurrencyLocked, 'A return is in the currency of the sale it takes back.', ['sale_id' => $returnOfSaleId]);
            }

            return $code === null ? $none : ['currency_code' => $code, 'currency_rate' => $origin->currency_rate];
        }

        if ($choice === null) {
            return $none;
        }

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            throw new AccountingException(AccountingError::ForeignCurrencyUnsupported, 'A document is in a foreign currency only while the books are on.');
        }

        $currency = (new Currencies($this->db))->documentCurrency($choice[0], $date, $choice[1], $employeeId);

        return $currency === null ? $none : ['currency_code' => $currency->code, 'currency_rate' => $currency->rate];
    }

    /**
     * Locks the row of a sale that is saved again and refuses what must not be: a quote that was
     * converted into an invoice is the record of it and is not saved again, a sale is saved again
     * only while it is still suspended (one that was issued or cancelled meanwhile, from another
     * screen, is never taken back to waiting or issued twice), and a draft that is issued is the
     * one that was read: when it was changed in between, nothing is issued.
     *
     * @throws AccountingException
     */
    private function lockStoredSale(int $saleId, ?string $draftFingerprint): ?object
    {
        $stored = LockedRead::row($this->db, 'SELECT sale_status, sale_type, quote_status, source_quote_id FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$saleId]);

        if ($stored === null) {
            return null;
        }

        if ((int) $stored->sale_type === SALE_TYPE_QUOTE && (int) $stored->sale_status === SUSPENDED && QuoteStatus::fromStored($stored->quote_status)->isLocked()) {
            throw new AccountingException(AccountingError::QuoteLocked, 'A quote that became an invoice cannot be changed.', ['sale_id' => $saleId]);
        }

        if ((int) $stored->sale_status !== SUSPENDED) {
            throw new AccountingException(AccountingError::DraftNotFound, 'The sale is not waiting to be completed.', ['sale_id' => $saleId]);
        }

        if ($draftFingerprint !== null && (new DraftInvoice($this->db))->fingerprint($saleId) !== $draftFingerprint) {
            throw new AccountingException(AccountingError::DraftChanged, 'The draft changed while it was being issued.', ['sale_id' => $saleId]);
        }

        return $stored;
    }

    /**
     * Issues a draft invoice: it is saved as the completed invoice its lines make now, takes the next
     * number of the invoice sequence and the day typed on it (the one it was saved with unless another
     * is given by an employee who may type it), and posts the invoice and the cost of the goods as any
     * invoice is. Returns the sale id, ACCOUNTING_FAILED when the books or the draft refuse (the
     * reason is in accountingFailure()), INSUFFICIENT_STOCK, INVOICE_DISCOUNT_INVALID, or NEW_ENTRY.
     */
    public function issueDraft(int $saleId, int $employeeId, ?string $invoiceNumber = null, ?string $invoiceDate = null, int|string|null $paymentTerms = null): int
    {
        $this->accountingFailure      = null;
        $this->invoiceDiscountProblem = null;

        $issue = $this->outsideRecordScope($saleId, $employeeId) ? null : (new DraftInvoice($this->db))->forIssue($saleId);

        if ($issue === null) {
            $this->accountingFailure = new AccountingException(AccountingError::DraftNotFound, 'The sale is not a draft invoice.', ['sale_id' => $saleId]);

            return ACCOUNTING_FAILED;
        }

        if ($issue->problem !== null) {
            $this->invoiceDiscountProblem = $issue->problem;

            return INVOICE_DISCOUNT_INVALID;
        }

        $saleStatus = COMPLETED;
        $items      = $issue->items;
        $taxes      = $issue->taxes;
        $draftsCode = $this->db->table('sales')->select('currency_code')->where('sale_id', $saleId)->get()->getRow()->currency_code ?? null;

        $this->useCurrency($draftsCode);

        return $this->save_value(
            $saleId,
            $saleStatus,
            $items,
            $issue->customerId ?? NEW_ENTRY,
            $employeeId,
            $issue->comment,
            $invoiceNumber ?? $issue->invoiceNumber,
            null,
            null,
            SALE_TYPE_INVOICE,
            $issue->payments,
            $issue->dinnerTableId,
            $taxes,
            $issue->delivered,
            null,
            $issue->invoiceDiscount,
            ReturnDocument::CreditNote,
            $invoiceDate ?? $issue->invoiceDate,
            $paymentTerms ?? $issue->paymentTermsDays,
            draftFingerprint: $issue->fingerprint,
            headerCharges: $issue->headerCharges,
            priceListId: $this->getPriceListId($saleId),
        );
    }

    /**
     * The price list a sale was priced from, or null when it was priced from none.
     */
    public function getPriceListId(int $saleId): ?int
    {
        $row = $this->db->table('sales')->select('price_list_id')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || $row->price_list_id === null ? null : (int) $row->price_list_id;
    }

    /**
     * The header of a draft invoice or a quote as its page shows it: who it is for, who made it, where,
     * and the numbers it carries.
     */
    public function getDocumentHeader(int $saleId, ?int $viewerId = null): ?object
    {
        if ($this->outsideRecordScope($saleId, $viewerId)) {
            return null;
        }

        return $this->db->table('sales s')
            ->select("s.sale_id, s.sale_time, s.comment, s.customer_id, s.sale_type, s.invoice_number, s.quote_number, s.invoice_date, s.payment_terms_days, s.delivered, s.branch_id, b.code AS branch_code, TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS customer_name, TRIM(CONCAT(ep.first_name, ' ', ep.last_name)) AS employee_name", false)
            ->join('branches b', 'b.branch_id = s.branch_id')
            ->join('people cp', 'cp.person_id = s.customer_id', 'left')
            ->join('people ep', 'ep.person_id = s.employee_id', 'left')
            ->where('s.sale_id', $saleId)
            ->get()
            ->getRow();
    }

    /**
     * The quote an invoice (or a draft of one) was made from, or null.
     */
    public function getSourceQuote(int $saleId): ?int
    {
        $row = $this->db->table('sales')->select('source_quote_id')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || $row->source_quote_id === null ? null : (int) $row->source_quote_id;
    }

    /**
     * A payment is a document of its own, with a number, unless it only records what stays unpaid
     * (a credit sale) or a rounding of cash.
     *
     * @param array<string, mixed> $payment
     */
    private function isPaymentDocument(array $payment): bool
    {
        return empty($payment['cash_adjustment']) && PaymentType::fromLabel((string) ($payment['payment_type'] ?? '')) !== PaymentType::Due;
    }

    /**
     * Gives the documents of a sale the numbers of their sequences, for the sequences that are on:
     * a quote when it is saved as anything but a draft, and an invoice, a credit note and each payment
     * when the sale is completed. A draft keeps its temporary number until it is issued. A number typed
     * by hand is kept as it is.
     *
     * @param list<int> $paymentIds
     *
     * @throws AccountingException
     */
    private function numberDocuments(int $saleId, int $saleType, bool $completed, ?string $invoiceNumber, ?string $quoteNumber, array $paymentIds, int $branchId, ReturnDocument $returnDocument, bool $quoteIsDraft): void
    {
        $numbering = new DocumentNumbering($this->db);

        if ($saleType === SALE_TYPE_QUOTE && ! $quoteIsDraft && ($quoteNumber ?? '') === '') {
            $numbering->assign(DocumentType::SalesQuote, 'sales', $saleId, $branchId);
        }

        if (! $completed) {
            return;
        }

        if ($saleType === SALE_TYPE_INVOICE && ($invoiceNumber ?? '') === '') {
            $numbering->assign(DocumentType::SalesInvoice, 'sales', $saleId, $branchId);
        }

        if ($saleType === SALE_TYPE_RETURN) {
            $numbering->assign($returnDocument->documentType(), 'sales', $saleId, $branchId);
        }

        foreach ($paymentIds as $paymentId) {
            $numbering->assign(DocumentType::CustomerPayment, 'sales_payments', $paymentId, $branchId);
        }
    }

    /**
     * An edit that leaves the invoice or the quote number blank does not take away the number the
     * sequence gave the document.
     *
     * @param array<string, mixed> $updateData
     *
     * @return array<string, mixed>
     */
    private function keepNumbersOfTheSequences(int $saleId, array $updateData): array
    {
        $numbering = new DocumentNumbering($this->db);

        foreach (['invoice_number' => DocumentType::SalesInvoice, 'quote_number' => DocumentType::SalesQuote] as $column => $type) {
            if (array_key_exists($column, $updateData) && ($updateData[$column] ?? '') === '' && $numbering->numbers($type, 'sales', [$saleId]) !== []) {
                unset($updateData[$column]);
            }
        }

        return $updateData;
    }

    /**
     * The reason a manager gave for letting a return of stock through with no sale to take it back
     * from, once the books are on and the return needs one. Nothing is asked of a return that has a
     * sale to come from, a return of what has no stock, or a return that is only being kept for later.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @throws AccountingException
     */
    private function returnOverrideOf(int $saleType, int $saleStatus, ?int $originId, array $items, ?string $reason, int $employeeId): ?string
    {
        if ($reason === null || $saleType !== SALE_TYPE_RETURN || $saleStatus !== COMPLETED || $originId !== null || ! (new AccountingSettings($this->db))->isEnabled()) {
            return null;
        }

        $item = model(Item::class);

        foreach ($items as $line) {
            if ((int) $item->get_info($line['item_id'])->stock_type === HAS_STOCK) {
                return (new ReturnOverride($this->db))->reasonFor($employeeId, $reason);
            }
        }

        return null;
    }

    /**
     * What an invoice carries about time besides the moment it was saved: the day typed on it, its
     * payment terms and the due date they give. Only an invoice of a books that are on has any of
     * it. A day is typed by an employee who may, or is the one a draft was given before; the due
     * date is worked out when the invoice is issued, from the day it is dated.
     *
     * @return array{invoice_date: ?string, payment_terms_days: ?int, due_date: ?string}
     *
     * @throws AccountingException
     */
    private function timingOf(int $saleId, int $saleType, int $saleStatus, ?string $invoiceDate, int|string|null $paymentTerms, int $employeeId): array
    {
        $none     = ['invoice_date' => null, 'payment_terms_days' => null, 'due_date' => null];
        $settings = new AccountingSettings($this->db);

        if (! $settings->isEnabled() || $saleType !== SALE_TYPE_INVOICE) {
            return $none;
        }

        $today = date('Y-m-d');
        $date  = InvoiceTiming::date($invoiceDate, $today, $settings->startDate());
        $terms = InvoiceTiming::terms($paymentTerms);

        if ($date !== null && ! $this->mayTypeInvoiceDate($saleId, $employeeId, $date)) {
            throw new AccountingException(AccountingError::PermissionDenied, 'The employee may not type the date of an invoice.');
        }

        return [
            'invoice_date'       => $date,
            'payment_terms_days' => $terms,
            'due_date'           => $terms !== null && $saleStatus === COMPLETED ? InvoiceTiming::dueDate($date ?? $today, $terms) : null,
        ];
    }

    /**
     * Whether the employee may put this day on the invoice: they hold the permission, or the day is the
     * one the draft they are issuing already carries.
     */
    private function mayTypeInvoiceDate(int $saleId, int $employeeId, string $date): bool
    {
        if ($this->datedBySchedule || model(Employee::class)->has_grant('sales_invoice_date', $employeeId)) {
            return true;
        }

        return $saleId !== NEW_ENTRY && $this->storedInvoiceDate($saleId) === $date;
    }

    private function storedInvoiceDate(int $saleId): ?string
    {
        $row = $this->db->table('sales')->select('invoice_date')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || $row->invoice_date === null ? null : (string) $row->invoice_date;
    }

    private function auditInvoiceDate(int $saleId, int $employeeId, int $branchId, string $date): void
    {
        (new AuditLog($this->db))->write('sale', $saleId, 'invoice_date_typed', $employeeId, JournalOrigin::User, ['date' => $date, 'saved_on' => date('Y-m-d')], $branchId);
    }

    /**
     * Writes in the audit log of the books that a draft was issued as an invoice, with the number it was
     * given and the day it is dated, in the transaction that issues it. While accounting is off nothing
     * is written.
     */
    private function auditDraftIssued(int $saleId, int $employeeId, int $branchId, ?string $invoiceDate): void
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return;
        }

        (new AuditLog($this->db))->write('sale', $saleId, 'draft_issued', $employeeId, JournalOrigin::User, [
            'number' => $this->getInvoiceNumber($saleId),
            'date'   => $invoiceDate ?? date('Y-m-d'),
        ], $branchId);
    }

    /**
     * Writes in the audit log of the books that a sale was given a discount on the whole of it, by
     * whom and for how much, in the transaction of the sale. While accounting is off nothing is written.
     */
    private function auditInvoiceDiscount(int $saleId, int $employeeId, int $branchId, InvoiceDiscountPlan $plan): void
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return;
        }

        (new AuditLog($this->db))->write('sale', $saleId, 'invoice_discount_given', $employeeId, JournalOrigin::User, [
            'type'   => $plan->type === PERCENT ? 'percent' : 'fixed',
            'value'  => InvoiceDiscount::normalise((string) $plan->value),
            'amount' => $plan->amount,
        ], $branchId);
    }

    /**
     * The SQL of a sale total with what the header of the sale adds to it: the adjustment, the shipping and
     * the tax on the shipping when it is on top of the amount, in the direction of the document, and the
     * whole rounded once as the journal rounds it. A sale without charges gives the total it always gave.
     */
    private function withHeaderCharges(string $total, int $decimals): string
    {
        $added = 'IFNULL(MAX(sales.adjustment_amount), 0) + IFNULL(MAX(sales.shipping_amount), 0)'
            . " + CASE WHEN MAX(sales.shipping_tax_option) = '" . ShippingTaxOption::Added->value . "' THEN IFNULL(MAX(sales.shipping_tax_amount), 0) ELSE 0 END";
        $sign = 'CASE WHEN MAX(sales.sale_type) = ' . SALE_TYPE_RETURN . ' THEN -1 ELSE 1 END';

        return "CASE WHEN MAX(sales.adjustment_amount) IS NULL AND MAX(sales.shipping_amount) IS NULL THEN ($total) ELSE ROUND(($total) + ($sign) * ($added), $decimals) END";
    }

    /**
     * What the header of a saved sale carries, as the register takes it back: the amounts that were
     * stored, with the tax option and rate they were worked out with.
     */
    public function getHeaderCharges(int $saleId): HeaderCharges
    {
        $row = $this->db->table('sales')
            ->select('adjustment_label, adjustment_amount, shipping_amount, shipping_options, shipping_tax_option, shipping_tax_percent, shipping_company_id')
            ->where('sale_id', $saleId)
            ->get()
            ->getRow();

        if ($row === null) {
            return HeaderCharges::none();
        }

        return new HeaderCharges(
            (string) ($row->adjustment_label ?? ''),
            (string) ($row->adjustment_amount ?? '0'),
            (string) ($row->shipping_amount ?? '0'),
            ShippingTaxOption::fromStored($row->shipping_tax_option),
            (string) ($row->shipping_tax_percent ?? '0'),
            $row->shipping_options === null ? null : (int) $row->shipping_options,
            $row->shipping_company_id === null ? null : (int) $row->shipping_company_id,
        );
    }

    /**
     * Saves the adjustment and the shipping typed on the header of the sale, worked out against the lines
     * that were just saved: only on the documents that may carry them (an invoice, a quote, a credit
     * note), only where the setting for each is on, with a tax on the shipping only at the rate and in the
     * mode the company works in, and never so that the document comes to less than nothing. What is
     * stored is what counts: the adjustment is nothing when the lines came to nothing.
     */
    private function storeHeaderCharges(int $saleId, int $saleType, ?HeaderCharges $charges, bool $taxIncluded): ?AccountingException
    {
        if ($charges === null || $charges->isEmpty()) {
            return null;
        }

        $settings = new AccountingSettings($this->db);

        if (! in_array($saleType, [SALE_TYPE_INVOICE, SALE_TYPE_QUOTE, SALE_TYPE_RETURN], true)) {
            return new AccountingException(AccountingError::HeaderChargesNotAllowed, 'Only an invoice, a quote or a credit note can carry an adjustment or shipping.', ['sale_id' => $saleId]);
        }

        if (($charges->hasAdjustment() && ! $settings->salesAdjustmentEnabled()) || ($charges->hasShipping() && ! $settings->salesShippingEnabled())) {
            return new AccountingException(AccountingError::HeaderChargesOff, 'The adjustment or the shipping is not turned on.', ['sale_id' => $saleId]);
        }

        if ($charges->shippingCompanyId !== null && $charges->hasShipping()) {
            try {
                (new ShippingCompanies($this->db))->requireNameable($charges->shippingCompanyId);
            } catch (AccountingException $refusal) {
                return $refusal;
            }
        }

        $sale = (new SaleLoader($this->db))->load($saleId, false);

        if ($sale === null) {
            return null;
        }

        $amounts = SaleAmounts::totals($sale, totals_decimals());
        $running = bcadd($amounts['net'], $amounts['tax'], SaleAmounts::SCALE);
        $problem = $charges->problem($running);

        if ($problem === null && $charges->shippingTaxOption !== ShippingTaxOption::None && ! $this->shippingTaxFollowsTheSettings($charges, $settings, $taxIncluded)) {
            $problem = HeaderChargesProblem::TaxPercentInvalid;
        }

        if ($problem !== null) {
            return new AccountingException($problem === HeaderChargesProblem::ExceedsTotal ? AccountingError::AdjustmentExceedsTotal : AccountingError::HeaderChargesInvalid, 'The adjustment or the shipping cannot be accepted: ' . $problem->value . '.', ['sale_id' => $saleId, 'problem' => $problem->value]);
        }

        $resolved = $charges->resolve($running);
        $zero     = fn (string $amount): ?string => bccomp($amount, '0', SaleAmounts::SCALE) === 0 ? null : $amount;
        $taxed    = bccomp($resolved->shipping, '0', SaleAmounts::SCALE) !== 0 && $charges->shippingTaxOption !== ShippingTaxOption::None;

        $this->db->table('sales')->where('sale_id', $saleId)->update([
            'adjustment_label'     => $zero($resolved->adjustment) === null || $resolved->adjustmentLabel === '' ? null : $resolved->adjustmentLabel,
            'adjustment_amount'    => $zero($resolved->adjustment),
            'shipping_amount'      => $zero($resolved->shipping),
            'shipping_options'     => $resolved->shippingOptions,
            'shipping_company_id'  => $resolved->shippingCompanyId,
            'shipping_tax_option'  => $taxed ? $charges->shippingTaxOption->value : null,
            'shipping_tax_percent' => $taxed ? $charges->shippingTaxPercent : null,
            'shipping_tax_amount'  => $taxed ? $resolved->shippingTax : null,
        ]);

        return null;
    }

    private function shippingTaxFollowsTheSettings(HeaderCharges $charges, AccountingSettings $settings, bool $taxIncluded): bool
    {
        $mode = $taxIncluded ? ShippingTaxOption::Included : ShippingTaxOption::Added;

        return bccomp($settings->shippingTaxPercent(), '0', 4) > 0
            && bccomp($charges->shippingTaxPercent, $settings->shippingTaxPercent(), 4) === 0
            && $charges->shippingTaxOption === $mode;
    }

    /**
     * Writes who gave an adjustment or a shipping charge on a completed sale, and how much, in the
     * transaction of the sale. While accounting is off nothing is written.
     */
    private function auditHeaderCharges(int $saleId, int $employeeId, int $branchId): void
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return;
        }

        $row = $this->db->table('sales')->select('adjustment_amount, shipping_amount, shipping_tax_amount')->where('sale_id', $saleId)->get()->getRow();

        if ($row === null || ($row->adjustment_amount === null && $row->shipping_amount === null)) {
            return;
        }

        (new AuditLog($this->db))->write('sale', $saleId, 'header_charges_given', $employeeId, JournalOrigin::User, [
            'adjustment'   => bcadd((string) ($row->adjustment_amount ?? '0'), '0', SaleAmounts::SCALE),
            'shipping'     => bcadd((string) ($row->shipping_amount ?? '0'), '0', SaleAmounts::SCALE),
            'shipping_tax' => bcadd((string) ($row->shipping_tax_amount ?? '0'), '0', SaleAmounts::SCALE),
        ], $branchId);
    }

    /**
     * The discount on the whole of a sale as it is saved, from the lines being saved and what was
     * entered, or for a return from the sale it takes its goods back from. It is worked out here, after
     * the sale a return comes from is locked, so that what is saved is what the books of that sale
     * allow. The taxes of a completed sale were worked out with the shares its lines carried, and those
     * must be the ones saved. A sale that is only suspended has no taxes yet.
     *
     * @param array<int|string, array<string, mixed>> $items
     * @param array{type: int, value: string}|null    $invoiceDiscount
     */
    private function invoiceDiscountPlan(array $items, int $saleType, ?int $returnOfSaleId, ?array $invoiceDiscount, int $saleId, bool $taxed): InvoiceDiscountPlan|InvoiceDiscountProblem
    {
        $decimals = totals_decimals();

        if ($saleType === SALE_TYPE_RETURN) {
            if ($invoiceDiscount !== null) {
                return InvoiceDiscountProblem::OnReturn;
            }

            $plan = $returnOfSaleId === null ? InvoiceDiscount::none() : InvoiceDiscount::forReturn($items, $this->invoiceDiscountOrigin($returnOfSaleId, $saleId), $decimals);
        } else {
            $plan = $invoiceDiscount === null ? InvoiceDiscount::none() : InvoiceDiscount::forSale($items, (int) $invoiceDiscount['type'], (string) $invoiceDiscount['value'], $decimals);
        }

        if ($plan instanceof InvoiceDiscountProblem) {
            return $plan;
        }

        foreach ($taxed ? $items : [] as $key => $row) {
            if (array_key_exists('invoice_discount_share', $row) && bccomp((string) $row['invoice_discount_share'], $plan->share($key), InvoiceDiscount::SCALE) !== 0) {
                return InvoiceDiscountProblem::Changed;
            }
        }

        return $plan;
    }

    /**
     * What a sale gave in units and in discount for each item, and what its returns have taken back.
     *
     * @return array<int, array{sold: string, share: string, returned: string, returnedShare: string}>
     */
    public function invoiceDiscountOrigin(int $saleId, int $exceptSaleId = NEW_ENTRY): array
    {
        $prefix = $this->db->getPrefix();
        $rows   = $this->db->query(
            "SELECT i.item_id,
                    SUM(CASE WHEN s.sale_id = ? THEN i.quantity_purchased ELSE 0 END) AS sold,
                    SUM(CASE WHEN s.sale_id = ? THEN IFNULL(i.invoice_discount_share, 0) ELSE 0 END) AS share,
                    SUM(CASE WHEN s.sale_id <> ? THEN ABS(i.quantity_purchased) ELSE 0 END) AS returned,
                    SUM(CASE WHEN s.sale_id <> ? THEN ABS(IFNULL(i.invoice_discount_share, 0)) ELSE 0 END) AS returned_share
             FROM {$prefix}sales s
             JOIN {$prefix}sales_items i ON i.sale_id = s.sale_id
             WHERE s.sale_id = ? OR (s.return_of_sale_id = ? AND s.sale_status = ? AND s.sale_type = ? AND s.sale_id <> ?)
             GROUP BY i.item_id",
            [$saleId, $saleId, $saleId, $saleId, $saleId, $saleId, COMPLETED, SALE_TYPE_RETURN, $exceptSaleId],
        )->getResult();

        $origin = [];

        foreach ($rows as $row) {
            $origin[(int) $row->item_id] = [
                'sold'          => bcadd((string) $row->sold, '0', 3),
                'share'         => bcadd((string) $row->share, '0', InvoiceDiscount::SCALE),
                'returned'      => bcadd((string) $row->returned, '0', 3),
                'returnedShare' => bcadd((string) $row->returned_share, '0', InvoiceDiscount::SCALE),
            ];
        }

        return $origin;
    }

    /**
     * What was entered as the discount on the whole of a sale, or null when there was none.
     *
     * @return array{type: int, value: string}|null
     */
    public function getInvoiceDiscount(int $saleId): ?array
    {
        $row = $this->db->table('sales')->select('invoice_discount_type, invoice_discount')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || $row->invoice_discount_type === null || $row->invoice_discount === null
            ? null
            : ['type' => (int) $row->invoice_discount_type, 'value' => (string) $row->invoice_discount];
    }

    /**
     * Posts a sale that has just been completed, when accounting is on. Nothing of accounting
     * runs while it is off. A failure is returned and not thrown, so the caller can undo the
     * sale as a whole.
     */
    private function postToAccounting(int $saleId, ?int $employeeId): ?Throwable
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return null;
        }

        try {
            (new SalePosting($this->db))->postCompleted($saleId, $employeeId);

            return null;
        } catch (Throwable $exception) {
            return $this->asAccountingFailure($exception, $saleId);
        }
    }

    /**
     * Whether the sale that was just posted puts its customer over the credit limit or the credit days,
     * which an employee who holds the permission to override is let through, and the audit log says
     * so. The books decide what is open: a return is a credit and not an invoice, so it is never
     * refused, and with accounting off nothing is posted, so nothing is open. An edit of the payments
     * of an issued invoice is held to the limit only when it left more open on the invoice than
     * before, which is the open amount it had before the edit.
     */
    private function creditRefusalOf(int $saleId, ?int $customerId, int $employeeId, int $branchId, ?string $openBefore = null): ?AccountingException
    {
        if ($customerId === null || $customerId <= 0) {
            return null;
        }

        $credit  = new CreditLimit($this->db);
        $refusal = $openBefore === null ? $credit->refusalFor($customerId, $saleId, date('Y-m-d')) : $credit->refusalForEdit($customerId, $saleId, $openBefore, date('Y-m-d'));

        if ($refusal === null) {
            return null;
        }

        if ($credit->mayOverride($employeeId)) {
            $credit->recordOverride($saleId, $branchId, $employeeId, $refusal);

            return null;
        }

        return $refusal;
    }

    /**
     * What the books refused is kept as it is. Anything else that went wrong while posting is
     * logged, and stands for a write that failed.
     */
    private function asAccountingFailure(Throwable $exception, int $saleId): AccountingException|PostingException
    {
        if ($exception instanceof AccountingException || $exception instanceof PostingException) {
            return $exception;
        }

        log_message('error', 'Posting sale ' . $saleId . ' failed: ' . $exception->getMessage());

        return new PostingException(PostingError::WriteFailed, 'The sale could not be posted.', ['reason' => $exception->getMessage()], $exception);
    }

    /**
     * Remembers why the books refused a change and writes it in the failure log. It must run
     * after the transaction has rolled back, or the row would go with it.
     */
    private function recordAccountingFailure(int $saleId, Throwable $failure, ?int $employeeId, ?int $branchId = null): void
    {
        $this->accountingFailure = $failure;

        $refusal = $failure instanceof AccountingException && in_array($failure->error, [AccountingError::SaleLocked, AccountingError::IssuedDocumentLocked, AccountingError::TreasuryDepositDenied, AccountingError::TreasuryWithdrawDenied, AccountingError::PaymentTermsInvalid, AccountingError::CreditLimitExceeded, AccountingError::CreditPeriodExceeded], true);

        if (($failure instanceof PostingException || $failure instanceof AccountingException) && ! $refusal) {
            (new SalePosting($this->db))->recordFailure($saleId, $failure, $employeeId, $branchId);
        }
    }

    /**
     * Saves sale tax
     */
    public function save_sales_tax(int $sale_id, array $sales_taxes): void    // TODO: should we return the result of the insert here as a bool?
    {
        $builder = $this->db->table('sales_taxes');

        foreach ($sales_taxes as $line => $sales_tax) {
            $sales_tax['sale_id'] = $sale_id;
            $builder->insert($sales_tax);
        }
    }

    /**
     * Apply customer sales tax if the customer sales tax is enabled
     * The original tax is still supported if the user configures it,
     * but it won't make sense unless it's used exclusively for the purpose
     * of VAT tax which becomes a price component.  VAT taxes must still be reported
     * as a separate tax entry on the invoice.
     */
    public function save_sales_items_taxes(int $saleId, array $salesItemTaxes): void
    {
        $builder = $this->db->table('sales_items_taxes');

        foreach ($salesItemTaxes as $line => $taxItem) {
            $salesItemsTaxes = [
                'sale_id'              => $saleId,
                'item_id'              => $taxItem['item_id'],
                'line'                 => $taxItem['line'],
                'name'                 => $taxItem['name'],
                'percent'              => $taxItem['percent'],
                'tax_type'             => $taxItem['tax_type'],
                'rounding_code'        => $taxItem['rounding_code'],
                'cascade_sequence'     => $taxItem['cascade_sequence'],
                'item_tax_amount'      => $taxItem['item_tax_amount'],
                'sales_tax_code_id'    => $taxItem['sales_tax_code_id'],
                'tax_category_id'      => $taxItem['tax_category_id'],
                'jurisdiction_id'      => $taxItem['jurisdiction_id'],
                'liability_account_id' => $taxItem['liability_account_id'] ?? null,
            ];

            $builder->insert($salesItemsTaxes);
        }
    }

    /**
     * Return the taxes that were charged
     */
    public function get_sales_taxes(int $sale_id): array
    {
        $builder = $this->db->table('sales_taxes');
        $builder->where('sale_id', $sale_id);
        $builder->orderBy('print_sequence', 'asc');

        $query = $builder->get();

        return $query->getResultArray();
    }

    /**
     * Return the taxes applied to a sale for a particular item
     */
    public function get_sales_item_taxes(int $saleId, int $itemId): array
    {
        $builder = $this->db->table('sales_items_taxes AS taxes');
        $builder->select('taxes.item_id, taxes.name, taxes.percent, taxes.tax_type, taxes.liability_account_id, SUM(taxes.item_tax_amount) AS item_tax_amount, SUM(items.quantity_purchased) AS sold_quantity');
        $builder->join('sales_items AS items', 'items.sale_id = taxes.sale_id AND items.item_id = taxes.item_id AND items.line = taxes.line', 'left');
        $builder->where('taxes.sale_id', $saleId);
        $builder->where('taxes.item_id', $itemId);
        $builder->groupBy('taxes.item_id, taxes.name, taxes.percent, taxes.tax_type, taxes.liability_account_id');
        $builder->orderBy('MIN(taxes.line), taxes.name, taxes.percent', '', false);

        return $builder->get()->getResultArray();
    }

    /**
     * Deletes list of sales
     * @throws ReflectionException
     */
    public function delete_list(array $sale_ids, int $employee_id, bool $update_inventory = true): bool
    {
        $result  = true;
        $failure = null;

        foreach ($sale_ids as $saleId) {
            if ($this->outsideRecordScope((int) $saleId, $employee_id)) {
                $this->accountingFailure = null;

                return false;
            }
        }

        foreach ($sale_ids as $saleId) {
            $result &= $this->delete($saleId, false, $update_inventory, $employee_id);
            $failure ??= $this->accountingFailure;
        }

        $this->accountingFailure = $failure;

        return $result;
    }

    /**
     * Restores list of sales
     */
    public function restore_list(array $sale_ids, int $employee_id, bool $update_inventory = true): bool    // TODO: $update_inventory is never used in the function.
    {
        foreach ($sale_ids as $saleId) {
            if ($this->outsideRecordScope((int) $saleId, $employee_id)) {
                return false;
            }
        }

        foreach ($sale_ids as $saleId) {
            $this->update_sale_status($saleId, SUSPENDED);
        }

        return true;
    }

    /**
     * Delete sale.  Hard deletes are not supported for sales transactions.
     * When a sale is "deleted" it is simply changed to a status of canceled.
     * However, if applicable the inventory still needs to be updated
     * @throws ReflectionException
     */
    public function delete($sale_id = null, bool $purge = false, bool $update_inventory = true, $employee_id = null): bool
    {
        if ($this->outsideRecordScope((int) $sale_id, $employee_id === null ? null : (int) $employee_id)) {
            $this->accountingFailure = null;

            return false;
        }

        try {
            return (new CustomerLock($this->db))->run($this->customerOf((int) $sale_id), fn (): bool => $this->deleteLocked($sale_id, $update_inventory, $employee_id));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::CustomerBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return false;
        }
    }

    private function hasCompletedReturn(int $saleId): bool
    {
        return $this->db->table('sales')->where(['return_of_sale_id' => $saleId, 'sale_type' => SALE_TYPE_RETURN, 'sale_status' => COMPLETED])->countAllResults() > 0;
    }

    private function refuseWhileReturnStands(int $saleId): bool
    {
        $this->db->transRollback();
        $this->accountingFailure = new AccountingException(AccountingError::SaleHasReturns, 'A sale that goods have been returned from cannot be cancelled while the return stands.', ['sale_id' => $saleId]);

        return false;
    }

    private function goodsNeverLeft(int $saleType, ReturnDocument $document, ?int $originId): bool
    {
        return $saleType === SALE_TYPE_RETURN && $document === ReturnDocument::CreditNote && (new UndeliveredReturns($this->db))->neverDelivered($originId);
    }

    private function deleteLocked($saleId, bool $updateInventory, $employeeId): bool
    {
        $this->accountingFailure = null;

        // Start a transaction to assure data integrity
        $this->db->transStart();

        // The lock of the sale comes first, so two requests cancelling it never run side by side
        $locked      = $this->db->query('SELECT sale_status, delivered, sale_type, return_of_sale_id, return_document FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$saleId]);
        $sale        = $locked === false ? null : $locked->getRow();
        $saleStatus  = $sale === null ? null : (int) $sale->sale_status;

        try {
            (new QuoteLifecycle($this->db))->assertUnlocked((int) $saleId);
        } catch (AccountingException $refusal) {
            $this->db->transRollback();
            $this->accountingFailure = $refusal;

            return false;
        }

        $returnStands = $saleStatus === COMPLETED && $this->hasCompletedReturn((int) $saleId);

        if ($updateInventory && $saleStatus === COMPLETED && (int) $sale->delivered === 1 && ! $this->goodsNeverLeft((int) $sale->sale_type, ReturnDocument::fromStored($sale->return_document), $sale->return_of_sale_id === null ? null : (int) $sale->return_of_sale_id)) {
            // Defect, not all item deletions will be undone?
            // Get array with all the items involved in the sale to update the inventory tracking
            $inventory = model('Inventory');
            $item = model(Item::class);
            $itemQuantity = model(Item_quantity::class);

            $items = $this->get_sale_items($saleId)->getResultArray();

            $tracking = new StockTracking($this->db);
            $reversed = [];

            try {
                $reversed = $tracking->reverseSource('sale', (int) $saleId);
            } catch (AccountingException $refusal) {
                $this->db->transRollback();
                $this->accountingFailure = $refusal;

                return false;
            }

            foreach ($items as $itemData) {
                $itemInfo = $item->get_info($itemData['item_id']);

                if ($itemInfo->stock_type == HAS_STOCK) {
                    // Create query to update inventory tracking
                    $inventoryRow = [
                        'trans_date'      => date('Y-m-d H:i:s'),
                        'trans_items'     => $itemData['item_id'],
                        'trans_user'      => $employeeId,
                        'trans_comment'   => 'Deleting sale ' . $saleId,
                        'trans_location'  => $itemData['item_location'],
                        'trans_inventory' => $itemData['quantity_purchased']
                    ];
                    // Update inventory
                    $inventory->insert($inventoryRow, false);

                    // Update quantities
                    if (! in_array((int) $itemData['item_id'], $reversed, true)) {
                        $itemQuantity->changeQuantity($itemData['item_id'], $itemData['item_location'], $itemData['quantity_purchased']);
                    }
                }
            }
        }

        // After the tracked items were looked at, so that a sale of batches or serial numbers keeps the refusal that names them; the rollback takes back what was written above
        if ($returnStands) {
            return $this->refuseWhileReturnStands((int) $saleId);
        }

        if ($saleStatus === SUSPENDED) {
            $this->giveBackStoredValue((int) $saleId, true);
        }

        $this->update_sale_status($saleId, CANCELED);

        if ($saleStatus === COMPLETED && (new AccountingSettings($this->db))->isEnabled()) {
            try {
                (new SalePosting($this->db))->cancel((int) $saleId, $employeeId === null ? null : (int) $employeeId);
            } catch (Throwable $exception) {
                $this->db->transRollback();
                $this->recordAccountingFailure((int) $saleId, $this->asAccountingFailure($exception, (int) $saleId), $employeeId === null ? null : (int) $employeeId, $this->getBranchId((int) $saleId));

                return false;
            }
        }

        // Execute transaction
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * Confirms that the goods of an issued invoice have left. The stock is taken now, the cost of
     * each item is brought up to what the item costs at this moment, and the cost of the sale
     * is posted. Confirming again does nothing. Returns the sale id, NEW_ENTRY when there is no
     * issued invoice to deliver, or ACCOUNTING_FAILED when the books refused.
     */
    public function confirm_delivery(int $saleId, ?int $employeeId = null): int
    {
        $this->accountingFailure = null;

        if ($this->outsideRecordScope($saleId, $employeeId)) {
            return NEW_ENTRY;
        }

        $this->db->transStart();

        $locked = $this->db->query('SELECT sale_status, sale_type, delivered, branch_id FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$saleId]);
        $sale   = $locked === false ? null : $locked->getRow();

        if ($sale === null || (int) $sale->sale_status !== COMPLETED || (int) $sale->sale_type !== SALE_TYPE_INVOICE) {
            $this->db->transRollback();

            return NEW_ENTRY;
        }

        if ((int) $sale->delivered === 1) {
            $this->db->transComplete();

            return $saleId;
        }

        $inventory    = model('Inventory');
        $item         = model(Item::class);
        $itemQuantity = model(Item_quantity::class);
        $back         = (new UndeliveredReturns($this->db))->unitsOf($saleId);
        $negative     = new NegativeStockGuard($this->db);

        try {
            (new StockTracking($this->db))->requireUntracked(array_map(static fn (array $row): int => (int) $row['item_id'], $this->get_sale_items($saleId)->getResultArray()));
        } catch (AccountingException $refusal) {
            $this->db->transRollback();
            $this->accountingFailure = $refusal;

            return ACCOUNTING_FAILED;
        }

        foreach ($this->get_sale_items($saleId)->getResultArray() as $line) {
            $info = $item->get_info($line['item_id']);

            if ($info->stock_type != HAS_STOCK) {
                continue;
            }

            $this->db->table('sales_items')
                ->where(['sale_id' => $saleId, 'item_id' => $line['item_id'], 'line' => $line['line']])
                ->update(['item_cost_price' => $info->cost_price]);

            [$units, $back] = UndeliveredReturns::remaining((int) $line['item_id'], (string) $line['quantity_purchased'], $back);

            if (bccomp($units, '0', 3) === 0) {
                continue;
            }

            try {
                $negative->assertSale((int) $line['item_id'], (int) $line['item_location'], $units, $saleId);
            } catch (AccountingException $refusal) {
                $this->db->transRollback();
                $this->accountingFailure = $refusal;

                return ACCOUNTING_FAILED;
            }

            $itemQuantity->changeQuantity($line['item_id'], $line['item_location'], -(float) $units);

            $inventory->insert([
                'trans_date'      => date('Y-m-d H:i:s'),
                'trans_items'     => $line['item_id'],
                'trans_user'      => $employeeId,
                'trans_location'  => $line['item_location'],
                'trans_comment'   => 'POS ' . $saleId,
                'trans_inventory' => -(float) $units,
            ], false);
        }

        $this->db->table('sales')->where('sale_id', $saleId)->update(['delivered' => 1]);

        if ((new AccountingSettings($this->db))->isEnabled()) {
            try {
                (new SalePosting($this->db))->postDelivery($saleId, $employeeId);
            } catch (Throwable $exception) {
                $this->db->transRollback();
                $this->recordAccountingFailure($saleId, $this->asAccountingFailure($exception, $saleId), $employeeId, (int) $sale->branch_id);

                return ACCOUNTING_FAILED;
            }
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $saleId : NEW_ENTRY;
    }

    /**
     * Gets sale item
     */
    public function get_sale_items(int $sale_id): ResultInterface
    {
        $builder = $this->db->table('sales_items');
        $builder->where('sale_id', $sale_id);

        return $builder->get();
    }

    /**
     * Used by the invoice and receipt programs
     */
    public function get_sale_items_ordered(int $sale_id): ResultInterface
    {
        $config = config(OSPOS::class)->settings;
        $item = model(Item::class);

        $builder = $this->db->table('sales_items AS sales_items');
        $builder->select('
            sales_items.sale_id,
            sales_items.item_id,
            sales_items.description,
            serialnumber,
            line,
            quantity_purchased,
            item_cost_price,
            item_unit_price,
            discount,
            discount_type,
            item_location,
            print_option,
            invoice_discount_share,
            sales_items.unit_id,
            sales_items.unit_factor,
            sales_items.unit_quantity,
            sales_items.unit_price_entered,
            ' . $item->get_item_name('name') . ',
            category,
            item_type,
            stock_type');
        $builder->join('items AS items', 'sales_items.item_id = items.item_id');
        $builder->where('sales_items.sale_id', $sale_id);

        // Entry sequence (this will render kits in the expected sequence)
        if ($config['line_sequence'] == '0') {    // TODO: Replace these with constants and this should be converted to a switch.
            $builder->orderBy('line', 'asc');
        }
        // Group by Stock Type (nonstock first - type 1, stock next - type 0)
        elseif ($config['line_sequence'] == '1') {
            $builder->orderBy('stock_type', 'desc');
            $builder->orderBy('sales_items.description', 'asc');
            $builder->orderBy('items.name', 'asc');
            $builder->orderBy('items.qty_per_pack', 'asc');
        }
        // Group by Item Category
        elseif ($config['line_sequence'] == '2') {
            $builder->orderBy('category', 'asc');
            $builder->orderBy('sales_items.description', 'asc');
            $builder->orderBy('items.name', 'asc');
            $builder->orderBy('items.qty_per_pack', 'asc');
        }
        // Group by entry sequence in descending sequence (the Standard)
        else {
            $builder->orderBy('line', 'desc');
        }

        return $builder->get();
    }

    /**
     * Gets sale payments
     */
    public function get_sale_payments(int $sale_id): ResultInterface
    {
        $builder = $this->db->table('sales_payments');
        $builder->where('sale_id', $sale_id);

        return $builder->get();
    }

    /**
     * Gets sale payment options
     */
    public function get_payment_options(bool $giftcard = true, bool $reward_points = true, array $keep = []): array
    {
        $payments = get_payment_options($keep);

        if ($giftcard) {
            $payments[lang('Sales.giftcard')] = lang('Sales.giftcard');
        }

        if ($reward_points) {
            $payments[lang('Sales.rewards')] = lang('Sales.rewards');
        }
        $sale_lib = new Sale_lib();
        if ($sale_lib->get_mode() == 'sale_work_order') {
            $payments[lang('Sales.cash_deposit')] = lang('Sales.cash_deposit');
            $payments[lang('Sales.credit_deposit')] = lang('Sales.credit_deposit');
        }

        return $payments;
    }

    /**
     * Gets sale customer name
     */
    public function get_customer(int $sale_id): object
    {
        $customer = model(Customer::class);

        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        return $customer->get_info($builder->get()->getRow()->customer_id);
    }

    /**
     * Gets sale employee name
     */
    public function get_employee(int $sale_id): object
    {
        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        $employee = model(Employee::class);

        return $employee->get_info($builder->get()->getRow()->employee_id);
    }

    /**
     * Checks if quote number exists
     */
    public function check_quote_number_exists(string $quote_number, string $sale_id = ''): bool
    {
        $builder = $this->db->table('sales');
        $builder->where('quote_number', $quote_number);

        if (!empty($sale_id)) {
            $builder->where('sale_id !=', $sale_id);
        }

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Checks if invoice number exists
     */
    public function check_invoice_number_exists(string $invoice_number, string $sale_id = ''): bool
    {
        $builder = $this->db->table('sales');
        $builder->where('invoice_number', $invoice_number);

        if (!empty($sale_id)) {
            $builder->where('sale_id !=', $sale_id);
        }

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Checks if work order number exists
     */
    public function check_work_order_number_exists(string $work_order_number, string $sale_id = ''): bool
    {
        $builder = $this->db->table('sales');
        $builder->where('invoice_number', $work_order_number);
        if (!empty($sale_id)) {
            $builder->where('sale_id !=', $sale_id);
        }

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Gets Giftcard value
     */
    public function get_giftcard_value(string $giftcardNumber): float
    {
        $giftcard = model(Giftcard::class);

        if (!$giftcard->exists($giftcard->getGiftcardId($giftcardNumber))) {    // TODO: camelCase is used here for the variable name but we are using _ everywhere else. CI4 moved to camelCase... we should pick one and do that.
            return 0;
        }

        $builder = $this->db->table('giftcards');
        $builder->where('giftcard_number', $giftcardNumber);

        return $builder->get()->getRow()->value;
    }

    /**
     * Creates sales temporary dimensional table
     * We create a temp table that allows us to do easy report/sales queries
     */
    public function create_temp_table(array $inputs): void
    {
        $config = config(OSPOS::class)->settings;

        if (empty($inputs['sale_id'])) {
            if (empty($config['date_or_time_format'])) {    // TODO: This needs to be replaced with Ternary notation
                $where = 'DATE(sales.sale_time) BETWEEN ' . $this->db->escape($inputs['start_date']) . ' AND ' . $this->db->escape($inputs['end_date']);
            } else {
                $where = 'sales.sale_time BETWEEN ' . $this->db->escape(rawurldecode($inputs['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($inputs['end_date']));
            }
        } else {
            $where = 'sales.sale_id = ' . $this->db->escape($inputs['sale_id']);
        }

        if (isset($inputs['branch_ids'])) {
            $where .= $inputs['branch_ids'] === [] ? ' AND 1 = 0' : ' AND sales.branch_id IN (' . implode(', ', array_map('intval', $inputs['branch_ids'])) . ')';
        }

        $decimals = totals_decimals();

        $salePrice = 'CASE WHEN sales_items.discount_type = ' . PERCENT
            . " THEN sales_items.quantity_purchased * sales_items.item_unit_price - ROUND(sales_items.quantity_purchased * sales_items.item_unit_price * sales_items.discount / 100, $decimals) "
            . 'ELSE sales_items.quantity_purchased * (sales_items.item_unit_price - sales_items.discount) END - ' . InvoiceDiscount::sqlShare('sales_items');

        $saleCost = 'SUM(sales_items.item_cost_price * sales_items.quantity_purchased)';

        $tax = 'IFNULL(SUM(sales_items_taxes.tax), 0)';
        $salesTax = 'IFNULL(SUM(sales_items_taxes.sales_tax), 0)';
        $internalTax = 'IFNULL(SUM(sales_items_taxes.internal_tax), 0)';
        $cashAdjustment = 'IFNULL(SUM(payments.sale_cash_adjustment), 0)';

        $saleSubtotal = "ROUND(SUM($salePrice), $decimals) - $internalTax + $cashAdjustment";
        $saleTotal = "ROUND(SUM($salePrice), $decimals) + $salesTax + $cashAdjustment";

        // Create a temporary table to contain all the sum of taxes per sale item
        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->prefixTable('sales_items_taxes_temp') .
            ' (INDEX(sale_id), INDEX(item_id)) ENGINE=MEMORY
            (
                SELECT sales_items_taxes.sale_id AS sale_id,
                    sales_items_taxes.item_id AS item_id,
                    sales_items_taxes.line AS line,
                    SUM(ROUND(sales_items_taxes.item_tax_amount, ' . $decimals . ')) AS tax,
                    SUM(ROUND(CASE WHEN sales_items_taxes.tax_type = 0 THEN sales_items_taxes.item_tax_amount ELSE 0 END, ' . $decimals . ')) AS internal_tax,
                    SUM(ROUND(CASE WHEN sales_items_taxes.tax_type = 1 THEN sales_items_taxes.item_tax_amount ELSE 0 END, ' . $decimals . ')) AS sales_tax
                FROM ' . $this->db->prefixTable('sales_items_taxes') . ' AS sales_items_taxes
                INNER JOIN ' . $this->db->prefixTable('sales') . ' AS sales
                    ON sales.sale_id = sales_items_taxes.sale_id
                INNER JOIN ' . $this->db->prefixTable('sales_items') . ' AS sales_items
                    ON sales_items.sale_id = sales_items_taxes.sale_id AND sales_items.line = sales_items_taxes.line
                WHERE ' . $where . '
                GROUP BY sales_items_taxes.sale_id, sales_items_taxes.item_id, sales_items_taxes.line
            )';

        ReadCommittedStatement::run($this->db, $sql);

        // Create a temporary table to contain all the payment types and amount
        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->prefixTable('sales_payments_temp') .
            ' (PRIMARY KEY(sale_id), INDEX(sale_id))
            (
                SELECT payments.sale_id AS sale_id,
                    SUM(CASE WHEN payments.cash_adjustment = 0 THEN payments.payment_amount ELSE 0 END) AS sale_payment_amount,
                    SUM(CASE WHEN payments.cash_adjustment = 1 THEN payments.payment_amount ELSE 0 END) AS sale_cash_adjustment,
                    SUM(payments.cash_refund) AS sale_cash_refund,
                    GROUP_CONCAT(CONCAT(payments.payment_type, " ", (payments.payment_amount - payments.cash_refund)) SEPARATOR ", ") AS payment_type,
                    GROUP_CONCAT(NULLIF(payments.reference_code, "") SEPARATOR ", ") AS reference_code
                FROM ' . $this->db->prefixTable('sales_payments') . ' AS payments
                INNER JOIN ' . $this->db->prefixTable('sales') . ' AS sales
                    ON sales.sale_id = payments.sale_id
                WHERE ' . $where . '
                GROUP BY payments.sale_id
            )';

        ReadCommittedStatement::run($this->db, $sql);
        $item = model(Item::class);
        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->prefixTable('sales_items_temp') .
            ' (INDEX(sale_date), INDEX(sale_time), INDEX(sale_id))
            (
                SELECT
                    MAX(DATE(sales.sale_time)) AS sale_date,
                    MAX(sales.sale_time) AS sale_time,
                    sales.sale_id AS sale_id,
                    MAX(sales.sale_status) AS sale_status,
                    MAX(sales.sale_type) AS sale_type,
                    MAX(sales.comment) AS comment,
                    MAX(sales.invoice_number) AS invoice_number,
                    MAX(sales.quote_number) AS quote_number,
                    MAX(sales.customer_id) AS customer_id,
                    MAX(CONCAT(customer_p.first_name, " ", customer_p.last_name)) AS customer_name,
                    MAX(customer_p.first_name) AS customer_first_name,
                    MAX(customer_p.last_name) AS customer_last_name,
                    MAX(customer_p.email) AS customer_email,
                    MAX(customer_p.comments) AS customer_comments,
                    MAX(customer.company_name) AS customer_company_name,
                    MAX(sales.employee_id) AS employee_id,
                    MAX(CONCAT(employee.first_name, " ", employee.last_name)) AS employee_name,
                    items.item_id AS item_id,
                    MAX(' . $item->get_item_name() . ') AS name,
                    MAX(items.item_number) AS item_number,
                    MAX(items.category) AS category,
                    MAX(items.supplier_id) AS supplier_id,
                    MAX(sales_items.quantity_purchased) AS quantity_purchased,
                    MAX(sales_items.item_cost_price) AS item_cost_price,
                    MAX(sales_items.item_unit_price) AS item_unit_price,
                    MAX(sales_items.discount) AS discount,
                    MAX(IFNULL(sales_items.invoice_discount_share, 0)) AS invoice_discount,
                    sales_items.discount_type AS discount_type,
                    sales_items.line AS line,
                    MAX(sales_items.serialnumber) AS serialnumber,
                    MAX(sales_items.item_location) AS item_location,
                    MAX(sales_items.description) AS description,
                    MAX(payments.payment_type) AS payment_type,
                    MAX(payments.sale_payment_amount) AS sale_payment_amount,
                    ' . "
                    $saleSubtotal AS subtotal,
                    $tax AS tax,
                    $saleTotal AS total,
                    $saleCost AS cost,
                    ($saleSubtotal - $saleCost) AS profit
                    " . '
                FROM ' . $this->db->prefixTable('sales_items') . ' AS sales_items
                INNER JOIN ' . $this->db->prefixTable('sales') . ' AS sales
                    ON sales_items.sale_id = sales.sale_id
                INNER JOIN ' . $this->db->prefixTable('items') . ' AS items
                    ON sales_items.item_id = items.item_id
                LEFT OUTER JOIN ' . $this->db->prefixTable('sales_payments_temp') . ' AS payments
                    ON sales_items.sale_id = payments.sale_id
                LEFT OUTER JOIN ' . $this->db->prefixTable('suppliers') . ' AS supplier
                    ON items.supplier_id = supplier.person_id
                LEFT OUTER JOIN ' . $this->db->prefixTable('people') . ' AS customer_p
                    ON sales.customer_id = customer_p.person_id
                LEFT OUTER JOIN ' . $this->db->prefixTable('customers') . ' AS customer
                    ON sales.customer_id = customer.person_id
                LEFT OUTER JOIN ' . $this->db->prefixTable('people') . ' AS employee
                    ON sales.employee_id = employee.person_id
                LEFT OUTER JOIN ' . $this->db->prefixTable('sales_items_taxes_temp') . ' AS sales_items_taxes
                    ON sales_items.sale_id = sales_items_taxes.sale_id AND sales_items.item_id = sales_items_taxes.item_id AND sales_items.line = sales_items_taxes.line
                WHERE ' . $where . '
                GROUP BY sales.sale_id, items.item_id, sales_items.line, sales_items.discount_type
            )';

        ReadCommittedStatement::run($this->db, $sql);
    }

    /**
     * Retrieves all sales that are in a suspended state
     */
    public function get_all_suspended(?int $customer_id = null, ?int $viewerId = null): array
    {
        $cut      = $this->viewerCut($viewerId);
        $mineOnly = $cut === null ? '' : ' AND employee_id = ' . $cut;

        $documentId = "case when sale_type = '" . SALE_TYPE_QUOTE . "' THEN IF(quote_status = " . QuoteStatus::Draft->value . " AND (quote_number IS NULL OR quote_number = ''), CONCAT('" . DraftInvoice::PREFIX . "', sale_id), quote_number) WHEN sale_type = '" . SALE_TYPE_WORK_ORDER . "' THEN work_order_number WHEN sale_type = '" . SALE_TYPE_INVOICE . "' THEN CONCAT('" . DraftInvoice::PREFIX . "', sale_id) else sale_id end as doc_id";

        if ($customer_id == NEW_ENTRY) {
            $query = $this->db->query("SELECT sale_id, {$documentId}, sale_id as suspended_sale_id, sale_status, sale_time, dinner_table_id, customer_id, employee_id, comment, branch_id FROM "
                . $this->db->prefixTable('sales') . ' where sale_status = ' . SUSPENDED . $mineOnly);
        } else {
            $query = $this->db->query("SELECT sale_id, {$documentId}, sale_status, sale_time, dinner_table_id, customer_id, employee_id, comment, branch_id FROM "
                . $this->db->prefixTable('sales') . ' where sale_status = ' . SUSPENDED . ' AND customer_id = ' . $customer_id . $mineOnly);
        }

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Gets the dinner table for the selected sale
     */
    public function get_dinner_table(int $sale_id)    // TODO: this is returning null or the table_id.  We can keep it this way but multiple return types can't be declared until PHP 8.x
    {
        if ($sale_id == NEW_ENTRY) {
            return null;
        }

        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        return $builder->get()->getRow()->dinner_table_id;
    }

    /**
     * Gets the sale type for the selected sale
     */
    public function get_sale_type(int $sale_id)
    {
        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        return $builder->get()->getRow()->sale_type;
    }

    /**
     * Gets the sale status for the selected sale
     */
    public function getSaleStatus(int $sale_id): ?int
    {
        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        $row = $builder->get()->getRow();

        return $row === null ? null : $row->sale_status;
    }

    /**
     * @param int $sale_id
     * @param int $sale_status
     * @return void
     */
    public function update_sale_status(int $sale_id, int $sale_status): void
    {
        $builder = $this->db->table('sales');

        $builder->where('sale_id', $sale_id);
        $builder->update(['sale_status' => $sale_status]);
    }

    public function getInvoiceNumber(int $saleId): ?string
    {
        $row = $this->db->table('sales')->select('invoice_number')->where('sale_id', $saleId)->get()->getRow();

        return $row === null ? null : $row->invoice_number;
    }

    /**
     * Gets the quote_number for the selected sale
     */
    public function get_quote_number(int $sale_id): ?string
    {
        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        $row = $builder->get()->getRow();

        if ($row != null) {
            return $row->quote_number;
        }

        return null;
    }

    /**
     * Gets the work order number for the selected sale
     */
    public function get_work_order_number(int $sale_id): ?string
    {
        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        $row = $builder->get()->getRow();

        if ($row != null) {    // TODO: === ?
            return $row->work_order_number;
        }

        return null;
    }

    /**
     * Gets the quote_number for the selected sale
     */
    public function get_comment(int $sale_id): ?string
    {
        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        $row = $builder->get()->getRow();

        if ($row != null) {    // TODO: === ?
            return $row->comment;
        }

        return null;
    }

    /**
     * Gets total of suspended invoices rows
     */
    public function get_suspended_invoice_count(): int
    {
        $builder = $this->db->table('sales');
        $builder->where('invoice_number IS NOT NULL');
        $builder->where('sale_status', SUSPENDED);

        return $builder->countAllResults();
    }

    /**
     * Removes a selected sale from the sales table.
     * This function should only be called for suspended sales that are being restored to the current cart
     */
    public function delete_suspended_sale(int $sale_id): bool
    {
        $this->accountingFailure = null;

        if ($this->outsideRecordScope($sale_id, null)) {
            return false;
        }

        try {
            (new QuoteLifecycle($this->db))->assertUnlocked($sale_id);
        } catch (AccountingException $refusal) {
            $this->accountingFailure = $refusal;

            return false;
        }

        try {
            return (new CustomerLock($this->db))->run($this->customerOf($sale_id), fn (): bool => $this->discardSuspendedSaleLocked($sale_id));
        } catch (AccountingException $busy) {
            if ($busy->error !== AccountingError::CustomerBusy) {
                throw $busy;
            }

            $this->accountingFailure = $busy;

            return false;
        }
    }

    private function discardSuspendedSaleLocked(int $sale_id): bool
    {
        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();
        $config = config(OSPOS::class)->settings;

        $this->db->query('SELECT sale_status FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$sale_id]);

        if ($config['dinner_table_enable']) {
            $dinner_table = model(Dinner_table::class);
            $dinner_table_id = $this->get_dinner_table($sale_id);
            $dinner_table->release($dinner_table_id);
        }

        $this->giveBackStoredValue($sale_id, true);
        $this->update_sale_status($sale_id, CANCELED);

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * A sale that is held keeps the gift card value and the reward points it will be paid with, taken when it
     * was saved. This gives them back, once, so that saving the sale again, completing it or cancelling it
     * starts from what the card and the customer held before the sale: the points the hold earned are taken
     * back too, and its record of points is dropped. A sale that is not held gives nothing back. With
     * $forgetPayments the payments of the sale that were stored value are taken off its record, as a cancelled
     * sale that is brought back must not give the same value back twice. Call it inside the transaction that
     * changes the sale, after the row of the sale is locked.
     */
    private function giveBackStoredValue(int $saleId, bool $forgetPayments = false): void
    {
        $sale = $this->db->table('sales')->select('customer_id, sale_status')->where('sale_id', $saleId)->get()->getRow();

        if ($sale === null || (int) $sale->sale_status !== SUSPENDED) {
            return;
        }

        $giftcard  = model(Giftcard::class);
        $used      = '0';
        $forgotten = [];

        foreach ($this->db->table('sales_payments')->select('payment_id, payment_type, payment_amount')->where('sale_id', $saleId)->get()->getResult() as $payment) {
            $type = PaymentType::fromLabel((string) $payment->payment_type);

            if ($type === PaymentType::Giftcard) {
                $number = explode(':', (string) $payment->payment_type, 2)[1] ?? '';

                if ($number !== '') {
                    $giftcard->incrementGiftcardValue($number, (float) $payment->payment_amount);
                }
            } elseif ($type === PaymentType::Rewards) {
                $used = bcadd($used, (string) $payment->payment_amount, 4);
            } else {
                continue;
            }

            $forgotten[] = (int) $payment->payment_id;
        }

        $earned = '0';

        foreach ($this->db->table('sales_reward_points')->select('earned')->where('sale_id', $saleId)->get()->getResult() as $row) {
            $earned = bcadd($earned, (string) $row->earned, 4);
        }

        $this->db->table('sales_reward_points')->where('sale_id', $saleId)->delete();

        if ($sale->customer_id !== null) {
            model(Customer::class)->giveBackRewardPoints((int) $sale->customer_id, (float) $used, (float) $earned);
        }

        if ($forgetPayments && $forgotten !== []) {
            $this->db->table('sales_payments')->whereIn('payment_id', $forgotten)->delete();
        }
    }

    /**
     * This clears the sales detail for a given sale_id before the detail is re-saved.
     * This allows us to reuse the same sale_id
     */
    public function clear_suspended_sale_detail(int $sale_id): bool
    {
        if ($this->outsideRecordScope($sale_id, null)) {
            return false;
        }

        $this->db->transStart();
        $config = config(OSPOS::class)->settings;

        if ($config['dinner_table_enable']) {
            $dinner_table = model(Dinner_table::class);
            $dinner_table_id = $this->get_dinner_table($sale_id);
            $dinner_table->release($dinner_table_id);
        }

        $this->giveBackStoredValue($sale_id);

        $builder = $this->db->table('sales_payments');
        $builder->delete(['sale_id' => $sale_id]);

        $builder = $this->db->table('sales_items_taxes');
        $builder->delete(['sale_id' => $sale_id]);

        $builder = $this->db->table('sales_items');
        $builder->delete(['sale_id' => $sale_id]);

        $builder = $this->db->table('sales_taxes');
        $builder->delete(['sale_id' => $sale_id]);

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * Gets suspended sale info
     */
    public function get_suspended_sale_info(int $sale_id, ?int $viewerId = null): ResultInterface
    {
        $cut     = $this->viewerCut($viewerId);
        $builder = $this->db->table('sales');
        $builder->where('sale_id', $sale_id);

        if ($cut !== null) {
            $builder->where('sales.employee_id', $cut);
        }

        $builder->join('people', 'people.person_id = sales.customer_id', 'LEFT');
        $builder->where('sale_status', SUSPENDED);

        return $builder->get();
    }

    /**
     * @param int $customer_id
     * @param int $sale_id
     * @param float $total_amount
     * @param float $total_amount_used
     */
    private function save_customer_rewards(int $customer_id, int $sale_id, float $total_amount, float $total_amount_used): void
    {
        $config = config(OSPOS::class)->settings;

        if (!empty($customer_id) && $config['customer_reward_enable']) {
            $customer = model(Customer::class);
            $customerRewards = model(Customer_rewards::class);
            $rewards = model(Rewards::class);

            $packageId = $customer->get_info($customer_id)->package_id;

            if (! empty($packageId)) {
                $pointsPercent     = $customerRewards->get_points_percent($packageId);
                $pointsPercent     = ($pointsPercent === null ? 0 : $pointsPercent);
                $totalAmountEarned = ($total_amount * $pointsPercent / 100);

                $customer->adjustRewardPoints($customer_id, $totalAmountEarned);

                $rewardsData = ['sale_id' => $sale_id, 'earned' => $totalAmountEarned, 'used' => $total_amount_used];

                $rewards->save_value($rewardsData);
            }
        }
    }

    /**
     * Creates a temporary table to store the sales_payments data
     *
     * @param string $where
     * @return array
     */
    private function create_temp_table_sales_payments_data(string $where): void
    {
        $builder = $this->db->table('sales_payments AS payments');
        $builder->select([
            'payments.sale_id',
            'SUM(CASE WHEN `payments`.`cash_adjustment` = 0 THEN `payments`.`payment_amount` ELSE 0 END) AS sale_payment_amount',
            'SUM(CASE WHEN `payments`.`cash_adjustment` = 1 THEN `payments`.`payment_amount` ELSE 0 END) AS sale_cash_adjustment',
            'SUM(`payments`.`cash_refund`) AS sale_cash_refund',
            'GROUP_CONCAT(CONCAT(`payments`.`payment_type`, " ", (`payments`.`payment_amount` - `payments`.`cash_refund`)) SEPARATOR ", ") AS payment_type',
            'GROUP_CONCAT(NULLIF(`payments`.`reference_code`, "") SEPARATOR ", ") AS reference_code'
        ]);
        $builder->join('sales', 'sales.sale_id = payments.sale_id', 'inner');
        $builder->where($where);
        $builder->groupBy('payments.sale_id');

        $sub_query = $builder->getCompiledSelect();

        ReadCommittedStatement::run($this->db, 'CREATE TEMPORARY TABLE IF NOT EXISTS '
            . $this->db->prefixTable('sales_search_payments_temp')
            . ' (PRIMARY KEY(`sale_id`), INDEX(`sale_id`)) AS (' . $sub_query . ')');
    }

    /**
     * Temporary table to store the sales_items_taxes data
     *
     * @param string $where
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function create_temp_table_sales_items_taxes_data(string $where): void
    {

        $builder = $this->db->table('sales_items_taxes AS sales_items_taxes');
        $builder->select([
            'sales_items_taxes.sale_id AS sale_id',
            'sales_items_taxes.item_id AS item_id',
            'sales_items_taxes.line AS line',
            'SUM(sales_items_taxes.item_tax_amount) AS tax',
            'SUM(CASE WHEN sales_items_taxes.tax_type = 0 THEN sales_items_taxes.item_tax_amount ELSE 0 END) AS internal_tax',
            'SUM(CASE WHEN sales_items_taxes.tax_type = 1 THEN sales_items_taxes.item_tax_amount ELSE 0 END) AS sales_tax'
        ]);
        $builder->join('sales', 'sales.sale_id = sales_items_taxes.sale_id', 'inner');
        $builder->join('sales_items', 'sales_items.sale_id = sales_items_taxes.sale_id AND sales_items.line = sales_items_taxes.line', 'inner');
        $builder->where($where);
        $builder->groupBy(['sale_id', 'item_id', 'line']);
        $sub_query = $builder->getCompiledSelect();

        ReadCommittedStatement::run($this->db, 'CREATE TEMPORARY TABLE IF NOT EXISTS '
            . $this->db->prefixTable('sales_items_taxes_temp')
            . ' (INDEX(sale_id), INDEX(item_id)) AS (' . $sub_query . ')');
    }

    /**
     * @param string $search
     * @param array $filters
     * @param BaseBuilder $builder
     * @return void
     */
    private function add_filters_to_query(?string $search, array $filters, BaseBuilder $builder): void
    {
        if (!empty($search)) {    // TODO: this is duplicated code.  We should think about refactoring out a method
            if ($filters['is_valid_receipt']) {
                $pieces = explode(' ', $search);
                $builder->where('sales.sale_id', $pieces[1]);
            } else {
                $builder->groupStart();
                // Customer last name
                $builder->like('customer_p.last_name', $search);
                // Customer first name
                $builder->orLike('customer_p.first_name', $search);
                // Customer first and last name
                $builder->orLike('CONCAT(customer_p.first_name, " ", customer_p.last_name)', $search);
                // Customer company name
                $builder->orLike('customer.company_name', $search);
                if (ctype_digit($search)) {
                    $builder->orWhere('sales.sale_id', $search);    // Sale ID
                }
                $builder->groupEnd();
            }
        }

        if ($filters['location_id'] != 'all') {
            $builder->where('sales_items.item_location', $filters['location_id']);
        }

        if ($filters['selected_customer'] != false) {
            $sale_lib = new Sale_lib();
            $builder->where('sales.customer_id', $sale_lib->get_customer());
        }


        if ($filters['only_invoices']) {
            $builder->where('sales.invoice_number IS NOT NULL');
        }

        if ($filters['only_cash']) {
            $builder->groupStart();
            $builder->like('payments.payment_type', lang('Sales.cash'));
            $builder->orWhere('payments.payment_type IS NULL');
            $builder->groupEnd();
        }

        if ($filters['only_creditcard']) {
            $builder->like('payments.payment_type', lang('Sales.credit'));
        }

        if ($filters['only_debit']) {
            $builder->like('payments.payment_type', lang('Sales.debit'));
        }

        if ($filters['only_due']) {
            $builder->like('payments.payment_type', lang('Sales.due'));
        }

        if ($filters['only_check']) {
            $builder->like('payments.payment_type', lang('Sales.check'));
        }

        if ($filters['only_bank_transfer']) {
            $builder->like('payments.payment_type', lang('Sales.bank_transfer'));
        }

        if ($filters['only_wallet']) {
            $builder->like('payments.payment_type', lang('Sales.wallet'));
        }
    }
}
