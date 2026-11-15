<?php

namespace app\Libraries;

use App\Libraries\Sale_lib;
use App\Models\Customer;
use App\Models\Enums\Rounding_mode;
use App\Models\Item_taxes;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\Tax_category;
use App\Models\Tax_code;
use App\Models\Tax_definition;
use App\Models\Tax_jurisdiction;
use Config\OSPOS;

/**
 * Tax library
 *
 * Library with utilities to manage taxes
 */
class Tax_lib
{
    public const TAX_TYPE_EXCLUDED = '1';    // TODO: These constants need to be moved to constants.php
    public const TAX_TYPE_INCLUDED = '0';

    private Sale_lib $sale_lib;
    private Customer $customer;
    private Item_taxes $item_taxes;
    private Sale $sale;
    private Tax $tax;
    private Tax_category $tax_category;
    private Tax_code $tax_code;
    private Tax_definition $taxDefinition;
    private Tax_jurisdiction $tax_jurisdiction;
    private array $config;

    public function __construct()
    {
        $this->sale_lib = new Sale_lib();

        $this->customer         = model(Customer::class);
        $this->item_taxes       = model(Item_taxes::class);
        $this->sale             = model(Sale::class);
        $this->tax              = model(Tax::class);
        $this->tax_category     = model(Tax_category::class);
        $this->tax_code         = model(Tax_code::class);
        $this->taxDefinition    = model(Tax_definition::class);
        $this->tax_jurisdiction = model(Tax_jurisdiction::class);
        $this->config           = config(OSPOS::class)->settings;
    }

    public function get_tax_types(): array
    {
        return [
            Tax_lib::TAX_TYPE_EXCLUDED => lang('Taxes.tax_excluded'),
            Tax_lib::TAX_TYPE_INCLUDED => lang('Taxes.tax_included'),
        ];
    }

    /**
     * Compute the tax basis and returns the tax amount
     */
    public function get_item_sales_tax(string $quantity, string $price, string $discount, int $discount_type, string $tax_percentage, int $rounding_code): string    // TODO: It appears this function is never called in the code.
    {
        $decimals = tax_decimals();

        // The tax basis should be returned to the currency scale
        $tax_basis = $this->sale_lib->get_item_total($quantity, $price, $discount, $discount_type, true);

        return $this->get_tax_for_amount($tax_basis, $tax_percentage, $rounding_code, $decimals);
    }

    /**
     * Computes the item level sales tax amount for a given tax basis
     */
    public function get_tax_for_amount(string $tax_basis, string $tax_percentage, int $rounding_mode, int $decimals): string
    {
        $tax_amount = bcmul($tax_basis, bcdiv($tax_percentage, '100'));

        return Rounding_mode::round_number($rounding_mode, $tax_amount, $decimals);
    }

