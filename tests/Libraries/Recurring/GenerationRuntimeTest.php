<?php

namespace Tests\Libraries\Recurring;

use App\Libraries\Recurring\GenerationRuntime;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * What a console command takes from the shop that a page request takes at its start: the language and the
 * time zone.
 *
 * @internal
 */
final class GenerationRuntimeTest extends CIUnitTestCase
{
    private string $zone;
    private string $locale;
    private array $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone     = date_default_timezone_get();
        $this->locale   = service('language')->getLocale();
        $this->settings = config(OSPOS::class)->settings;
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        service('language')->setLocale($this->locale);
        config(OSPOS::class)->settings = $this->settings;

        parent::tearDown();
    }

    /**
     * @param array<string, string> $settings
     */
    private function applyWith(array $settings): void
    {
        config(OSPOS::class)->settings = [...$this->settings, ...$settings];

        GenerationRuntime::apply();
    }

    public function testApply_SetsTheTimeZoneOfTheShop(): void
    {
        $this->applyWith(['timezone' => 'Pacific/Kiritimati']);

        $this->assertSame('Pacific/Kiritimati', date_default_timezone_get());
    }

    public function testApply_SetsEachGulfAndIraqZoneThatTheSettingsScreenOffers(): void
    {
        require_once ROOTPATH . 'app/Helpers/locale_helper.php';

        foreach (['Asia/Riyadh', 'Asia/Kuwait', 'Asia/Baghdad', 'Asia/Qatar', 'Asia/Bahrain', 'Asia/Muscat'] as $zone) {
            $this->assertArrayHasKey($zone, get_timezones());

            $this->applyWith(['timezone' => $zone]);

            $this->assertSame($zone, date_default_timezone_get(), "{$zone} is applied to a console command.");
        }
    }

    public function testApply_SetsTheLanguageOfTheShop(): void
    {
        $this->applyWith(['language_code' => 'fr']);

        $this->assertSame('fr', service('language')->getLocale());

        $this->applyWith(['language_code' => 'ar-EG']);

        $this->assertSame('ar-EG', service('language')->getLocale());
    }

    public function testApply_LeavesTheTimeZoneAndTheLanguageAloneWhenTheShopNamesNoneThatExists(): void
    {
        foreach (['', 'Mars/Olympus', 'UTC; drop', '../etc'] as $zone) {
            $this->applyWith(['timezone' => $zone]);

            $this->assertSame($this->zone, date_default_timezone_get(), $zone);
        }

        foreach (['', 'xx', '../Config', 'en/../fr', 'ar-EG ', "en\0"] as $language) {
            service('language')->setLocale($this->locale);
            $this->applyWith(['language_code' => $language]);

            $this->assertSame($this->locale, service('language')->getLocale(), $language);
        }
    }
}
