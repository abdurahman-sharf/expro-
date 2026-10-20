(function($) {

    const initTooltips = function(root) {
        $(root || document).find('[data-bs-toggle="tooltip"]').each(function() {
            bootstrap.Tooltip.getOrCreateInstance(this);
        });
    };

    const phoneQuery = window.matchMedia('(min-width: 640px)');

    const closeDrawerWhenWide = function(event) {
        if (event.matches) {
            const drawer = document.getElementById('app-sidebar');
            drawer && bootstrap.Offcanvas.getInstance(drawer)?.hide();
        }
    };

    $(function() {
        initTooltips();
        phoneQuery.addEventListener('change', closeDrawerWhenWide);

        $('#app-sidebar').on('click', '.app-nav-link', function() {
            const drawer = document.getElementById('app-sidebar');
            bootstrap.Offcanvas.getInstance(drawer)?.hide();
        });
    });

    $(document).ajaxComplete(function() {
        initTooltips();
    });

})(jQuery);
