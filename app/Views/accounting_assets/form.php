<?php
/**
 * @var object|null                                                                 $asset
 * @var string                                                                      $code
 * @var list<object>                                                                $categories
 * @var array{cost: list<object>, accumulated: list<object>, expense: list<object>} $accounts
 * @var list<object>                                                                $branches
 * @var list<object>                                                                $treasuries
 * @var list<object>                                                                $suppliers
 * @var list<object>                                                                $employees
 * @var list<object>                                                                $storages
 * @var string                                                                      $today
 * @var bool                                                                        $locked
 * @var string|null                                                                 $notice
 */
$isNew  = $asset === null;
$value  = static fn (string $field, string $default = ''): string => $isNew ? $default : (string) ($asset->{$field} ?? $default);
$amount = static fn (string $field, string $default = ''): string => $isNew || $asset->{$field} === null ? $default : rtrim(rtrim((string) $asset->{$field}, '0'), '.');
$units  = $isNew ? [] : $asset->units;
$years  = ! $isNew && $asset->useful_life_months !== null && (int) $asset->useful_life_months % 12 === 0;
$action = $isNew ? 'accounting_assets/save' : 'accounting_assets/save/' . (int) $asset->asset_id;
$method = $value('method', 'fixed');
$option = static fn (string $current, string $wanted): string => $current === $wanted ? ' selected' : '';
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang($isNew ? 'Accounting_assets.new' : 'Accounting_assets.edit') ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($isNew ? 'accounting_assets' : 'accounting_assets/view/' . (int) $asset->asset_id), 'attr') ?>"><?= lang('Accounting_assets.back') ?></a>
</div>

