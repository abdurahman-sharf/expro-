<?php

use App\Libraries\Accounting\Currency;

/**
 * @var Currency $currency
 * @var string   $table_headers
 * @var bool     $can_manage
 * @var array    $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const confirmation = <?= json_encode(lang('Accounting_currencies.confirm_delete_rate'), $jsonFlags) ?>;

        table_support.init({
            resource: 'accounting_currencies/rates/<?= esc($currency->code, 'js') ?>',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'rate_id',
            cardTitle: ['effective_date']
        });

        $(document).on('click', '.acc-action', function() {
            if (!confirm(confirmation)) {
                return;
            }

            $.post('accounting_currencies/rate_delete/' + $(this).data('acc-id'), {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    table_support.refresh();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="accounting_currencies"><?= esc(lang('Accounting_currencies.back')) ?></a>
    <strong class="mx-3"><bdi dir="ltr"><?= esc($currency->code) ?></bdi> <?= esc(str_starts_with(service('request')->getLocale(), 'ar') ? $currency->nameAr : $currency->nameEn) ?></strong>
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_currencies/rate_form/<?= esc($currency->code, 'url') ?>" title="<?= lang('Accounting_currencies.new_rate') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_currencies.new_rate') ?>
        </button>
    <?php endif; ?>
</div>

<p class="text-muted small"><?= esc(lang('Accounting_currencies.rates_note')) ?></p>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
