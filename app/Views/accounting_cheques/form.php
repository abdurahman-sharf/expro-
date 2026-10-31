<?php
/**
 * @var object|null  $cheque
 * @var string       $direction
 * @var list<object> $treasuries
 * @var list<object> $books
 * @var string       $today
 * @var bool         $locked
 */
use App\Libraries\Accounting\AmountText;

$isNew      = $cheque === null;
$isReceived = $direction === 'received';
$value      = static fn (string $field, string $default = ''): string => $isNew ? $default : esc((string) ($cheque->{$field} ?? $default), 'attr');
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_cheques/save/' . $direction . '/' . ($isNew ? NEW_ENTRY : (int) $cheque->cheque_id), ['id' => 'cheque_form', 'class' => 'app-form']) ?>
    <?php if ($locked): ?>
        <p class="alert alert-info small" role="note"><?= lang('Accounting_cheques.locked_note') ?></p>
    <?php endif; ?>
    <fieldset id="cheque_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang($isReceived ? 'Accounting_cheques.customer' : 'Accounting_cheques.supplier'), 'party_name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <?php if ($isNew): ?>
                        <input type="text" id="party_name" class="form-control form-control-sm" autocomplete="off" value="">
                        <input type="hidden" name="party_id" id="party_id" value="">
                    <?php else: ?>
                        <span class="form-control-plaintext" id="party_name"><?= esc($cheque->party_name) ?></span>
                        <input type="hidden" name="party_id" id="party_id" value="<?= (int) $cheque->party_id ?>">
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isReceived): ?>
                <div class="field">
                    <?= form_label(lang('Accounting_cheques.collection_bank'), 'treasury_id', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <select name="treasury_id" id="treasury_id" class="form-select form-select-sm"<?= $locked ? ' disabled' : '' ?>>
                            <option value=""></option>
                            <?php foreach ($treasuries as $treasury): ?>
                                <option value="<?= (int) $treasury->treasury_id ?>"<?= ! $isNew && (int) $cheque->treasury_id === (int) $treasury->treasury_id ? ' selected' : '' ?>><?= esc($treasury->name . ' (' . $treasury->branch_code . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($locked): ?>
                            <input type="hidden" name="treasury_id" value="<?= (int) $cheque->treasury_id ?>">
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="field">
                    <?= form_label(lang('Accounting_cheques.book'), 'book_id', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <select name="book_id" id="book_id" class="form-select form-select-sm"<?= $locked ? ' disabled' : '' ?>>
                            <option value=""></option>
                            <?php foreach ($books as $book): ?>
                                <option value="<?= (int) $book->book_id ?>"<?= ! $isNew && (int) $cheque->book_id === (int) $book->book_id ? ' selected' : '' ?>><?= esc($book->name . ' - ' . $book->treasury_name . ' (' . $book->first_serial . '-' . $book->last_serial . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($locked): ?>
                            <input type="hidden" name="book_id" value="<?= (int) $cheque->book_id ?>">
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <?= form_label(lang('Accounting_cheques.cheque_number'), 'cheque_number', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="cheque_number" id="cheque_number" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="64" value="<?= $value('cheque_number') ?>"<?= $locked ? ' readonly' : '' ?>>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_cheques.amount'), 'amount', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= $isNew ? '' : esc(AmountText::forField((string) $cheque->amount), 'attr') ?>"<?= $locked ? ' readonly' : '' ?>>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_cheques.issue_date'), 'issue_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="issue_date" id="issue_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= $isNew ? esc($today, 'attr') : esc(substr((string) $cheque->issue_date, 0, 10), 'attr') ?>"<?= $locked ? ' readonly' : '' ?>>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_cheques.due_date'), 'due_date', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="date" name="due_date" id="due_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= $isNew ? esc($today, 'attr') : esc(substr((string) $cheque->due_date, 0, 10), 'attr') ?>"<?= $locked ? ' readonly' : '' ?>>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_cheques.drawer_name'), 'drawer_name', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="drawer_name" id="drawer_name" class="form-control form-control-sm" maxlength="255" value="<?= $value('drawer_name') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_cheques.endorsed_to'), 'endorsed_to', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="endorsed_to" id="endorsed_to" class="form-control form-control-sm" maxlength="255" value="<?= $value('endorsed_to') ?>">
                    <p class="form-text"><?= lang('Accounting_cheques.endorsed_to_note') ?></p>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_cheques.description'), 'description', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="description" id="description" class="form-control form-control-sm" rows="2" maxlength="500"><?= $isNew ? '' : esc((string) $cheque->description) ?></textarea>
                </div>
            </div>

            <?php if (! $isReceived && $isNew): ?>
                <div class="field">
                    <div class="field-control">
                        <label class="choice"><input type="checkbox" class="form-check-input" name="deliver" id="deliver" value="1" checked> <?= lang('Accounting_cheques.issue_and_deliver') ?></label>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?php if ($isNew): ?>
        $('#party_name').autocomplete({
            source: 'accounting_cheques/parties/<?= esc($direction, 'js') ?>',
            minChars: 0,
            delay: 100,
            appendTo: '.modal-content',
            select: function(event, ui) {
                $('#party_id').val(ui.item.value);
                $('#party_name').val(ui.item.label);

                return false;
            },
            focus: function(event, ui) {
                $('#party_name').val(ui.item.label);

                return false;
            }
        }).on('input', function() {
            $('#party_id').val('');
        }).on('change', function() {
            if ($('#party_id').val() === '') {
                $(this).val('');
            }
        });

        <?php endif; ?>
        $('#cheque_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_cheques', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                <?= $isNew ? 'party_name' : 'party_id' ?>: 'required',
                <?= $isReceived ? 'treasury_id' : 'book_id' ?>: 'required',
                cheque_number: 'required',
                amount: 'required',
                issue_date: 'required'
            },

            messages: {
                <?= $isNew ? 'party_name' : 'party_id' ?>: "<?= esc(lang('Accounting_cheques.party_required'), 'js') ?>",
                <?= $isReceived ? 'treasury_id' : 'book_id' ?>: "<?= esc(lang($isReceived ? 'Accounting_cheques.bank_required' : 'Accounting_cheques.book_required'), 'js') ?>",
                cheque_number: "<?= esc(lang('Accounting_cheques.number_required'), 'js') ?>",
                amount: "<?= esc(lang('Accounting_cheques.amount_required'), 'js') ?>",
                issue_date: "<?= esc(lang('Accounting_cheques.issue_date_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
