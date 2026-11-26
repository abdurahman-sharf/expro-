<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The rules of a bundle, which is an item kit made of components, with the setting on. A bundle has no
 * stock of its own: the product that carries its price keeps none and is of the kit type, its price option
 * is the one that gives the price to the kit and nothing to its components, so selling it earns the price
 * of the bundle and takes its components out of stock at the cost each of them has, and the cost of the
 * sale is the sum of those costs. A bundle cannot contain itself or another bundle: a component is an
 * ordinary item, never the product that carries the price of any bundle.
 */
final class Bundles
{
    private BaseConnection $db;
    private StockOptions $options;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->options = new StockOptions($this->db);
    }

    /**
     * Whether a kit with these components may be saved as a bundle. With the setting off nothing is asked
     * of a kit, as before.
     *
     * @param list<int> $componentItemIds
     *
     * @throws AccountingException
     */
    public function assertValid(?int $pricedItemId, array $componentItemIds, ?int $priceOption): void
    {
        if (! $this->options->bundlesEnabled()) {
            return;
        }

        $priced = $pricedItemId === null || $pricedItemId <= 0 ? null : $this->db->table('items')->select('item_id, name, item_type, stock_type')->where('item_id', $pricedItemId)->get()->getRow();

        if ($priced === null || (int) $priced->item_type !== ITEM_KIT || $priceOption !== PRICE_OPTION_KIT) {
            throw new AccountingException(AccountingError::BundleShapeInvalid, 'A bundle needs a kit product that carries its price and the price option of the kit.');
        }

        if ((int) $priced->stock_type === HAS_STOCK) {
            throw new AccountingException(AccountingError::BundleKeepsStock, 'The product that carries the price of a bundle keeps no stock.', ['name' => (string) $priced->name]);
        }

        $pricedProducts = array_map('intval', array_column($this->db->table('item_kits')->select('item_id')->where('item_id IS NOT NULL', null, false)->get()->getResultArray(), 'item_id'));

        foreach (array_values(array_unique($componentItemIds)) as $componentId) {
            if ($componentId === $pricedItemId) {
                throw new AccountingException(AccountingError::BundleContainsItself, 'A bundle cannot contain itself.');
            }

            $component = $this->db->table('items')->select('name, item_type')->where('item_id', $componentId)->get()->getRow();

            if ($component !== null && ((int) $component->item_type === ITEM_KIT || in_array($componentId, $pricedProducts, true))) {
                throw new AccountingException(AccountingError::BundleContainsBundle, 'A bundle cannot contain another bundle.', ['name' => (string) $component->name]);
            }
        }
    }

    /**
     * The kits that do not follow the rules of a bundle, for the check before the setting is trusted.
     *
     * @return list<array{kit_id: int, name: string, problem: string}>
     */
    public function violations(): array
    {
        $found = [];

        foreach ($this->db->table('item_kits')->select('item_kit_id, name, item_id, price_option')->get()->getResult() as $kit) {
            $components = array_map('intval', array_column($this->db->table('item_kit_items')->select('item_id')->where('item_kit_id', (int) $kit->item_kit_id)->get()->getResultArray(), 'item_id'));

            try {
                $this->assertValid($kit->item_id === null ? null : (int) $kit->item_id, $components, $kit->price_option === null ? null : (int) $kit->price_option);
            } catch (AccountingException $problem) {
                $found[] = ['kit_id' => (int) $kit->item_kit_id, 'name' => (string) $kit->name, 'problem' => $problem->error->value];
            }
        }

        return $found;
    }
}
