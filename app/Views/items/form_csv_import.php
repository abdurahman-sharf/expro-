<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open_multipart('items/importCsvFile/', ['id' => 'csv_form', 'class' => 'app-form']) ?>
    <fieldset id="item_basic_info">

        <div class="field">
            <div class="field-control">
                <a href="<?= esc('items/generateCsvFile', 'attr') ?>"><?= lang('Common.download_import_template') ?></a>
            </div>
        </div>

        <div class="field">
            <div class="field-control">
                <input type="file" class="form-control" id="file_path" name="file_path" accept=".csv" aria-label="<?= lang('Items.import_items_csv') ?>">
            </div>
        </div>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $('#csv_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('<?= esc('items') ?>', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                file_path: 'required'
            },

            messages: {
                file_path: "<?= lang('Common.import_full_path') ?>"
            }
        }, form_support.error));
    });
</script>
