(function($) {

    $(document).on('change', '#branch_switcher', function() {
        const $select = $(this);

        $select.prop('disabled', true);

        $.post($select.data('url'), {branch_id: $select.val()}, function(response) {
            if (response.success) {
                window.location.reload();
                return;
            }

            toast_support.show(response.message, 'danger');
            $select.prop('disabled', false);
        }, 'json').fail(function() {
            $select.prop('disabled', false);
        });
    });

})(jQuery);
