(function(toast_support, $) {

    const DISPLAY_MS = 4000;
    const EXIT_MS = 220;

    const container = function() {
        let $container = $('#app-toasts');

        if ($container.length === 0) {
            $container = $('<div id="app-toasts" class="position-fixed top-0 end-0 p-3 d-flex flex-column gap-2" aria-live="polite"></div>').appendTo(document.body);
        }

        return $container;
    };

    const show = function(message, type) {
        const $toast = $('<div class="app-toast app-toast-in alert d-flex align-items-start gap-2 shadow" role="alert"></div>').addClass('alert-' + (type || 'info'));

        const dismiss = function() {
            $toast.addClass('app-toast-out');
            setTimeout(function() {
                $toast.remove();
            }, EXIT_MS);
        };

        $('<span class="flex-grow-1"></span>').text(message).appendTo($toast);
        $('<button type="button" class="btn-close"></button>').attr('aria-label', lang.line('common_close')).on('click', dismiss).appendTo($toast);

        container().append($toast);
        setTimeout(dismiss, DISPLAY_MS);
    };

    $.extend(toast_support, {show: show});

})(window.toast_support = window.toast_support || {}, jQuery);
