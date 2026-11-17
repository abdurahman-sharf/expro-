(function($) {

    const LEGACY_KEYS = ['expro.nav.open', 'expro.nav.rail'];
    const FRAGMENT = /^#([a-z]+_tab)$/;
    const OPEN = 'is-open';

    const forgetLegacyState = function() {
        try {
            LEGACY_KEYS.forEach(function(key) {
                localStorage.removeItem(key);
            });
        } catch (error) {
            // a browser without storage has nothing to forget
        }
    };

    const nav = function() {
        return $('.app-nav');
    };

    const sections = function() {
        return $('.app-nav-section');
    };

    const headings = function() {
        return $('.app-nav-heading').children('.app-nav-toggle, .app-nav-link');
    };

    const initialKey = function() {
        return nav().attr('data-nav-open') || null;
    };

    const setOpen = function(key) {
        sections().each(function() {
            const $toggle = $(this).children('.app-nav-heading').children('.app-nav-toggle');

            if (!$toggle.length) {
                return;
            }

            const open = key !== null && this.dataset.navSection === key;

            $(this).toggleClass(OPEN, open);
            $toggle.attr('aria-expanded', open ? 'true' : 'false');
            $(this).children('.app-nav-panel').prop('hidden', !open);
        });
    };

    const revealSection = function($section) {
        const box = nav()[0].getBoundingClientRect();
        const rect = $section[0].getBoundingClientRect();

        if (rect.bottom > box.bottom) {
            nav()[0].scrollTop += Math.min(rect.bottom - box.bottom, rect.top - box.top);
        } else if (rect.top < box.top) {
            nav()[0].scrollTop -= box.top - rect.top;
        }
    };

    const revealLink = function($link) {
        if (!$link.length || !nav().length || !$link[0].getClientRects().length) {
            return;
        }

        const box = nav()[0].getBoundingClientRect();
        const rect = $link[0].getBoundingClientRect();

        if (rect.top < box.top || rect.bottom > box.bottom) {
            nav()[0].scrollTop += rect.top - box.top - Math.max(0, (box.height - rect.height) / 2);
        }
    };

    const markCurrent = function($link) {
        $('.app-nav-link.active').removeClass('active').removeAttr('aria-current');
        sections().removeClass('has-active');
        $link.addClass('active').attr('aria-current', 'page');
        $link.closest('.app-nav-section').addClass('has-active');
    };

    const onThisPage = function() {
        const strip = function(path) {
            return path.replace(/\/index\.php(?=\/|$)/i, '').replace(/\/+$/, '').toLowerCase();
        };

        return function() {
            return strip(this.pathname) === strip(window.location.pathname);
        };
    }();

    const syncFragment = function(hash) {
        const $tabs = $('.app-nav-link[data-nav-fragment]').filter(onThisPage);

        if (!$tabs.length) {
            return;
        }

        const match = FRAGMENT.exec(typeof hash === 'string' ? hash : window.location.hash);
        const $target = match
            ? $tabs.filter(function() {
                return this.dataset.navFragment === match[1];
            })
            : $();
        const $link = $target.length
            ? $target.first()
            : $('.app-nav-link').not('[data-nav-fragment]').filter(onThisPage).first();

        if (!$link.length) {
            return;
        }

        markCurrent($link);
        setOpen($link.closest('.app-nav-section')[0].dataset.navSection);
        revealLink($link);
    };

    const sync = function() {
        setOpen(initialKey());
        syncFragment();
    };

    const moveBetweenHeadings = function(event) {
        if (event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) {
            return false;
        }

        const $all = headings();
        const index = $all.index(event.target);

        if (index === -1) {
            return false;
        }

        const last = $all.length - 1;
        const next = {
            ArrowDown: index === last ? 0 : index + 1,
            ArrowUp: index === 0 ? last : index - 1,
            Home: 0,
            End: last
        }[event.key];

        if (next === undefined) {
            return false;
        }

        event.preventDefault();
        $all.eq(next).trigger('focus');

        return true;
    };

    const closeWithEscape = function(event) {
        const $open = sections().filter('.' + OPEN);

        if (event.key !== 'Escape' || !$open.length || nav().closest('[aria-modal="true"]').length) {
            return false;
        }

        const inside = $.contains($open[0], event.target);

        setOpen(null);
        event.preventDefault();
        event.stopPropagation();

        if (inside) {
            $open.children('.app-nav-heading').children('.app-nav-toggle').trigger('focus');
        }

        return true;
    };

    window.exproNav = {sync: sync};

    forgetLegacyState();

    $(function() {
        sync();
        revealLink($('.app-nav-link.active').first());

        nav().on('click', '.app-nav-toggle', function() {
            const $section = $(this).closest('.app-nav-section');
            const open = $section.hasClass(OPEN);

            setOpen(open ? null : $section[0].dataset.navSection);

            if (!open) {
                revealSection($section);
            }
        });

        nav().on('keydown', function(event) {
            if (!closeWithEscape(event)) {
                moveBetweenHeadings(event);
            }
        });

        $(window).on('hashchange', function() {
            const match = FRAGMENT.exec(window.location.hash);

            if (match && document.querySelector('.settings-content > .tab-pane#' + match[1])) {
                syncFragment();
            }
        });

        $(document).on('shown.bs.tab', '.settings-link', function(event) {
            syncFragment($(event.target).attr('data-bs-target'));
        });

        $('#app-sidebar').on('shown.bs.offcanvas', function() {
            revealLink($('.app-nav-link.active').first());
        });

        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                sync();
            }
        });
    });

})(jQuery);
