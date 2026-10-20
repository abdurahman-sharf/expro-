<script type="text/javascript">
    (function(lang, $) {

        const lines = {
            'common_submit': "<?= lang('Common.submit') ?>",
            'common_close': "<?= lang('Common.close') ?>",
            'table_select_all': "<?= esc(lang('Bootstrap_tables.select_all'), 'js') ?>",
            'table_select_row': "<?= esc(lang('Bootstrap_tables.select_row'), 'js') ?>"
        };

        $.extend(lang, {
            line: function(key) {
                return lines[key];
            }
        });

    })(window.lang = window.lang || {}, jQuery);
</script>
