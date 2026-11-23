(function($) {

    const ICONS = {danger: 'bi-trash3', warning: 'bi-exclamation-triangle', primary: 'bi-question-circle'};

    // The design system's confirmation dialog in place of the browser's: it resolves true only when the action
    // is confirmed. A destructive action is red and starts with the focus on "cancel".
    window.appConfirm = function(message, options) {
        const settings = $.extend({tone: 'primary', title: lang.line('common_confirm_title'), confirmLabel: lang.line('common_confirm')}, options);

        return new Promise(function(resolve) {
            let confirmed = false;
            const titleId = 'confirm-title-' + Date.now();
            const $modal = $(
                '<div class="modal fade app-confirm" tabindex="-1" role="alertdialog" aria-modal="true">' +
                    '<div class="modal-dialog modal-dialog-centered">' +
                        '<div class="modal-content">' +
                            '<div class="modal-body">' +
                                '<span class="app-confirm-art" aria-hidden="true"><i class="bi"></i></span>' +
                                '<div class="app-confirm-copy"><h2 class="app-confirm-title"></h2><p class="app-confirm-text"></p></div>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"></button>' +
                                '<button type="button" class="btn app-confirm-ok"></button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );

            $modal.addClass('is-' + settings.tone).attr({'aria-labelledby': titleId, 'aria-describedby': titleId + '-text'});
            $modal.find('.app-confirm-art i').addClass(ICONS[settings.tone] || ICONS.primary);
            $modal.find('.app-confirm-title').attr('id', titleId).text(settings.title);
            $modal.find('.app-confirm-text').attr('id', titleId + '-text').text(message);
            $modal.find('[data-bs-dismiss]').text(lang.line('common_cancel'));
            $modal.find('.app-confirm-ok')
                .addClass(settings.tone === 'danger' ? 'btn-danger' : 'btn-primary')
                .text(settings.confirmLabel)
                .on('click', function() {
                    confirmed = true;
                    modal.hide();
                });

            const modal = new bootstrap.Modal($modal.appendTo(document.body)[0]);

            $modal.on('show.bs.modal', function() {
                // Asked from inside another dialog: stand above it and its backdrop.
                setTimeout(function() {
                    $('.modal-backdrop').last().addClass('app-confirm-backdrop');
                });
            });
            $modal.on('shown.bs.modal', function() {
                $modal.find(settings.tone === 'danger' ? '[data-bs-dismiss]' : '.app-confirm-ok').trigger('focus');
            });
            $modal.on('hidden.bs.modal', function() {
                modal.dispose();
                $modal.remove();
                resolve(confirmed);
            });

            modal.show();
        });
    };

})(jQuery);
