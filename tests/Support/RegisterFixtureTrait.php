<?php

namespace Tests\Support;

use App\Libraries\Sale_lib;
use App\Libraries\Tax_lib;
use Config\OSPOS;

/**
 * What a test needs to ring lines up on the real register and save them the way the sales screen
 * does: the register works out the share of the discount on the whole invoice and the tax of
 * each line with the decimals of the shop's settings, and the sale model saves what it gives. A
 * class that uses it also uses SaleAccountingFixtureTrait, ItemFixtureTrait and ParityCaseTrait.
 */
trait RegisterFixtureTrait
{
    /**
     * An item whose cost is given, taxed with VAT at the rate given or untaxed.
     */
    protected function taxedItem(string $cost, bool $stock, ?string $taxPercent): int
    {
        $itemId = $this->item($cost, $stock);

        if ($taxPercent !== null) {
            $this->accountingDb()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'VAT', 'percent' => $taxPercent]);
        }

        return $itemId;
    }

    /**
     * Rings the lines up and saves the sale or the return of a sale. A line is the item, the quantity
     * (negative for a return), the price and the discount of the line.
     *
     * @param list<array{item: int, quantity: string, price: string, discount?: string, discount_type?: int}> $lines
     * @param array{type: int, value: string}|null                                                            $invoiceDiscount
     * @param array<string, string>                                                                           $settings        Settings of the shop to work with while the register works, such as tax_decimals.
     *
     * @return array{sale: int, cart: array<int, array<string, mixed>>, taxes: array<int, array<int, array<string, mixed>>>, totals: array<string, mixed>}
     */
    protected function ringUp(array $lines, ?array $invoiceDiscount = null, ?int $returnOf = null, bool $delivered = true, array $settings = [], bool $asReturn = false): array
    {
        $shop     = config(OSPOS::class);
        $original = $shop->settings;
        $before   = bcscale();

        $shop->settings = [...$original, ...$settings];

        try {
            bcscale(max(2, totals_decimals() + tax_decimals()));

            $register = new Sale_lib();
            $register->clear_all();
            $returning = $asReturn || $returnOf !== null;

            $register->set_mode($returning ? 'return' : 'sale');
            $register->set_customer($this->customerId);

            foreach ($lines as $line) {
                $itemId   = (string) $line['item'];
                $discount = $line['discount'] ?? '0';

                $this->assertTrue($register->add_item($itemId, self::LOCATION_ID, $line['quantity'], $discount, $line['discount_type'] ?? PERCENT, PRICE_MODE_STANDARD, null, null, $line['price']));
            }

            if ($returnOf !== null) {
                $register->set_return_origin($returnOf);
            } elseif ($invoiceDiscount !== null) {
                $register->setInvoiceDiscount($invoiceDiscount['type'], $invoiceDiscount['value']);
            }

            $cart   = $register->get_cart();
            $taxes  = (new Tax_lib())->get_taxes($cart);
            $totals = $register->get_totals($taxes[0]);
            $type   = $returning ? SALE_TYPE_RETURN : SALE_TYPE_INVOICE;
            $items  = array_values($cart);
            $state  = COMPLETED;

            $saleId = $this->sales->save_value(NEW_ENTRY, $state, $items, $this->customerId, $this->employeeId, 'test', null, null, null, $type, [], null, $taxes, $delivered, $returnOf, $returning ? null : $register->getInvoiceDiscount());

            if ($saleId > 0) {
                $this->saleIds[] = $saleId;
            }

            $register->clear_all();

            return ['sale' => $saleId, 'cart' => $cart, 'taxes' => $taxes, 'totals' => $totals];
        } finally {
            $shop->settings = $original;
            bcscale($before);
        }
    }

    /**
     * Makes the items of the lines of a case and puts the quantities, prices and discounts of the
     * case on them.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array{item: int, quantity: string, price: string, discount: string}>
     */
    protected function linesOfCase(array $lines): array
    {
        return array_map(fn (array $line): array => [
            'item'     => $this->taxedItem($line['cost'] ?? '0', $line['item'] === 'stock', $line['tax_percent']),
            'quantity' => $line['quantity'],
            'price'    => $line['price'],
            'discount' => $line['discount'],
        ], $lines);
    }

    /**
     * @param array{type: string, value: string}|null $discount
     *
     * @return array{type: int, value: string}|null
     */
    protected function discountOfCase(?array $discount): ?array
    {
        return $discount === null ? null : ['type' => $discount['type'] === 'fixed' ? FIXED : PERCENT, 'value' => $discount['value']];
    }

    /**
     * The account of each role a journal of a sale names.
     *
     * @return array<string, int>
     */
    protected function saleRoles(): array
    {
        return [
            'customer'      => $this->customerAccount(),
            'sales_revenue' => $this->accountId('sales_revenue'),
            'sales_returns' => $this->accountId('sales_returns'),
            'tax_output'    => $this->accountId('tax_output'),
            'cogs'          => $this->accountId('cogs'),
            'inventory'     => $this->stockAccount(),
        ];
    }

    /**
     * The lines of the journal of one kind that stands for a sale, none when it has none.
     *
     * @return list<array{role: string, debit: string, credit: string}>
     */
    protected function roleLinesOfSale(int $saleId, string $entry): array
    {
        $standing = $this->standing($saleId, $entry);

        $this->assertLessThanOrEqual(1, count($standing), "sale {$saleId} has more than one standing {$entry} journal");

        return $standing === [] ? [] : $this->roleLines($standing[0]['id'], $this->saleRoles());
    }
}
