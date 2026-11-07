<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\Currency;
use App\Libraries\Accounting\CurrencyDisplay;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use RuntimeException;

class CurrencyDisplayTest extends CIUnitTestCase
{
    /** @var array<string, mixed> */
    private array $settingsBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsBefore = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['currency_symbol']   = 'SR';
        config(OSPOS::class)->settings['currency_decimals'] = '2';
    }

    protected function tearDown(): void
    {
        config(OSPOS::class)->settings = $this->settingsBefore;

        parent::tearDown();
    }

    private function yen(): Currency
    {
        return new Currency('JPY', 'ين', 'Yen', 0, true);
    }

    public function testSettingsFor_GivesTheSettingsOfTheShopForTheBaseCurrency(): void
    {
        $this->assertSame(config(OSPOS::class)->settings, CurrencyDisplay::settingsFor(null));
    }

    public function testSettingsFor_PutsTheCodeAndTheDecimalsOfACurrencyOverTheOnesOfTheShop(): void
    {
        $settings = CurrencyDisplay::settingsFor($this->yen());

        $this->assertSame(['JPY', '0'], [$settings['currency_symbol'], $settings['currency_decimals']]);
        $this->assertSame(['SR', '2'], [config(OSPOS::class)->settings['currency_symbol'], config(OSPOS::class)->settings['currency_decimals']], 'the settings of the shop are not changed by asking');
    }

    public function testWithin_RendersWithTheCurrencyAndPutsTheSettingsOfTheShopBack(): void
    {
        $seen = CurrencyDisplay::within($this->yen(), static fn (): array => [config(OSPOS::class)->settings['currency_symbol'], config(OSPOS::class)->settings['currency_decimals']]);

        $this->assertSame(['JPY', '0'], $seen);
        $this->assertSame(['SR', '2'], [config(OSPOS::class)->settings['currency_symbol'], config(OSPOS::class)->settings['currency_decimals']]);
    }

    public function testWithin_PutsTheSettingsOfTheShopBackWhenTheRenderingFails(): void
    {
        try {
            CurrencyDisplay::within($this->yen(), static function (): never {
                throw new RuntimeException('the view failed');
            });
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException) {
            $this->assertSame(['SR', '2'], [config(OSPOS::class)->settings['currency_symbol'], config(OSPOS::class)->settings['currency_decimals']]);
        }
    }

    public function testWithin_RendersWithTheSettingsOfTheShopForTheBaseCurrency(): void
    {
        $seen = CurrencyDisplay::within(null, static fn (): string => config(OSPOS::class)->settings['currency_symbol']);

        $this->assertSame('SR', $seen);
    }
}
