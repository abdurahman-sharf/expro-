(function($) {

    // The action bar every list and document screen opens with becomes the page header of the design system:
    // the trail of the section and the title at the start, the actions at the end.
    $(function() {
        const $bar = $('#title_bar');

        if (!$bar.length || $bar.hasClass('page-head')) {
            return;
        }

        const $heading = $bar.children('h1, h2, h3, h4, h5, h6').first();
        const $actions = $('<div class="page-head-actions"></div>');
        const $start = $('<div class="page-head-start"></div>');
        const title = $.trim($('.app-title').first().text());
        const section = $.trim($('.app-nav-section.has-active > .app-nav-heading .app-nav-text').first().text());

        $bar.children().not($heading).appendTo($actions);
        $actions.find('.float-end, .float-start').removeClass('float-end float-start');

        if (section && section !== title) {
            $start.append($('<p class="page-crumbs" aria-hidden="true"></p>').text(section));
        }

        if ($heading.length) {
            $start.append($heading.addClass('page-title'));
        } else if (title) {
            $start.append($('<p class="page-title" aria-hidden="true"></p>').text(title));
        }

        $bar.addClass('page-head').empty().append($start, $actions);
        $actions.prop('hidden', !$actions.children().length);
    });

})(jQuery);
