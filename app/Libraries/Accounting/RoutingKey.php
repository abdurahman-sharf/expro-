<?php

namespace App\Libraries\Accounting;

/**
 * Every account a posting can need, named by the part of the business it belongs to. The
 * first nineteen keys are the rows of the six routing families of the reference system. The
 * others are accounts this application needs on top of them.
 */
enum RoutingKey: string
{
    case Customers             = 'sales.customers';
    case SalesRevenue          = 'sales.revenue';
    case SalesReturns          = 'sales.returns';
    case SalesDiscount         = 'sales.discount';
    case ProductSales          = 'sales.product';
    case SalesAdjustment       = 'sales.adjustment';
    case SalesShipping         = 'sales.shipping';
    case CostOfGoodsSold       = 'sales.cogs';
    case Suppliers             = 'purchases.suppliers';
    case Purchases             = 'purchases.purchases';
    case PurchaseReturns       = 'purchases.returns';
    case PurchaseDiscount      = 'purchases.discount';
    case PurchaseAdjustment    = 'purchases.adjustment';
    case StockLocations        = 'inventory.locations';
    case Treasuries            = 'treasuries.accounts';
    case StockPermitAdd        = 'stock_permits.add';
    case StockPermitIssue      = 'stock_permits.issue';
    case StockCountOut         = 'stock_permits.count_out';
    case StockCountIn          = 'stock_permits.count_in';
    case RetainedEarnings      = 'fiscal.retained_earnings';
    case CashDefault           = 'cash.default';
    case CashDifference        = 'cash.difference';
    case TaxOutput             = 'tax.output';
    case TaxInput              = 'tax.input';
    case ExpensesDefault       = 'expenses.default';
    case GiftcardLiability     = 'giftcards.liability';
    case OpeningBalance        = 'equity.opening';
    case CounterCustomers      = 'sales.counter';
    case Banks                 = 'treasuries.banks';
    case InterBranchReceivable = 'branches.receivable';
    case InterBranchPayable    = 'branches.payable';

    case NotesReceivable        = 'cheques.receivable';
    case ChequesUnderCollection = 'cheques.collection';
    case ChequeDepositFees      = 'cheques.fees';
    case NotesPayable           = 'cheques.payable';
    case RevenuesDefault        = 'revenues.default';

    public const GROUPS = ['sales', 'purchases', 'inventory', 'treasuries', 'cheques', 'stock_permits', 'fiscal', 'other'];

    public function group(): string
    {
        return match ($this) {
            self::Customers, self::SalesRevenue, self::SalesReturns, self::SalesDiscount, self::ProductSales, self::SalesAdjustment, self::SalesShipping, self::CostOfGoodsSold => 'sales',
            self::Suppliers, self::Purchases, self::PurchaseReturns, self::PurchaseDiscount, self::PurchaseAdjustment => 'purchases',
            self::StockLocations => 'inventory',
            self::Treasuries, self::Banks, self::InterBranchReceivable, self::InterBranchPayable => 'treasuries',
            self::NotesReceivable, self::ChequesUnderCollection, self::ChequeDepositFees, self::NotesPayable => 'cheques',
            self::StockPermitAdd, self::StockPermitIssue, self::StockCountOut, self::StockCountIn => 'stock_permits',
            self::RetainedEarnings => 'fiscal',
            default                => 'other',
        };
    }

