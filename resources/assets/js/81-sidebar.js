(function($) {

    const OPEN_KEY = 'expro.nav.open';
    const RAIL_KEY = 'expro.nav.rail';
    const root = document.documentElement;
    const wide = window.matchMedia('(min-width: 640px)');
    const tablet = window.matchMedia('(min-width: 640px) and (max-width: 1099.98px)');

    const read = function(key) {
        try {
            return JSON.parse(localStorage.getItem(key));
        } catch (error) {
            return null;
        }
    };

    const write = function(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (error) {
            // the menu works without a memory
        }
    };

    const isRail = function() {
        return root.classList.contains('nav-rail');
    };

    const closeFlyouts = function(except) {
        $('.app-nav-section.is-flyout').not(except || null).each(function() {
            $(this).removeClass('is-flyout').find('.app-nav-toggle').attr('aria-expanded', 'false');
        });
    };

    const applyRail = function() {
        const rail = wide.matches && (tablet.matches || read(RAIL_KEY) === true);
        const button = $('.app-sidebar-collapse');

        root.classList.toggle('nav-rail', rail);
        closeFlyouts();

        button
            .attr('aria-pressed', rail ? 'true' : 'false')
            .attr('title', button.data(rail ? 'label-expand' : 'label-collapse'))
            .find('.app-nav-text').text(button.data(rail ? 'label-expand' : 'label-collapse'));

        $('.app-nav-section').each(function() {
            const open = $(this).hasClass('is-open');
            $(this).find('.app-nav-toggle').attr('aria-expanded', !rail && open ? 'true' : 'false');
        });
    };

    const rememberOpen = function() {
        write(OPEN_KEY, $('.app-nav-section.is-open').map(function() {
            return this.dataset.navSection;
        }).get());
    };

    const placeFlyout = function($section) {
        const toggle = $section.find('.app-nav-toggle')[0].getBoundingClientRect();
        const panel = $section.find('.app-nav-panel')[0];
        const room = window.innerHeight - panel.offsetHeight - 8;

        $section[0].style.setProperty('--nav-fly-top', Math.max(8, Math.min(toggle.top, room)) + 'px');
    };

    $(function() {
        const remembered = read(OPEN_KEY);

        if (Array.isArray(remembered)) {
            $('.app-nav-section').each(function() {
                if (remembered.indexOf(this.dataset.navSection) !== -1) {
                    $(this).addClass('is-open');
                }
            });
        }

        applyRail();

        wide.addEventListener('change', applyRail);
        tablet.addEventListener('change', applyRail);

        $('.app-nav').on('click', '.app-nav-toggle', function() {
            const $section = $(this).closest('.app-nav-section');

            if (isRail()) {
                const opening = !$section.hasClass('is-flyout');

                closeFlyouts($section);
                $section.toggleClass('is-flyout', opening);
                $(this).attr('aria-expanded', opening ? 'true' : 'false');

                if (opening) {
                    placeFlyout($section);
                }

                return;
            }

            const open = !$section.hasClass('is-open');

            $section.toggleClass('is-open', open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            rememberOpen();
        });

        $('.app-sidebar-collapse').on('click', function() {
            write(RAIL_KEY, !isRail());
            applyRail();
        });

        $(document).on('click', function(event) {
            if (isRail() && !$(event.target).closest('.app-nav-section').length) {
                closeFlyouts();
            }
        });

        $(document).on('keydown', function(event) {
            if (event.key === 'Escape' && $('.app-nav-section.is-flyout').length) {
                const $toggle = $('.app-nav-section.is-flyout .app-nav-toggle').first();

                closeFlyouts();
                $toggle.trigger('focus');
            }
        });
    });

})(jQuery);
