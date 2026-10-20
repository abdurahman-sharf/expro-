<script type="text/javascript">
    (function(lang, $) {

        const lines = {
            'common_submit': "<?= lang('Common.submit') ?>",
            'common_close': "<?= lang('Common.close') ?>",
            'table_export_pdf': "<?= esc(lang('Bootstrap_tables.export_pdf'), 'js') ?>",
            'table_export_too_many_rows': "<?= esc(lang('Bootstrap_tables.export_too_many_rows'), 'js') ?>",
            'table_row_details': "<?= esc(lang('Common.det'), 'js') ?>",
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