<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open($action, ['id' => 'asset_form', 'class' => 'app-form']) ?>
    <p class="small text-body-secondary"><?= lang('Accounting_assets.form_note') ?></p>
    <?php if ($locked): ?>
        <p class="alert alert-warning" id="asset_locked_note"><?= lang('Accounting_assets.terms_locked_note') ?></p>
    <?php endif; ?>

    <fieldset id="asset_basic">
        <legend class="h6"><?= lang('Accounting_assets.section_basic') ?></legend>
        <div class="form-grid">
            <div class="field">
                <label class="form-label text-danger required" for="name"><?= lang('Accounting_assets.field_name') ?></label>
                <div class="field-control"><input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="255" value="<?= esc($value('name'), 'attr') ?>"></div>
            </div>
            <div class="field">
                <label class="form-label" for="code"><?= lang('Accounting_assets.field_code') ?></label>
                <div class="field-control"><input type="text" name="code" id="code" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="64" placeholder="<?= esc($code, 'attr') ?>" value="<?= esc($isNew ? '' : $code, 'attr') ?>"></div>
            </div>
            <div class="field">
                <label class="form-label" for="category_id"><?= lang('Accounting_assets.field_category') ?></label>
                <div class="field-control">
                    <select name="category_id" id="category_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.none') ?></option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category->category_id ?>" data-method="<?= esc((string) $category->method, 'attr') ?>" data-life="<?= (int) $category->life_months ?>"<?= (int) $value('category_id', '0') === (int) $category->category_id ? ' selected' : '' ?>><?= esc($category->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="form-label text-danger required" for="branch_id"><?= lang('Accounting_assets.field_branch') ?></label>
                <div class="field-control">
                    <select name="branch_id" id="branch_id" class="form-select form-select-sm">
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch->branch_id ?>"<?= (int) $value('branch_id', '0') === (int) $branch->branch_id ? ' selected' : '' ?>><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="form-label text-danger required" for="cost_account_id"><?= lang('Accounting_assets.field_cost_account') ?></label>
                <div class="field-control">
                    <select name="cost_account_id" id="cost_account_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.account_default') ?></option>
                        <?php foreach ($accounts['cost'] as $account): ?>
                            <option value="<?= (int) $account->account_id ?>"<?= (int) $value('cost_account_id', '0') === (int) $account->account_id ? ' selected' : '' ?>><?= esc($account->code . ' ' . $account->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="useful_life_value"><?= lang('Accounting_assets.field_life') ?></label>
                <div class="field-control input-group input-group-sm">
                    <input type="number" min="1" name="useful_life_value" id="useful_life_value" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($isNew || $asset->useful_life_months === null ? '' : (string) ($years ? intdiv((int) $asset->useful_life_months, 12) : (int) $asset->useful_life_months), 'attr') ?>">
                    <select name="useful_life_unit" id="useful_life_unit" class="form-select form-select-sm">
                        <option value="years"<?= $years || $isNew ? ' selected' : '' ?>><?= lang('Accounting_assets.unit_years') ?></option>
                        <option value="months"<?= ! $years && ! $isNew ? ' selected' : '' ?>><?= lang('Accounting_assets.unit_months') ?></option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="form-label text-danger required" for="in_service_date"><?= lang('Accounting_assets.field_service_date') ?></label>
                <div class="field-control"><input type="date" name="in_service_date" id="in_service_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($value('in_service_date', $today), 'attr') ?>"></div>
            </div>
            <div class="field">
                <label class="form-label" for="quantity"><?= lang('Accounting_assets.field_quantity') ?></label>
                <div class="field-control"><input type="number" min="1" name="quantity" id="quantity" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($value('quantity', '1'), 'attr') ?>"></div>
            </div>
            <div class="field">
                <div class="field-control form-check">
                    <input type="checkbox" class="form-check-input" name="track_units" id="track_units" value="1"<?= ! $isNew && (int) $asset->track_units === 1 ? ' checked' : '' ?>>
                    <label class="form-check-label" for="track_units"><?= lang('Accounting_assets.field_track_units') ?></label>
                </div>
            </div>
        </div>

        <div id="asset_units" class="mt-2">
            <h3 class="h6"><?= lang('Accounting_assets.units_title') ?></h3>
            <div id="unit_rows" class="vstack gap-2"></div>
        </div>
    </fieldset>

    <fieldset id="asset_purchase" class="mt-3">
        <legend class="h6"><?= lang('Accounting_assets.section_purchase') ?></legend>
        <div class="form-grid">
            <div class="field">
                <label class="form-label text-danger required" for="cost"><?= lang('Accounting_assets.field_cost') ?></label>
                <div class="field-control"><input type="text" name="cost" id="cost" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount('cost'), 'attr') ?>"></div>
            </div>
            <div class="field">
                <label class="form-label text-danger required" for="purchase_date"><?= lang('Accounting_assets.field_purchase_date') ?></label>
                <div class="field-control"><input type="date" name="purchase_date" id="purchase_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($value('purchase_date', $today), 'attr') ?>"></div>
            </div>
            <div class="field">
                <label class="form-label text-danger required" for="funding"><?= lang('Accounting_assets.field_funding') ?></label>
                <div class="field-control">
                    <select name="funding" id="funding" class="form-select form-select-sm">
                        <?php foreach (['treasury', 'payable', 'opening', 'none'] as $funding): ?>
                            <option value="<?= $funding ?>"<?= $option($value('funding', 'treasury'), $funding) ?>><?= lang('Accounting_assets.funding_' . $funding) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field funding-treasury">
                <label class="form-label" for="treasury_id"><?= lang('Accounting_assets.field_treasury') ?></label>
                <div class="field-control">
                    <select name="treasury_id" id="treasury_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.none') ?></option>
                        <?php foreach ($treasuries as $treasury): ?>
                            <option value="<?= (int) $treasury->treasury_id ?>"<?= (int) $value('treasury_id', '0') === (int) $treasury->treasury_id ? ' selected' : '' ?>><?= esc($treasury->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field funding-opening">
                <label class="form-label" for="opening_accumulated"><?= lang('Accounting_assets.field_opening_accumulated') ?></label>
                <div class="field-control"><input type="text" name="opening_accumulated" id="opening_accumulated" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount('opening_accumulated', '0'), 'attr') ?>"></div>
            </div>
            <div class="field">
                <label class="form-label" for="supplier_id"><?= lang('Accounting_assets.field_supplier') ?></label>
                <div class="field-control">
                    <select name="supplier_id" id="supplier_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.none') ?></option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= (int) $supplier->person_id ?>"<?= (int) $value('supplier_id', '0') === (int) $supplier->person_id ? ' selected' : '' ?>><?= esc($supplier->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="purchase_reference"><?= lang('Accounting_assets.field_purchase_reference') ?></label>
                <div class="field-control"><input type="text" name="purchase_reference" id="purchase_reference" class="form-control form-control-sm" maxlength="100" value="<?= esc($value('purchase_reference'), 'attr') ?>"></div>
            </div>
            <div class="field span-all">
                <label class="form-label" for="description"><?= lang('Accounting_assets.field_description') ?></label>
                <div class="field-control"><textarea name="description" id="description" class="form-control form-control-sm" rows="2" maxlength="1000"><?= esc($value('description')) ?></textarea></div>
            </div>
        </div>
    </fieldset>

    <fieldset id="asset_depreciation" class="mt-3">
        <legend class="h6"><?= lang('Accounting_assets.section_depreciation') ?></legend>
        <div class="form-grid">
            <div class="field">
                <label class="form-label text-danger required" for="method"><?= lang('Accounting_assets.field_method') ?></label>
                <div class="field-control">
                    <select name="method" id="method" class="form-select form-select-sm">
                        <?php foreach (['fixed', 'declining', 'units', 'none'] as $candidate): ?>
                            <option value="<?= $candidate ?>"<?= $option($method, $candidate) ?>><?= lang('Accounting_assets.method_' . $candidate) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field m-salvage">
                <label class="form-label" for="salvage"><?= lang('Accounting_assets.field_salvage') ?></label>
                <div class="field-control"><input type="text" name="salvage" id="salvage" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount('salvage', '0'), 'attr') ?>"></div>
            </div>
            <div class="field m-fixed m-declining">
                <label class="form-label text-danger required" for="period_length"><?= lang('Accounting_assets.field_period') ?></label>
                <div class="field-control input-group input-group-sm">
                    <input type="number" min="1" name="period_length" id="period_length" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($value('period_length', '1'), 'attr') ?>">
                    <select name="period_interval" id="period_interval" class="form-select form-select-sm">
                        <?php foreach (['month', 'day', 'week'] as $interval): ?>
                            <option value="<?= $interval ?>"<?= $option($value('period_interval', 'month'), $interval) ?>><?= lang('Accounting_assets.interval_' . $interval) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field m-fixed">
                <label class="form-label" for="amount_per_period"><?= lang('Accounting_assets.field_amount') ?></label>
                <div class="field-control"><input type="text" name="amount_per_period" id="amount_per_period" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount('amount_per_period'), 'attr') ?>"></div>
            </div>
            <div class="field m-declining">
                <label class="form-label text-danger required" for="percentage"><?= lang('Accounting_assets.field_percentage') ?></label>
                <div class="field-control"><input type="text" name="percentage" id="percentage" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount('percentage'), 'attr') ?>"></div>
            </div>
            <div class="field m-units">
                <label class="form-label text-danger required" for="cost_per_unit"><?= lang('Accounting_assets.field_cost_per_unit') ?></label>
                <div class="field-control"><input type="text" name="cost_per_unit" id="cost_per_unit" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount('cost_per_unit'), 'attr') ?>"></div>
            </div>
            <div class="field m-units">
                <label class="form-label" for="unit_name"><?= lang('Accounting_assets.field_unit_name') ?></label>
                <div class="field-control"><input type="text" name="unit_name" id="unit_name" class="form-control form-control-sm" maxlength="100" value="<?= esc($value('unit_name'), 'attr') ?>"></div>
            </div>
            <div class="field m-units">
                <label class="form-label" for="total_units"><?= lang('Accounting_assets.field_total_units') ?></label>
                <div class="field-control"><input type="text" name="total_units" id="total_units" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount('total_units'), 'attr') ?>"></div>
            </div>
            <div class="field m-fixed m-declining">
                <label class="form-label" for="first_depreciation_date"><?= lang('Accounting_assets.field_first_date') ?></label>
                <div class="field-control">
                    <input type="date" name="first_depreciation_date" id="first_depreciation_date" class="form-control form-control-sm ltr-input" dir="ltr" aria-describedby="first_date_hint" value="<?= esc($value('first_depreciation_date'), 'attr') ?>">
                    <div class="form-text" id="first_date_hint" aria-live="polite"></div>
                </div>
            </div>
            <div class="field m-fixed m-declining m-units">
                <label class="form-label" for="end_date"><?= lang('Accounting_assets.field_end_date') ?></label>
                <div class="field-control"><input type="date" name="end_date" id="end_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($value('end_date'), 'attr') ?>"></div>
            </div>
            <div class="field m-accounts">
                <label class="form-label" for="accumulated_account_id"><?= lang('Accounting_assets.field_accumulated_account') ?></label>
                <div class="field-control">
                    <select name="accumulated_account_id" id="accumulated_account_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.account_default') ?></option>
                        <?php foreach ($accounts['accumulated'] as $account): ?>
                            <option value="<?= (int) $account->account_id ?>"<?= (int) $value('accumulated_account_id', '0') === (int) $account->account_id ? ' selected' : '' ?>><?= esc($account->code . ' ' . $account->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field m-accounts">
                <label class="form-label" for="expense_account_id"><?= lang('Accounting_assets.field_expense_account') ?></label>
                <div class="field-control">
                    <select name="expense_account_id" id="expense_account_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.account_default') ?></option>
                        <?php foreach ($accounts['expense'] as $account): ?>
                            <option value="<?= (int) $account->account_id ?>"<?= (int) $value('expense_account_id', '0') === (int) $account->account_id ? ' selected' : '' ?>><?= esc($account->code . ' ' . $account->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </fieldset>

    <div class="mt-3">
        <button type="submit" id="submit_asset" class="btn btn-primary btn-sm"><?= lang('Accounting_assets.save') ?></button>
    </div>
<?= form_close() ?>

<template id="unit_row_template">
    <div class="unit-row border rounded p-2">
        <div class="form-grid">
            <div class="field">
                <label class="form-label"><?= lang('Accounting_assets.unit_serial') ?></label>
                <div class="field-control"><input type="text" data-field="serial_number" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="255"></div>
            </div>
            <div class="field">
                <label class="form-label"><?= lang('Accounting_assets.unit_barcode') ?></label>
                <div class="field-control"><input type="text" data-field="barcode" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="255"></div>
            </div>
            <div class="field">
                <label class="form-label"><?= lang('Accounting_assets.unit_used_by') ?></label>
                <div class="field-control">
                    <select data-field="used_by" class="form-select form-select-sm unit-used-by">
                        <?php foreach (['not_assigned', 'employee', 'storage'] as $usedBy): ?>
                            <option value="<?= $usedBy ?>"><?= lang('Accounting_assets.used_by_' . $usedBy) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field unit-employee">
                <label class="form-label"><?= lang('Accounting_assets.unit_employee') ?></label>
                <div class="field-control">
                    <select data-field="employee_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.none') ?></option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee->person_id ?>"><?= esc($employee->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field unit-storage">
                <label class="form-label"><?= lang('Accounting_assets.unit_storage') ?></label>
                <div class="field-control">
                    <select data-field="stock_location_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.none') ?></option>
                        <?php foreach ($storages as $storage): ?>
                            <option value="<?= (int) $storage->location_id ?>"><?= esc($storage->location_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="form-label"><?= lang('Accounting_assets.unit_location') ?></label>
                <div class="field-control"><input type="text" data-field="physical_location" class="form-control form-control-sm" maxlength="255"></div>
            </div>
        </div>
    </div>
</template>

<script type="text/javascript">
    $(document).ready(function() {
        const stored = <?= json_encode(array_map(static fn (object $unit): array => [
            'serial_number'     => (string) $unit->serial_number, 'barcode' => (string) $unit->barcode, 'used_by' => (string) $unit->used_by, 'employee_id' => (string) $unit->employee_id,
            'stock_location_id' => (string) $unit->stock_location_id, 'physical_location' => (string) $unit->physical_location,
        ], $units), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const addMonths = function(date, count) {
            const parts = date.split('-').map(Number);
            const total = parts[0] * 12 + (parts[1] - 1) + count;
            const year = Math.floor(total / 12);
            const month = total % 12;
            const last = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();

            return new Date(Date.UTC(year, month, Math.min(parts[2], last))).toISOString().slice(0, 10);
        };

        const defaultFirstDate = function() {
            const service = $('#in_service_date').val();
            const period = parseInt($('#period_length').val(), 10) || 1;
            const interval = $('#period_interval').val();

            if (!service) {
                return '';
            }

            if (interval === 'month') {
                return addMonths(service, period);
            }

            return new Date(Date.parse(service + 'T00:00:00Z') + period * (interval === 'week' ? 7 : 1) * 86400000).toISOString().slice(0, 10);
        };

        let firstDateTouched = $('#first_depreciation_date').val() !== '';

        const firstDateText = "<?= esc(lang('Accounting_assets.first_date_default'), 'js') ?>";

        const showFirstDateDefault = function() {
            const proposed = firstDateTouched ? '' : defaultFirstDate();

            $('#first_date_hint').text(proposed === '' ? '' : firstDateText.replace('{0}', proposed));
        };

        const method = function() {
            const chosen = $('#method').val();

            $('.m-fixed, .m-declining, .m-units, .m-salvage, .m-accounts').each(function() {
                const classes = this.className;
                const show = (chosen === 'fixed' && /m-fixed|m-salvage|m-accounts/.test(classes))
                    || (chosen === 'declining' && /m-declining|m-salvage|m-accounts/.test(classes))
                    || (chosen === 'units' && /m-units|m-salvage|m-accounts/.test(classes));

                $(this).toggle(show);
            });
        };

        const fromCategory = function() {
            const chosen = $('#category_id option:selected');
            const categoryMethod = String(chosen.data('method') || '');
            const categoryLife = parseInt(chosen.data('life'), 10) || 0;

            if (categoryMethod !== '') {
                $('#method').val(categoryMethod);
                method();
            }

            if (categoryLife > 0) {
                const wholeYears = categoryLife % 12 === 0;

                $('#useful_life_value').val(wholeYears ? categoryLife / 12 : categoryLife);
                $('#useful_life_unit').val(wholeYears ? 'years' : 'months');
            }
        };

        const funding = function() {
            const chosen = $('#funding').val();

            $('.funding-treasury').toggle(chosen === 'treasury');
            $('.funding-opening').toggle(chosen === 'opening' || chosen === 'none');
        };

        const custody = function(row) {
            const usedBy = row.find('.unit-used-by').val();

            row.find('.unit-employee').toggle(usedBy === 'employee');
            row.find('.unit-storage').toggle(usedBy === 'storage');
        };

        const units = function() {
            const tracked = $('#track_units').is(':checked');
            const wanted = tracked ? Math.max(1, parseInt($('#quantity').val(), 10) || 1) : 1;
            const rows = $('#unit_rows');

            while (rows.children().length > wanted) {
                rows.children().last().remove();
            }

            while (rows.children().length < wanted) {
                const row = $($('#unit_row_template').html());
                const index = rows.children().length;
                const saved = stored[index] || {};

                row.find('[data-field]').each(function() {
                    const field = $(this).data('field');
                    const id = 'unit_' + index + '_' + field;
                    const label = $(this).closest('.field').find('label');

                    $(this).attr({ name: 'units[' + index + '][' + field + ']', id: id, 'aria-label': label.text() + ' (' + (index + 1) + ')' }).val(saved[field] || '');
                    label.attr('for', id);
                });

                row.find('.unit-used-by').on('change', function() { custody(row); });
                custody(row);
                rows.append(row);
            }
        };

        $('#method').on('change', method);
        $('#funding').on('change', funding);
        $('#track_units, #quantity').on('input change', units);
        $('#first_depreciation_date').on('input', function() {
            firstDateTouched = $(this).val() !== '';
            showFirstDateDefault();
        });
        $('#category_id').on('change', fromCategory);
        $('#in_service_date, #period_length, #period_interval').on('input change', showFirstDateDefault);

        method();
        funding();
        units();
        showFirstDateDefault();

        $('#asset_form').on('submit', function(event) {
            event.preventDefault();
            $('#submit_asset').prop('disabled', true);
            $(this).ajaxSubmit({
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.href = response.redirect;
                    } else {
                        $('#error_message_box').empty().append($('<li></li>').text(response.message));
                        $('#submit_asset').prop('disabled', false);
                    }
                },
                error: function() {
                    toast_support.show("<?= esc(lang('Common.request_failed'), 'js') ?>", 'danger');
                    $('#submit_asset').prop('disabled', false);
                }
            });
        });
    });
</script>

<?= view('partial/footer') ?>
