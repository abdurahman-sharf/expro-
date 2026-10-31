<?php
/**
 * @var object|null  $book
 * @var list<object> $banks
 */

$isNew = $book === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_cheques/save_book/' . ($isNew ? NEW_ENTRY : (int) $book->book_id), ['id' => 'book_form', 'class' => 'app-form']) ?>
    <fieldset>
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_cheques.bank'), 'treasury_id', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <select name="treasury_id" id="treasury_id" class="form-select form-select-sm"<?= $isNew ? '' : ' disabled' ?>>
                        <option value=""></option>
                        <?php foreach ($banks as $bank): ?>
                            <option value="<?= (int) $bank->treasury_id ?>"<?= ! $isNew && (int) $book->treasury_id === (int) $bank->treasury_id ? ' selected' : '' ?>><?= esc($bank->name . ' (' . $bank->branch_code . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <?= form_label(lang('Accounting_cheques.book_name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="100" value="<?= $isNew ? '' : esc($book->name, 'attr') ?>">
                </div>
            </div>
            <div class="field">
                <?= form_label(lang('Accounting_cheques.first_serial'), 'first_serial', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="first_serial" id="first_serial" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="numeric" maxlength="18" value="<?= $isNew ? '' : esc((string) $book->first_serial, 'attr') ?>">
                </div>
            </div>
            <div class="field">
                <?= form_label(lang('Accounting_cheques.last_serial'), 'last_serial', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="last_serial" id="last_serial" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="numeric" maxlength="18" value="<?= $isNew ? '' : esc((string) $book->last_serial, 'attr') ?>">
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#book_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        toast_support.show(response.message, response.success ? 'success' : 'danger');
                        if (response.success) {
                            window.location.reload();
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                <?= $isNew ? "treasury_id: 'required',\n                " : '' ?>name: 'required',
                first_serial: 'required',
                last_serial: 'required'
            },

            messages: {
                <?= $isNew ? 'treasury_id: "' . esc(lang('Accounting_cheques.bank_required'), 'js') . "\",\n                " : '' ?>name: "<?= esc(lang('Accounting_cheques.book_name_required'), 'js') ?>",
                first_serial: "<?= esc(lang('Accounting_cheques.serial_required'), 'js') ?>",
                last_serial: "<?= esc(lang('Accounting_cheques.serial_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
