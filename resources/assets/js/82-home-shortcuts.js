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
