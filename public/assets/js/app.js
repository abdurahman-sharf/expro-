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
;
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
;
(function(table_support, $) {

    let enable_actions = function(callback) {
        return function() {
            const selection_empty = selected_rows().length == 0;
            $("#toolbar button:not(.dropdown-toggle)").attr('disabled', selection_empty);
            typeof callback == 'function' && callback();
        }
    };

    const table = function() {
        return $("#table").data('bootstrap.table');
    }

    const selected_ids = function () {
        return $.map(table().getSelections(), function (element) {
            return element[options.uniqueId || 'id'] !== '-' ? element[options.uniqueId || 'id'] : null;
        });
    };

    const selected_rows = function () {
        return $("#table td input:checkbox:checked").parents("tr");
    };

    const row_selector = function(id) {
        return "tr[data-uniqueid='" + id + "']";
    };

    const rows_selector = function(ids) {
        const selectors = [];
        ids = ids instanceof Array ? ids : ("" + ids).split(":");
        $.each(ids, function(index, element) {
            selectors.push(row_selector(element));
        });
        return selectors;;
    };

    const highlight_row = function (id) {
        $(rows_selector(id).join(',')).addClass('row-highlight');
        setTimeout(function() {
            $('#table tr.row-highlight').removeClass('row-highlight');
        }, 6000);
    };

    const do_action = function(action) {
        return function (url, ids) {
            if (confirm($.fn.bootstrapTable.defaults.formatConfirmAction(action))) {
                const targetIds = ids || selected_ids();
                $.post((url || options.resource) + '/' + action, {'ids[]': targetIds}, function (response) {
                    if (response.success) {
                        if (options) {
                            const selector = ids ? row_selector(ids) : selected_rows();
                            table().collapseAllRows();
                            $(selector).addClass('row-removing');
                            setTimeout(function () {
                                table().remove({
                                    field: options.uniqueId,
                                    values: targetIds
                                });
                                refresh();
                                enable_actions();
                            }, 900);
                        } else {
                            location.reload();
                        }
                        $.notify(response.message, {type: 'success'});
                    } else {
                        $.notify(response.message, {type: 'danger'});
                    }
                }, "json");
            } else {
                return false;
            }
        };
    };

    let load_success = function(callback) {
        return function(response) {
            typeof options.load_callback == 'function' && options.load_callback();
            options.load_callback = undefined;
            $('#table input[name="btSelectAll"]').attr('aria-label', lang.line('table_select_all'));
            $('#table input[name="btSelectItem"]').attr('aria-label', lang.line('table_select_row'));
            typeof callback == 'function' && callback.call(this, response);
        }
    };

    let options;

    const toggle_column_visibility = function() {
        if (localStorage[options.employee_id]) {
            const user_settings = JSON.parse(localStorage[options.employee_id]);
            user_settings[options.resource] && $.each(user_settings[options.resource], function(index, element) {
                if (table().fieldsColumnsIndex[index] === undefined) {
                    return;
                }
                element ? table().showColumn(index) : table().hideColumn(index);
            });
        }
    };

    const init = function (_options) {
        options = _options;
        enable_actions = enable_actions(options.enableActions);
        load_success = load_success(options.onLoadSuccess);
        const export_suffix = new Date().toISOString().slice(0, 16).replace(/(-|\s*|T|:)*/g,"");
        const cardTitle = options.cardTitle || [];
        const isAction = function(column) {
            return !column.checkbox && /^(|&nbsp;)$/.test(column.title || '');
        };
        const actionFields = $.map(options.headers, function(column) {
            return isAction(column) ? column.field : null;
        }).sort(function(first, second) {
            return (second === 'edit') - (first === 'edit');
        });
        const columns = $.map(options.headers, function(column) {
            if (isAction(column)) {
                return null;
            }
            return $.extend({}, column, {
                cardVisible: column.field !== options.uniqueId,
                cellStyle: cardTitle.indexOf(column.field) >= 0 ? function() {
                    return {classes: 'card-primary'};
                } : column.cellStyle
            });
        });
        if (actionFields.length) {
            columns.push({
                field: 'row_actions',
                title: '',
                switchable: false,
                sortable: false,
                escape: false,
                class: 'print_hide row-actions',
                formatter: function(value, row) {
                    return $.map(actionFields, function(field) {
                        return row[field] || '';
                    }).join('');
                }
            });
        }
        $('#table')
            .addClass("table-hover")
            .bootstrapTable($.extend(options, {
            columns: columns,
            stickyHeader: true,
            url: options.resource + '/search',
            sidePagination: 'server',
            selectItemName: 'btSelectItem',
            pageSize: options.pageSize,
            pagination: true,
            search: options.resource || false,
            showColumns: true,
            clickToSelect: true,
            showExport: true,
            mobileResponsive: true,
            minWidth: columns.length > 8 ? 1100 : 640,
            exportDataType: 'basic',
            exportTypes: ['json', 'xml', 'csv', 'txt', 'sql', 'excel'],
            buttons: pdf_export.buttons(false),
            exportOptions: {
                fileName: options.resource.replace(/.*\/(.*?)$/g, '$1') + "_" + export_suffix
            },
            onPageChange: function(response) {
                load_success(response);
                enable_actions();
            },
            toolbar: '#toolbar',
            uniqueId: options.uniqueId || 'id',
            trimOnSearch: false,
            onCheck: enable_actions,
            onUncheck: enable_actions,
            onCheckAll: enable_actions,
            onUncheckAll: enable_actions,
            onLoadSuccess: function(response) {
                load_success(response);
                enable_actions();
            },
            onPostBody: isolate_numbers,
            onColumnSwitch : function(field, checked) {
                let user_settings = localStorage[options.employee_id];
                user_settings = (user_settings && JSON.parse(user_settings)) || {};
                user_settings[options.resource] = user_settings[options.resource] || {};
                user_settings[options.resource][field] = checked;
                localStorage[options.employee_id] = JSON.stringify(user_settings);
            },
            loadingTemplate: function (loadingMessage) {
                return '<div class="loading-message text-center"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span role="status">' + loadingMessage + '</span></div>';
            },
            loadingFontSize: '1em',
            queryParamsType: 'limit',
            iconSize: 'sm',
            buttonsClass: 'outline-secondary',
            silentSort: true,
            paginationVAlign: 'bottom',
            escape: true
        }));
        enable_actions();
        init_delete();
        init_restore();
        toggle_column_visibility();
    };

    const isolate_numbers = function () {
        $('#table tbody td, #table tbody .card-view-value').each(function () {
            const nodes = this.childNodes;
            if (nodes.length === 1 && nodes[0].nodeType === 3 && /^[^\u0590-\u08FF]*\d[^\u0590-\u08FF]*$/.test(nodes[0].nodeValue)) {
                $(this).wrapInner('<bdi dir="ltr"></bdi>');
            }
        });
    };

    const init_delete = function (confirmMessage) {
        $("#delete").click(function(event) {
            do_action("delete")();
        });
    };

    const init_restore = function (confirmMessage) {
        $("#restore").click(function(event) {
            do_action("restore")();
        });
    };

    const refresh = function() {
        table().refresh();
    }

    const submit_handler = function(url) {
        return function (resource, response) {
            const id = response.id !== undefined ? response.id.toString() : "";
            if (!response.success) {
                $.notify(response.message, { type: 'danger' });
            } else {
                const message = response.message;
                const selector = rows_selector(response.id);
                const rows = $(selector.join(",")).length;
                if (rows > 0 && rows < 15) {
                    const ids = id.split(":");
                    $.get([url || resource + '/row', id].join("/"), {}, function (response) {
                        $.each(selector, function (index, element) {
                            const id = $(element).data('uniqueid');
                            table().updateByUniqueId({id: id, row: response[id] || response});
                        });
                        highlight_row(ids);
                    }, 'json');
                } else {
                    // Call hightlight function once after refresh
                    options.load_callback = function () {
                        enable_actions();
                        highlight_row(id);
                    };
                    refresh();
                }
                $.notify(message, {type: 'success' });
            }
            return false;
        };
    };

    const handle_submit = submit_handler();

    $.extend(table_support, {
        submit_handler: function(url) {
            this.handle_submit = submit_handler(url);
        },
        handle_submit: handle_submit,
        init: init,
        do_delete: do_action("delete"),
        do_restore: do_action("restore"),
        refresh : refresh,
        selected_ids : selected_ids,
    });

})(window.table_support = window.table_support || {}, jQuery);

