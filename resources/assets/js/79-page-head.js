(function($) {

    // The action bar every list, document and print screen opens with becomes the page header of the design system:
    // the trail of the section and the title at the start, the actions at the end, and the section tabs below.

    // Links between sibling pages drawn as tabs or pills, not the panes of one page.
    const isSectionTabs = function(element) {
        const $element = $(element);
        const $list = $element.is('ul') ? $element : $element.children('ul');

        return $list.length === 1 && $list.is('.nav-tabs, .nav-pills') && !$list.find('[data-bs-toggle]').length;
    };

    // Screens that keep a layout of their own: the sale and receiving registers, the dashboards, the settings
    // and the full-page states.
    const fullLayouts = '.pos, .dash, .nt, .settings-layout, .empty-state';

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

        // A title row: the heading first, its buttons after it, and perhaps a line of introduction below. A
        // block that also holds the content of the page (the dashboard opens on its welcome) is no title row.
        const isTitleRow = $first.is('div') && $first.children().first().is('h1, h2')
            && !$first.is(fullLayouts) && !$first.find('section, table, dl, .card, .table-responsive, .row').length;

        if (isTitleRow) {
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
        // A toolbar inside one tab of a page belongs to that tab, not to the page.
        const $titleBar = $('#title_bar').not('.tab-pane #title_bar');
        let $bar = $titleBar.length ? $titleBar : $('#control_buttons.document-actions');

        if (!$bar.length && !$('.page-head').length) {
            $bar = headingBar();
        }

        // A working screen with no title of its own still gets a header naming it.
        if (!$bar.length && !$('.page-head').length && !$('#app-main').find(fullLayouts).length) {
            $bar = $('<div></div>').prependTo('#app-main');
        }

        if (!$bar.length || $bar.hasClass('page-head')) {
            return;
        }

        const $tabs = sectionTabs($bar).detach();
        let $heading = $bar.children('h1, h2, h3, h4, h5, h6').first();
        const $pageHeading = $('#app-main h1').not($bar.find('h1')).not('.modal h1, .print-only h1, [hidden] h1');

        // A bar without a heading takes the one title the page gives itself further down.
        if (!$heading.length && $pageHeading.length === 1) {
            $heading = $pageHeading.removeClass('h3 h4 h5 me-auto mb-0 mb-2 mb-3');
        }
        const $note = $bar.children('p');
        const $actions = $('<div class="page-head-actions"></div>');
        const $start = $('<div class="page-head-start"></div>');
        const title = $.trim($('.app-title').first().text());
        const section = $.trim($('.app-nav-section.has-active > .app-nav-heading .app-nav-text').first().text());

        $bar.children().not($heading).not($note).appendTo($actions);
        $actions.find('.float-end, .float-start').removeClass('float-end float-start');

        // A bar of document actions standing alone right under the header joins the header's actions.
        if (!$actions.children().length) {
            const $toolbar = ($bar.parent().is('#app-main') ? $bar : $bar.parent()).nextAll().not('script, style').first().filter('.acc-journal-bar');
            $toolbar.children().appendTo($actions);
            $toolbar.remove();
        }

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

        // Links to neighbouring reports gather under one menu so the header keeps to its own actions.
        const $related = $actions.children('a.btn').not('.btn-primary').filter(function() {
            const target = (this.getAttribute('href') || '') + ' ' + this.id;

            return /(accounting_reports|accounting_statements|\/reports\/)/.test(target) && !/(csv|xlsx|pdf|print|export)/i.test(target);
        });

        if ($related.length >= 3) {
            const $menu = $('<ul class="dropdown-menu dropdown-menu-end"></ul>');
            const $toggle = $('<button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"></button>')
                .append('<span class="bi bi-collection" aria-hidden="true"></span> ', document.createTextNode(lang.line('related_reports')));

            $related.first().before($('<div class="dropdown"></div>').append($toggle, $menu));
            $related.each(function() {
                $menu.append($('<li></li>').append($(this).removeClass('btn btn-sm btn-outline-secondary btn-outline-primary').addClass('dropdown-item')));
            });
        }

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
