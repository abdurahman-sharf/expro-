<?php

namespace app\Libraries;

use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\InvoiceDiscountProblem;
use App\Libraries\Accounting\InvoiceDiscountShares;
use App\Libraries\Accounting\ItemPurchaseTax;
use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\ReturnDiscountShares;
use App\Models\Attribute;
use App\Models\Item;
use App\Models\Item_kit_items;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Receiving;
use App\Models\Stock_location;
use CodeIgniter\Session\Session;
use Config\OSPOS;

/**
 * Receiving library
 *
 * Library with utilities to manage receivings
 */
class Receiving_lib
{
    private Attribute $attribute;
    private Item $item;
    private Item_kit_items $item_kit_items;
    private Item_quantity $item_quantity;
    private Item_taxes $item_taxes;
    private Receiving $receiving;
    private Stock_location $stock_location;
    private Session $session;

    public function __construct()
    {
        $this->attribute      = model(Attribute::class);
        $this->item           = model(Item::class);
        $this->item_kit_items = model(Item_kit_items::class);
        $this->item_quantity  = model(Item_quantity::class);
        $this->item_taxes     = model(Item_taxes::class);
        $this->receiving      = model(Receiving::class);
        $this->stock_location = model(Stock_location::class);

        $this->session = session();
    }

    public function get_cart(): array
    {
        if (! $this->session->get('recv_cart')) {
            $this->set_cart([]);
        }

        return $this->session->get('recv_cart');
    }

    public function set_cart(array $cart_data): void
    {
        $this->session->set('recv_cart', $cart_data);
    }

    public function empty_cart(): void
    {
        $this->session->remove('recv_cart');
    }

    public function get_supplier(): int
    {
        if (! $this->session->get('recv_supplier')) {
            $this->set_supplier(-1);    // TODO: Replace -1 with a constant.
        }

        return $this->session->get('recv_supplier');
    }

    public function set_supplier(int $supplier_id): void
    {
        $this->session->set('recv_supplier', $supplier_id);
    }

    public function remove_supplier(): void
    {
        $this->session->remove('recv_supplier');
    }

    public function get_mode(): string
    {
        if (! $this->session->get('recv_mode')) {
            $this->set_mode('receive');
        }

        return $this->session->get('recv_mode');
    }

    public function set_mode(string $mode): void
    {
        $this->session->set('recv_mode', $mode);
    }

    public function clear_mode(): void    // TODO: This function verb is inconsistent from the others.  Consider refactoring to remove_mode()
    {
        $this->session->remove('recv_mode');
    }

    /**
     * The receiving the goods of a return go back from, or null when none was named.
     */
    public function get_return_origin(): ?int
    {
        $origin = $this->session->get('recv_return_origin');

        return $origin === null || $origin === '' ? null : (int) $origin;
    }

    public function set_return_origin(?int $receivingId): void
    {
        $receivingId === null ? $this->session->remove('recv_return_origin') : $this->session->set('recv_return_origin', $receivingId);
    }

    /**
     * The discount on the whole purchase that was entered in the register, or none.
     */
    public function getInvoiceDiscount(): InvoiceDiscount
    {
        $kept = $this->session->get('recv_invoice_discount');

        return is_array($kept) ? (InvoiceDiscount::of((int) ($kept['type'] ?? PERCENT), (string) ($kept['value'] ?? '0')) ?? InvoiceDiscount::none()) : InvoiceDiscount::none();
    }

    public function setInvoiceDiscount(InvoiceDiscount $discount): void
    {
        $discount->isNone() ? $this->clearInvoiceDiscount() : $this->session->set('recv_invoice_discount', ['type' => $discount->type, 'value' => $discount->value]);
    }

    public function clearInvoiceDiscount(): void
    {
        $this->session->remove('recv_invoice_discount');
    }

