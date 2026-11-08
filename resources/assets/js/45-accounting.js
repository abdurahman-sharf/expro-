(function(accounting_support, $) {

    let pickers = 0;

    const normalize = function(value) {
        return $.trim(value || '').toLowerCase();
    };

    const bindSearch = function(remote, remember) {
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

            remember(entry);
            const $chosen = $input;
            close();
            $chosen.val(entry.label).trigger('input').trigger('change');
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
            search();
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

        const remember = function(entry) {
            byLabel[normalize(entry.label)] = entry;
            normalize(entry.code) !== '' && (byCode[normalize(entry.code)] = entry);
        };

        $('#' + listId + ' option').each(function() {
            remember({id: String($(this).data('id')), label: this.value, code: $(this).data('code')});
        });

        if (remote && remote.url) {
            $.each(remote.initial || [], function(index, entry) {
                remember({id: String(entry.id), label: entry.label, code: entry.code});
            });
            bindSearch(remote, remember);
        }

        const resolve = function(text) {
            const key = normalize(text);
            return key === '' ? null : byLabel[key] || byCode[key] || null;
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
