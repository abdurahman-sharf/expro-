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
            const message = $.fn.bootstrapTable.defaults.formatConfirmAction(action);
            const tone = action === 'delete' ? 'danger' : 'primary';

            appConfirm(message, {tone: tone, confirmLabel: lang.line('common_' + action)}).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }

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
            });

            return false;
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