    /**
     * What the tax of an item is by default: the sum of its purchase taxes, each of which follows the
     * sales tax of the same number while it is not set, or nothing for goods that are not taxed.
     */
    public function default_tax_percent(int $itemId): string
    {
        return ItemPurchaseTax::defaultPercent($this->item->get_info($itemId), $this->item_taxes->get_info($itemId));
    }

    public function get_stock_source(): int
    {
        if (! $this->session->get('recv_stock_source')) {
            $this->set_stock_source($this->stock_location->get_default_location_id('receivings', true));
        }

        return $this->session->get('recv_stock_source');
    }

    public function get_comment(): string
    {
        $comment = $this->session->get('recv_comment');

        return empty($comment) ? '' : $comment;
    }

    public function set_comment(string $comment): void
    {
        $this->session->set('recv_comment', $comment);
    }

    public function clear_comment(): void    // TODO: This function verb is inconsistent from the others.  Consider refactoring to remove_comment()
    {
        $this->session->remove('recv_comment');
    }

    public function get_reference(): string
    {
        return $this->session->get('recv_reference') ?? '';
    }

    public function set_reference(string $reference): void
    {
        $this->session->set('recv_reference', $reference);
    }

    public function clear_reference(): void    // TODO: This function verb is inconsistent from the others.  Consider refactoring to remove_reference()
    {
        $this->session->remove('recv_reference');
    }

    public function is_print_after_sale(): bool
    {
        return $this->session->get('recv_print_after_sale') == 'true'
            || $this->session->get('recv_print_after_sale') == '1';
    }

    public function set_print_after_sale(bool $print_after_sale): void
    {
        $this->session->set('recv_print_after_sale', $print_after_sale);
    }

    public function set_stock_source(int $stock_source): void
    {
        $this->session->set('recv_stock_source', $stock_source);
    }

    public function clear_stock_source(): void
    {
        $this->session->remove('recv_stock_source');
    }

    public function get_stock_destination(): string
    {
        if (! $this->session->get('recv_stock_destination')) {
            $this->set_stock_destination($this->stock_location->get_default_location_id('receivings', true));
        }

        return $this->session->get('recv_stock_destination');
    }

    public function set_stock_destination(?string $stock_destination): void
    {
        $this->session->set('recv_stock_destination', $stock_destination);
    }

    public function clear_stock_destination(): void
    {
        $this->session->remove('recv_stock_destination');
    }
    // TODO: This array signature needs to be reworked.  It's way too long. Perhaps an object needs to be passed rather than these?

