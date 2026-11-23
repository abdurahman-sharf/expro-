(function($) {

    $(function() {
        const $page = $('#notifications');

        if (!$page.length) {
            return;
        }

        const unreadOnly = $page.data('unread-only') === 1;
        const $count = $('#unread_count');

        const setCount = function(count) {
            $count.toggleClass('has-unread', count > 0)
                .attr('aria-label', String($page.data('unread-count')).replace('{0}', count))
                .find('bdi').text(count);

            if (count === 0) {
                $('#read_all').remove();
            }
        };

        const unreadCount = function() {
            return parseInt($count.find('bdi').text(), 10) || 0;
        };

        const busy = function($button, on) {
            const $label = $button.find('.nt-label');

            if (on) {
                $button.data('label', $label.text());
                $label.text($page.data('marking'));
                $button.find('.bi').attr('class', 'bi bi-arrow-repeat nt-spin');
            } else {
                $label.text($button.data('label'));
                $button.find('.bi').attr('class', 'bi ' + ($button.is('#read_all') ? 'bi-check2-all' : 'bi-check2'));
            }

            $button.prop('disabled', on).attr('aria-busy', on ? 'true' : 'false');
        };

        const failed = function(message) {
            toast_support.show(message || $page.data('failed-text'), 'danger', {title: $page.data('failed-title'), sticky: true});
        };

        const showEmptyIfNone = function() {
            $('.nt-day').each(function() {
                if (!$(this).find('.nt-item').length) {
                    $(this).remove();
                }
            });

            if (!$('.nt-item').length) {
                $('#notifications_list, .nt-pager').remove();
                $('#notifications_empty').prop('hidden', false);
            }
        };

        const readLabel = $('<span class="nt-read"><i class="bi bi-check2" aria-hidden="true"></i> </span>');

        const markRead = function($items) {
            $items.each(function() {
                const $item = $(this);
                const $action = $item.find('.nt-action');

                $item.removeClass('is-unread').addClass('is-read');
                $item.find('.nt-state').text($page.data('read-state'));
                $action.empty().append(readLabel.clone().append(document.createTextNode($page.data('read-label'))));
            });
        };

        const leave = function($items) {
            const $next = $items.last().nextAll('.nt-item').first().find('.nt-mark');
            const $after = $next.length ? $next : $items.last().closest('.nt-day').nextAll('.nt-day').first().find('.nt-mark').first();

            $items.remove();
            showEmptyIfNone();
            ($after.length ? $after : $('.nt-seg-option.is-on')).trigger('focus');
        };

        $page.on('click', '.nt-mark', function() {
            const $button = $(this);
            const $item = $button.closest('.nt-item');

            busy($button, true);

            $.post($button.data('url'), function(response) {
                if (!response.success) {
                    busy($button, false);
                    failed(response.message);
                    return;
                }

                setCount(Math.max(0, unreadCount() - 1));
                toast_support.show(response.message, 'success');

                if (unreadOnly) {
                    leave($item);
                } else {
                    markRead($item);
                    $item.find('.nt-read').attr('tabindex', '-1').trigger('focus');
                }
            }, 'json').fail(function() {
                busy($button, false);
                failed();
            });
        });

        $page.on('click', '#read_all', function() {
            const $button = $(this);

            busy($button, true);

            $.post($button.data('url'), function(response) {
                if (!response.success) {
                    busy($button, false);
                    failed(response.message);
                    return;
                }

                const $unread = $('.nt-item.is-unread');

                setCount(0);
                toast_support.show(response.message, 'success');

                if (unreadOnly) {
                    leave($unread);
                } else {
                    markRead($unread);
                    $('.nt-seg-option.is-on').trigger('focus');
                }
            }, 'json').fail(function() {
                busy($button, false);
                failed();
            });
        });

    });

})(jQuery);
