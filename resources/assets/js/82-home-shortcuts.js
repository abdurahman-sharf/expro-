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
            const $button = $(this).prop('disabled', true);

            $.post($button.data('home-quote'), {mode: 'sale_quote'}).always(function() {
                window.location.href = $button.data('home-register');
            });
        });

        const $chooser = $('#home_shortcuts_chooser');
        let working = [];

        const render = function() {
            const order = working.concat(catalog.filter(function(id) {
                return working.indexOf(id) === -1;
            }));
            const full = working.length >= max;

            $chooser.empty();
            order.forEach(function(id) {
                const $source = byId[id];
                const checked = working.indexOf(id) !== -1;
                const label = $source.find('.home-shortcut-label').text();
                const icon = $source.find('.home-shortcut-icon').clone();
                const $row = $('<li class="list-group-item"></li>').attr('data-id', id);
                const $label = $('<label class="home-shortcuts-chooser-label"></label>');
                const $box = $('<input type="checkbox" class="form-check-input mt-0">').prop('checked', checked).prop('disabled', !checked && full);

                $label.append($box, icon, $('<span></span>').text(label));
                $row.append($label);

                if (checked) {
                    const position = working.indexOf(id);

                    $row.append(
                        $('<button type="button" class="btn btn-outline-secondary btn-sm" data-move="-1"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>').attr('aria-label', $chooser.data('up')).prop('disabled', position === 0),
                        $('<button type="button" class="btn btn-outline-secondary btn-sm" data-move="1"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>').attr('aria-label', $chooser.data('down')).prop('disabled', position === working.length - 1)
                    );
                }

                $chooser.append($row);
            });
        };

        $('#home_shortcuts_modal').on('show.bs.modal', function() {
            working = pinned().slice();
            render();
        });

        $chooser.on('change', 'input[type=checkbox]', function() {
            const id = $(this).closest('li').data('id');

            if (this.checked) {
                working.push(id);
            } else {
                working = working.filter(function(other) {
                    return other !== id;
                });
            }

            render();
        });

        $chooser.on('click', '[data-move]', function() {
            const id = $(this).closest('li').data('id');
            const from = working.indexOf(id);
            const to = from + parseInt($(this).data('move'), 10);

            if (to >= 0 && to < working.length) {
                working.splice(to, 0, working.splice(from, 1)[0]);
                render();
            }
        });

        const close = function() {
            bootstrap.Modal.getInstance($('#home_shortcuts_modal')[0]).hide();
        };

        $('#home_shortcuts_save').on('click', function() {
            write(key, working.slice());
            apply(working);
            close();
        });

        $('#home_shortcuts_reset').on('click', function() {
            write(key, null);
            apply(defaults);
            close();
        });
    });

})(jQuery);
