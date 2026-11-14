<?php

use App\Libraries\SafeHtml;

/*
 * The framework prints the text of an option and of a label as it receives it, so a stored name that holds markup
 * became markup on the page. The two functions below replace the framework's (CodeIgniter loads this file first and
 * its own definitions are skipped) and escape that text. Entities already in the text are kept (double_encode off):
 * the legacy screens store names entity-encoded on write and several call sites pass esc() output, and both must
 * show once-decoded text instead of "&amp;lt;". Wrap trusted markup in SafeHtml to print it as it is.
 */

if (! function_exists('escapeFormText')) {
    function escapeFormText(mixed $text): string
    {
        if ($text instanceof SafeHtml) {
            return (string) $text;
        }

        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}

if (! function_exists('form_dropdown')) {
    /**
     * @param array|string        $data
     * @param array|string        $options
     * @param array|string        $selected
     * @param array|object|string $extra
     */
    function form_dropdown($data = '', $options = [], $selected = [], $extra = ''): string
    {
        $defaults = [];

        if (is_array($data)) {
            if (isset($data['selected'])) {
                $selected = $data['selected'];
                unset($data['selected']);
            }

            if (isset($data['options'])) {
                $options = $data['options'];
                unset($data['options']);
            }
        } else {
            $defaults = ['name' => $data];
        }

        if (! is_array($selected)) {
            $selected = [$selected];
        }

        if (! is_array($options)) {
            $options = [$options];
        }

        if ($selected === []) {
            $superglobals = service('superglobals');

            if (is_array($data)) {
                $postValue = $superglobals->post($data['name'] ?? '');

                if (isset($data['name']) && $postValue !== null) {
                    $selected = [$postValue];
                }
            } else {
                $postValue = $superglobals->post($data);

                if ($postValue !== null) {
                    $selected = [$postValue];
                }
            }
        }

        foreach ($selected as $key => $item) {
            $selected[$key] = (string) $item;
        }

        $extra    = stringify_attributes($extra);
        $multiple = (count($selected) > 1 && ! str_contains(strtolower($extra), 'multiple')) ? ' multiple="multiple"' : '';
        $form     = '<select ' . rtrim(parse_form_attributes($data, $defaults)) . $extra . $multiple . ">\n";

        foreach ($options as $key => $val) {
            $key = (string) $key;

            if (is_array($val)) {
                if ($val === []) {
                    continue;
                }

                $form .= '<optgroup label="' . escapeFormText($key) . "\">\n";

                foreach ($val as $optgroupKey => $optgroupVal) {
                    $optgroupKey = (string) $optgroupKey;

                    $sel = in_array($optgroupKey, $selected, true) ? ' selected="selected"' : '';
                    $form .= '<option value="' . htmlspecialchars($optgroupKey) . '"' . $sel . '>' . escapeFormText($optgroupVal) . "</option>\n";
                }

                $form .= "</optgroup>\n";
            } else {
                $form .= '<option value="' . htmlspecialchars($key) . '"'
                    . (in_array($key, $selected, true) ? ' selected="selected"' : '') . '>'
                    . escapeFormText($val) . "</option>\n";
            }
        }

        return $form . "</select>\n";
    }
}

if (! function_exists('form_label')) {
    function form_label(SafeHtml|string $labelText = '', string $id = '', array $attributes = []): string
    {
        $label = '<label';

        if ($id !== '') {
            $label .= ' for="' . escapeFormText($id) . '"';
        }

        foreach ($attributes as $key => $val) {
            $label .= ' ' . $key . '="' . escapeFormText($val) . '"';
        }

        return $label . '>' . escapeFormText($labelText) . '</label>';
    }
}
