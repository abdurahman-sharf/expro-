(function($) {

    const initTooltips = function(root) {
        $(root || document).find('[data-bs-toggle="tooltip"]').each(function() {
            bootstrap.Tooltip.getOrCreateInstance(this);
        });
    };

    const drawerQuery = window.matchMedia('(min-width: 1100px)');

    const hideDrawer = function() {
        const drawer = document.getElementById('app-sidebar');
        drawer && bootstrap.Offcanvas.getInstance(drawer)?.hide();
    };

    const closeDrawerWhenWide = function(event) {
        if (event.matches) {
            hideDrawer();
        }
    };

    $(document).on('post-body.bs.table', function(event) {
        $(event.target).find('a.detail-icon').attr('aria-label', lang.line('table_row_details'));
    });

    $(function() {
        initTooltips();
        drawerQuery.addEventListener('change', closeDrawerWhenWide);

        $('#app-sidebar').on('click', '.app-nav-link, .app-user-link', function(event) {
            if (event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
                hideDrawer();
            }
        });
    });

    $(document).ajaxComplete(function() {
        initTooltips();
    });

})(jQuery);

(function($) {

    $(document).on('change', 'input[type="file"][data-preview]', function() {
        const file = this.files && this.files[0];
        const $preview = $(this).closest('.image-upload').find($(this).data('preview'));

        if (file && file.type.indexOf('image/') === 0) {
            $preview.attr('src', URL.createObjectURL(file)).removeClass('d-none');
        }
    });

})(jQuery);

(function($) {

    $(document).on('click', '.image-upload-remove', function() {
        const $box = $(this).closest('.image-upload');

        $box.find('.image-upload-preview').addClass('d-none').removeAttr('src');
        $box.find('input[type="file"]').val('');
        $(this).addClass('d-none');
    });

})(jQuery);
