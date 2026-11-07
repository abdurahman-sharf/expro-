<?php

namespace App\Libraries\Accounting;

use Config\OSPOS;

/**
 * Shows the amounts of a screen in a foreign currency with the symbol and the decimals of that currency
 * instead of those of the shop, which the money helpers read from the settings. The settings are put back
 * when the screen is rendered, so nothing else that runs after it, in the same request or in a test, sees them.
 */
final class CurrencyDisplay
{
    /**
     * @return array<string, mixed> The settings of the shop with the symbol and the decimals of the currency, or as they are for none.
     */
    public static function settingsFor(?Currency $currency): array
    {
        $settings = config(OSPOS::class)->settings;

        return $currency === null ? $settings : [...$settings, 'currency_symbol' => $currency->code, 'currency_decimals' => (string) $currency->decimals];
    }

    /**
     * @template T
     *
     * @param callable(): T $render
     *
     * @return T
     */
    public static function within(?Currency $currency, callable $render): mixed
    {
        if ($currency === null) {
            return $render();
        }

        $shop   = config(OSPOS::class);
        $before = $shop->settings;

        $shop->settings = self::settingsFor($currency);

        try {
            return $render();
        } finally {
            $shop->settings = $before;
        }
    }
}