    /**
     * @param string|null $taxPercent The tax rate of the line, or null for the rate the item carries.
     */
    public function add_item(string $itemId, int|float $quantity = 1, ?int $itemLocation = null, float $discount = 0, int $discountType = 0, ?float $price = null, ?string $description = null, ?string $serialNumber = null, ?float $receivingQuantity = null, ?int $receivingId = null, bool $includeDeleted = false, ?string $taxPercent = null): bool
    {
        $config   = config(OSPOS::class)->settings;
        $itemInfo = $this->item->get_info_by_id_or_number($itemId, $includeDeleted);

        if (empty($itemInfo)) {
            return false;
        }

        $itemId = $itemInfo->item_id;
        $items  = $this->get_cart();

        $maxKey            = 0;
        $itemAlreadyInSale = false;
        $updateKey         = 0;

        foreach ($items as $item) {
            if ($maxKey <= $item['line']) {
                $maxKey = $item['line'];
            }

            if ($item['item_id'] == $itemId && $item['item_location'] == $itemLocation) {
                $itemAlreadyInSale = true;
                $updateKey         = $item['line'];
            }
        }

        $insertKey = $maxKey + 1;
        $itemInfo  = $this->item->get_info((int) $itemId);

        $price = $price != null ? $price : $itemInfo->cost_price;

        if ($config['multi_pack_enabled']) {
            $itemInfo->name .= NAME_SEPARATOR . $itemInfo->pack_name;
        }

        if ($itemInfo->receiving_quantity == 0 || $itemInfo->receiving_quantity == 1) {
            $receivingQuantityChoices = [1 => 'x1'];
        } else {
            $receivingQuantityChoices = [
                to_quantity_decimals($itemInfo->receiving_quantity) => 'x' . to_quantity_decimals($itemInfo->receiving_quantity),
                1                                                   => 'x1',
            ];
        }

        if (null === $receivingQuantity) {
            $receivingQuantity = $itemInfo->receiving_quantity;
        }

        $attributeLinks = $this->attribute->get_link_values((int) $itemId, 'receiving_id', $receivingId, Attribute::SHOW_IN_RECEIVINGS)->getRowObject();

        $item = [
            $insertKey => [
                'item_id'                    => $itemId,
                'item_location'              => $itemLocation,
                'item_number'                => $itemInfo->item_number,
                'stock_name'                 => $this->stock_location->get_location_name($itemLocation),
                'line'                       => $insertKey,
                'name'                       => $itemInfo->name,
                'description'                => $description != null ? $description : $itemInfo->description,
                'serialnumber'               => $serialNumber != null ? $serialNumber : '',
                'attribute_values'           => $attributeLinks->attribute_values,
                'attribute_dtvalues'         => $attributeLinks->attribute_dtvalues,
                'allow_alt_description'      => $itemInfo->allow_alt_description,
                'is_serialized'              => $itemInfo->is_serialized,
                'quantity'                   => $quantity,
                'discount'                   => $discount,
                'discount_type'              => $discountType,
                'in_stock'                   => $this->item_quantity->get_item_quantity((int) $itemId, $itemLocation)->quantity,
                'price'                      => $price,
                'receiving_quantity'         => $receivingQuantity,
                'receiving_quantity_choices' => $receivingQuantityChoices,
                'tax_percent'                => $this->get_mode() === 'requisition' ? '0' : ($taxPercent ?? $this->default_tax_percent((int) $itemId)),
                'total'                      => $this->get_item_total($quantity, $price, $discount, $discountType, $receivingQuantity),
            ],
        ];

        if ($itemAlreadyInSale) {
            $items[$updateKey]['quantity'] += $quantity;
            $items[$updateKey]['total'] = $this->get_item_total($items[$updateKey]['quantity'], $price, $discount, $discountType, $items[$updateKey]['receiving_quantity']);
        } else {
            $items += $item;
        }

        $this->set_cart($items);

        return true;
    }

    public function edit_item($line, string $description, string $serialnumber, float $quantity, float $discount, ?int $discount_type, float $price, float $receiving_quantity, ?string $tax_percent = null): bool
    {
        $items = $this->get_cart();
        if (isset($items[$line])) {
            $line                       = &$items[$line];
            $line['description']        = $description;
            $line['serialnumber']       = $serialnumber;
            $line['quantity']           = $quantity;
            $line['receiving_quantity'] = $receiving_quantity;
            $line['discount']           = $discount;

            if (null !== $discount_type) {
                $line['discount_type'] = $discount_type;
            }

            $line['price'] = $price;

            if ($tax_percent !== null && $this->get_mode() !== 'requisition') {
                $line['tax_percent'] = $tax_percent;
            }

            $line['total'] = $this->get_item_total($quantity, $price, $discount, $discount_type, $receiving_quantity);
            $this->set_cart($items);
        }

        return false;    // TODO: This function will always return false.
    }

    /**
     * @param $line int|string The item_number or item_id of the item to be removed from the receiving.
     */
    public function delete_item(int|string $line): void
    {
        $items = $this->get_cart();
        unset($items[$line]);
        $this->set_cart($items);
    }

