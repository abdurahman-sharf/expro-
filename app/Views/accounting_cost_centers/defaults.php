<?php
/**
 * @var array<int, string>                    $accounts   The accounts a default can be set on, by id.
 * @var int                                   $account_id The account being set, or 0.
 * @var list<array{id: int, percent: string}> $rows       Its default distribution now.
 * @var list<object>                          $centers    The cost centers that may be chosen.
 * @var array<int, string>                    $labels
 * @var list<object>                          $defaults   The accounts that have a default.
 * @var bool                                  $can_manage
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<div class="container-fluid py-3" id="defaults_page">
    <h1 class="h4"><?= lang('Accounting_cost_centers.defaults_title') ?></h1>
    <p class="text-body-secondary small"><?= lang('Accounting_cost_centers.defaults_desc') ?></p>

    <div class="mb-3">
        <label class="form-label" for="defaults_account"><?= lang('Accounting_cost_centers.defaults_account') ?></label>
        <select id="defaults_account" class="form-select form-select-sm">
            <option value=""><?= lang('Accounting_cost_centers.defaults_choose_account') ?></option>
            <?php foreach ($accounts as $id => $label): ?>
                <option value="<?= (int) $id ?>"<?= $id === $account_id ? ' selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($account_id > 0): ?>
        <form id="defaults_form" class="app-form" novalidate>
            <table class="table table-sm" id="defaults_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_cost_centers.defaults_center') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_cost_centers.defaults_percent') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Accounting_cost_centers.defaults_remove_row') ?></span></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <th scope="row"><?= lang('Accounting_cost_centers.defaults_total') ?></th>
                        <td class="text-end"><bdi dir="ltr" id="defaults_total">0</bdi></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <?php if ($can_manage): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="defaults_add"><?= lang('Accounting_cost_centers.defaults_add_row') ?></button>
                <button type="submit" class="btn btn-primary btn-sm"><?= lang('Common.submit') ?></button>
                <p class="form-text"><?= lang('Accounting_cost_centers.defaults_rule') ?></p>
            <?php else: ?>
                <p class="form-text mb-0" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_cost_centers.defaults_permission_note') ?></p>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <h2 class="h5 mt-4"><?= lang('Accounting_cost_centers.defaults_list') ?></h2>
    <?php if ($defaults === []): ?>
        <p class="text-body-secondary"><?= lang('Accounting_cost_centers.defaults_none') ?></p>
    <?php else: ?>
        <ul>
            <?php foreach ($defaults as $account): ?>
                <li><a href="<?= esc(base_url('accounting_cost_centers/defaults/' . (int) $account->account_id), 'attr') ?>"><bdi dir="ltr"><?= esc($account->code) ?></bdi> <?= esc($account->name) ?></a> <span class="text-body-secondary">(<?= (int) $account->row_count ?>)</span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        const centers = <?= json_encode(array_map(static fn (object $center): array => ['id' => (int) $center->cost_center_id, 'label' => $center->code . ' - ' . $center->name], $centers), $jsonFlags) ?>;
        const labels = <?= json_encode($labels, $jsonFlags) ?>;
        const rows = <?= json_encode($rows, $jsonFlags) ?>;
        const editable = <?= $can_manage ? 'true' : 'false' ?>;
        const remove = <?= json_encode(lang('Accounting_cost_centers.defaults_remove_row'), $jsonFlags) ?>;
        const centerLabel = <?= json_encode(lang('Accounting_cost_centers.defaults_center'), $jsonFlags) ?>;
        const percentLabel = <?= json_encode(lang('Accounting_cost_centers.defaults_percent'), $jsonFlags) ?>;
        const digits = {'٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9'};
        const $body = $('#defaults_table tbody');
        let next = 0;

        $('#defaults_account').on('change', function() {
            window.location.href = 'accounting_cost_centers/defaults' + ($(this).val() === '' ? '' : '/' + $(this).val());
        });

        const options = function(selected) {
            let html = '<option value=""></option>';
            let found = false;

            centers.forEach(function(center) {
                found = found || center.id === selected;
                html += '<option value="' + center.id + '"' + (center.id === selected ? ' selected' : '') + '>' + $('<span></span>').text(center.label).html() + '</option>';
            });

            if (selected && !found) {
                html += '<option value="' + selected + '" selected>' + $('<span></span>').text(labels[selected] || selected).html() + '</option>';
            }

            return html;
        };

        const normalize = function(value) {
            return String(value).replace(/[٠-٩۰-۹]/g, function(character) {
                return digits[character];
            }).replace(/[٫,،]/g, '.').trim();
        };

        const total = function() {
            let sum = 0;

            $body.find('.defaults-percent').each(function() {
                const value = parseFloat(normalize($(this).val()));
                sum += isNaN(value) ? 0 : value;
            });

            $('#defaults_total').text(String(Math.round(sum * 10000) / 10000));
        };

        const add = function(id, percent) {
            const disabled = editable ? '' : ' disabled';

            $body.append('<tr><td><select name="rows[' + next + '][cost_center_id]" class="form-select form-select-sm" aria-label="' + centerLabel + '"' + disabled + '>' + options(id) + '</select></td>'
                + '<td><input type="text" name="rows[' + next + '][percent]" class="form-control form-control-sm defaults-percent text-end" dir="ltr" inputmode="decimal" aria-label="' + percentLabel + '" value="' + percent + '"' + disabled + '></td>'
                + '<td>' + (editable ? '<button type="button" class="btn btn-outline-danger btn-sm defaults-remove" title="' + remove + '" aria-label="' + remove + '"><span class="bi bi-x-lg" aria-hidden="true"></span></button>' : '') + '</td></tr>');
            next++;
        };

        rows.forEach(function(row) {
            add(row.id, row.percent);
        });
        total();

        $('#defaults_add').on('click', function() {
            add(null, '');
        });

        $body.on('click', '.defaults-remove', function() {
            $(this).closest('tr').remove();
            total();
        });

        $body.on('input', '.defaults-percent', function() {
            const normalized = normalize($(this).val());

            if (normalized !== $(this).val()) {
                $(this).val(normalized);
            }
            total();
        });

        $('#defaults_form').on('submit', function(event) {
            event.preventDefault();

            $.post('accounting_cost_centers/save_defaults/<?= (int) $account_id ?>', $(this).serialize(), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 600);
                }
            }, 'json');
        });
    });
</script>

<?= view('partial/footer') ?>
