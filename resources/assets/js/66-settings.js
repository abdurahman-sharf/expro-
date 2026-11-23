(function(settings_support, $) {
    'use strict';

    const MOBILE_QUERY = '(max-width: 991.98px)';

    const paneId = function(hash) {
        return /^#[a-z]+_tab$/.test(hash) && $('.settings-content > .tab-pane' + hash).length ? hash : null;
    };

    const show = function(hash) {
        const link = document.querySelector('.settings-link[data-bs-target="' + hash + '"]');
        if (link) {
            bootstrap.Tab.getOrCreateInstance(link).show();
        }
    };

    const collapseOnMobile = function() {
        const list = document.getElementById('settings_nav_list');
        if (list && list.classList.contains('show') && window.matchMedia(MOBILE_QUERY).matches) {
            bootstrap.Collapse.getOrCreateInstance(list).hide();
        }
    };

    const CONTROLS = 'input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="submit"], [type="button"]), select, textarea';

    const nameOrphans = function() {
        $('.settings-content').find(CONTROLS).each(function() {
            const control = $(this);
            if (control.attr('aria-label') || control.attr('aria-labelledby') || (this.labels && this.labels.length)) {
                return;
            }
            const row = control.closest('.row.mb-3');
            const label = $.trim(row.find('label').first().text());
            if (!label) {
                return;
            }
            const siblings = row.find(CONTROLS).filter(function() {
                return !(this.labels && this.labels.length);
            });
            const position = siblings.index(this) + 1;
            control.attr('aria-label', siblings.length > 1 ? label + ' (' + position + ')' : label);
        });
    };

    $(function() {
        nameOrphans();
        $(window).on('load', nameOrphans);
        const nav = $('#settings_nav_list');
        if (!nav.length) {
            return;
        }

        $('.settings-link').on('shown.bs.tab', function(event) {
            const link = $(event.target);
            $('#settings_nav_current, #settings_title').text($.trim(link.text()));
            try {
                history.replaceState(null, '', window.location.pathname + window.location.search + link.attr('data-bs-target'));
            } catch (error) {
                // the address bar is only a convenience
            }
        });

        $('.settings-link').on('click', collapseOnMobile);

        const initial = paneId(window.location.hash);
        if (initial) {
            show(initial);
        }

        $(window).on('hashchange', function() {
            const hash = paneId(window.location.hash);
            if (hash) {
                show(hash);
            }
        });
    });

    $.extend(settings_support, {show: show, nameOrphans: nameOrphans});

})(window.settings_support = window.settings_support || {}, jQuery);
