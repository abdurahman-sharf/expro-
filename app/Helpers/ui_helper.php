<?php

/**
 * Renders a checkbox as a two-state button, used for the percent/currency discount switch.
 *
 * @param array  $attributes Attributes of the checkbox, id is required.
 * @param string $onLabel    Text shown while the checkbox is checked.
 * @param string $offLabel   Text shown while the checkbox is not checked.
 */
function toggleButton(array $attributes, string $onLabel, string $offLabel): string
{
    $input = form_checkbox(array_merge($attributes, ['class' => 'btn-check', 'autocomplete' => 'off']));

    return $input
        . '<label class="btn btn-sm btn-outline-primary toggle-button" for="' . esc($attributes['id'], 'attr') . '">'
        . '<span class="toggle-on">' . esc($onLabel) . '</span>'
        . '<span class="toggle-off">' . esc($offLabel) . '</span>'
        . '</label>';
}