    /**
     * The keys the routing screen edits, family by family. The other keys keep the account the
     * chart gave them until their own phases.
     *
     * @return list<self>
     */
    public static function editable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $key): bool => ! in_array($key, [self::CashDefault, self::TaxInput, self::ExpensesDefault, self::RevenuesDefault, self::OpeningBalance], true),
        ));
    }

    /**
     * @return list<self>
     */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::editable(), static fn (self $key): bool => $key->group() === $group));
    }

    /**
     * Whether the account is the main account of an entity kind: customers, suppliers, stock
     * locations and treasuries each get a sub-account under it.
     */
    public function isEntity(): bool
    {
        return $this->entityKind() !== null;
    }

    public function entityKind(): ?string
    {
        return match ($this) {
            self::Customers      => 'customer',
            self::Suppliers      => 'supplier',
            self::StockLocations => 'stock_location',
            self::Treasuries, self::Banks => 'treasury',
            default => null,
        };
    }

    /**
     * The types the row offers, in the order the reference system lists them.
     *
     * @return list<RoutingType>
     */
    public function allowedTypes(): array
    {
        return match ($this) {
            self::Customers => [RoutingType::Auto, RoutingType::PerItem, RoutingType::MainPerEntity],
            self::SalesRevenue, self::SalesReturns, self::Purchases, self::PurchaseReturns => [RoutingType::Auto, RoutingType::PerItem, RoutingType::Cancelled],
            self::SalesDiscount, self::PurchaseDiscount, self::SalesShipping => [RoutingType::Cancelled, RoutingType::Auto],
            self::ProductSales => [RoutingType::Cancelled, RoutingType::PerItem],
            self::SalesAdjustment, self::PurchaseAdjustment => [RoutingType::Cancelled, RoutingType::Auto, RoutingType::PerItem],
            self::CostOfGoodsSold => [RoutingType::Cancelled, RoutingType::Auto],
            self::Suppliers, self::StockLocations, self::Treasuries, self::Banks => [RoutingType::Auto, RoutingType::PerItem],
            default => [RoutingType::Auto],
        };
    }

    /**
     * The rows whose lines are made up of the goods of a document, so that each product may name its
     * own account for them. The cost of sales row and the stock row are not among them: the valuation
     * of the stock and what it ties to are kept by location, and a product with an account of its own
     * would split the stock of a location over accounts that no tie reads.
     *
     * @return list<self>
     */
    public static function perItemKeys(): array
    {
        return [self::SalesRevenue, self::SalesReturns];
    }

    public function takesItemAccounts(): bool
    {
        return in_array($this, self::perItemKeys(), true);
    }

    /**
     * Whether posting applies the type on this row. The purchase adjustment row takes amounts that no
     * document produces yet, so choosing "automatic" there would change nothing and look done. A row
     * may be saved with a type it offers that nothing applies on it: posting then stops with a named
     * failure and the readiness check says so.
     */
    public function applies(RoutingType $type): bool
    {
        return match ($type) {
            RoutingType::Auto          => $this !== self::PurchaseAdjustment,
            RoutingType::Cancelled     => true,
            RoutingType::PerItem       => $this->takesItemAccounts(),
            RoutingType::MainPerEntity => $this->isEntity(),
        };
    }

    public function defaultType(): RoutingType
    {
        return match ($this) {
            self::SalesDiscount, self::ProductSales, self::PurchaseDiscount, self::PurchaseAdjustment => RoutingType::Cancelled,
            default => RoutingType::Auto,
        };
    }

    /**
     * The reference system calls the cancelled choice "no routing" on these rows: there is
     * no account to cancel, the row simply sets nothing.
     */
    public function cancelMeansNone(): bool
    {
        return in_array($this, [self::ProductSales, self::CostOfGoodsSold], true);
    }

    /**
     * Whether the row needs an account while its type uses one. The product sales row and the
     * purchase returns row may stay empty: the document decides. So may the two rows between
     * branches, which only a transfer between branches reads.
     */
    public function accountRequired(): bool
    {
        return ! in_array($this, [self::ProductSales, self::PurchaseReturns, self::InterBranchReceivable, self::InterBranchPayable], true);
    }

    /**
     * @return list<AccountType>
     */
    public function allowedAccountTypes(): array
    {
        return match ($this) {
            self::Customers, self::StockLocations, self::Treasuries, self::Banks, self::StockPermitIssue, self::CashDefault, self::CounterCustomers, self::InterBranchReceivable, self::NotesReceivable, self::ChequesUnderCollection => [AccountType::Asset],
            self::Suppliers, self::StockPermitAdd, self::TaxOutput, self::GiftcardLiability, self::InterBranchPayable, self::NotesPayable => [AccountType::Liability],
            self::SalesRevenue, self::SalesReturns, self::ProductSales, self::SalesShipping => [AccountType::Revenue],
            self::SalesDiscount, self::SalesAdjustment, self::PurchaseDiscount, self::PurchaseAdjustment => [AccountType::Revenue, AccountType::Expense],
            self::CostOfGoodsSold, self::StockCountOut, self::StockCountIn, self::ChequeDepositFees => [AccountType::Expense],
            self::Purchases, self::PurchaseReturns => [AccountType::Asset, AccountType::Expense],
            self::CashDifference   => [AccountType::Asset, AccountType::Revenue, AccountType::Expense],
            self::RetainedEarnings => [AccountType::Equity],
            self::TaxInput         => [AccountType::Asset, AccountType::Liability],
            default                => [],
        };
    }

    /**
     * The system accounts the row may point at: its own default, and for the two rows that
     * start empty the closest account of the chart. The adjustment row may also point at the
     * account of discounts allowed, which was its default before adjustments were posted.
     *
     * @return list<string>
     */
    public function acceptedSystemKeys(): array
    {
        return match ($this) {
            self::ProductSales    => [self::SalesRevenue->defaultSystemKey()],
            self::PurchaseReturns => [self::Purchases->defaultSystemKey()],
            self::SalesAdjustment => [self::SalesAdjustment->defaultSystemKey(), self::SalesDiscount->defaultSystemKey()],
            default               => [$this->defaultSystemKey()],
        };
    }

    public function defaultSystemKey(): ?string
    {
        return match ($this) {
            self::Customers             => 'receivables',
            self::SalesRevenue          => 'sales_revenue',
            self::SalesReturns          => 'sales_returns',
            self::SalesDiscount         => 'sales_discount',
            self::ProductSales          => null,
            self::SalesAdjustment       => 'sales_adjustment',
            self::SalesShipping         => 'sales_shipping',
            self::CostOfGoodsSold       => 'cogs',
            self::Suppliers             => 'payables',
            self::Purchases             => 'purchases',
            self::PurchaseReturns       => null,
            self::PurchaseDiscount      => 'purchase_discount',
            self::PurchaseAdjustment    => 'purchase_discount',
            self::StockLocations        => 'inventory',
            self::Treasuries            => 'treasuries',
            self::Banks                 => 'banks',
            self::StockPermitAdd        => 'other_payables',
            self::StockPermitIssue      => 'other_receivables',
            self::StockCountOut         => 'cogs',
            self::StockCountIn          => 'cogs',
            self::RetainedEarnings      => 'retained_earnings',
            self::CashDefault           => 'cash',
            self::CashDifference        => 'cash_difference',
            self::TaxOutput             => 'tax_output',
            self::TaxInput              => 'tax_input',
            self::ExpensesDefault       => 'expenses_default',
            self::GiftcardLiability     => 'giftcard_liability',
            self::OpeningBalance        => 'opening_balance',
            self::CounterCustomers      => 'counter_customers',
            self::InterBranchReceivable => 'branches_receivable',
            self::InterBranchPayable    => 'branches_payable',

            self::NotesReceivable        => 'notes_receivable',
            self::ChequesUnderCollection => 'cheques_under_collection',
            self::ChequeDepositFees      => 'cheque_deposit_fees',
            self::NotesPayable           => 'notes_payable',
            self::RevenuesDefault        => 'revenues_default',
        };
    }
}