    public function return_entire_receiving(int $receipt_receiving_id): void
    {
        // RECV #
        $pieces = explode(' ', $receipt_receiving_id);

        if (preg_match('/(RECV|KIT)/', $pieces[0])) {    // TODO: this needs to be converted to ternary notation.
            $receiving_id = $pieces[1];
        } else {
            $receiving_id = $this->receiving->get_receiving_by_reference($receipt_receiving_id)->getRow()->receiving_id;
        }

        $this->empty_cart();
        $this->remove_supplier();
        $this->clear_comment();

        foreach ($this->receiving->get_receiving_items($receiving_id)->getResult() as $row) {
            $this->add_item($row->item_id, -$row->quantity_purchased, $row->item_location, $row->discount, $row->discount_type, $row->item_unit_price, $row->description, $row->serialnumber, $row->receiving_quantity, $receiving_id, true, (string) $row->tax_percent);
        }

        $this->set_supplier($this->receiving->get_supplier($receiving_id)->person_id);
        $this->set_return_origin((int) $receiving_id);
    }

    /**
     * Prepares a return of what a purchase brought in and has not been given back yet: the units
     * of each line less what earlier returns took from the same item, at the price and the tax of
     * the line. It is the debit note of the purchase.
     */
    public function return_remaining_of_receiving(int $receivingId): void
    {
        $this->empty_cart();
        $this->remove_supplier();
        $this->clear_comment();

        $left = [];

        foreach ($this->receiving->getReturnableQuantities($receivingId) as $itemId => $quantities) {
            $left[$itemId] = $quantities['returned'];
        }

        foreach ($this->receiving->get_receiving_items($receivingId)->getResult() as $row) {
            $perPack   = (float) $row->receiving_quantity !== 0.0 ? (float) $row->receiving_quantity : 1.0;
            $units     = (float) $row->quantity_purchased * $perPack;
            $taken     = min($units, $left[(int) $row->item_id] ?? 0.0);
            $remaining = $units - $taken;

            $left[(int) $row->item_id] = ($left[(int) $row->item_id] ?? 0.0) - $taken;

            if ($remaining <= 0) {
                continue;
            }

            $discount = (int) $row->discount_type === FIXED_LINE ? round((float) $row->discount * $remaining / $units, 2) : $row->discount;

            $this->add_item($row->item_id, -($remaining / $perPack), $row->item_location, $discount, $row->discount_type, $row->item_unit_price, $row->description, $row->serialnumber, $row->receiving_quantity, $receivingId, true, (string) $row->tax_percent);
        }

        $this->set_supplier($this->receiving->get_supplier($receivingId)->person_id);
        $this->set_return_origin($receivingId);
    }

    public function add_item_kit(string $external_item_kit_id, int $item_location, float $discount, int $discount_type): void
    {
        // KIT #
        $pieces      = explode(' ', $external_item_kit_id);
        $item_kit_id = count($pieces) > 1 ? $pieces[1] : $external_item_kit_id;

        foreach ($this->item_kit_items->get_info($item_kit_id) as $item_kit_item) {
            $this->add_item($item_kit_item['item_id'], $item_kit_item['quantity'], $item_location, $discount, $discount_type);
        }
    }

    public function copy_entire_receiving(int $receiving_id): void
    {
        $this->empty_cart();
        $this->remove_supplier();

        foreach ($this->receiving->get_receiving_items($receiving_id)->getResult() as $row) {
            $this->add_item($row->item_id, $row->quantity_purchased, $row->item_location, $row->discount, $row->discount_type, $row->item_unit_price, $row->description, $row->serialnumber, $row->receiving_quantity, $receiving_id, true, (string) $row->tax_percent);
            $this->addStoredShare((int) $row->item_id, (int) $row->item_location, (string) ($row->invoice_discount_share ?? '0'));
        }

        $header = $this->receiving->get_info($receiving_id)->getRow();

        if ($header !== null) {
            $this->setInvoiceDiscount(InvoiceDiscount::stored($header->invoice_discount_type === null ? null : (int) $header->invoice_discount_type, $header->invoice_discount === null ? null : (string) $header->invoice_discount));
        }

        $this->set_supplier((int) $this->receiving->get_supplier($receiving_id)->person_id);
        // $this->set_reference($this->receiving->get_info($receiving_id)->getRow()->reference);    // TODO: If this code won't be added back in, then let's delete it.
    }