(function(form_support, $) {

    form_support.error = {
        errorClass: "text-danger",
        errorLabelContainer: "#error_message_box",
        wrapper: "li",
        onfocusout: false,
        highlight: function (e) {
            $(e).addClass('is-invalid');
        },
        unhighlight: function (e) {
            $(e).removeClass('is-invalid');
        }
    };

    form_support.handler = $.extend({

        submitHandler: function(form) {
            $(form).ajaxSubmit({
                success: function(response)
                {
                    $.notify(response.message, { type: response.success ? 'success' : 'danger' });
                },
                dataType: 'json'
            });
        },

        rules:
        {

        },

        messages:
        {

        }
    }, form_support.error);

})(window.form_support = window.form_support || {}, jQuery);

function number_sorter(a, b) {
    a = +a.replace(/[^\-0-9]+/g, '');
    b = +b.replace(/[^\-0-9]+/g, '');
    return a - b;
}
;
(function(pdf_export, $) {

    const MAX_ROWS = 5000;
    const parser = new DOMParser();

    const to_text = function(value) {
        if (value === null || value === undefined) {
            return '';
        }
        const html = String(value).replace(/<br\s*\/?>/gi, '\n');
        return parser.parseFromString('<body>' + html + '</body>', 'text/html').body.textContent
            .replace(/[ \t ]+/g, ' ')
            .replace(/ ?\n ?/g, '\n')
            .trim();
    };

    const exportable = function(column) {
        return !column.checkbox && column.field && column.title && !/print_hide/.test(column.class || '');
    };

    const collect = function(table, all) {
        const columns = table.getVisibleColumns().filter(exportable);
        const rows = table.getData({
            useCurrentPage: !all && table.options.sidePagination !== 'server',
            includeHiddenRows: false,
            formatted: true
        });
        const matrix = rows.map(function(row) {
            return columns.map(function(column) {
                return to_text(row[column.field]);
            });
        });
        const kept = columns.map(function(column, index) {
            return matrix.some(function(cells) {
                return cells[index] !== '';
            });
        });

        return {
            title: to_text($('#page_title').text()) || document.title.split('|')[0].trim(),
            subtitle: to_text($('#page_subtitle').text()),
            columns: columns.filter(function(column, index) {
                return kept[index];
            }).map(function(column) {
                return to_text(column.title);
            }),
            rows: matrix.map(function(cells) {
                return cells.filter(function(cell, index) {
                    return kept[index];
                });
            })
        };
    };

    const submit = function(payload) {
        const form = $('<form method="post" target="_blank" class="d-none"></form>').attr('action', 'exports/pdf');
        $.each(csrf_form_base(), function(name, value) {
            form.append($('<input type="hidden">').attr('name', name).val(typeof value === 'function' ? value() : value));
        });
        form.append($('<input type="hidden" name="payload">').val(JSON.stringify(payload)));
        form.appendTo(document.body);
        form[0].submit();
        setTimeout(function() {
            form.remove();
        }, 1000);
    };

    pdf_export.buttons = function(all) {
        return function() {
            return {
                exportPdf: {
                    text: lang.line('table_export_pdf'),
                    icon: 'bi-file-earmark-pdf',
                    event: function() {
                        const payload = collect(this, all);
                        if (payload.rows.length > MAX_ROWS) {
                            $.notify(lang.line('table_export_too_many_rows'), { type: 'danger' });
                            return;
                        }
                        submit(payload);
                    },
                    attributes: {
                        title: lang.line('table_export_pdf')
                    }
                }
            };
        };
    };

})(window.pdf_export = window.pdf_export || {}, jQuery);
;
(function($) {

    const config = function() {
        return window.posConfig || {decimals: 2, decimalSeparator: '.', groupSeparator: ''};
    };

    const parseAmount = function(text) {
        const settings = config();
        let value = String(text).replace(/\s/g, '');
        if (settings.groupSeparator) {
            value = value.split(settings.groupSeparator).join('');
        }
        if (settings.decimalSeparator !== '.') {
            value = value.replace(settings.decimalSeparator, '.');
        }
        const number = parseFloat(value);
        return isNaN(number) ? 0 : number;
    };

    const formatAmount = function(number) {
        const settings = config();
        return number.toFixed(settings.decimals).replace('.', settings.decimalSeparator);
    };

    const formatQuantity = function(number) {
        const separator = config().decimalSeparator;
        const text = formatAmount(number);
        return text.indexOf(separator) === -1 ? text : text.replace(/0+$/, '').replace(separator, '.').replace(/\.$/, '').replace('.', separator);
    };

    const STEPS = [5, 10, 20, 50, 100, 200, 500, 1000];

    const roundedAmounts = function(due) {
        const amounts = [];
        STEPS.forEach(function(step) {
            const rounded = Math.ceil(due / step) * step;
            if (rounded > due && amounts.indexOf(rounded) === -1) {
                amounts.push(rounded);
            }
        });
        return amounts.slice(0, 4);
    };

    let dueAmount = 0;

    const renderChange = function() {
        const $input = $('#amount_tendered');
        const $box = $('#change_box');
        if (!$box.length) {
            return;
        }
        const change = parseAmount($input.val()) - dueAmount;
        const visible = $input.is(':enabled') && change > 0.000001;
        $box.toggleClass('d-none', !visible);
        $('#change_value').text(visible ? formatAmount(change) : '');
    };

    const renderQuickAmounts = function() {
        const $quick = $('#quick_amounts');
        if (!$quick.length) {
            return;
        }
        const usable = $('#amount_tendered').is(':enabled') && dueAmount > 0;
        $quick.toggleClass('d-none', !usable).empty();
        if (!usable) {
            return;
        }
        const amounts = [dueAmount].concat(roundedAmounts(dueAmount));
        amounts.forEach(function(amount, index) {
            $('<button type="button" class="btn btn-outline-secondary btn-sm"></button>')
                .text(index === 0 ? $quick.data('exact-label') : formatAmount(amount))
                .attr('data-amount', formatAmount(amount))
                .appendTo($quick);
        });
    };

    const syncTotalBar = function() {
        const total = $('#sale_total').html();
        if (total !== undefined) {
            $('#pos_total_value').html(total);
        }
    };

    $(document).on('pos:payment-changed', function() {
        dueAmount = parseAmount($('#amount_tendered').val());
        renderQuickAmounts();
        renderChange();
        syncTotalBar();
    });

    $(document).on('click', '#quick_amounts [data-amount]', function() {
        $('#amount_tendered').val($(this).attr('data-amount')).trigger('input').trigger('focus');
    });

    $(document).on('input', '#amount_tendered', renderChange);

    const stepTimers = {};

    $(document).on('click', '.qty-b', function() {
        const $input = $(this).siblings('input');
        const $form = $(this).closest('form');
        const next = parseAmount($input.val()) + Number($(this).attr('data-step'));
        $input.val(formatQuantity(next));
        clearTimeout(stepTimers[$form.attr('id')]);
        stepTimers[$form.attr('id')] = setTimeout(function() {
            $form.submit();
        }, 500);
    });

    const MAX_METHOD_BUTTONS = 4;

    const buildOptionButtons = function(select) {
        const $select = $(select);
        if ($select.next('.pos-methods').length || $select.find('option').length > MAX_METHOD_BUTTONS) {
            return;
        }
        const $group = $('<div class="pos-methods" role="group"></div>').attr('aria-label', $('label[for="' + select.id + '"]').text());
        $select.find('option').each(function() {
            $('<button type="button" class="pos-method"></button>')
                .text($(this).text())
                .attr('data-value', this.value)
                .appendTo($group);
        });
        $select.addClass('d-none').after($group);

        const refresh = function() {
            $group.children().each(function() {
                $(this).attr('aria-pressed', $(this).attr('data-value') === String($select.val()));
            });
        };
        $group.on('click', '.pos-method', function() {
            $select.val($(this).attr('data-value')).trigger('change');
            refresh();
        });
        $select.on('change', refresh);
        refresh();
    };

    $(function() {
        $('select[data-option-buttons]').each(function() {
            buildOptionButtons(this);
        });

        $('#pos_total_action').on('click', function() {
            const $finish = $('#finish_sale_button, #finish_invoice_quote_button, #finish_receiving_button').first();
            if ($finish.length) {
                $finish.trigger('click');
                return;
            }
            const $target = $('#payment_card, #select_customer').first();
            if ($target.length) {
                $target[0].scrollIntoView({behavior: 'smooth', block: 'start'});
                $target.find('input:enabled:visible').first().trigger('focus');
            }
        });
    });

})(jQuery);
;
/*
 * imgPreview jQuery plugin
 * Copyright (c) 2009 James Padolsey
 * j@qd9.co.uk | http://james.padolsey.com
 * Dual licensed under MIT and GPL.
 * Updated: 09/02/09
 * @author James Padolsey
 * @version 0.22
 */
