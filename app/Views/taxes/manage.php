<?php
/**
 * @var string $controller_name
 */
?>

<?= view('partial/header') ?>

<ul class="nav nav-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link active" data-bs-toggle="tab" role="tab" aria-controls="tax_codes_tab" href="#tax_codes_tab" title="<?= lang(ucfirst($controller_name) . '.tax_codes_configuration') ?>">
            <?= lang(ucfirst($controller_name) . '.tax_codes') ?>
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="tax_jurisdictions_tab" href="#tax_jurisdictions_tab" title="<?= lang(ucfirst($controller_name) . '.tax_jurisdictions_configuration') ?>">
            <?= lang(ucfirst($controller_name) . '.tax_jurisdictions') ?>
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="tax_categories_tab" href="#tax_categories_tab" title="<?= lang(ucfirst($controller_name) . '.tax_categories_configuration') ?>">
            <?= lang(ucfirst($controller_name) . '.tax_categories') ?>
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="tax_rates_tab" href="#tax_rates_tab" title="<?= lang(ucfirst($controller_name) . '.tax_rate_configuration') ?>">
            <?= lang(ucfirst($controller_name) . '.tax_rates') ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= esc(base_url('tax_definitions'), 'attr') ?>" title="<?= esc(lang('Config.tax_list_desc'), 'attr') ?>">
            <?= lang('Config.tax_list') ?>
        </a>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tax_codes_tab" role="tabpanel">
        <?= view('taxes/tax_codes') ?>
    </div>
    <div class="tab-pane" id="tax_jurisdictions_tab" role="tabpanel">
        <?= view('taxes/tax_jurisdictions') ?>
    </div>
    <div class="tab-pane" id="tax_categories_tab" role="tabpanel">
        <?= view('taxes/tax_categories') ?>
    </div>
    <div class="tab-pane" id="tax_rates_tab" role="tabpanel">
        <?= view('taxes/tax_rates') ?>
    </div>
</div>

<?= view('partial/footer') ?>

<script type="text/javascript">
    $('a[data-bs-toggle="tab"][href="#tax_rates_tab"]').on('shown.bs.tab', function() {
        $('#table').bootstrapTable('resetView');
    });
</script>
