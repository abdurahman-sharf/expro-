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

        appConfirm(settings.confirmations[action], {tone: action === 'delete' ? 'danger' : 'primary'}).then(function(confirmed) {
            if (confirmed) {
                run($button, action, id);
            }
        });
    };

    const run = function($button, action, id) {
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
