(function(report_support, $) {
    'use strict';

    const KEY = 'reportSensitiveShown';

    const stored = function() {
        try {
            return localStorage.getItem(KEY) === '1';
        } catch (error) {
            return false;
        }
    };

    const store = function(shown) {
        try {
            localStorage.setItem(KEY, shown ? '1' : '0');
        } catch (error) {
            // storage can be blocked; the toggle then only lasts for the page
        }
    };

    const apply = function(shown) {
        $('.report-kpi.is-sensitive').toggleClass('d-none', !shown);
        $('#toggleCostProfitButton').attr('aria-pressed', shown ? 'true' : 'false')
            .find('.bi').toggleClass('bi-eye', !shown).toggleClass('bi-eye-slash', shown);
    };

    $(function() {
        let shown = stored();
        apply(shown);

        $('#toggleCostProfitButton').click(function() {
            shown = !shown;
            store(shown);
            apply(shown);
        });
    });

    $.extend(report_support, {apply: apply});

})(window.report_support = window.report_support || {}, jQuery);
