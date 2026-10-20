(function(dialog_support, $) {

    let clickedId, currentModal;

    const SUBMITTING = ['submit', 'new'];

    const BUTTON_CLASSES = {
        submit: 'btn-primary',
        new: 'btn-outline-primary',
        delete: 'btn-danger',
        restore: 'btn-outline-primary'
    };

    const hide = function() {
        currentModal && currentModal.hide();
    };

    const clicked_id = function() {
        return clickedId;
    };

    const submit = function(buttonId, $modal) {
        const form = $('form', $modal.find('.modal-body')).first();
        const validator = form.data('validator');
        const submitted = validator && validator.formSubmitted;
        const $button = $modal.find('.modal-footer [data-dialog-button="' + buttonId + '"]');

        clickedId = buttonId;

        if (SUBMITTING.includes(buttonId) && form.length && !submitted) {
            form.submit();
            if (validator && validator.valid()) {
                $button.prop('disabled', true);
                $(document).one('ajaxComplete', function() {
                    $button.prop('disabled', false);
                });
            }
        }
        return false;
    };

    const buttonsOf = function($link) {
        const buttons = [];

        $.each($link.data(), function(name, label) {
            const match = /^btn([A-Z].*)$/.exec(name);
            if (match) {
                const id = match[1].toLowerCase();
                buttons.push({id: id, label: label, cssClass: BUTTON_CLASSES[id] || 'btn-outline-secondary'});
            }
        });

        buttons.sort(function(a, b) {
            return SUBMITTING.indexOf(a.id) - SUBMITTING.indexOf(b.id);
        });

        return buttons;
    };

    const dialogClassOf = function($link) {
        const widthClass = ($link.attr('class') || '').split(/\s+/).find(function(className) {
            return className.indexOf('modal-dlg-') === 0;
        });

        return widthClass || '';
    };

    const modalOf = function($link, buttons) {
        const wide = dialogClassOf($link) === 'modal-dlg-wide';
        const $modal = $(
            '<div class="modal fade modal-dlg" tabindex="-1" aria-hidden="true">' +
                '<div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down ' + (wide ? 'modal-xl' : 'modal-lg') + '">' +
                    '<div class="modal-content">' +
                        '<div class="modal-header"><h2 class="modal-title fs-5"></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                        '<div class="modal-body"><div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div></div>' +
                        '<div class="modal-footer"></div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        const title = $link.attr('title') || '';
        $modal.find('.modal-title').text(title).attr('id', 'dialog-title-' + Date.now());
        $modal.attr('aria-labelledby', $modal.find('.modal-title').attr('id'));
        $modal.find('.btn-close').attr('aria-label', lang.line('common_close'));

        const $footer = $modal.find('.modal-footer');
        $footer.append($('<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"></button>').text(lang.line('common_close')));
        $.each(buttons, function(index, button) {
            $('<button type="button"></button>')
                .attr({id: button.id, 'data-dialog-button': button.id})
                .addClass('btn ' + button.cssClass)
                .text(button.label)
                .on('click', function() {
                    return submit(button.id, $modal);
                })
                .appendTo($footer);
        });

        return $modal;
    };

    const show = function(link) {
        const $link = $(link);
        const buttons = buttonsOf($link);
        const $modal = modalOf($link, buttons);
        const hasNew = buttons.some(function(button) {
            return button.id === 'new';
        });
        const enterButton = hasNew ? 'new' : 'submit';

        hide();
        $modal.appendTo(document.body);
        const modal = new bootstrap.Modal($modal[0]);
        currentModal = modal;

        $modal.on('hidden.bs.modal', function() {
            modal.dispose();
            $modal.remove();
            if (currentModal === modal) {
                currentModal = undefined;
            }
        });

        $modal.on('keydown', function(event) {
            if (event.key === 'Enter' && $(event.target).is('input:not([type=button],[type=submit],[type=checkbox],[type=radio],[type=file])')) {
                const $enterButton = $modal.find('[data-dialog-button="' + enterButton + '"]');
                if ($enterButton.length && !$enterButton.prop('disabled')) {
                    event.preventDefault();
                    $enterButton.trigger('click');
                }
            }
        });

        const $body = $modal.find('.modal-body');
        $.get($link.attr('href') || $link.data('href'))
            .done(function(data) {
                $body.html(data);
            })
            .fail(function(jqXHR) {
                $body.empty().append($('<div class="alert alert-danger mb-0" role="alert"></div>').text(jqXHR.status + ' ' + jqXHR.statusText));
            });

        modal.show();
    };

    const init = function() {
        $(document).off('click.dialog').on('click.dialog', '.modal-dlg', function(event) {
            const $link = $(event.target).closest('a, button');
            if ($link.hasClass('modal-dlg')) {
                event.preventDefault();
                show($link[0]);
            }
        });
    };

    $(init);

    $.extend(dialog_support, {
        init: init,
        submit: submit,
        hide: hide,
        clicked_id: clicked_id
    });

})(window.dialog_support = window.dialog_support || {}, jQuery);
