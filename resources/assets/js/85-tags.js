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
