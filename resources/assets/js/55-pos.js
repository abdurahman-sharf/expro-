(function($) {

    const config = function() {
        return window.posConfig || {decimals: 2, decimalSeparator: '.', groupSeparator: ''};
    };

    const parseAmount = function(text) {
        const settings = config();
        let value = String(text).replace(/\s/g, '');
        if (settings.groupSeparator) {
            value = value.split(settings.groupSeparator).join('');
        }
        if (settings.decimalSeparator !== '.') {
            value = value.replace(settings.decimalSeparator, '.');
        }
        const number = parseFloat(value);
        return isNaN(number) ? 0 : number;
    };

    const formatAmount = function(number) {
        const settings = config();
        return number.toFixed(settings.decimals).replace('.', settings.decimalSeparator);
    };

    const formatQuantity = function(number) {
        const separator = config().decimalSeparator;
        const text = formatAmount(number);
        return text.indexOf(separator) === -1 ? text : text.replace(/0+$/, '').replace(separator, '.').replace(/\.$/, '').replace('.', separator);
    };

    const STEPS = [5, 10, 20, 50, 100, 200, 500, 1000];

    const roundedAmounts = function(due) {
        const amounts = [];
        STEPS.forEach(function(step) {
            const rounded = Math.ceil(due / step) * step;
            if (rounded > due && amounts.indexOf(rounded) === -1) {
                amounts.push(rounded);
            }
        });
        return amounts.slice(0, 4);
    };

    let dueAmount = 0;

    const renderChange = function() {
        const $input = $('#amount_tendered');
        const $box = $('#change_box');
        if (!$box.length) {
            return;
        }
        const change = parseAmount($input.val()) - dueAmount;
        const visible = $input.is(':enabled') && change > 0.000001;
        $box.toggleClass('d-none', !visible);
        $('#change_value').text(visible ? formatAmount(change) : '');
    };

    const renderQuickAmounts = function() {
        const $quick = $('#quick_amounts');
        if (!$quick.length) {
            return;
        }
        const usable = $('#amount_tendered').is(':enabled') && dueAmount > 0;
        $quick.toggleClass('d-none', !usable).empty();
        if (!usable) {
            return;
        }
        const amounts = [dueAmount].concat(roundedAmounts(dueAmount));
        amounts.forEach(function(amount, index) {
            $('<button type="button" class="btn btn-outline-secondary btn-sm"></button>')
                .text(index === 0 ? $quick.data('exact-label') : formatAmount(amount))
                .attr('data-amount', formatAmount(amount))
                .appendTo($quick);
        });
    };

    const syncTotalBar = function() {
        const total = $('#sale_total').html();
        if (total !== undefined) {
            $('#pos_total_value').html(total);
        }
    };

    $(document).on('pos:payment-changed', function() {
        dueAmount = parseAmount($('#amount_tendered').val());
        renderQuickAmounts();
        renderChange();
        syncTotalBar();
    });

    $(document).on('click', '#quick_amounts [data-amount]', function() {
        $('#amount_tendered').val($(this).attr('data-amount')).trigger('input').trigger('focus');
    });

    $(document).on('input', '#amount_tendered', renderChange);

    const stepTimers = {};

    $(document).on('click', '.qty-b', function() {
        const $input = $(this).siblings('input');
        const $form = $(this).closest('form');
        const next = parseAmount($input.val()) + Number($(this).attr('data-step'));
        $input.val(formatQuantity(next));
        clearTimeout(stepTimers[$form.attr('id')]);
        stepTimers[$form.attr('id')] = setTimeout(function() {
            $form.submit();
        }, 500);
    });

    const MAX_METHOD_BUTTONS = 4;

    const buildOptionButtons = function(select) {
        const $select = $(select);
        if ($select.next('.pos-methods').length || $select.find('option').length > MAX_METHOD_BUTTONS) {
            return;
        }
        const $group = $('<div class="pos-methods" role="group"></div>').attr('aria-label', $('label[for="' + select.id + '"]').text());
        $select.find('option').each(function() {
            $('<button type="button" class="pos-method"></button>')
                .text($(this).text())
                .attr('data-value', this.value)
                .appendTo($group);
        });
        $select.addClass('d-none').after($group);

        const refresh = function() {
            $group.children().each(function() {
                $(this).attr('aria-pressed', $(this).attr('data-value') === String($select.val()));
            });
        };
        $group.on('click', '.pos-method', function() {
            $select.val($(this).attr('data-value')).trigger('change');
            refresh();
        });
        $select.on('change', refresh);
        refresh();
    };

    $(function() {
        $('select[data-option-buttons]').each(function() {
            buildOptionButtons(this);
        });

        $('#pos_total_action').on('click', function() {
            const $finish = $('#finish_sale_button, #finish_invoice_quote_button, #finish_receiving_button').first();
            if ($finish.length) {
                $finish.trigger('click');
                return;
            }
            const $target = $('#payment_card, #select_customer').first();
            if ($target.length) {
                $target[0].scrollIntoView({behavior: 'smooth', block: 'start'});
                $target.find('input:enabled:visible').first().trigger('focus');
            }
        });
    });

})(jQuery);
