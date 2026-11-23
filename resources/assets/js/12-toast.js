(function(toast_support, $) {

    const DISPLAY_MS = 5000;
    const EXIT_MS = 220;

    const ICONS = {
        success: 'bi-check-lg',
        danger: 'bi-exclamation-octagon',
        warning: 'bi-exclamation-triangle',
        info: 'bi-info-lg'
    };

    const container = function() {
        let $container = $('#app-toasts');

        if ($container.length === 0) {
            $container = $('<div id="app-toasts" aria-live="polite"></div>').appendTo(document.body);
        }

        return $container;
    };

    // options.title shows the message under a heading, options.sticky keeps the toast until it is closed.
    const show = function(message, type, options) {
        const kind = ICONS[type] ? type : 'info';
        const settings = $.extend({title: '', sticky: false}, options);
        const $toast = $('<div class="app-toast app-toast-in"></div>')
            .addClass('app-toast-' + kind)
            .attr('role', kind === 'danger' ? 'alert' : 'status');
        const $body = $('<div class="app-toast-body"></div>');

        const dismiss = function() {
            $toast.addClass('app-toast-out');
            setTimeout(function() {
                $toast.remove();
            }, EXIT_MS);
        };

        $('<span class="app-toast-icon" aria-hidden="true"></span>').append($('<i class="bi"></i>').addClass(ICONS[kind])).appendTo($toast);

        if (settings.title) {
            $('<p class="app-toast-title"></p>').text(settings.title).appendTo($body);
        }

        if (message) {
            $('<p class="app-toast-text"></p>').text(message).appendTo($body);
        }

        $toast.append($body);
        $('<button type="button" class="btn-close"></button>').attr('aria-label', lang.line('common_close')).on('click', dismiss).appendTo($toast);

        container().append($toast);

        if (!settings.sticky) {
            setTimeout(dismiss, DISPLAY_MS);
        }
    };

    $.extend(toast_support, {show: show});

})(window.toast_support = window.toast_support || {}, jQuery);
