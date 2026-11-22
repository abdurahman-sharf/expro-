<?php
/**
 * @var list<object>                $classes
 * @var array<string, list<string>> $exemptions
 * @var int                         $unclassed
 * @var int                         $conflicting
 * @var list<string>                $categories
 * @var bool                        $saudiMode
 * @var string                      $today
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$active    = array_values(array_filter($classes, static fn (object $class): bool => (int) $class->active === 1));
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const send = function(url, data) {
            $.post(url, data, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 600);
                }
            }, 'json');
        };

        $('#vat_class_form').on('submit', function(event) {
            event.preventDefault();
            send('vat_classes/save_class', $(this).serialize());
        });

        $('.vat-rate-form').on('submit', function(event) {
            event.preventDefault();
            send('vat_classes/add_rate/' + parseInt($(this).data('class'), 10), $(this).serialize());
        });

        $('.vat-classify-form').on('submit', function(event) {
            event.preventDefault();
            send('vat_classes/classify', $(this).serialize());
        });

        const exemptions = <?= json_encode($exemptions, $jsonFlags) ?>;
        const fillCodes = function() {
            const codes = exemptions[$('#vat_class_category').val()] || [];
            const select = $('#vat_class_exemption_code').empty().append($('<option>').val('').text(''));
            codes.forEach(function(code) {
                select.append($('<option>').val(code).text(code));
            });
        };
        $('#vat_class_category').on('change', fillCodes);
        fillCodes();
    });
</script>

<div class="container-fluid py-3" id="vat_classes_screen">
    <h1 class="h4"><?= lang('Vat_classes.title') ?></h1>

    <div class="alert alert-info" role="alert" id="vat_classes_mode">
        <?= lang($saudiMode ? 'Vat_classes.mode_on' : 'Vat_classes.mode_off') ?>
    </div>

    <section class="acc-setup-card" aria-labelledby="vat_classes_list_title">
        <h2 id="vat_classes_list_title" class="acc-setup-title"><?= lang('Vat_classes.list_title') ?></h2>

        <div class="table-responsive">
            <table class="table table-sm align-middle" id="vat_classes_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Vat_classes.code') ?></th>
                        <th scope="col"><?= lang('Vat_classes.name') ?></th>
                        <th scope="col"><?= lang('Vat_classes.category') ?></th>
                        <th scope="col"><?= lang('Vat_classes.exemption_code') ?></th>
                        <th scope="col"><?= lang('Vat_classes.current_rate') ?></th>
                        <th scope="col"><?= lang('Vat_classes.rates') ?></th>
                        <th scope="col"><?= lang('Vat_classes.items') ?></th>
                        <th scope="col"><?= lang('Vat_classes.add_rate') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $class): ?>
                        <tr data-class="<?= (int) $class->class_id ?>"<?= (int) $class->active === 1 ? '' : ' class="text-body-secondary"' ?>>
                            <td><bdi dir="ltr"><?= esc($class->code) ?></bdi></td>
                            <td><?= esc($class->name) ?><?= (int) $class->active === 1 ? '' : ' (' . lang('Vat_classes.inactive') . ')' ?></td>
                            <td><?= esc($class->category) ?> - <?= lang('Vat_classes.category_' . $class->category) ?></td>
                            <td><bdi dir="ltr"><?= esc((string) $class->exemption_code) ?></bdi></td>
                            <td><?= $class->current_rate === null ? lang('Vat_classes.no_rate') : esc(rtrim(rtrim((string) $class->current_rate, '0'), '.')) . '%' ?></td>
                            <td>
                                <ul class="list-unstyled mb-0 small">
                                    <?php foreach ($class->rates as $rate): ?>
                                        <li><?= esc(rtrim(rtrim((string) $rate->percent, '0'), '.')) ?>% <?= lang('Vat_classes.from_to', [esc((string) $rate->valid_from), esc((string) ($rate->valid_to ?? lang('Vat_classes.open')))]) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                            <td><?= (int) $class->items ?></td>
                            <td>
                                <form class="vat-rate-form d-flex gap-1" data-class="<?= (int) $class->class_id ?>" novalidate>
                                    <input type="text" name="percent" class="form-control form-control-sm" style="max-width: 6rem" aria-label="<?= esc(lang('Vat_classes.percent'), 'attr') ?>" value="<?= $class->category === 'S' ? '' : '0' ?>">
                                    <input type="date" name="valid_from" class="form-control form-control-sm" aria-label="<?= esc(lang('Vat_classes.valid_from'), 'attr') ?>" min="<?= esc($today, 'attr') ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-sm"><?= lang('Vat_classes.add') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-text"><?= lang('Vat_classes.rates_help') ?></p>
    </section>

    <section class="acc-setup-card" aria-labelledby="vat_classify_title">
        <h2 id="vat_classify_title" class="acc-setup-title"><?= lang('Vat_classes.classify_title') ?></h2>
        <p id="vat_classes_counts"><?= lang('Vat_classes.counts', [$unclassed, $conflicting]) ?></p>

        <form class="vat-classify-form app-form mb-3" novalidate>
            <input type="hidden" name="scope" value="one_tax">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label" for="classify_one_tax_class"><?= lang('Vat_classes.classify_one_tax') ?></label>
                    <select name="class_id" id="classify_one_tax_class" class="form-select form-select-sm">
                        <?php foreach ($active as $class): ?>
                            <option value="<?= (int) $class->class_id ?>"><?= esc($class->code . ' - ' . $class->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" id="classify_one_tax"><?= lang('Vat_classes.classify') ?></button>
            </div>
        </form>

        <?php if ($categories !== []): ?>
            <form class="vat-classify-form app-form" novalidate>
                <input type="hidden" name="scope" value="category">
                <div class="d-flex flex-wrap gap-2 align-items-end">
                    <div>
                        <label class="form-label" for="classify_category"><?= lang('Vat_classes.item_category') ?></label>
                        <select name="category" id="classify_category" class="form-select form-select-sm">
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= esc($category, 'attr') ?>"><?= esc($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="classify_category_class"><?= lang('Vat_classes.class') ?></label>
                        <select name="class_id" id="classify_category_class" class="form-select form-select-sm">
                            <?php foreach ($active as $class): ?>
                                <option value="<?= (int) $class->class_id ?>"><?= esc($class->code . ' - ' . $class->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm" id="classify_by_category"><?= lang('Vat_classes.classify') ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="acc-setup-card" aria-labelledby="vat_class_new_title">
        <h2 id="vat_class_new_title" class="acc-setup-title"><?= lang('Vat_classes.new_title') ?></h2>

        <form id="vat_class_form" class="app-form" novalidate>
            <div class="form-grid">
                <div class="field">
                    <label class="form-label" for="vat_class_code"><?= lang('Vat_classes.code') ?></label>
                    <div class="field-control"><input type="text" name="code" id="vat_class_code" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="30"></div>
                </div>
                <div class="field">
                    <label class="form-label" for="vat_class_name"><?= lang('Vat_classes.name') ?></label>
                    <div class="field-control"><input type="text" name="name" id="vat_class_name" class="form-control form-control-sm" maxlength="100"></div>
                </div>
                <div class="field">
                    <label class="form-label" for="vat_class_category"><?= lang('Vat_classes.category') ?></label>
                    <div class="field-control">
                        <select name="category" id="vat_class_category" class="form-select form-select-sm">
                            <?php foreach (['S', 'Z', 'E', 'O'] as $category): ?>
                                <option value="<?= $category ?>"><?= $category ?> - <?= lang('Vat_classes.category_' . $category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="form-label" for="vat_class_exemption_code"><?= lang('Vat_classes.exemption_code') ?></label>
                    <div class="field-control"><select name="exemption_code" id="vat_class_exemption_code" class="form-select form-select-sm"></select></div>
                </div>
                <div class="field span-all">
                    <label class="form-label" for="vat_class_exemption_text"><?= lang('Vat_classes.exemption_text') ?></label>
                    <div class="field-control"><input type="text" name="exemption_text" id="vat_class_exemption_text" class="form-control form-control-sm" maxlength="1000"></div>
                </div>
                <div class="field">
                    <label class="form-label" for="vat_class_tax_name"><?= lang('Vat_classes.tax_name') ?></label>
                    <div class="field-control">
                        <input type="text" name="tax_name" id="vat_class_tax_name" class="form-control form-control-sm" maxlength="255">
                        <p class="form-text mb-0"><?= lang('Vat_classes.tax_name_help') ?></p>
                    </div>
                </div>
                <div class="field">
                    <label class="form-label" for="vat_class_percent"><?= lang('Vat_classes.percent') ?></label>
                    <div class="field-control"><input type="text" name="percent" id="vat_class_percent" class="form-control form-control-sm"></div>
                </div>
                <div class="field">
                    <label class="form-label" for="vat_class_valid_from"><?= lang('Vat_classes.valid_from') ?></label>
                    <div class="field-control"><input type="date" name="valid_from" id="vat_class_valid_from" class="form-control form-control-sm" value="<?= esc($today, 'attr') ?>"></div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" id="vat_class_save"><?= lang('Common.submit') ?></button>
        </form>
    </section>
</div>

<?= view('partial/footer') ?>
