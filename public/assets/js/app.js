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
(function(toast_support, $) {

    const DISPLAY_MS = 4000;
    const EXIT_MS = 220;

    const container = function() {
        let $container = $('#app-toasts');

        if ($container.length === 0) {
            $container = $('<div id="app-toasts" class="position-fixed top-0 end-0 p-3 d-flex flex-column gap-2" aria-live="polite"></div>').appendTo(document.body);
        }

        return $container;
    };

    const show = function(message, type) {
        const $toast = $('<div class="app-toast app-toast-in alert d-flex align-items-start gap-2 shadow" role="alert"></div>').addClass('alert-' + (type || 'info'));

        const dismiss = function() {
            $toast.addClass('app-toast-out');
            setTimeout(function() {
                $toast.remove();
            }, EXIT_MS);
        };

        $('<span class="flex-grow-1"></span>').text(message).appendTo($toast);
        $('<button type="button" class="btn-close"></button>').attr('aria-label', lang.line('common_close')).on('click', dismiss).appendTo($toast);

        container().append($toast);
        setTimeout(dismiss, DISPLAY_MS);
    };

    $.extend(toast_support, {show: show});

})(window.toast_support = window.toast_support || {}, jQuery);
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
            $("#toolbar button:not(.dropdown-toggle):not([data-keep-enabled])").attr('disabled', selection_empty);
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
                }, "json").fail(function () {
                    $.notify($.fn.bootstrapTable.defaults.formatRequestFailed(), {type: 'danger'});
                });
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
            const rowActions = {
                field: 'row_actions',
                title: options.rowActionsTitle || '',
                switchable: false,
                sortable: false,
                escape: false,
                class: 'print_hide row-actions',
                formatter: function(value, row) {
                    return $.map(actionFields, function(field) {
                        return row[field] || '';
                    }).join('');
                }
            };
            options.rowActionsFirst === true ? columns.unshift(rowActions) : columns.push(rowActions);
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
            showExport: options.showExport !== false,
            mobileResponsive: true,
            minWidth: columns.length > 8 ? 1100 : 640,
            exportDataType: 'basic',
            exportTypes: options.exportTypes || ['json', 'xml', 'csv', 'txt', 'sql', 'excel'],
            buttons: options.buttons || pdf_export.buttons(false),
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
        report_options: function(columns) {
            return {
                columns: $.map(columns, function(column, index) {
                    return $.extend({}, column, {
                        cellStyle: index === 0 ? function() {
                            return {classes: 'card-primary'};
                        } : column.cellStyle
                    });
                }),
                mobileResponsive: true,
                minWidth: columns.length > 8 ? 1100 : 640,
                onPostBody: isolate_numbers
            };
        }
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
(function(accounting_support, $) {

    let pickers = 0;

    const normalize = function(value) {
        return $.trim(value || '').toLowerCase();
    };

    const bindSearch = function(remote, remember, pin) {
        const id = ++pickers;
        const listId = 'acc_picker_list_' + id;
        const hintId = 'acc_picker_hint_' + id;
        const text = $.extend({count: '{0}', hint: '', more: '', none: '', failed: ''}, remote.text || {});
        const $list = $('<ul class="list-group shadow-sm acc-picker-list" role="listbox" hidden></ul>').attr('id', listId).css({position: 'absolute', zIndex: 2000, maxHeight: '16rem', overflowY: 'auto'});
        const $hint = $('<div class="visually-hidden"></div>').attr('id', hintId).text(text.hint);
        const $status = $('<div class="visually-hidden" role="status" aria-live="polite"></div>');
        let $input = null;
        let entries = [];
        let active = -1;
        let timer = null;
        let request = null;
        let choosing = false;

        $('body').append($list, $hint, $status);

        const close = function() {
            $list.attr('hidden', true).empty();
            entries = [];
            active = -1;
            $input && $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
        };

        const highlight = function(index) {
            active = index;
            $list.children('[role=option]').each(function(position) {
                $(this).toggleClass('active', position === index).attr('aria-selected', position === index ? 'true' : 'false');
            });

            if (index >= 0 && $input) {
                $input.attr('aria-activedescendant', listId + '_' + index);
                const option = document.getElementById(listId + '_' + index);
                option && option.scrollIntoView({block: 'nearest'});
            } else {
                $input && $input.removeAttr('aria-activedescendant');
            }
        };

        const choose = function(index) {
            const entry = entries[index];

            if (!entry || !$input) {
                return;
            }

            pin(entry);
            const $chosen = $input;
            clearTimeout(timer);
            request && request.abort();
            close();
            choosing = true;
            $chosen.val(entry.label).trigger('input').trigger('change');
            choosing = false;
        };

        const render = function(found, more) {
            entries = found;
            active = -1;
            $list.empty();

            $.each(found, function(index, entry) {
                $('<li class="list-group-item list-group-item-action py-1 px-2" role="option" aria-selected="false"></li>').attr('id', listId + '_' + index).text(entry.label).data('index', index).appendTo($list);
            });

            if (more) {
                $('<li class="list-group-item small text-body-secondary py-1 px-2" role="presentation"></li>').text(text.more).appendTo($list);
            }

            const rect = $input[0].getBoundingClientRect();
            $list.css({left: rect.left + window.pageXOffset, top: rect.bottom + window.pageYOffset, width: rect.width}).attr('hidden', found.length === 0);
            $input.attr('aria-expanded', found.length === 0 ? 'false' : 'true').removeAttr('aria-activedescendant');
            $status.text(found.length === 0 ? text.none : text.count.replace('{0}', found.length) + (more ? ' ' + text.more : ''));
        };

        const search = function() {
            clearTimeout(timer);
            const $asked = $input;

            timer = setTimeout(function() {
                request && request.abort();
                request = $.getJSON(remote.url, $.extend({}, remote.params, {term: $.trim($asked.val())})).done(function(data) {
                    if ($input && $input[0] === $asked[0]) {
                        $.each(data.results, function(index, entry) {
                            remember({id: String(entry.id), label: entry.label, code: entry.code});
                        });
                        render(data.results, data.more);
                    }
                }).fail(function(xhr, state) {
                    state !== 'abort' && $status.text(text.failed);
                });
            }, 200);
        };

        $(document).on('focusin', remote.selector, function() {
            $input = $(this);
            $input.attr({role: 'combobox', 'aria-autocomplete': 'list', 'aria-haspopup': 'listbox', 'aria-controls': listId, 'aria-describedby': hintId, 'aria-expanded': 'false', autocomplete: 'off'});
        }).on('input', remote.selector, function() {
            $input = $(this);
            choosing || search();
        }).on('keydown', remote.selector, function(event) {
            const open = !$list.prop('hidden') && entries.length > 0;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();

                if (!open) {
                    search();
                    return;
                }

                const step = event.key === 'ArrowDown' ? 1 : -1;
                highlight((active + step + entries.length) % entries.length);
            } else if (event.key === 'Enter' && open && active >= 0) {
                event.preventDefault();
                choose(active);
            } else if (event.key === 'Escape' && open) {
                event.preventDefault();
                event.stopPropagation();
                close();
            }
        }).on('focusout', remote.selector, function() {
            clearTimeout(timer);
            request && request.abort();
            close();
        });

        $list.on('mousedown', '[role=option]', function(event) {
            event.preventDefault();
            choose($(this).data('index'));
        });
    };

    const accountPicker = function(listId, remote) {
        const byLabel = {};
        const byCode = {};
        const pinned = {};

        const add = function(map, key, entry) {
            key !== '' && (map[key] = (map[key] || []).filter(function(other) {
                return other.id !== entry.id;
            }).concat(entry));
        };

        const remember = function(entry) {
            const known = {id: String(entry.id), label: entry.label, code: entry.code};

            add(byLabel, normalize(known.label), known);
            add(byCode, normalize(known.code), known);

            return known;
        };

        const pin = function(entry) {
            pinned[normalize(entry.label)] = remember(entry);
        };

        const only = function(found) {
            return found && found.length === 1 ? found[0] : null;
        };

        $('#' + listId + ' option').each(function() {
            remember({id: String($(this).data('id')), label: this.value, code: $(this).data('code')});
        });

        if (remote && remote.url) {
            $.each(remote.initial || [], function(index, entry) {
                pin({id: String(entry.id), label: entry.label, code: entry.code});
            });
            bindSearch(remote, remember, pin);
        }

        const resolve = function(text) {
            const key = normalize(text);
            return key === '' ? null : pinned[key] || only(byLabel[key]) || only(byCode[key]);
        };

        const sync = function($text, $hidden) {
            const match = resolve($text.val());
            $hidden.val(match ? match.id : '');
            $text.toggleClass('is-invalid', !match && normalize($text.val()) !== '');
            return match;
        };

        const complete = function($text, $hidden) {
            const match = sync($text, $hidden);
            match && $text.val(match.label);
        };

        return {resolve: resolve, sync: sync, complete: complete};
    };

    $.extend(accounting_support, {accountPicker: accountPicker});

})(window.accounting_support = window.accounting_support || {}, jQuery);
;
(function(accounting_chart, $) {

    const VIEW_KEY = 'expro.chart.view';
    const SEARCH_DELAY = 250;
    const HIGHLIGHT_MS = 4000;

    let settings = {confirmations: {}, text: {}, table: $.noop, selectedId: 0};
    let tableReady = false;
    let treeStale = false;
    let timer = null;
    let request = null;

    const remembered = function() {
        try {
            return window.localStorage.getItem(VIEW_KEY);
        } catch (error) {
            return null;
        }
    };

    const remember = function(view) {
        try {
            window.localStorage.setItem(VIEW_KEY, view);
        } catch (error) {
            // the choice only lasts for this visit
        }
    };

    const nodeById = function(id) {
        return $('#chart_tree_root li.acc-node[data-account-id="' + id + '"]');
    };

    const setOpen = function($node, open) {
        const $caret = $node.children('.acc-row').children('.acc-caret');

        if ($caret.length) {
            $caret.attr('aria-expanded', open ? 'true' : 'false');
            $node.children('.acc-children').prop('hidden', !open);
        }
    };

    const openIds = function($scope) {
        return $scope.find('li.acc-node').addBack('li.acc-node').filter(function() {
            return $(this).children('.acc-row').children('.acc-caret[aria-expanded="true"]').length > 0;
        }).map(function() {
            return $(this).data('account-id');
        }).get();
    };

    const searching = function() {
        return !$('#chart_results').prop('hidden');
    };

    const viewing = function() {
        return $('#chart_table_view').prop('hidden') ? 'tree' : 'table';
    };

    const find = function() {
        const term = $.trim($('#chart_search').val());

        request && request.abort();

        if (term === '') {
            $('#chart_results').prop('hidden', true).empty().removeAttr('aria-busy');
            $('#chart_tree_root').prop('hidden', false);
            $('#chart_tree_tools').prop('hidden', false);

            return;
        }

        $('#chart_tree_root').prop('hidden', true);
        $('#chart_tree_tools').prop('hidden', true);
        $('#chart_results').prop('hidden', false).attr('aria-busy', 'true');

        request = $.get('accounting_chart/find', {search: term})
            .done(function(html) {
                $('#chart_results').html(html).removeAttr('aria-busy');
            })
            .fail(function(jqXHR, status) {
                if (status !== 'abort') {
                    $('#chart_results').removeAttr('aria-busy');
                    $.notify(settings.text.load_failed, {type: 'danger'});
                }
            });
    };

    const showView = function(view) {
        const table = view === 'table';

        $('#chart_tree_view').prop('hidden', table);
        $('#chart_table_view').prop('hidden', !table);
        $('#chart_search_box').prop('hidden', table);
        $('#chart_tree_tools').prop('hidden', table || searching());
        $('#chart_view_switch [data-chart-view]').each(function() {
            const current = $(this).data('chart-view') === view;

            $(this).toggleClass('active', current).attr('aria-pressed', current ? 'true' : 'false');
        });
        remember(view);

        if (table) {
            if (tableReady) {
                table_support.refresh();
            } else {
                settings.table();
                tableReady = true;
            }
        } else if (treeStale) {
            window.location.reload();
        }
    };

    const highlight = function(id) {
        const $node = nodeById(id);

        if ($node.length) {
            $node[0].scrollIntoView({block: 'nearest'});
            setTimeout(function() {
                $node.removeClass('is-new');
            }, HIGHLIGHT_MS);
        }
    };

    const refresh = function(accountId, parentId) {
        if (tableReady) {
            table_support.refresh();
        }

        if (viewing() === 'table') {
            treeStale = true;

            return;
        }

        if (searching()) {
            find();

            return;
        }

        const nodeId = parentId > 0 ? parentId : accountId;
        const $old = nodeById(nodeId);

        if (!$old.length) {
            window.location.reload();

            return;
        }

        const open = openIds($old).concat([nodeId]);

        $.get('accounting_chart/node/' + nodeId, {open: open.join(','), highlight: accountId})
            .done(function(html) {
                $old.replaceWith(html);
                highlight(accountId);
            })
            .fail(function() {
                window.location.reload();
            });
    };

    const saved = function(response, parentId) {
        if (!response.success) {
            $.notify(response.message, {type: 'danger'});

            return;
        }

        $.notify(response.message, {type: 'success'});
        refresh(response.id, parseInt(parentId, 10) || 0);
    };

    const perform = function($button) {
        const action = $button.data('acc-action');
        const id = $button.data('acc-id');

        if (!window.confirm(settings.confirmations[action])) {
            return;
        }

        const parentId = $button.closest('li.acc-node').parent().closest('li.acc-node').data('account-id') || 0;

        $button.prop('disabled', true);

        $.post('accounting_chart/' + action + '/' + id, {}, function(response) {
            $button.prop('disabled', false);
            $.notify(response.message, {type: response.success ? 'success' : 'danger'});

            if (response.success) {
                refresh(action === 'delete' ? 0 : id, parentId);
            }
        }, 'json').fail(function() {
            $button.prop('disabled', false);
            $.notify(settings.text.load_failed, {type: 'danger'});
        });
    };

    const load = function($button) {
        const label = $button.html();

        $button.prop('disabled', true).text(settings.text.loading);

        $.get($button.data('branch'))
            .done(function(html) {
                $button.closest('li').replaceWith(html);
            })
            .fail(function() {
                $button.prop('disabled', false).html(label);
                $.notify(settings.text.load_failed, {type: 'danger'});
            });
    };

    const bind = function() {
        $(document).on('click', '.acc-caret', function() {
            const $node = $(this).closest('li.acc-node');

            setOpen($node, $(this).attr('aria-expanded') !== 'true');
        });

        $(document).on('click', '.acc-action', function() {
            perform($(this));
        });

        $(document).on('click', '.acc-load', function() {
            load($(this));
        });

        $(document).on('click', '.acc-more', function() {
            const open = $(this).attr('aria-expanded') !== 'true';

            $(this).attr('aria-expanded', open ? 'true' : 'false').closest('.acc-row').toggleClass('actions-open', open);
        });

        $('#chart_expand_all').on('click', function() {
            $('#chart_tree_root li.acc-node').each(function() {
                setOpen($(this), true);
            });
        });

        $('#chart_collapse_all').on('click', function() {
            $('#chart_tree_root li.acc-node').each(function() {
                setOpen($(this), false);
            });
        });

        $('#chart_view_switch').on('click', '[data-chart-view]', function() {
            showView($(this).data('chart-view'));
        });

        $('#chart_search').on('input', function() {
            clearTimeout(timer);
            timer = setTimeout(find, SEARCH_DELAY);
        }).on('keydown', function(event) {
            if (event.key === 'Escape') {
                $(this).val('');
                find();
            }
        });
    };

    const init = function(options) {
        settings = $.extend(settings, options);
        bind();

        if (remembered() === 'table') {
            showView('table');
        }

        if (settings.selectedId > 0) {
            const $selected = nodeById(settings.selectedId);

            $selected.length && $selected[0].scrollIntoView({block: 'center'});
        }
    };

    $.extend(accounting_chart, {
        init: init,
        refresh: refresh,
        saved: saved
    });

})(window.accounting_chart = window.accounting_chart || {}, jQuery);
;
(function() {

    const cleanText = function(node) {
        return node.textContent.replace(/\s+/g, ' ').trim();
    };

    const spanOf = function(cell) {
        return Math.max(1, parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
    };

    const labelCells = function(table) {
        const heading = table.querySelector('thead tr:last-child');

        if (!heading) {
            return;
        }

        const labels = [];

        heading.querySelectorAll('th').forEach(function(cell) {
            for (let index = 0; index < spanOf(cell); index++) {
                labels.push(cleanText(cell));
            }
        });

        table.querySelectorAll('tbody tr, tfoot tr').forEach(function(row) {
            let position = 0;

            row.querySelectorAll('td, th').forEach(function(cell) {
                if (cell.tagName === 'TD' && spanOf(cell) === 1 && !cell.hasAttribute('data-label') && labels[position]) {
                    cell.setAttribute('data-label', labels[position]);
                }

                position += spanOf(cell);
            });
        });
    };

    const SCROLLERS = '.table-responsive, .fixed-table-body';

    const focusScrollers = function() {
        document.querySelectorAll(SCROLLERS).forEach(function(region) {
            const scrolls = region.scrollWidth > region.clientWidth || region.scrollHeight > region.clientHeight;

            if (scrolls && !region.hasAttribute('tabindex')) {
                region.setAttribute('tabindex', '0');
                region.setAttribute('data-scroll-focus', '1');
            } else if (!scrolls && region.hasAttribute('data-scroll-focus')) {
                region.removeAttribute('tabindex');
                region.removeAttribute('data-scroll-focus');
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('table.acc-lines').forEach(labelCells);
        focusScrollers();

        let pending = null;
        const later = function() {
            clearTimeout(pending);
            pending = setTimeout(focusScrollers, 150);
        };

        window.addEventListener('resize', later);
        new MutationObserver(later).observe(document.body, {childList: true, subtree: true});
    });

})();
;
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
;
(function(app_charts, $) {
    'use strict';

    const PALETTE = ['#015ce4', '#d97706', '#0b7a4b', '#7c3aed', '#0496f9', '#db2777', '#0d9488', '#92400e', '#56657f', '#b42318'];
    const MAX_VALUE_LABELS = 24;

    const css = function(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    };

    const isRtl = function() {
        return document.documentElement.dir === 'rtl';
    };

    const isolate = function(text) {
        return '⁦' + text + '⁩';
    };

    const formatter = function(currency) {
        const number = new Intl.NumberFormat('en-US', {maximumFractionDigits: 2});

        return function(value) {
            return isolate((currency.prefix || '') + number.format(value) + (currency.suffix || ''));
        };
    };

    const valueLabels = function(format, rtl, horizontal) {
        return {
            id: 'valueLabels',
            afterDatasetsDraw: function(chart) {
                const dataset = chart.data.datasets[0];
                if (!dataset || dataset.data.length > MAX_VALUE_LABELS) {
                    return;
                }
                const context = chart.ctx;
                context.save();
                context.font = '600 11px ' + chart.options.font.family;
                context.fillStyle = css('--text', '#0b1b3a');
                context.textAlign = 'center';
                context.textBaseline = 'bottom';
                chart.getDatasetMeta(0).data.forEach(function(element, index) {
                    const value = dataset.data[index];
                    if (value === 0) {
                        return;
                    }
                    const text = format(value);
                    if (horizontal) {
                        context.textAlign = rtl ? 'right' : 'left';
                        context.textBaseline = 'middle';
                        context.fillText(text, element.x + (rtl ? -6 : 6), element.y);
                    } else {
                        context.fillText(text, element.x, element.y - 6);
                    }
                });
                context.restore();
            }
        };
    };

    const axisTitle = function(text, color) {
        return text ? {display: true, text: text, color: color, font: {weight: '600'}} : {display: false};
    };

    const pieConfig = function(spec, format, rtl, font) {
        const total = spec.values.reduce(function(sum, value) {
            return sum + value;
        }, 0) || 1;

        return {
            type: 'pie',
            data: {
                labels: spec.labels,
                datasets: [{
                    data: spec.values,
                    backgroundColor: spec.labels.map(function(label, index) {
                        return PALETTE[index % PALETTE.length];
                    }),
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                plugins: {
                    legend: {
                        position: window.innerWidth < 640 ? 'bottom' : 'right',
                        rtl: rtl,
                        textDirection: rtl ? 'rtl' : 'ltr',
                        labels: {boxWidth: 12, font: font, color: css('--text-2', '#3d4c70')}
                    },
                    tooltip: {
                        rtl: rtl,
                        textDirection: rtl ? 'rtl' : 'ltr',
                        callbacks: {
                            label: function(item) {
                                return ' ' + item.label + ': ' + format(item.parsed) + ' (' + isolate((item.parsed * 100 / total).toFixed(1) + '%') + ')';
                            }
                        }
                    }
                }
            }
        };
    };

    const compact = function(tick) {
        return Math.abs(tick) >= 1000 ? (tick / 1000).toLocaleString('en-US', {maximumFractionDigits: 1}) + 'k' : String(tick);
    };

    const trendColors = function(values) {
        const last = values.length - 1;
        const fill = css('--chart-bar', '#b7dff5');
        const stroke = css('--chart-bar-line', '#2f8fc4');

        return {
            background: values.map(function(value, index) {
                return value <= 0 ? '#d8dde4' : (index === last ? stroke : fill);
            }),
            border: stroke
        };
    };

    const cartesianConfig = function(spec, format, rtl, font) {
        if (spec.trend) {
            return trendConfig(spec, format, font);
        }

        const kind = spec.kind;
        const horizontal = kind === 'hbar';
        const color = css('--blue-500', '#015ce4');
        const muted = css('--muted', '#56657f');
        const line = css('--line', '#dce3ef');
        const category = {
            grid: {display: false},
            ticks: {color: css('--text-2', '#3d4c70'), autoSkip: true, maxRotation: 60, font: font},
            title: axisTitle(horizontal ? spec.yTitle : spec.xTitle, muted)
        };
        const value = {
            beginAtZero: true,
            grid: {color: line},
            ticks: {color: muted, font: font, callback: function(tick) {
                return format(tick);
            }},
            title: axisTitle(horizontal ? spec.xTitle : spec.yTitle, muted)
        };

        if (kind === 'line') {
            category.offset = true;
        }

        if (horizontal) {
            category.position = rtl ? 'right' : 'left';
            category.reverse = false;
            value.reverse = rtl;
        } else {
            category.reverse = rtl;
            value.position = rtl ? 'right' : 'left';
        }

        return {
            type: kind === 'line' ? 'line' : 'bar',
            data: {
                labels: spec.labels,
                datasets: [{
                    label: spec.name || '',
                    data: spec.values,
                    backgroundColor: kind === 'line' ? 'rgba(1, 92, 228, .12)' : color,
                    borderColor: color,
                    borderWidth: kind === 'line' ? 2 : 0,
                    borderRadius: kind === 'line' ? 0 : 4,
                    pointRadius: kind === 'line' ? 3 : 0,
                    pointHoverRadius: 5,
                    fill: kind === 'line',
                    tension: 0
                }]
            },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                layout: {padding: {top: 18, right: horizontal ? 56 : 8}},
                scales: horizontal ? {y: category, x: value} : {x: category, y: value},
                plugins: {
                    legend: {display: false},
                    tooltip: {
                        rtl: rtl,
                        textDirection: rtl ? 'rtl' : 'ltr',
                        callbacks: {
                            label: function(item) {
                                return ' ' + format(item.parsed[horizontal ? 'x' : 'y']);
                            }
                        }
                    }
                }
            },
            plugins: [valueLabels(format, rtl, horizontal)]
        };
    };

    const trendConfig = function(spec, format, font) {
        const colors = trendColors(spec.values);
        const muted = css('--muted', '#627189');

        return {
            type: 'bar',
            data: {
                labels: spec.labels,
                datasets: [{
                    label: spec.name || '',
                    data: spec.values,
                    backgroundColor: colors.background,
                    hoverBackgroundColor: colors.border,
                    borderColor: colors.border,
                    borderWidth: {top: 2, right: 0, bottom: 0, left: 0},
                    borderRadius: 2,
                    borderSkipped: 'bottom',
                    maxBarThickness: 28
                }]
            },
            options: {
                layout: {padding: {top: 8}},
                scales: {
                    x: {grid: {display: false}, ticks: {color: muted, autoSkip: true, maxRotation: 0, font: font}},
                    y: {beginAtZero: true, position: 'left', border: {display: false}, grid: {color: css('--line-light', '#e9eef4')}, ticks: {color: muted, maxTicksLimit: 5, font: font, callback: compact}}
                },
                plugins: {
                    legend: {display: false},
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            label: function(item) {
                                return format(item.parsed.y);
                            }
                        }
                    }
                }
            }
        };
    };

    const draw = function(canvas, spec) {
        if (typeof Chart === 'undefined' || !spec || !spec.labels || !spec.labels.length) {
            return null;
        }

        const rtl = isRtl();
        const format = formatter(spec.currency || {});
        const font = {family: getComputedStyle(document.body).fontFamily, size: 12};
        const config = spec.kind === 'pie' ? pieConfig(spec, format, rtl, font) : cartesianConfig(spec, format, rtl, font);

        config.options = $.extend(true, {
            responsive: true,
            maintainAspectRatio: false,
            font: font,
            animation: {duration: 250}
        }, config.options);

        if (spec.kind === 'hbar') {
            canvas.parentElement.style.setProperty('--chart-rows', spec.labels.length);
        }

        return new Chart(canvas, config);
    };

    const toNumber = function(entry) {
        return parseFloat(entry !== null && typeof entry === 'object' ? entry.value : entry) || 0;
    };

    const init = function() {
        $('canvas[data-chart]').each(function() {
            const spec = JSON.parse(this.getAttribute('data-chart'));
            spec.values = (spec.values || []).map(toNumber);
            draw(this, spec);
        });
    };

    $(init);

    $.extend(app_charts, {
        draw: draw
    });

})(window.app_charts = window.app_charts || {}, jQuery);
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
(function(report_support, $) {
    'use strict';

    const KEY = 'reportSensitiveShown';

    const stored = function() {
        try {
            return localStorage.getItem(KEY) === '1';
        } catch (error) {
            return false;
        }
    };

    const store = function(shown) {
        try {
            localStorage.setItem(KEY, shown ? '1' : '0');
        } catch (error) {
            // storage can be blocked; the toggle then only lasts for the page
        }
    };

    const apply = function(shown) {
        $('.report-kpi.is-sensitive').toggleClass('d-none', !shown);
        $('#toggleCostProfitButton').attr('aria-pressed', shown ? 'true' : 'false')
            .find('.bi').toggleClass('bi-eye', !shown).toggleClass('bi-eye-slash', shown);
    };

    $(function() {
        let shown = stored();
        apply(shown);

        $('#toggleCostProfitButton').click(function() {
            shown = !shown;
            store(shown);
            apply(shown);
        });
    });

    $.extend(report_support, {apply: apply});

})(window.report_support = window.report_support || {}, jQuery);
;
(function(settings_support, $) {
    'use strict';

    const MOBILE_QUERY = '(max-width: 991.98px)';

    const paneId = function(hash) {
        return /^#[a-z]+_tab$/.test(hash) && $('.settings-content > .tab-pane' + hash).length ? hash : null;
    };

    const show = function(hash) {
        const link = document.querySelector('.settings-link[data-bs-target="' + hash + '"]');
        if (link) {
            bootstrap.Tab.getOrCreateInstance(link).show();
        }
    };

    const collapseOnMobile = function() {
        const list = document.getElementById('settings_nav_list');
        if (list && list.classList.contains('show') && window.matchMedia(MOBILE_QUERY).matches) {
            bootstrap.Collapse.getOrCreateInstance(list).hide();
        }
    };

    const CONTROLS = 'input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="submit"], [type="button"]), select, textarea';

    const nameOrphans = function() {
        $('.settings-content').find(CONTROLS).each(function() {
            const control = $(this);
            if (control.attr('aria-label') || control.attr('aria-labelledby') || (this.labels && this.labels.length)) {
                return;
            }
            const row = control.closest('.row.mb-3');
            const label = $.trim(row.find('label').first().text());
            if (!label) {
                return;
            }
            const siblings = row.find(CONTROLS).filter(function() {
                return !(this.labels && this.labels.length);
            });
            const position = siblings.index(this) + 1;
            control.attr('aria-label', siblings.length > 1 ? label + ' (' + position + ')' : label);
        });
    };

    $(function() {
        nameOrphans();
        $(window).on('load', nameOrphans);
        const nav = $('#settings_nav_list');
        if (!nav.length) {
            return;
        }

        $('.settings-link').on('shown.bs.tab', function(event) {
            const link = $(event.target);
            $('#settings_nav_current').text($.trim(link.text()));
            try {
                history.replaceState(null, '', window.location.pathname + window.location.search + link.attr('data-bs-target'));
            } catch (error) {
                // the address bar is only a convenience
            }
        });

        $('.settings-link').on('click', collapseOnMobile);

        const initial = paneId(window.location.hash);
        if (initial) {
            show(initial);
        }

        $(window).on('hashchange', function() {
            const hash = paneId(window.location.hash);
            if (hash) {
                show(hash);
            }
        });
    });

    $.extend(settings_support, {show: show, nameOrphans: nameOrphans});

})(window.settings_support = window.settings_support || {}, jQuery);
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
(function(global_search, $) {
    'use strict';

    const MAX_RESULTS = 30;
    const MODULE_LIMIT = 24;

    let entries = null;
    let loading = null;
    let active = -1;
    let returnFocus = null;

    const normalize = function(text) {
        return String(text).toLowerCase()
            .replace(/[٠-٩]/g, function(digit) {
                return String.fromCharCode(digit.charCodeAt(0) - 1632);
            })
            .replace(/[ً-ٰٟـ]/g, '')
            .replace(/[أإآٱ]/g, 'ا')
            .replace(/ى/g, 'ي')
            .replace(/ة/g, 'ه');
    };

    const score = function(entry, tokens) {
        const label = entry.normalizedLabel;
        let total = 0;
        for (let index = 0; index < tokens.length; index++) {
            const token = tokens[index];
            if (label.indexOf(token) === 0) {
                total += 3;
            } else if (label.indexOf(' ' + token) !== -1) {
                total += 2;
            } else if (entry.haystack.indexOf(token) !== -1) {
                total += 1;
            } else {
                return 0;
            }
        }
        return total;
    };

    const search = function(query) {
        const tokens = normalize(query).split(/\s+/).filter(Boolean);
        if (!tokens.length) {
            return entries.filter(function(entry) {
                return entry.type === 'module';
            }).slice(0, MODULE_LIMIT);
        }
        return entries
            .map(function(entry) {
                return {entry: entry, score: score(entry, tokens)};
            })
            .filter(function(match) {
                return match.score > 0;
            })
            .sort(function(first, second) {
                return second.score - first.score;
            })
            .slice(0, MAX_RESULTS)
            .map(function(match) {
                return match.entry;
            });
    };

    const load = function() {
        if (entries) {
            return $.Deferred().resolve().promise();
        }
        if (!loading) {
            loading = $.getJSON($('#global_search').data('index-url')).done(function(data) {
                entries = data.map(function(entry) {
                    entry.normalizedLabel = normalize(entry.label);
                    entry.haystack = entry.normalizedLabel + ' ' + normalize(entry.group);
                    return entry;
                });
            }).fail(function() {
                loading = null;
            });
        }
        return loading;
    };

    const setActive = function(index) {
        const options = $('#global_search_list [role="option"]');
        options.attr('aria-selected', 'false').removeClass('active');
        active = options.length ? (index + options.length) % options.length : -1;
        const input = $('#global_search_input');
        if (active < 0) {
            input.removeAttr('aria-activedescendant');
            return;
        }
        const option = options.eq(active).attr('aria-selected', 'true').addClass('active');
        input.attr('aria-activedescendant', option.attr('id'));
        option[0].scrollIntoView({block: 'nearest'});
    };

    const GROUPS = ['module', 'report', 'setting'];

    const highlight = function(label, tokens) {
        const container = $('<span>', {'class': 'search-result-label'});
        const normalized = normalize(label);

        if (normalized.length !== label.length) {
            return container.text(label);
        }

        const marks = new Array(label.length).fill(false);

        tokens.forEach(function(token) {
            const at = normalized.indexOf(token);

            for (let index = at; at !== -1 && index < at + token.length; index++) {
                marks[index] = true;
            }
        });

        let start = 0;

        for (let index = 1; index <= label.length; index++) {
            if (index === label.length || marks[index] !== marks[start]) {
                const piece = label.slice(start, index);
                container.append(marks[start] ? $('<mark>').text(piece) : document.createTextNode(piece));
                start = index;
            }
        }

        return container;
    };

    const render = function() {
        const list = $('#global_search_list').empty();
        const status = $('#global_search_status');
        const query = $('#global_search_input').val();
        const tokens = normalize(query).split(/\s+/).filter(Boolean);
        const results = search(query);
        let index = 0;

        $('#global_search_intro').prop('hidden', tokens.length > 0);

        GROUPS.forEach(function(type) {
            const members = results.filter(function(entry) {
                return (GROUPS.indexOf(entry.type) === -1 ? 'module' : entry.type) === type;
            });

            if (!members.length) {
                return;
            }

            list.append($('<li>', {role: 'presentation', 'class': 'search-group'}).text(list.data('group-' + type)));

            members.forEach(function(entry) {
                const link = $('<a>', {id: 'global_search_option_' + index, role: 'option', 'aria-selected': 'false', href: entry.url, 'class': 'search-result'});
                link.append($('<span>', {'class': 'search-result-icon', 'aria-hidden': 'true'}).append($('<i>', {'class': 'bi ' + entry.icon})));
                link.append(highlight(entry.label, tokens));
                link.append($('<span>', {'class': 'search-result-group'}).text(entry.group));
                list.append($('<li>', {role: 'presentation'}).append(link));
                index++;
            });
        });

        if (results.length) {
            status.prop('hidden', true);
            setActive(0);
        } else {
            status.find('.search-status-title').text(status.data('empty').replace('{0}', query.trim()));
            status.find('.search-status-hint').prop('hidden', false);
            status.prop('hidden', false);
            setActive(-1);
        }
    };

    const showFailure = function() {
        const status = $('#global_search_status');

        $('#global_search_list').empty();
        status.find('.search-status-title').text(lang.line('search_failed'));
        status.find('.search-status-hint').prop('hidden', true);
        status.prop('hidden', false);
    };

    const open = function() {
        const modal = document.getElementById('global_search');
        if (!modal || $('.modal.show').length) {
            return;
        }
        returnFocus = document.activeElement;
        bootstrap.Modal.getOrCreateInstance(modal).show();
    };

    $(function() {
        const modal = $('#global_search');
        if (!modal.length) {
            return;
        }

        $('#global_search_open, [data-global-search]').on('click', open);

        $(document).on('keydown', function(event) {
            if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                open();
            }
        });

        modal.on('shown.bs.modal', function() {
            $('#global_search_input').val('').trigger('focus');
            load().done(render).fail(showFailure);
        });

        modal.on('hidden.bs.modal', function() {
            if (returnFocus && document.contains(returnFocus) && typeof returnFocus.focus === 'function') {
                returnFocus.focus();
            }
            returnFocus = null;
        });

        $('#global_search_input').on('input', function() {
            if (entries) {
                render();
            }
        }).on('keydown', function(event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive(active + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(active - 1);
            } else if (event.key === 'Enter') {
                const option = $('#global_search_list [role="option"]').eq(active);
                if (option.length) {
                    event.preventDefault();
                    window.location.href = option.attr('href');
                }
            }
        });

        $('#global_search_list').on('mousemove', '[role="option"]', function() {
            setActive($('#global_search_list [role="option"]').index(this));
        });

        modal.on('click', '.search-result', function() {
            bootstrap.Modal.getOrCreateInstance(modal[0]).hide();
        });
    });

    $.extend(global_search, {open: open, search: search, normalize: normalize});

})(window.global_search = window.global_search || {}, jQuery);
;
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

        $('#app-sidebar').on('click', '.app-nav-link', function(event) {
            if (event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
                hideDrawer();
            }
        });

        $('#app-sidebar').on('click', '.app-user-link', function() {
            hideDrawer();
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

    const LEGACY_KEYS = ['expro.nav.open', 'expro.nav.rail'];
    const FRAGMENT = /^#([a-z]+_tab)$/;
    const OPEN = 'is-open';

    const forgetLegacyState = function() {
        try {
            LEGACY_KEYS.forEach(function(key) {
                localStorage.removeItem(key);
            });
        } catch (error) {
            // a browser without storage has nothing to forget
        }
    };

    const nav = function() {
        return $('.app-nav');
    };

    const sections = function() {
        return $('.app-nav-section');
    };

    const headings = function() {
        return $('.app-nav-heading').children('.app-nav-toggle, .app-nav-link');
    };

    const rail = window.matchMedia('(min-width: 1024px) and (max-width: 1279.98px)');

    const initialKey = function() {
        return rail.matches ? null : nav().attr('data-nav-open') || null;
    };

    const setOpen = function(key) {
        sections().each(function() {
            const $toggle = $(this).children('.app-nav-heading').children('.app-nav-toggle');

            if (!$toggle.length) {
                return;
            }

            const open = key !== null && this.dataset.navSection === key;

            $(this).toggleClass(OPEN, open);
            $toggle.attr('aria-expanded', open ? 'true' : 'false');
            $(this).children('.app-nav-panel').prop('hidden', !open);
        });
    };

    const revealSection = function($section) {
        const box = nav()[0].getBoundingClientRect();
        const rect = $section[0].getBoundingClientRect();

        if (rect.bottom > box.bottom) {
            nav()[0].scrollTop += Math.min(rect.bottom - box.bottom, rect.top - box.top);
        } else if (rect.top < box.top) {
            nav()[0].scrollTop -= box.top - rect.top;
        }
    };

    const revealLink = function($link) {
        if (!$link.length || !nav().length || !$link[0].getClientRects().length) {
            return;
        }

        const box = nav()[0].getBoundingClientRect();
        const rect = $link[0].getBoundingClientRect();

        if (rect.top < box.top || rect.bottom > box.bottom) {
            nav()[0].scrollTop += rect.top - box.top - Math.max(0, (box.height - rect.height) / 2);
        }
    };

    const markCurrent = function($link) {
        $('.app-nav-link.active').removeClass('active').removeAttr('aria-current');
        sections().removeClass('has-active');
        $link.addClass('active').attr('aria-current', 'page');
        $link.closest('.app-nav-section').addClass('has-active');
    };

    const onThisPage = function() {
        const strip = function(path) {
            return path.replace(/\/index\.php(?=\/|$)/i, '').replace(/\/+$/, '').toLowerCase();
        };

        return function() {
            return strip(this.pathname) === strip(window.location.pathname);
        };
    }();

    const syncFragment = function(hash) {
        const $tabs = $('.app-nav-link[data-nav-fragment]').filter(onThisPage);

        if (!$tabs.length) {
            return;
        }

        const match = FRAGMENT.exec(typeof hash === 'string' ? hash : window.location.hash);
        const $target = match
            ? $tabs.filter(function() {
                return this.dataset.navFragment === match[1];
            })
            : $();
        const $link = $target.length
            ? $target.first()
            : $('.app-nav-link').not('[data-nav-fragment]').filter(onThisPage).first();

        if (!$link.length) {
            return;
        }

        markCurrent($link);

        if (!rail.matches) {
            setOpen($link.closest('.app-nav-section')[0].dataset.navSection);
            revealLink($link);
        }
    };

    const sync = function() {
        setOpen(initialKey());
        syncFragment();
    };

    const moveBetweenHeadings = function(event) {
        if (event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) {
            return false;
        }

        const $all = headings();
        const index = $all.index(event.target);

        if (index === -1) {
            return false;
        }

        const last = $all.length - 1;
        const next = {
            ArrowDown: index === last ? 0 : index + 1,
            ArrowUp: index === 0 ? last : index - 1,
            Home: 0,
            End: last
        }[event.key];

        if (next === undefined) {
            return false;
        }

        event.preventDefault();
        $all.eq(next).trigger('focus');

        return true;
    };

    const closeWithEscape = function(event) {
        const $open = sections().filter('.' + OPEN);

        if (event.key !== 'Escape' || !$open.length || nav().closest('[aria-modal="true"]').length) {
            return false;
        }

        const inside = $.contains($open[0], event.target);

        setOpen(null);
        event.preventDefault();
        event.stopPropagation();

        if (inside) {
            $open.children('.app-nav-heading').children('.app-nav-toggle').trigger('focus');
        }

        return true;
    };

    window.exproNav = {sync: sync};

    forgetLegacyState();

    $(function() {
        sync();
        revealLink($('.app-nav-link.active').first());

        nav().on('click', '.app-nav-toggle', function() {
            const $section = $(this).closest('.app-nav-section');
            const open = $section.hasClass(OPEN);

            setOpen(open ? null : $section[0].dataset.navSection);

            if (!open) {
                revealSection($section);
            }
        });

        nav().on('keydown', function(event) {
            if (!closeWithEscape(event)) {
                moveBetweenHeadings(event);
            }
        });

        $(window).on('hashchange', function() {
            const match = FRAGMENT.exec(window.location.hash);

            if (match && document.querySelector('.settings-content > .tab-pane#' + match[1])) {
                syncFragment();
            }
        });

        $(document).on('shown.bs.tab', '.settings-link', function(event) {
            syncFragment($(event.target).attr('data-bs-target'));
        });

        $(document).on('click', function(event) {
            if (rail.matches && !$(event.target).closest('.app-nav-section.' + OPEN).length) {
                setOpen(null);
            }
        });

        rail.addEventListener('change', function() {
            setOpen(initialKey());
        });

        $('#app-sidebar').on('shown.bs.offcanvas', function() {
            revealLink($('.app-nav-link.active').first());
        });

        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                sync();
            }
        });
    });

})(jQuery);
;
(function($) {

    const KEY_PREFIX = 'expro.home.shortcuts.';

    const read = function(key) {
        try {
            return JSON.parse(localStorage.getItem(key));
        } catch (error) {
            return null;
        }
    };

    const write = function(key, value) {
        try {
            if (value === null) {
                localStorage.removeItem(key);
            } else {
                localStorage.setItem(key, JSON.stringify(value));
            }
        } catch (error) {
            // the strip works without a memory
        }
    };

    $(function() {
        const $section = $('#home_shortcuts');

        if (!$section.length) {
            return;
        }

        const key = KEY_PREFIX + $section.data('person');
        const max = parseInt($section.data('max'), 10);
        const $grid = $('#home_shortcuts_grid');
        const $items = $grid.children('li[data-shortcut-id]');
        const byId = {};

        $items.each(function() {
            byId[this.dataset.shortcutId] = $(this);
        });

        const catalog = $items.toArray().sort(function(first, second) {
            return first.dataset.order - second.dataset.order;
        }).map(function(item) {
            return item.dataset.shortcutId;
        });

        const defaults = $items.toArray().filter(function(item) {
            return parseInt(item.dataset.default, 10) > 0;
        }).sort(function(first, second) {
            return first.dataset.default - second.dataset.default;
        }).map(function(item) {
            return item.dataset.shortcutId;
        });

        const pinned = function() {
            const stored = read(key);

            return Array.isArray(stored)
                ? stored.filter(function(id, index) {
                    return byId[id] && stored.indexOf(id) === index;
                }).slice(0, max)
                : defaults;
        };

        const apply = function(ids) {
            $items.prop('hidden', true);
            ids.forEach(function(id) {
                byId[id].prop('hidden', false).appendTo($grid);
            });
            $grid.toggleClass('is-large', ids.length > 0 && ids.length <= 4)
                .css('--shortcut-count', ids.length);
        };

        apply(pinned());

        // A dialog opened from here has no table behind it to refresh: the strip only says what happened.
        table_support.handle_submit = function(resource, response) {
            if (response) {
                $.notify(response.message, {type: response.success ? 'success' : 'danger'});
            }

            return false;
        };

        $grid.on('click', '[data-home-quote]', function() {
            const $button = $(this).prop('disabled', true).attr('aria-busy', 'true').addClass('is-busy');

            $button.find('.home-shortcut-label').text($grid.data('converting'));
            $button.find('.home-shortcut-icon .bi').attr('class', 'bi bi-arrow-repeat home-shortcut-spin');

            $.post($button.data('home-quote'), {mode: 'sale_quote'}).always(function() {
                window.location.href = $button.data('home-register');
            });
        });

        const $chosen = $('#home_shortcuts_chosen');
        const $others = $('#home_shortcuts_others');
        let working = [];

        const row = function(id, position) {
            const $source = byId[id];
            const checked = position !== -1;
            const label = $source.find('.home-shortcut-label').text();
            const icon = $source.find('.home-shortcut-icon').clone();
            const $row = $('<li class="list-group-item"></li>').attr('data-id', id);
            const $label = $('<label class="home-shortcuts-chooser-label"></label>');
            const $box = $('<input type="checkbox" class="form-check-input mt-0">').prop('checked', checked).prop('disabled', !checked && working.length >= max);

            if (checked) {
                $label.append($('<bdi class="home-shortcuts-position" dir="ltr"></bdi>').text(position + 1));
            }

            $label.append($box, icon, $('<span></span>').text(label));
            $row.append($label);

            if (checked) {
                $row.append(
                    $('<button type="button" class="btn btn-outline-secondary btn-sm" data-move="-1"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>').attr('aria-label', $chosen.data('up')).prop('disabled', position === 0),
                    $('<button type="button" class="btn btn-outline-secondary btn-sm" data-move="1"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>').attr('aria-label', $chosen.data('down')).prop('disabled', position === working.length - 1)
                );
            }

            return $row;
        };

        const render = function() {
            const rest = catalog.filter(function(id) {
                return working.indexOf(id) === -1;
            });

            $chosen.empty().append(working.map(function(id, position) {
                return row(id, position);
            }));
            $others.empty().append(rest.map(function(id) {
                return row(id, -1);
            }));
            $('#home_shortcuts_count').text(working.length + ' / ' + max).toggleClass('is-full', working.length >= max);
            $('#home_shortcuts_limit').prop('hidden', working.length < max);
            $('#home_shortcuts_empty').prop('hidden', working.length > 0);
            $('#home_shortcuts_others_title').prop('hidden', rest.length === 0);
        };

        $('#home_shortcuts_modal').on('show.bs.modal', function() {
            working = pinned().slice();
            render();
        });

        $chosen.add($others).on('change', 'input[type=checkbox]', function() {
            const id = $(this).closest('li').data('id');

            if (this.checked) {
                working.push(id);
            } else {
                working = working.filter(function(other) {
                    return other !== id;
                });
            }

            render();
            $('[data-id="' + id + '"] input[type=checkbox]').trigger('focus');
        });

        $chosen.on('click', '[data-move]', function() {
            const id = $(this).closest('li').data('id');
            const step = parseInt($(this).data('move'), 10);
            const from = working.indexOf(id);
            const to = from + step;

            if (to >= 0 && to < working.length) {
                working.splice(to, 0, working.splice(from, 1)[0]);
                render();

                const $moved = $chosen.find('[data-id="' + id + '"] [data-move="' + step + '"]');
                ($moved.prop('disabled') ? $chosen.find('[data-id="' + id + '"] [data-move]').not(':disabled') : $moved).first().trigger('focus');
            }
        });

        const close = function() {
            bootstrap.Modal.getInstance($('#home_shortcuts_modal')[0]).hide();
        };

        $('#home_shortcuts_save').on('click', function() {
            write(key, working.slice());
            apply(working);
            close();
            $.notify($section.data('saved'), {type: 'success'});
        });

        $('#home_shortcuts_reset').on('click', function() {
            write(key, null);
            apply(defaults);
            close();
        });
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
        const label = select.labels && select.labels.length ? $.trim(select.labels[0].textContent) : $select.attr('aria-label');
        if (label) {
            $input.attr('aria-label', label);
        }

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