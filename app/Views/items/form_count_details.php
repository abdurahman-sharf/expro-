<?php
/**
 * @var object $item_info
 * @var array  $stock_locations
 * @var array  $item_quantities
 */

use App\Models\Employee;
use App\Models\Inventory;

?>

<?= form_open('items', ['id' => 'item_form', 'class' => 'app-form']) ?>
    <fieldset id="count_item_basic_info" class="form-grid">

        <div class="field">
            <?= form_label(lang('Items.item_number'), 'item_number', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group">
                    <span class="input-group-text"><span class="bi bi-upc-scan" aria-hidden="true"></span></span>
                    <?= form_input([
                        'name'     => 'item_number',
                        'id'       => 'item_number',
                        'class'    => 'form-control form-control-sm',
                        'disabled' => '',
                        'value'    => $item_info->item_number,
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.name'), 'name', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'     => 'name',
                    'id'       => 'name',
                    'class'    => 'form-control form-control-sm',
                    'disabled' => '',
                    'value'    => $item_info->name,
                ]) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.category'), 'category', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group">
                    <span class="input-group-text"><span class="bi bi-tag" aria-hidden="true"></span></span>
                    <?= form_input([
                        'name'     => 'category',
                        'id'       => 'category',
                        'class'    => 'form-control form-control-sm',
                        'disabled' => '',
                        'value'    => $item_info->category,
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.stock_location'), 'stock_location', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('stock_location', $stock_locations, current($stock_locations), ['onchange' => 'display_stock(this.value);', 'class' => 'form-select', 'id' => 'stock_location']) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.current_quantity'), 'quantity', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'     => 'quantity',
                    'id'       => 'quantity',
                    'class'    => 'form-control form-control-sm',
                    'disabled' => '',
                    'value'    => to_quantity_decimals(current($item_quantities)),
                ]) ?>
            </div>
        </div>

    </fieldset>
<?= form_close() ?>

<div class="table-responsive">
<table id="items_count_details" class="table table-striped table-hover">
    <thead>
        <tr class="table-secondary">
            <th colspan="4"><?= lang('Items.inventory_data_tracking') ?></th>
        </tr>
        <tr>
            <th><?= lang('Items.inventory_date') ?></th>
            <th><?= lang('Items.inventory_employee') ?></th>
            <th><?= lang('Items.inventory_in_out_quantity') ?></th>
            <th><?= lang('Items.inventory_remarks') ?></th>
        </tr>
    </thead>
    <tbody id="inventory_result">
        <?php
        // The tbody content of the table will be filled in by the javascript (see bottom of page)
        $employee = model(Employee::class);
$inventory        = model(Inventory::class);

$inventory_array = $inventory->get_inventory_data_for_item($item_info->item_id, array_keys($stock_locations))->getResultArray();
$employee_name   = [];

foreach ($inventory_array as $row) {
    $employee_data   = $employee->get_info($row['trans_user']);
    $employee_name[] = $employee_data->first_name . ' ' . $employee_data->last_name;
}
?>
    </tbody>
</table>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        display_stock(<?= json_encode(key(esc($stock_locations, 'raw'))) ?>);
    });

    function display_stock(location_id) {
        const item_quantities = <?= json_encode(esc($item_quantities, 'raw')) ?>;
        document.getElementById("quantity").value = parseFloat(item_quantities[location_id]).toFixed(<?= quantity_decimals() ?>);

        const inventory_data = <?= json_encode(esc($inventory_array, 'raw')) ?>;
        const employee_data = <?= json_encode(esc($employee_name, 'raw')) ?>;

        const table = document.getElementById("inventory_result");

        // Remove old query from tbody
        const rowCount = table.rows.length;
        for (let index = rowCount; index > 0; index--) {
            table.deleteRow(index - 1);
        }

        // Add new query to tbody
        for (let index = 0; index < inventory_data.length; index++) {
            const data = inventory_data[index];
            if (data['trans_location'] == location_id) {
                const tr = document.createElement('tr');

                let td = document.createElement('td');
                td.appendChild(document.createTextNode(data['trans_date']));
                tr.appendChild(td);

                td = document.createElement('td');
                td.appendChild(document.createTextNode(employee_data[index]));
                tr.appendChild(td);

                td = document.createElement('td');
                td.appendChild(document.createTextNode(parseFloat(data['trans_inventory']).toFixed(<?= quantity_decimals() ?>)));
                td.className = 'text-center';
                tr.appendChild(td);

                td = document.createElement('td');
                td.appendChild(document.createTextNode(data['trans_comment']));
                tr.appendChild(td);

                table.appendChild(tr);
            }
        }
    }
</script>
