(function($) {

    // The action bar every list, document and print screen opens with becomes the page header of the design system:
    // the trail of the section and the title at the start, the actions at the end, and the section tabs below.

    // Links between sibling pages drawn as tabs or pills, not the panes of one page.
    const isSectionTabs = function(element) {
        const $element = $(element);
        const $list = $element.is('ul') ? $element : $element.children('ul');

        return $list.length === 1 && $list.is('.nav-tabs, .nav-pills') && !$list.find('[data-bs-toggle]').length;
    };

    const leading = function($parent) {
        return $parent.children().not('script, style, .alert').filter(function() {
            return !isSectionTabs(this);
        }).first();
    };

    // A screen without an action bar that opens on its own heading gets a header made of that heading.
    const headingBar = function() {
        const $main = $('#app-main');
        let $first = leading($main);

        if ($first.is('.container-fluid, .container')) {
            $first = leading($first);
        }

        // A title row: the heading first, its buttons after it, and perhaps a line of introduction below.
        if ($first.is('div') && $first.children().first().is('h1, h2')) {
            $first.children().first().removeClass('h3 h4 h5 me-auto mb-0 mb-2 mb-3');
            $first.append($first.next('p.text-body-secondary'));

            return $first.removeClass('d-flex flex-wrap align-items-center gap-2 mb-2 mb-3').prependTo($main);
        }

        if (!$first.is('h1, h2')) {
            return $();
        }

        const $intro = $first.next('p.text-body-secondary');

        return $('<div></div>').append($first.removeClass('h3 h4 h5 me-auto mb-0 mb-2 mb-3'), $intro).prependTo($main);
    };

    const sectionTabs = function($bar) {
        const inside = $bar.children('nav, ul.nav').filter(function() {
            return isSectionTabs(this);
        });

        if (inside.length) {
            return inside.first();
        }

        const near = $bar.prevAll().not('script, style').first().add($bar.nextAll().not('script, style').first());

        return near.filter(function() {
            return isSectionTabs(this);
        }).first();
    };

    $(function() {
        let $bar = $('#title_bar').length ? $('#title_bar') : $('#control_buttons.document-actions');

        if (!$bar.length && !$('.page-head').length) {
            $bar = headingBar();
        }

        if (!$bar.length || $bar.hasClass('page-head')) {
            return;
        }

        const $tabs = sectionTabs($bar).detach();
        const $heading = $bar.children('h1, h2, h3, h4, h5, h6').first();
        const $note = $bar.children('p');
        const $actions = $('<div class="page-head-actions"></div>');
        const $start = $('<div class="page-head-start"></div>');
        const title = $.trim($('.app-title').first().text());
        const section = $.trim($('.app-nav-section.has-active > .app-nav-heading .app-nav-text').first().text());

        $bar.children().not($heading).not($note).appendTo($actions);
        $actions.find('.float-end, .float-start').removeClass('float-end float-start');

        // One primary action per page, as the design system asks: the last filled button (the "new" one) stays
        // filled, the others become outlined.
        $actions.find('.btn-primary').slice(0, -1).removeClass('btn-primary').addClass('btn-outline-secondary');

        if (section && section !== title) {
            $start.append($('<p class="page-crumbs" aria-hidden="true"></p>').text(section));
        }

        if ($heading.length) {
            $start.append($heading.addClass('page-title'));
        } else if (title) {
            $start.append($('<p class="page-title" aria-hidden="true"></p>').text(title));
        }

        $start.append($note.removeClass('mb-0 mb-1 mb-2 mb-3 small text-body-secondary text-secondary me-auto').addClass('page-note'));

        $bar.addClass('page-head').empty().append($start, $actions);
        $actions.prop('hidden', !$actions.children().length);

        if ($tabs.length) {
            const $wrap = $tabs.is('ul') ? $('<nav></nav>').attr('aria-label', $tabs.attr('aria-label') || null).append($tabs) : $tabs;
            $wrap.find('ul.nav').removeClass('nav-pills nav-fill-sm acc-tabs me-auto').addClass('nav-tabs');
            $wrap.find('.nav-link').removeClass('py-1');
            $wrap.removeClass('mb-2 mb-3').addClass('page-head-tabs').appendTo($bar);
        }
    });

})(jQuery);