    public function clear_all(): void
    {
        $this->clear_mode();
        $this->empty_cart();
        $this->remove_supplier();
        $this->clear_comment();
        $this->clear_reference();
        $this->set_return_origin(null);
        $this->clearInvoiceDiscount();
    }

    /**
     * Notes on the cart line of a purchase that was saved what its row took of the discount on the
     * whole purchase, so that its receipt shows what was saved and not what would be worked out now.
     */
    private function addStoredShare(int $itemId, int $location, string $share): void
    {
        $items = $this->get_cart();

        foreach ($items as $line => $item) {
            if ((int) $item['item_id'] === $itemId && (int) $item['item_location'] === $location) {
                $items[$line]['invoice_discount_share'] = bcadd((string) ($item['invoice_discount_share'] ?? '0'), $share, ReceivingAmounts::SCALE);
            }
        }

        $this->set_cart($items);
    }

    public function get_item_total(float $quantity, float $price, float $discount, ?int $discount_type, float $receiving_quantity): string
    {
        $extended_quantity = bcmul($quantity, $receiving_quantity);
        $total             = bcmul($extended_quantity, $price);
        $discount_amount   = bcmul($extended_quantity, $discount);

        if ($discount_type == PERCENT) {    // TODO: === ?
            $discount_fraction = bcdiv($discount, 100);
            $discount_amount   = bcmul($total, $discount_fraction);
        } elseif ($discount_type == FIXED_LINE) {
            $discount_amount = bccomp($extended_quantity, '0', 3) < 0 ? bcmul($discount, '-1') : (string) $discount;
        }

        return bcsub($total, $discount_amount);
    }

    /**
     * What the lines cost, after their own discounts and before the discount on the whole purchase
     * and the tax.
     */
    public function get_total(): string
    {
        $figures = $this->invoiceFigures();

        if ($figures !== null) {
            return $figures['before'];
        }

        $total = 0;

        foreach ($this->get_cart() as $item) {
            $total = bcadd($total, $this->get_item_total(($item['quantity']), $item['price'], $item['discount'], $item['discount_type'], $item['receiving_quantity']));
        }

        return $total;
    }

    /**
     * What the discount on the whole purchase takes off, with the sign that lowers the total: below
     * nothing for goods that come in, above it for goods that go back. Nothing when there is none.
     */
    public function getInvoiceDiscountAmount(): string
    {
        return $this->invoiceFigures()['discount'] ?? '0';
    }

    /**
     * The tax on the lines, each at the rate of its own line, after its share of the discount on the
     * whole purchase.
     */
    public function get_tax_total(): string
    {
        $figures = $this->invoiceFigures();

        if ($figures !== null) {
            return $figures['tax'];
        }

        $tax = '0';

        foreach ($this->get_cart() as $item) {
            $net = $this->get_item_total($item['quantity'], $item['price'], $item['discount'], $item['discount_type'], $item['receiving_quantity']);
            $tax = bcadd($tax, bcmul($net, bcdiv((string) ($item['tax_percent'] ?? '0'), '100', 8), 8), 8);
        }

        return $tax;
    }

    /**
     * What the supplier is owed: the cost after the discount on the whole purchase, and the tax on it.
     */
    public function get_total_with_tax(): string
    {
        $figures = $this->invoiceFigures();

        return $figures !== null ? $figures['total'] : bcadd($this->get_total(), $this->get_tax_total(), 8);
    }