    /**
     * Compute taxes for all items in the cart. The customer and the mode are the register's unless
     * the lines belong to a document that is not in the register, which names its own. The taxes of a
     * saved sale, and of the return that takes goods back from a sale, are the ones that sale was saved
     * with, mode and account included, and not what the tax list says today.
     */
    public function get_taxes(array &$cart, int $saleId = -1, ?int $customerId = null, ?string $registerMode = null): array    // TODO: Replace -1 with constant.
    {
        $registerModeUsed = $registerMode ?? $this->sale_lib->get_mode();
        $taxDecimals      = tax_decimals();
        $customerIdUsed   = $customerId ?? $this->sale_lib->get_customer();
        $customerInfo     = $this->customer->get_info($customerIdUsed);
        $taxes            = [];
        $itemTaxes        = [];

        // Charge sales tax if customer is not selected (walk-in) or customer is flagged as taxable
        if ($customerIdUsed == -1 || $customerInfo->taxable) {    // TODO: Replace -1 with constant.
            $storedSale    = $this->storedSource($saleId, $registerMode);
            $taxInfoByLine = [];
            $definitions   = [];

            if (! $this->config['use_destination_based_tax']) {
                foreach ($cart as $line => $item) {
                    $taxInfoByLine[$line] = $storedSale === null
                        ? $this->item_taxes->get_info($item['item_id'])
                        : $this->sale->get_sales_item_taxes($storedSale, $item['item_id']);
                }

                if ($storedSale === null) {
                    $definitions = $this->taxDefinition->forNames(array_column(array_merge([], ...array_values($taxInfoByLine)), 'name'));
                }
            }

            foreach ($cart as $line => $item) {
                $taxed = false;

                if (! $this->config['use_destination_based_tax']) {
                    // Start of current Base System tax calculations
                    $taxGroupSequence = 0;

                    foreach ($this->lineTaxes($item, $taxInfoByLine[$line], $definitions, $taxDecimals) as $tax) {
                        if ($tax['amount'] != 0) {
                            $taxGroupSequence++;
                            $this->update_taxes($taxes, $tax['type'], $tax['name'], $tax['percent'], $tax['basis'], $tax['amount'], $taxGroupSequence, Rounding_mode::HALF_UP, -1, $tax['name']);    // TODO: Replace -1 with constant.
                            $taxGroupSequence++;    // TODO: $taxGroupSequence++;
                            $taxed = true;
                        }

                        $itemTaxes[] = [
                            'item_id'              => $item['item_id'],
                            'line'                 => $item['line'],
                            'name'                 => $tax['name'],
                            'percent'              => $tax['percent'],
                            'tax_type'             => $tax['type'],
                            'rounding_code'        => Rounding_mode::HALF_UP,
                            'cascade_sequence'     => 0,
                            'item_tax_amount'      => $tax['amount'],
                            'sales_tax_code_id'    => null,
                            'jurisdiction_id'      => null,
                            'tax_category_id'      => null,
                            'liability_account_id' => $tax['account'],
                        ];
                    }
                } else {
                    // Start of destination based tax calculations
                    if ($item['tax_category_id'] == null) {    // TODO: === ?
                        $item['tax_category_id'] = $this->config['default_tax_category'];
                    }

                    $taxed = $this->apply_destination_tax($item, $customerInfo->city, $customerInfo->state, $customerInfo->sales_tax_code_id, $registerModeUsed, 0, $taxes, $itemTaxes, $item['line']);
                }

                if ($taxed) {    // TODO: Convert to ternary notation
                    $cart[$line]['taxed_flag'] = lang('Sales.taxed_ind');
                } else {
                    $cart[$line]['taxed_flag'] = lang('Sales.nontaxed_ind');
                }
            }
            $this->round_taxes($taxes);
        }

        $taxDetails    = [];
        $taxDetails[0] = $taxes;
        $taxDetails[1] = $itemTaxes;

        return $taxDetails;
    }

    /**
     * The taxes of one item as the register would charge them now, for a quantity at a price.
     *
     * @return list<array{name: string, percent: string, type: string, amount: string, basis: string, account: int|null}>
     */
    public function taxesOfItem(int $itemId, string $quantity, string $price, string $discount, int $discountType, string $invoiceDiscountShare = '0'): array
    {
        $taxInfo = $this->item_taxes->get_info($itemId);
        $item    = ['quantity' => $quantity, 'price' => $price, 'discount' => $discount, 'discount_type' => $discountType, 'invoice_discount_share' => $invoiceDiscountShare];

        return $this->lineTaxes($item, $taxInfo, $this->taxDefinition->forNames(array_column($taxInfo, 'name')), tax_decimals());
    }

