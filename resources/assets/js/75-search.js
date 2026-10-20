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

    const render = function() {
        const list = $('#global_search_list').empty();
        const status = $('#global_search_status');
        const results = search($('#global_search_input').val());

        results.forEach(function(entry, index) {
            const link = $('<a>', {id: 'global_search_option_' + index, role: 'option', 'aria-selected': 'false', href: entry.url, 'class': 'search-result'});
            link.append($('<i>', {'class': 'bi ' + entry.icon, 'aria-hidden': 'true'}));
            link.append($('<span>', {'class': 'search-result-label'}).text(entry.label));
            link.append($('<span>', {'class': 'search-result-group'}).text(entry.group));
            list.append($('<li>', {role: 'presentation'}).append(link));
        });

        if (results.length) {
            status.prop('hidden', true).text('');
            setActive(0);
        } else {
            status.prop('hidden', false).text(lang.line('search_empty'));
            setActive(-1);
        }
    };

    const showFailure = function() {
        $('#global_search_list').empty();
        $('#global_search_status').prop('hidden', false).text(lang.line('search_failed'));
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

        $('#global_search_open').on('click', open);

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