    /**
     * Why the discount of the register cannot be saved with the lines it holds now, when it cannot: the
     * lines were lowered or removed after it was applied, or they hold goods going out. The totals of
     * the register show the discount capped, so this is what tells the buyer that saving will refuse it.
     */
    public function invoiceDiscountProblem(): ?InvoiceDiscountProblem
    {
        $discount = $this->getInvoiceDiscount();
        $items    = array_values($this->get_cart());

        if ($discount->isNone() || $items === [] || $this->get_mode() !== 'receive') {
            return null;
        }

        $lines = array_map(static fn (array $item): ReceivingLine => ReceivingLine::ofCart($item), $items);

        if (ReceivingType::ofQuantities(array_map(static fn (ReceivingLine $line): string => $line->quantity, $lines)) !== ReceivingType::Receive) {
            return InvoiceDiscountProblem::NotGoodsIn;
        }

        $base = array_reduce($lines, static fn (string $sum, ReceivingLine $line): string => bcadd($sum, ReceivingAmounts::lineNet($line), ReceivingAmounts::SCALE), '0');

        return $discount->fitsOn($base) ? null : InvoiceDiscountProblem::MoreThanTheLines;
    }

    /**
     * What the cart comes to when part of it is taken off by the discount on the whole purchase, as
     * the purchase will be saved and posted: null when none is, so that a cart without one is added up
     * exactly as it always was. Amounts have the sign of the goods: below nothing when they go back.
     *
     * @return array{before: numeric-string, discount: numeric-string, tax: numeric-string, total: numeric-string}|null
     */
    private function invoiceFigures(): ?array
    {
        $items = array_values($this->get_cart());

        if ($items === []) {
            return null;
        }

        $lines  = array_map(static fn (array $item): ReceivingLine => ReceivingLine::ofCart($item), $items);
        $type   = ReceivingType::ofQuantities(array_map(static fn (ReceivingLine $line): string => $line->quantity, $lines));
        $shares = $this->sharesOf($items, $lines, $type);

        if (array_filter($shares, static fn (string $share): bool => bccomp($share, '0', ReceivingAmounts::SCALE) > 0) === []) {
            return null;
        }

        $shared = [];
        $before = '0';

        foreach ($items as $index => $item) {
            $shared[] = ReceivingLine::ofCart($item, $shares[$index]);
            $before   = bcadd($before, ReceivingAmounts::lineNet($lines[$index]), ReceivingAmounts::SCALE);
        }

        $totals = ReceivingAmounts::totals($shared);
        $given  = array_reduce($shared, static fn (string $sum, ReceivingLine $line): string => bcadd($sum, ReceivingAmounts::invoiceDiscount($line), ReceivingAmounts::SCALE), '0');
        $sign   = $type === ReceivingType::Return ? '-1' : '1';

        return [
            'before'   => bcmul($before, $sign, ReceivingAmounts::SCALE),
            'discount' => bcmul($given, bcsub('0', $sign), ReceivingAmounts::SCALE),
            'tax'      => bcmul($totals['tax'], $sign, ReceivingAmounts::SCALE),
            'total'    => bcmul($totals['total'], $sign, 2),
        ];
    }

    /**
     * What each line of the cart takes of the discount on the whole purchase: what a saved purchase
     * kept, or else the discount of the register for goods coming in and the share of the purchase a
     * return comes from for goods going back.
     *
     * @param list<array<string, mixed>> $items
     * @param list<ReceivingLine>        $lines
     *
     * @return list<numeric-string>
     */
    private function sharesOf(array $items, array $lines, ReceivingType $type): array
    {
        if (count(array_filter($items, static fn (array $item): bool => array_key_exists('invoice_discount_share', $item))) === count($items)) {
            return array_map(static fn (array $item): string => bcadd((string) $item['invoice_discount_share'], '0', ReceivingAmounts::SCALE), $items);
        }

        $origin = $this->get_return_origin();

        return match (true) {
            $type === ReceivingType::Receive                     => InvoiceDiscountShares::forPurchase($lines, $this->getInvoiceDiscount()),
            $type === ReceivingType::Return && $origin !== null => (new ReturnDiscountShares())->forReturn($origin, $lines),
            default                                              => array_fill(0, count($lines), '0.0000'),
        };
    }
}
