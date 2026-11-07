<?php

namespace App\Libraries\Recurring;

use Config\OSPOS;
use Config\Services;

/**
 * What a page request has and a console command has not: the language and the time zone of the shop. The
 * day a schedule is counted on and the names a document is saved with (a way of payment is saved by its
 * name in the language in use) are the shop's, whoever starts the run.
 */
final class GenerationRuntime
{
    public static function apply(): void
    {
        $settings = config(OSPOS::class)->settings;
        $language = (string) ($settings['language_code'] ?? '');
        $zone     = (string) ($settings['timezone'] ?? '');

        if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z]{2,4})?$/', $language) === 1 && is_dir(APPPATH . 'Language/' . $language)) {
            Services::language()->setLocale($language);
        }

        if ($zone !== '' && in_array($zone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($zone);
        }
    }
}