    /**
     * The tax of one line, each tax with the mode it is charged in. A tax that is inside the price
     * is taken out of it, and what is left is the net the taxes that are added are worked out on,
     * so that two taxes on a line are each charged on the same net. When only one tax is inside the
     * price it is taken out as it always was; when several are, the net is the price less the sum of
     * their rates over that sum plus a hundred, and each is charged on the net, unless the rows are
     * those of a saved sale, whose amounts are kept for the units of the line.
     *
     * @param array<string, mixed>                                         $item
     * @param list<array<string, mixed>>                                   $taxInfo     The tax rows of the item: the item's own, or those a sale was saved with.
     * @param array<string, array{included: bool|null, account: int|null}> $definitions By the name in lower case.
     *
     * @return list<array{name: string, percent: string, type: string, amount: string, basis: string, account: int|null}>
     */
    public function lineTaxes(array $item, array $taxInfo, array $definitions, int $taxDecimals): array
    {
        $share = $item['invoice_discount_share'] ?? '0';
        $total = $this->sale_lib->get_item_total($item['quantity'], $item['price'], $item['discount'], $item['discount_type'], true, $share);
        $rows  = [];

        foreach ($taxInfo as $info) {
            $rows[] = [
                'name'     => (string) $info['name'],
                'percent'  => (string) $info['percent'],
                'included' => $this->isIncluded($info, $definitions),
                'account'  => $this->liabilityAccount($info, $definitions),
                'amount'   => '0',
                'basis'    => $total,
                'saved'    => $info['item_tax_amount'] ?? null,
                'sold'     => $info['sold_quantity'] ?? null,
            ];
        }

        $includedRows = array_keys(array_filter($rows, static fn (array $row): bool => $row['included']));
        $net          = $total;

        if (count($includedRows) === 1) {
            $index                  = $includedRows[0];
            $rows[$index]['amount'] = $this->get_included_tax($item['quantity'], $item['price'], $item['discount'], $item['discount_type'], $rows[$index]['percent'], $taxDecimals, Rounding_mode::HALF_UP, $share);
            $net                    = bcsub($total, $rows[$index]['amount']);
        } elseif (count($includedRows) > 1) {
            $quantity = (string) $item['quantity'];
            $net      = $this->savedAmountsOf($rows, $includedRows)
                ? $this->takeSavedOut($rows, $includedRows, $quantity, $total)
                : $this->takeSeveralOut($rows, $includedRows, $total);
        }

        $taxes = [];

        foreach ($rows as $row) {
            if (! $row['included']) {
                $row['amount'] = $this->get_tax_for_amount($net, $row['percent'], Rounding_mode::HALF_UP, $taxDecimals);
                $row['basis']  = $net;
            }

            $taxes[] = [
                'name'    => $row['name'],
                'percent' => $row['percent'],
                'type'    => $row['included'] ? Tax_lib::TAX_TYPE_INCLUDED : Tax_lib::TAX_TYPE_EXCLUDED,
                'amount'  => $row['amount'],
                'basis'   => $row['basis'],
                'account' => $row['account'],
            ];
        }

        return $taxes;
    }

