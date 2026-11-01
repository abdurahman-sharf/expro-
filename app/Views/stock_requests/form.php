<?php
/**
 * @var array<int, string> $from   The locations stock may be asked from
 * @var array<int, string> $to     The locations the employee may ask for
 * @var string|null        $number
 * @var string             $today
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('stock_documents/requests/save', ['id' => 'request_form', 'class' => 'app-form']) ?>
    <p class="small text-body-secondary"><?= lang('Stock_documents.request_form_note') ?></p>

    <fieldset id="request_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Stock_documents.number'), 'request_number', ['class' => 'form-label']) ?>
                <div class="field-control"><span class="form-control-plaintext" id="request_number"><bdi dir="ltr"><?= esc($number ?? lang('Stock_documents.number_next')) ?></bdi></span></div>
            </div>

            <div class="field">
                <?= form_label(lang('Stock_documents.date'), 'request_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="request_date" id="request_date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($today, 'attr') ?>" value="<?= esc($today, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Stock_documents.requested_from'), 'from_location_id', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <select name="from_location_id" id="from_location_id" class="form-select form-select-sm">
                        <?php foreach ($from as $locationId => $name): ?>
                            <option value="<?= (int) $locationId ?>"><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Stock_documents.requested_for'), 'to_location_id', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <select name="to_location_id" id="to_location_id" class="form-select form-select-sm">
                        <?php foreach ($to as $locationId => $name): ?>
                            <option value="<?= (int) $locationId ?>"><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field span-all">
                <?= form_label(lang('Stock_documents.note'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                </div>
            </div>
        </div>

        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle" id="request_lines">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Stock_documents.item') ?></th>
                        <th scope="col" class="text-end"><?= lang('Stock_documents.quantity') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Stock_documents.remove_line') ?></span></th>
                    </tr>
                </thead>
                <tbody id="request_lines_body"></tbody>
            </table>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="request_add_line"><span class="bi bi-plus" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.add_line') ?></button>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        let next = 0;

        const addLine = function() {
            const index = next++;
            const row = $('<tr></tr>');
            const name = $('<input type="text" class="form-control form-control-sm" autocomplete="off">').attr('aria-label', "<?= esc(lang('Stock_documents.item'), 'js') ?>");
            const id = $('<input type="hidden">').attr('name', 'lines[' + index + '][item_id]');
            const quantity = $('<input type="text" class="form-control form-control-sm ltr-input text-end" dir="ltr" inputmode="decimal" autocomplete="off">').attr({name: 'lines[' + index + '][quantity]', 'aria-label': "<?= esc(lang('Stock_documents.quantity'), 'js') ?>"});
            const remove = $('<button type="button" class="btn btn-outline-danger btn-sm"></button>').attr('aria-label', "<?= esc(lang('Stock_documents.remove_line'), 'js') ?>").append('<span class="bi bi-x" aria-hidden="true"></span>');

            row.append($('<td></td>').append(name, id), $('<td></td>').append(quantity), $('<td></td>').append(remove));
            $('#request_lines_body').append(row);

            name.autocomplete({
                source: 'stock_documents/items',
                minChars: 0,
                delay: 100,
                appendTo: '.modal-content',
                select: function(event, ui) {
                    id.val(ui.item.value);
                    name.val(ui.item.label);

                    return false;
                }
            }).on('input', function() {
                id.val('');
            });

            remove.on('click', function() {
                row.remove();
            });
        };

        $('#request_add_line').on('click', addLine);
        addLine();

        $('#request_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        toast_support.show(response.message, response.success ? 'success' : 'danger');
                        if (response.success) {
                            window.location.href = 'stock_documents/requests/view/' + response.id;
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                request_date: 'required'
            },

            messages: {
                request_date: "<?= esc(lang('Stock_documents.date_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
