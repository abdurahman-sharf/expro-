(function($) {

    const RELEASE_AFTER_MS = 20000;
    const LOCKED = 'singleShotLocked';

    const buttonsOf = function($form) {
        const $own = $form.find('button, input[type="submit"]');
        const outside = $form.attr('data-single-shot-buttons');
        return outside ? $own.add($(outside)) : $own;
    };

    const lockForm = function($form) {
        const $buttons = buttonsOf($form).filter(':enabled');
        $form.data(LOCKED, true);
        $buttons.prop('disabled', true);
        setTimeout(function() {
            $form.data(LOCKED, false);
            $buttons.prop('disabled', false);
        }, RELEASE_AFTER_MS);
    };

    $(document).on('submit', 'form[data-single-shot]', function(event) {
        const $form = $(this);
        if ($form.data(LOCKED)) {
            event.preventDefault();
            return false;
        }
        lockForm($form);
    });

    $(document).on('click', 'a[data-single-shot]', function(event) {
        const $link = $(this);
        if ($link.data(LOCKED)) {
            event.preventDefault();
            return;
        }
        $link.data(LOCKED, true).addClass('disabled').attr('aria-disabled', 'true');
        setTimeout(function() {
            $link.data(LOCKED, false).removeClass('disabled').removeAttr('aria-disabled');
        }, RELEASE_AFTER_MS);
    });

    $(window).on('pageshow', function(event) {
        if (!event.originalEvent || !event.originalEvent.persisted) {
            return;
        }
        $('form[data-single-shot]').each(function() {
            $(this).data(LOCKED, false);
            buttonsOf($(this)).prop('disabled', false);
        });
        $('a[data-single-shot]').data(LOCKED, false).removeClass('disabled').removeAttr('aria-disabled');
    });

})(jQuery);