    /**
     * Whether every one of these rows carries what the sale it comes from charged for the item, and the
     * units the sale sold of it, so that the amounts of a saved document can be taken from it.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int>                  $indexes
     */
    private function savedAmountsOf(array $rows, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if ($rows[$index]['saved'] === null || $rows[$index]['sold'] === null || bccomp((string) $rows[$index]['sold'], '0') === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the taxes inside the price were when the sale was saved, for the units of this line: a sale
     * saved before the taxes were taken out together took each of them out of the whole price, and the
     * document that gives its goods back, or prints it again, must show what that sale charged and not
     * what the arithmetic of today would make of the price.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int>                  $indexes
     */
    private function takeSavedOut(array &$rows, array $indexes, string $quantity, string $total): string
    {
        $taken = '0';

        foreach ($indexes as $index) {
            $rows[$index]['amount'] = bcdiv(bcmul((string) $rows[$index]['saved'], $quantity, 8), (string) $rows[$index]['sold'], 4);
            $taken                  = bcadd($taken, $rows[$index]['amount']);
        }

        return bcsub($total, $taken);
    }

    /**
     * The net of a price with several taxes inside it: the price less the sum of their rates over that
     * sum plus a hundred, each tax charged on that net.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<int>                  $indexes
     */
    private function takeSeveralOut(array &$rows, array $indexes, string $total): string
    {
        $rates = '0';

        foreach ($indexes as $index) {
            $rates = bcadd($rates, $rows[$index]['percent']);
        }

        $net = bcsub($total, bcdiv(bcmul($total, $rates), bcadd($rates, '100')));

        foreach ($indexes as $index) {
            $rows[$index]['amount'] = bcmul($net, bcdiv($rows[$index]['percent'], '100'));
        }

        return $net;
    }

    /**
     * A row that was saved with a sale says whether its tax was inside the price, and that is not
     * asked again. The tax of an item is asked of the tax list by its name, and a tax the list does not
     * know, or knows with no mode, follows the default mode of the shop.
     *
     * @param array<string, mixed>                                         $info
     * @param array<string, array{included: bool|null, account: int|null}> $definitions
     */
    private function isIncluded(array $info, array $definitions): bool
    {
        if (isset($info['tax_type'])) {
            return (string) $info['tax_type'] === Tax_lib::TAX_TYPE_INCLUDED;
        }

        $included = $definitions[Tax_definition::key((string) $info['name'])]['included'] ?? null;

        return $included ?? (bool) ($this->config['tax_included'] ?? false);
    }

    /**
     * @param array<string, mixed>                                         $info
     * @param array<string, array{included: bool|null, account: int|null}> $definitions
     */
    private function liabilityAccount(array $info, array $definitions): ?int
    {
        if (array_key_exists('liability_account_id', $info)) {
            return $info['liability_account_id'] === null ? null : (int) $info['liability_account_id'];
        }

        return $definitions[Tax_definition::key((string) $info['name'])]['account'] ?? null;
    }

    /**
     * The sale whose saved tax rows the taxes of these lines are read from, or null when they are
     * worked out from the items: the sale itself when it is saved, or the sale a return in the register
     * takes its goods back from.
     */
    private function storedSource(int $saleId, ?string $registerMode): ?int
    {
        if ($saleId !== NEW_ENTRY) {
            return $saleId;
        }

        return $registerMode === null && $this->sale_lib->is_return_mode() ? $this->sale_lib->get_return_origin() : null;
    }

    public function get_included_tax(string $quantity, string $price, string $discount_percentage, int $discount_type, string $tax_percentage, $tax_decimal, $rounding_code, string $invoiceDiscountShare = '0'): string    // TODO: $tax_decimal and $rounding_code are in the signature but never used in the function.
    {
        $itemTotal    = $this->sale_lib->get_item_total($quantity, $price, $discount_percentage, $discount_type, true, $invoiceDiscountShare);
        $taxFraction  = bcdiv(bcadd('100', $tax_percentage), '100');
        $priceTaxExcl = bcdiv($itemTotal, $taxFraction);

        return bcsub($itemTotal, $priceTaxExcl);
    }

    /**
     * Updates the sales_tax array which is later saved to the `sales_taxes` table and used for printing taxes on receipts and invoices
     */
    public function update_taxes(array &$taxes, string $taxType, string $taxGroup, string $taxRate, string $taxBasis, string $itemTaxAmount, int $taxGroupSequence, int $roundingCode, int $saleId, string $name = '', ?int $taxCodeId = null, ?int $jurisdictionId = null, ?int $taxCategoryId = null): void
    {
        $taxGroupIndex = $this->clean('X' . (float) $taxRate . '% ' . $taxGroup);    // TODO: Not sure we should be casting to a float here.  The clean() function takes a string, so it just gets converted back to a string and there's risk of inaccuracies in the value displayed.

        if (isset($taxes[$taxGroupIndex]) && (string) $taxes[$taxGroupIndex]['tax_type'] !== $taxType) {
            $taxGroupIndex .= '-' . $taxType;
        }

        if (! array_key_exists($taxGroupIndex, $taxes)) {
            $insertKey = $taxGroupIndex;

            $tax = [
                $insertKey => [
                    'sale_id'           => $saleId,
                    'tax_type'          => $taxType,
                    'tax_group'         => $taxGroup,
                    'sale_tax_basis'    => $taxBasis,
                    'sale_tax_amount'   => $itemTaxAmount,
                    'print_sequence'    => $taxGroupSequence,
                    'name'              => $name,
                    'tax_rate'          => $taxRate,
                    'sales_tax_code_id' => $taxCodeId,
                    'jurisdiction_id'   => $jurisdictionId,
                    'tax_category_id'   => $taxCategoryId,
                    'rounding_code'     => $roundingCode,
                ],
            ];

            // Add to existing array
            $taxes += $tax;
        } else {
            // Important: the sales amounts are accumulated for the group at the maximum configurable scale value of 4
            // but the scale will in reality be the scale specified by the tax_decimal configuration value  used for sales_items_taxes
            $taxes[$taxGroupIndex]['sale_tax_basis']  = bcadd($taxes[$taxGroupIndex]['sale_tax_basis'], $taxBasis, 4);
            $taxes[$taxGroupIndex]['sale_tax_amount'] = bcadd($taxes[$taxGroupIndex]['sale_tax_amount'], $itemTaxAmount, 4);
        }
    }

    /**
     * If invoice taxing (as opposed to invoice_item_taxing) rules apply then recalculate the sales tax after tax group totals are final
     * This is currently used ONLY for the original sales tax migration.
     */
    public function apply_invoice_taxing(array &$taxes): void
    {
        if (! empty($taxes)) {
            $sort = [];

            foreach ($taxes as $k => $v) {
                $sort['print_sequence'][$k] = $v['print_sequence'];
            }
            array_multisort($sort['print_sequence'], SORT_ASC, $taxes);
        }

        $decimals = totals_decimals();

        foreach ($taxes as $row_number => $tax) {
            $taxes[$row_number]['sale_tax_amount'] = $this->get_tax_for_amount($tax['sale_tax_basis'], $tax['tax_rate'], $tax['rounding_code'], $decimals);
        }
    }

    /**
     * Apply rounding rules to the accumulated sales tax amounts
     */
    public function round_taxes(array &$taxes): void
    {
        if (! empty($taxes)) {
            $sort = [];

            foreach ($taxes as $k => $v) {
                $sort['print_sequence'][$k] = $v['print_sequence'];
            }
            array_multisort($sort['print_sequence'], SORT_ASC, $taxes);
        }

        foreach ($taxes as $rowNumber => $salesTax) {
            // A tax that is inside the price is rounded to tax decimals, one that is added to currency_decimals
            $included = $this->config['use_destination_based_tax']
                ? (bool) $this->config['tax_included']
                : (string) $salesTax['tax_type'] === Tax_lib::TAX_TYPE_INCLUDED;
            $decimals = $included ? tax_decimals() : totals_decimals();

            $taxAmount        = $salesTax['sale_tax_amount'];
            $roundingCode     = $salesTax['rounding_code'];
            $roundedTaxAmount = $taxAmount;

            if ($roundingCode == Rounding_mode::HALF_UP) {    // TODO: this block needs to be converted to a switch statement
                $roundedTaxAmount = round($taxAmount, $decimals, PHP_ROUND_HALF_UP);
            } elseif ($roundingCode == Rounding_mode::HALF_DOWN) {
                $roundedTaxAmount = round($taxAmount, $decimals, PHP_ROUND_HALF_DOWN);
            } elseif ($roundingCode == Rounding_mode::HALF_EVEN) {
                $roundedTaxAmount = round($taxAmount, $decimals, PHP_ROUND_HALF_EVEN);
            } elseif ($roundingCode == Rounding_mode::HALF_ODD) {
                $roundedTaxAmount = round($taxAmount, $decimals, PHP_ROUND_HALF_UP);
            } elseif ($roundingCode == Rounding_mode::ROUND_UP) {
                $fig              = (int) str_pad('1', $decimals, '0');
                $roundedTaxAmount = ceil($taxAmount * $fig) / $fig;
            } elseif ($roundingCode == Rounding_mode::ROUND_DOWN) {
                $fig              = (int) str_pad('1', $decimals, '0');
                $roundedTaxAmount = floor($taxAmount * $fig) / $fig;
            } elseif ($roundingCode == Rounding_mode::HALF_FIVE) {
                $roundedTaxAmount = round($taxAmount / 5) * 5;
            }

            $taxes[$rowNumber]['sale_tax_amount'] = $roundedTaxAmount;
        }
    }

    /**
     * Determine the applicable tax code and then determine the tax amount to be applied.
     * If a tax amount was identified then accumulate into the sales_taxes array
     */
    public function apply_destination_tax(array &$item, string $city, string $state, int $sales_tax_code_id, string $register_mode, int $sale_id, array &$taxes, array &$item_taxes, int $line): bool
    {
        $taxed = false;

        $tax_code_id = $this->get_applicable_tax_code($register_mode, $city, $state, $sales_tax_code_id);

        // If tax code cannot be determined or the price is zero then skip this item
        if ($tax_code_id != -1 && $item['price'] != 0) {    // TODO: Replace -1 with constant. Also === ?
            $tax_decimals = tax_decimals();

            $tax_definition = $this->tax->get_taxes($tax_code_id, $item['tax_category_id']);

            // The tax basis should be returned to the currency scale
            $tax_basis = $this->sale_lib->get_item_total($item['quantity'], $item['price'], $item['discount'], $item['discount_type'], true, $item['invoice_discount_share'] ?? '0');

            $row = 0;    // TODO: This variable is set but never used.

            $last_cascade_sequence = 0;
            $cascade_tax_amount    = '0.0';

            foreach ($tax_definition as $tax) {
                $cascade_sequence = $tax['cascade_sequence'];
                if ($cascade_sequence != $last_cascade_sequence) {
                    $last_cascade_sequence = $cascade_sequence;
                    $tax_basis             = bcadd($tax_basis, $cascade_tax_amount);
                }

                $tax_rate      = $tax['tax_rate'];
                $rounding_code = $tax['tax_rounding_code'];

                // This computes tax for each line item and adds it to the tax type total
                $tax_type = $tax['tax_type'];

                if ($tax_type == Tax_lib::TAX_TYPE_INCLUDED) {
                    $tax_amount = $this->get_included_tax($item['quantity'], $item['price'], $item['discount'], $item['discount_type'], $tax_rate, $tax_decimals, $rounding_code, $item['invoice_discount_share'] ?? '0');
                } else {
                    $tax_amount         = $this->get_tax_for_amount($tax_basis, $tax_rate, $rounding_code, $tax_decimals);
                    $cascade_tax_amount = bcadd($cascade_tax_amount, $tax_amount);
                }

                if ($tax_amount != 0) {
                    $taxed = true;
                    $this->update_taxes($taxes, $tax_type, $tax['tax_group'], $tax_rate, $tax_basis, $tax_amount, $tax['tax_group_sequence'], $rounding_code, $sale_id, $tax['tax_group'], $tax_code_id, $tax['rate_jurisdiction_id'], $item['tax_category_id']);
                }

                $item_taxes_detail                       = [];
                $item_taxes_detail['line']               = $line;
                $item_taxes_detail['item_id']            = $item['item_id'];
                $item_taxes_detail['name']               = $tax['tax_group'];
                $item_taxes_detail['percent']            = $tax['tax_rate'];
                $item_taxes_detail['tax_type']           = $tax_type;
                $item_taxes_detail['rounding_code']      = $rounding_code;
                $item_taxes_detail['cascade_sequence']   = $cascade_sequence;
                $item_taxes_detail['item_tax_amount']    = $tax_amount;
                $item_taxes_detail['sales_tax_code_id']  = $tax_code_id;
                $item_taxes_detail['jurisdiction_id']    = $tax['rate_jurisdiction_id'];
                $item_taxes_detail['tax_category_id']    = $tax['rate_tax_category_id'];
                $item_taxes_detail['tax_group_sequence'] = $tax['tax_group_sequence'];

                $item_taxes[] = $item_taxes_detail;
            }
        }

        return $taxed;
    }

    public function get_applicable_tax_code(string $register_mode, string $city, string $state, int $sales_tax_code_id): int
    {
        if ($register_mode == 'sale') {
            $sales_tax_code_id = $this->config['default_tax_code']; // Overrides customer assigned code
        } else {
            if ($sales_tax_code_id == null || $sales_tax_code_id == 0) {
                $sales_tax_code_id = $this->tax_code->get_sales_tax_code($city, $state);

                if ($sales_tax_code_id == null || $sales_tax_code_id == 0) {
                    $sales_tax_code_id = $this->config['default_tax_code']; // Overrides customer assigned code
                }
            }
        }

        return $sales_tax_code_id;
    }

    public function clean(string $string): string    // TODO: $string is not a good choice of variable name here.
    {
        $string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.

        return preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
    }

    public function get_tax_code_options(): array
    {
        $tax_codes            = $this->tax_code->get_all()->getResultArray();
        $tax_code_options     = [];
        $tax_code_options[''] = '';

        foreach ($tax_codes as $tax_code) {
            $a                    = $tax_code['tax_code_id'];    // TODO: Need to refactor single-letter variables into meaningful variable names
            $b                    = $tax_code['tax_code_name'];
            $tax_code_options[$a] = $b;
        }

        return $tax_code_options;
    }

    public function get_tax_jurisdiction_options(): array
    {
        $tax_jurisdictions           = $this->tax_jurisdiction->get_all()->getResultArray();
        $tax_jurisdiction_options    = [];
        $tax_jurisdiction_options[0] = '';

        foreach ($tax_jurisdictions as $tax_jurisdiction) {    // TODO: Need to refactor single-letter variables into meaningful variable names
            $a                            = $tax_jurisdiction['jurisdiction_id'];
            $b                            = $tax_jurisdiction['jurisdiction_name'];
            $tax_jurisdiction_options[$a] = $b;
        }

        return $tax_jurisdiction_options;
    }

    public function get_tax_category_options(): array
    {
        $tax_categories          = $this->tax_category->get_all()->getResultArray();
        $tax_category_options    = [];
        $tax_category_options[0] = '';

        foreach ($tax_categories as $tax_category) {
            $a = $tax_category['tax_category_id'];    // TODO: Need to refactor single-letter variables into meaningful variable names
            $b = $tax_category['tax_category'];

            $tax_category_options[$a] = $b;
        }

        return $tax_category_options;
    }

    public function get_tax_type_options(string $selected_tax_type): string
    {
        $selected = 'selected=\"selected\" ';

        $s1 = '';    // TODO: Need to refactor short variables into meaningful variable names
        $s2 = '';

        if ($selected_tax_type === Tax_lib::TAX_TYPE_EXCLUDED) {
            $s1 = $selected;
        } elseif ($selected_tax_type === Tax_lib::TAX_TYPE_INCLUDED) {
            $s2 = $selected;
        }

        return '<option value=\"' . Tax_lib::TAX_TYPE_EXCLUDED . '\" ' . $s1 . '> ' . lang('Taxes.sales_tax') . '</option>'
            . '<option value=\"' . Tax_lib::TAX_TYPE_INCLUDED . '\" ' . $s2 . '> ' . lang('Taxes.vat_tax') . '</option>';
    }
}
