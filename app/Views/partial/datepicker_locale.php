<?php
/**
 * @var string|null $format
 */

use Config\OSPOS;

$config = config(OSPOS::class)->settings;
$timeFormat = $config['timeformat'];
$hasTime = !isset($format);
$format ??= dateformatFlatpickr($config['dateformat'] . ' ' . $timeFormat);
$meridian = str_contains($timeFormat, 'a') || str_contains($timeFormat, 'A');

$lines = static fn(string $prefix, array $keys): string => json_encode(
    array_map(static fn(string $key): string => lang("$prefix.$key"), $keys),
    JSON_UNESCAPED_UNICODE
);
?>

const pickerconfig = function(config) {
    return $.extend(true, {
        dateFormat: <?= json_encode($format) ?>,
        enableTime: <?= $hasTime ? 'true' : 'false' ?>,
        time_24hr: <?= $meridian ? 'false' : 'true' ?>,
        allowInput: true,
        disableMobile: true,
        locale: {
            weekdays: {
                longhand: <?= $lines('Calendar', ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']) ?>,
                shorthand: <?= $lines('Calendar', ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']) ?>
            },
            months: {
                longhand: <?= $lines('Calendar', ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']) ?>,
                shorthand: <?= $lines('Calendar', ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec']) ?>
            },
            firstDayOfWeek: <?= (int) lang('Datepicker.weekstart') ?>,
            rtl: <?= isRtl() ? 'true' : 'false' ?>
        }
    }, config);
};

flatpickr('.datetime, .datepicker', pickerconfig());
