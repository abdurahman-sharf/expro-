<?php
/**
 * The behaviour of the distribution of a journal line over cost centers. The server decides what is
 * accepted and what each share comes to; the amounts shown here follow the same rule so that what a
 * person sees is what is saved: each share is the amount times its percent, rounded to the decimals
 * of the base currency, and what the rounding leaves over goes to the largest share.
 *
 * @var list<object> $centers
 * @var int          $decimals
 * @var bool         $overAllowed
 * @var string       $cap
 */

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<script type="text/javascript">
    const journal_distribution = (function() {
        const SCALE = 10000n;
        const text = <?= json_encode([
            'center' => lang('Accounting_journals.distribution_center'),
            'percent' => lang('Accounting_journals.distribution_percent'),
            'amount' => lang('Accounting_journals.distribution_amount'),
            'total' => lang('Accounting_journals.distribution_total'),
            'add' => lang('Accounting_journals.distribution_add_row'),
            'remove' => lang('Accounting_journals.distribution_remove_row'),
            'choose' => lang('Accounting_journals.no_cost_center'),
            'whole' => lang('Accounting_journals.distribution_whole'),
            'not_whole' => lang('Accounting_journals.distribution_not_whole'),
            'over' => lang('Accounting_journals.distribution_over', [$cap]),
        ], $jsonFlags) ?>;
        const centers = <?= json_encode(array_map(static fn (object $center): array => ['id' => (int) $center->cost_center_id, 'label' => $center->code . ' - ' . $center->name], $centers), $jsonFlags) ?>;
        const decimals = <?= (int) $decimals ?>;
        const overAllowed = <?= $overAllowed ? 'true' : 'false' ?>;
        const cap = <?= json_encode($cap, $jsonFlags) ?>;
        const digits = {'٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9'};
        let amountOf = function() {
            return 0n;
        };

        const normalize = function(value) {
            return String(value).replace(/[٠-٩۰-۹]/g, function(character) {
                return digits[character];
            }).replace(/[٫,،]/g, '.').trim();
        };

        const units = function(value) {
            const match = /^(\d{0,11})(?:\.(\d{0,4}))?\d*$/.exec(normalize(value));

            return match === null || (match[1] === '' && (match[2] || '') === '') ? 0n : BigInt(match[1] || '0') * SCALE + BigInt(((match[2] || '') + '0000').slice(0, 4));
        };

        const plain = function(amount) {
            const whole = amount / SCALE;
            const fraction = String(amount % SCALE).padStart(4, '0').replace(/0+$/, '');

            return String(whole) + (fraction === '' ? '' : '.' + fraction);
        };

        const rounded = function(numerator, denominator) {
            return (numerator + denominator / 2n) / denominator;
        };

        const shares = function(line, percents) {
            const step = 10n ** BigInt(4 - decimals);
            let total = 0n;
            let sum = 0n;
            let largest = -1;
            const amounts = percents.map(function(percent, index) {
                total += percent;
                const amount = rounded(line * percent, 1000000n * step) * step;
                sum += amount;
                if (largest < 0 || percent > percents[largest]) {
                    largest = index;
                }
                return amount;
            });

            if (largest >= 0) {
                amounts[largest] += rounded(line * total, 1000000n) - sum;
            }

            return {amounts: amounts, total: total};
        };

        const optionsHtml = function(selected) {
            let html = '<option value="">' + $('<span></span>').text(text.choose).html() + '</option>';

            centers.forEach(function(center) {
                html += '<option value="' + center.id + '"' + (String(selected) === String(center.id) ? ' selected' : '') + '>' + $('<span></span>').text(center.label).html() + '</option>';
            });

            return html;
        };

        const panelHtml = function() {
            return '<table class="table table-sm acc-dist-table"><thead><tr><th scope="col">' + text.center + '</th><th scope="col" class="text-end">' + text.percent + '</th><th scope="col" class="text-end">' + text.amount + '</th><th scope="col"></th></tr></thead>'
                + '<tbody></tbody><tfoot><tr><th scope="row">' + text.total + '</th><td class="text-end"><bdi dir="ltr" class="acc-dist-total-percent"></bdi></td><td class="text-end"><bdi dir="ltr" class="acc-dist-total-amount"></bdi></td><td></td></tr></tfoot></table>'
                + '<button type="button" class="btn btn-outline-secondary btn-sm acc-dist-add print_hide">' + text.add + '</button>'
                + '<p class="form-text acc-dist-state mb-0" role="status"></p>';
        };

        const rowHtml = function(name, index, centerId, percent) {
            return '<tr class="acc-dist-line"><td><select name="' + name + '[distribution][' + index + '][cost_center_id]" class="form-select form-select-sm acc-dist-center" aria-label="' + text.center + '">' + optionsHtml(centerId) + '</select></td>'
                + '<td><input type="text" name="' + name + '[distribution][' + index + '][percent]" class="form-control form-control-sm ltr-input acc-dist-percent" dir="ltr" inputmode="decimal" autocomplete="off" value="' + percent + '" aria-label="' + text.percent + '"></td>'
                + '<td class="text-end"><bdi dir="ltr" class="acc-dist-amount"></bdi></td>'
                + '<td><button type="button" class="btn btn-outline-danger btn-sm acc-dist-remove print_hide" title="' + text.remove + '" aria-label="' + text.remove + '"><span class="bi bi-x-lg" aria-hidden="true"></span></button></td></tr>';
        };

        const refresh = function($dist) {
            const $lines = $dist.find('.acc-dist-line');
            const $panel = $dist.find('.acc-dist-panel');
            const none = $lines.length === 0;

            $dist.find('.acc-dist-select').prop('disabled', !none);

            if (none) {
                return;
            }

            const result = shares(amountOf($dist), $lines.map(function() {
                return units($(this).find('.acc-dist-percent').val());
            }).get());

            $lines.each(function(index) {
                $(this).find('.acc-dist-amount').text(plain(result.amounts[index]));
            });

            const sum = result.amounts.reduce(function(carry, amount) {
                return carry + amount;
            }, 0n);
            const whole = result.total === 100n * SCALE || (overAllowed && result.total > 100n * SCALE && result.total <= units(cap));

            $panel.find('.acc-dist-total-percent').text(plain(result.total));
            $panel.find('.acc-dist-total-amount').text(plain(sum));
            $panel.find('.acc-dist-state').text(whole ? (result.total > 100n * SCALE ? text.over : text.whole) : text.not_whole).toggleClass('text-danger', !whole);
        };

        const addRow = function($dist, centerId, percent) {
            const $body = $dist.find('.acc-dist-table tbody');
            const next = $dist.data('next') || 0;

            $dist.data('next', next + 1);
            $body.append(rowHtml($dist.data('name'), next, centerId, percent));
        };

        const open = function($dist, open) {
            $dist.find('.acc-dist-panel').prop('hidden', !open);
            $dist.find('.acc-dist-toggle').attr('aria-expanded', open ? 'true' : 'false');
        };

        const init = function($dist) {
            const $panel = $dist.find('.acc-dist-panel');

            $panel.html(panelHtml());
            $dist.data('next', 0);

            (JSON.parse($dist.attr('data-rows') || '[]')).forEach(function(row) {
                addRow($dist, row.id, row.percent);
            });

            open($dist, $dist.find('.acc-dist-line').length > 0);
            refresh($dist);
        };

        return {
            bind: function($root, readAmount) {
                amountOf = readAmount;

                $root.find('.acc-dist').each(function() {
                    init($(this));
                });

                $root.on('click', '.acc-dist-toggle', function() {
                    const $dist = $(this).closest('.acc-dist');
                    const opening = $dist.find('.acc-dist-panel').prop('hidden');

                    if (opening && $dist.find('.acc-dist-line').length === 0) {
                        addRow($dist, $dist.find('.acc-dist-select').val(), '100');
                    }

                    open($dist, opening);
                    refresh($dist);
                });

                $root.on('click', '.acc-dist-add', function() {
                    const $dist = $(this).closest('.acc-dist');

                    addRow($dist, '', '');
                    refresh($dist);
                });

                $root.on('click', '.acc-dist-remove', function() {
                    const $dist = $(this).closest('.acc-dist');

                    $(this).closest('tr').remove();
                    if ($dist.find('.acc-dist-line').length === 0) {
                        open($dist, false);
                    }
                    refresh($dist);
                });

                $root.on('input', '.acc-dist-percent', function() {
                    const normalized = normalize($(this).val());

                    if (normalized !== $(this).val()) {
                        $(this).val(normalized);
                    }
                    refresh($(this).closest('.acc-dist'));
                });
            },
            init: init,
            units: units,
            refreshAll: function($root) {
                $root.find('.acc-dist').each(function() {
                    refresh($(this));
                });
            },
            fill: function($dist, rows) {
                $dist.find('.acc-dist-table tbody').empty();
                $dist.data('next', 0);
                rows.forEach(function(row) {
                    addRow($dist, row.cost_center_id, String(row.percent).replace(/0+$/, '').replace(/\.$/, ''));
                });
                open($dist, rows.length > 0);
                refresh($dist);
            },
            hasRows: function($dist) {
                return $dist.find('.acc-dist-line').length > 0;
            },
            single: function($dist) {
                return $dist.find('.acc-dist-select').val();
            }
        };
    })();
</script>
