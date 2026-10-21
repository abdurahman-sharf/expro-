(function(accounting_support, $) {

    const normalize = function(value) {
        return $.trim(value || '').toLowerCase();
    };

    const accountPicker = function(listId) {
        const byLabel = {};
        const byCode = {};

        $('#' + listId + ' option').each(function() {
            const entry = {id: String($(this).data('id')), label: this.value};
            byLabel[normalize(this.value)] = entry;
            byCode[normalize($(this).data('code'))] = entry;
        });

        const resolve = function(text) {
            const key = normalize(text);
            return byLabel[key] || byCode[key] || null;
        };

        const sync = function($text, $hidden) {
            const match = resolve($text.val());
            $hidden.val(match ? match.id : '');
            $text.toggleClass('is-invalid', !match && normalize($text.val()) !== '');
            return match;
        };

        const complete = function($text, $hidden) {
            const match = sync($text, $hidden);
            match && $text.val(match.label);
        };

        return {resolve: resolve, sync: sync, complete: complete};
    };

    $.extend(accounting_support, {accountPicker: accountPicker});

})(window.accounting_support = window.accounting_support || {}, jQuery);
