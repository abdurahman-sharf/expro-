<?php
/**
 * @var list<OpeningImportKind> $kinds
 * @var bool                    $customers_off
 * @var string                  $start_date
 * @var string|null             $notice
 */

use App\Libraries\Accounting\OpeningImport\OpeningImportKind;

?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_periods/tabs', ['active' => 'opening_import']) ?>

<div class="container-fluid py-3" id="opening_import">
    <h1 class="h4"><?= lang('Accounting_opening_import.title') ?></h1>
    <p class="text-body-secondary"><?= lang('Accounting_opening_import.intro') ?></p>

    <?php if ($kinds === []): ?>
        <p class="text-body-secondary small" role="note" id="opening_import_permission_note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_opening_import.not_allowed') ?></p>
    <?php else: ?>
        <?php if ($customers_off): ?>
            <p class="alert alert-warning" role="alert" id="opening_import_off"><?= lang('Accounting_opening_import.off_customers') ?></p>
        <?php endif; ?>

        <?= form_open_multipart('accounting_periods/opening_import/upload', ['id' => 'opening_import_form', 'class' => 'app-form']) ?>
            <div class="form-grid">
                <div class="field">
                    <?= form_label(lang('Accounting_opening_import.kind'), 'kind', ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <select name="kind" id="kind" class="form-select form-select-sm">
                            <?php foreach ($kinds as $kind): ?>
                                <option value="<?= esc($kind->value, 'attr') ?>"><?= lang('Accounting_opening_import.kind_' . $kind->value) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <?= form_label(lang('Accounting_opening_import.file'), 'file', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <input type="file" name="file" id="file" class="form-control form-control-sm" accept=".csv,.txt,text/csv">
                    </div>
                </div>
            </div>
            <p class="mt-2">
                <button type="submit" class="btn btn-primary btn-sm" id="opening_import_preview"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_opening_import.upload') ?></button>
                <?php foreach ($kinds as $kind): ?>
                    <a class="btn btn-outline-secondary btn-sm" id="opening_import_sample_<?= esc($kind->value, 'attr') ?>" href="<?= esc(base_url('accounting_periods/opening_import/sample/' . $kind->value), 'attr') ?>"><span class="bi bi-download" aria-hidden="true">&nbsp;</span><?= lang('Accounting_opening_import.sample_download') ?> (<?= lang('Accounting_opening_import.kind_' . $kind->value) ?>)</a>
                <?php endforeach; ?>
            </p>
            <ul id="error_message_box" class="error_message_box" role="alert"></ul>
        <?= form_close() ?>
    <?php endif; ?>

    <section class="mt-4" id="opening_import_sign" aria-label="<?= esc(lang('Accounting_opening_import.sign_title'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_opening_import.sign_title') ?></h2>
        <ul>
            <li><?= lang('Accounting_opening_import.sign_customers') ?></li>
            <li><?= lang('Accounting_opening_import.sign_suppliers') ?></li>
        </ul>
    </section>

    <section class="mt-3" id="opening_import_columns" aria-label="<?= esc(lang('Accounting_opening_import.columns_title'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_opening_import.columns_title') ?></h2>
        <ul>
            <li><?= esc(lang('Accounting_opening_import.columns_party')) ?></li>
            <li><?= esc(lang('Accounting_opening_import.columns_amount')) ?></li>
            <li><?= esc(lang('Accounting_opening_import.columns_date', [$start_date])) ?></li>
            <li><?= esc(lang('Accounting_opening_import.columns_note')) ?></li>
        </ul>
    </section>

    <section class="mt-3" id="opening_import_rules" aria-label="<?= esc(lang('Accounting_opening_import.rules_title'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_opening_import.rules_title') ?></h2>
        <ul>
            <li><?= lang('Accounting_opening_import.rules_once') ?></li>
            <li><?= lang('Accounting_opening_import.rules_switch') ?></li>
            <li><?= lang('Accounting_opening_import.rules_permission') ?></li>
            <li><?= lang('Accounting_opening_import.rules_accounts') ?></li>
            <li><?= lang('Accounting_opening_import.rules_steps') ?></li>
        </ul>
    </section>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('#opening_import_form').on('submit', function(event) {
            event.preventDefault();
            $('#error_message_box').empty();
            $(this).ajaxSubmit({
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.redirect;
                        return;
                    }
                    $('#error_message_box').append($('<li></li>').text(response.message));
                }
            });
        });
    });
</script>

<?= view('partial/footer') ?>
