<?php

namespace app\Libraries;

use App\Models\Stock_location;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\Session\Session;

/**
 * Item library
 *
 * Library with utilities to manage items
 */
class Item_lib
{
    private Session $session;
    private Stock_location $stock_location;

    public function __construct()
    {
        $this->session        = Session();
        $this->stock_location = model(Stock_location::class);
    }

    public function get_item_location(): string
    {
        $current = $this->session->get('item_location');

        if (! $current || ! $this->stock_location->is_allowed_location((int) $current, 'items', true)) {
            if ($this->stock_location->get_allowed_locations('items', true) === []) {
                throw new RedirectException('no_access/items/items_stock');
            }

            $locationId = $this->stock_location->get_default_location_id('items', true);
            $this->set_item_location($locationId);
        }

        return $this->session->get('item_location');
    }

    public function set_item_location(?string $location): void
    {
        $this->session->set('item_location', $location);
    }

    public function clear_item_location(): void    // TODO: This isn't called from anywhere in the code.
    {
        $this->session->remove('item_location');
    }
}
