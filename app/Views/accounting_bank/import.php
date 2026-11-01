<?php
/**
 * @var object       $treasury
 * @var float|int    $maxMb
 * @var int          $maxRows
 * @var list<string> $delimiters
 * @var list<string> $dateOrders
 * @var int          $maxColumn
 */
$id    = (int) $treasury->treasury_id;
$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$field = static function (string $name, string $label, int $default, bool $required) use ($maxColumn): string {
    $options = $required ? '' : '<option value="">' . esc(lang('Accounting_bank.column_none')) . '</option>';

    for ($column = 1; $column <= $maxColumn; $column++) {
        $options .= '<option value="' . $column . '"' . ($column === $default ? ' selected' : '') . '>' . $column . '</option>';
    }

    return '<div class="col-sm-6 col-md-4"><label class="form-label' . ($required ? ' text-danger required' : '') . '" for="' . $name . '">' . esc($label) . '</label><select class="form-select form-select-sm" name="' . $name . '" id="' . $name . '">' . $options . '</select></div>';
};
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#bank_import_form').on('submit', function(event) {
            event.preventDefault();

            $('#bank_import_errors').empty();

            $(this).ajaxSubmit({
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? (response.created ? 'success' : 'warning') : 'danger');

                    if (response.errors) {
                        response.errors.forEach(function(text) {
                            $('#bank_import_errors').append($('<li>').text(text));
                        });
                    }

                    if (response.success && response.created) {
                        setTimeout(function() {
                            window.location.href = <?= json_encode(base_url('accounting_treasuries/bank/' . $id), $flags) ?>;
                        }, 900);
                    }
                }
            });
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/bank/' . $id), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.back_account') ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/bank/sample'), 'attr') ?>"><span class="bi bi-download" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.sample') ?></a>
</div>

<div class="container-fluid py-3" id="bank_import">
    <h1 class="h4"><?= lang('Accounting_bank.import_title', [esc($treasury->name)]) ?></h1>
    <p class="text-body-secondary"><?= lang('Accounting_bank.import_note', [$maxMb, $maxRows]) ?></p>

    <?= form_open_multipart('accounting_treasuries/bank/' . $id . '/import', ['id' => 'bank_import_form', 'class' => 'app-form']) ?>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="statement_file"><?= lang('Accounting_bank.file_label') ?></label>
                <input type="file" class="form-control form-control-sm" name="statement_file" id="statement_file" accept=".csv,.txt,text/csv,text/plain">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="statement_text"><?= lang('Accounting_bank.text_label') ?></label>
                <textarea class="form-control form-control-sm" name="statement_text" id="statement_text" rows="3" dir="ltr"></textarea>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6 col-md-4">
                <label class="form-label" for="delimiter"><?= lang('Accounting_bank.delimiter') ?></label>
                <select class="form-select form-select-sm" name="delimiter" id="delimiter">
                    <?php foreach ($delimiters as $delimiter): ?>
                        <option value="<?= esc($delimiter, 'attr') ?>"><?= lang('Accounting_bank.delimiter_' . $delimiter) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label" for="date_order"><?= lang('Accounting_bank.date_order') ?></label>
                <select class="form-select form-select-sm" name="date_order" id="date_order">
                    <?php foreach ($dateOrders as $order): ?>
                        <option value="<?= esc($order, 'attr') ?>"><?= lang('Accounting_bank.date_order_' . $order) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <input type="hidden" name="skip_first_row" value="0">
                    <input class="form-check-input" type="checkbox" name="skip_first_row" id="skip_first_row" value="1" checked>
                    <label class="form-check-label" for="skip_first_row"><?= lang('Accounting_bank.skip_first_row') ?></label>
                </div>
            </div>
        </div>

        <fieldset class="mb-3">
            <legend class="fs-6"><?= lang('Accounting_bank.mapping') ?></legend>
            <p class="small text-body-secondary"><?= lang('Accounting_bank.mapping_note') ?></p>
            <div class="row g-3">
                <?= $field('date_column', lang('Accounting_bank.column_date'), 1, true) ?>
                <?= $field('description_column', lang('Accounting_bank.column_description'), 2, false) ?>
                <?= $field('deposit_column', lang('Accounting_bank.column_deposit'), 3, true) ?>
                <?= $field('withdrawal_column', lang('Accounting_bank.column_withdrawal'), 4, true) ?>
                <?= $field('reference_column', lang('Accounting_bank.column_reference'), 5, false) ?>
            </div>
        </fieldset>

        <ul class="text-danger small" id="bank_import_errors" aria-live="polite"></ul>

        <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-upload" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.import') ?></button>
    <?= form_close() ?>
</div>

<?= view('partial/footer') ?>