(function($){

    $.expr[':'].linkingToImage = function(elem, index, match){
        // This will return true if the specified attribute contains a valid link to an image:
        return !! ($(elem).attr(match[3]) && $(elem).attr(match[3]).match(/\.(gif|jpe?g|png|bmp)$/i));
    };

    $.fn.imgPreview = function(userDefinedSettings){

        var s = $.extend({

            /* DEFAULTS */

            // CSS to be applied to image:
            imgCSS: {},
            // Distance between cursor and preview:
            distanceFromCursor: {top:10, left:10},
            // Boolean, whether or not to preload images:
            preloadImages: true,
            // Callback: run when link is hovered: container is shown:
            onShow: function(){},
            // Callback: container is hidden:
            onHide: function(){},
            // Callback: Run when image within container has loaded:
            onLoad: function(){},
            // ID to give to container (for CSS styling):
            containerID: 'imgPreviewContainer',
            // Class to be given to container while image is loading:
            containerLoadingClass: 'loading',
            // Prefix (if using thumbnails), e.g. 'thumb_'
            thumbPrefix: '',
            // Where to retrieve the image from:
            srcAttr: 'href'

        }, userDefinedSettings),

        $container = $('<div/>').attr('id', s.containerID)
                        .append('<img/>').hide()
                        .css('position','absolute')
                        .appendTo('body'),

        $img = $('img', $container).css(s.imgCSS),

        // Get all valid elements (linking to images / ATTR with image link):
        $collection = this.filter(':linkingToImage(' + s.srcAttr + ')');

        // Re-usable means to add prefix (from setting):
        function addPrefix(src) {
            return src && src.replace(/(\/?)([^\/]+)$/,'$1' + s.thumbPrefix + '$2');
        }

        if (s.preloadImages) {
            (function(i){
                var tempIMG = new Image(),
                    callee = arguments.callee;
                var src = $($collection[i]).attr(s.srcAttr)
                if (src)
                {
                    tempIMG.src = addPrefix(src);
                    tempIMG.onload = function(){
                        $collection[i + 1] && callee(i + 1);
                    };
                }
            })(0);
        }

        $collection
            .mousemove(function(e){

                $container.css({
                    top: e.pageY + s.distanceFromCursor.top + 'px',
                    left: e.pageX + s.distanceFromCursor.left + 'px'
                });

            })
            .hover(function(){

                var link = this;
                $container
                    .addClass(s.containerLoadingClass)
                    .show();
                $img
                    .load(function(){
                        $container.removeClass(s.containerLoadingClass);
                        $img.show();
                        s.onLoad.call($img[0], link);
                    })
                    .attr( 'src' , addPrefix($(link).attr(s.srcAttr)) );
                s.onShow.call($container[0], link);

            }, function(){

                $container.hide();
                $img.unbind('load').attr('src','').hide();
                s.onHide.call($container[0], this);

            });

        // Return full selection, not $collection!
        return this;

    };

})(jQuery);
;
(function($) {

    function http_s(url)
    {
        return document.location.protocol + '//' + url;
    }

    const url = http_s('nominatim.openstreetmap.org/search');

    const handle_auto_completion = function(fields) {
        return function(event, ui) {
            const results = ui.item.results;
            if (results != null && results.length > 0) {
                // Handle auto completion
                for(const i in fields) {
                    $("#" + fields[i]).val(results[i]);
                }
                return false;
            }
            return true;
        };
    };

    const create_parser = function(field_name, parse_format)
    {
        const parse_field = function(format, address)
        {
            const fields = [];
            $.each(format.split("|"), function(key, value)
               {
                if (address[value] && fields.length < 2 && $.inArray(address[value], fields) === -1)
                {
                    fields.push(address[value]);
                }
               });
               return fields[0] + (fields[1] ? ' (' + fields[1] + ')' : '');
        };

        return function(data)
        {
            const parsed = [];
            $.each(data, function(index, value)
            {
                const row = [];
                const address = value.address;
                $.each(parse_format, function(key, format)
                {
                    row.push(parse_field(format, address));
                });
                parsed[index] = {
                    label: row.join(", "),
                    results: row,
                    value: address[field_name]
                };
            });
            return parsed;
        };
    };

    const init = function(options) {

        const default_params = function(id, key, language)
        {
            return function() {
                const result = {
                    format: 'json',
                    limit: 5,
                    addressdetails: 1,
                    countrycodes: options.country_codes,
                    'accept-language' : language || navigator.language
                };
                result[key || id] = $("#"+id).val();
                return result;
            }

        };

        const unique = function(parsed) {
            let filtered = [];
            $.each(parsed, function(index, element)
            {
                filtered = $.map(filtered, function(el, ind)
                {
                    return el.label == element.label ? null : el;
                });
                filtered.push(element);

            });
            return filtered;
        };

        $.each(options.fields, function(key, value)
        {
            const handle_field_completion = handle_auto_completion(value.dependencies);

            $("#" + key).autocomplete({
                source: function (request, response) {
                    const params = default_params(key, value.response && value.response.field, options.language);
                    const request_params = {};
                    options.extra_params && $.each(options.extra_params, function(key, param) {
                        request_params[key] = typeof param == "function" ? param() : param;
                    });

                    $.ajax({
                        type: "GET",
                        url: url,
                        dataType: "json",
                        data: $.extend(request_params, params()),
                        success: function(data) {
                            response(unique($.map(data, function(item) {
                                return (create_parser(key, (value.response && value.response.format) || value.dependencies))(data)
                            })))
                        }
                    });
                },
                minChars:3,
                delay:1000,
                appendTo: '.modal-content',
                select: handle_field_completion
            });

        });
    };

    const nominatim = {

        init : init

    };

    window['nominatim'] = nominatim;

})(jQuery);
;
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

    $(document).on('post-body.bs.table', function(event) {
        $(event.target).find('a.detail-icon').attr('aria-label', lang.line('table_row_details'));
    });

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
;
(function($) {

    const addTag = function($select, text) {
        const value = $.trim(text);
        if (value === '' || $select.find('option').filter(function() { return this.value === value; }).length) {
            return false;
        }
        $('<option>').val(value).text(value).prop('selected', true).appendTo($select);
        return true;
    };

    const render = function($select, $box) {
        $box.find('.tag').remove();
        const $input = $box.find('.tags-input-field');
        $select.find('option:selected').each(function() {
            const value = this.value;
            const $tag = $('<span class="tag"></span>').text(value);
            $('<button type="button" class="btn-close" aria-label="' + lang.line('common_close') + '"></button>')
                .on('click', function() {
                    $select.find('option').filter(function() { return this.value === value; }).remove();
                    render($select, $box);
                })
                .appendTo($tag);
            $tag.insertBefore($input);
        });
    };

    const enhance = function(select) {
        const $select = $(select);
        const $box = $('<div class="tags-input form-control form-control-sm"></div>');
        const $input = $('<input type="text" class="tags-input-field">').appendTo($box);

        $select.addClass('d-none').attr('data-tags-ready', '').after($box);
        render($select, $box);

        $input.on('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                addTag($select, $input.val()) && render($select, $box);
                $input.val('');
            }
        }).on('blur', function() {
            addTag($select, $input.val()) && render($select, $box);
            $input.val('');
        });
        $box.on('click', function() {
            $input.trigger('focus');
        });
    };

    const init = function() {
        $('select[data-tags]:not([data-tags-ready])').each(function() {
            enhance(this);
        });
    };

    $(init);
    $(document).ajaxComplete(init);

})(jQuery);