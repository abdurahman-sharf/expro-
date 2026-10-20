(function($) {

    $(function() {
        $.notifyDefaults({
            z_index: 2000,
            delay: 4000,
            offset: {x: 16, y: 16},
            animate: {enter: 'app-toast-in', exit: 'app-toast-out'},
            template: '<div data-notify="container" class="app-toast alert alert-{0} d-flex align-items-start gap-2 shadow" role="alert">' +
                '<span data-notify="message" class="flex-grow-1">{2}</span>' +
                '<button type="button" class="btn-close" data-notify="dismiss" aria-label="' + lang.line('common_close') + '"></button>' +
                '</div>'
        });
    });

})(jQuery);
