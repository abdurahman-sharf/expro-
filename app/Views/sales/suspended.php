<?php
/**
 * @var array $suspended_sales
 * @var array $config
 */

use App\Models\Customer;
use App\Models\Employee;

$this->dinner_table = model(Dinner_table::class);
?>

<div class="table-responsive" tabindex="0" role="region" aria-label="<?= lang('Sales.suspended_sales') ?>">
<table id="suspended_sales_table" class="table table-hover align-middle">
    <thead>
        <tr>
            <th><?= lang('Sales.suspended_doc_id') ?></th>
            <th><?= lang('Sales.date') ?></th>
            <?php if ($config['dinner_table_enable']) { ?>
                <th><?= lang('Sales.table') ?></th>
            <?php } ?>
            <th><?= lang('Sales.customer') ?></th>
            <th><?= lang('Sales.employee') ?></th>
            <th><?= lang('Sales.comments') ?></th>
            <th><?= lang('Sales.unsuspend_and_delete') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($suspended_sales as $suspendedSale) { ?>
            <tr>
                <td><?= $suspendedSale['doc_id'] ?></td>
                <td><?= date($config['dateformat'], strtotime($suspendedSale['sale_time'])) ?></td>
                <?php if ($config['dinner_table_enable']) { ?>
                    <td><?= esc($this->dinner_table->get_name($suspendedSale['dinner_table_id'])) ?></td>
                <?php } ?>
                <td>
                    <?php if (isset($suspendedSale['customer_id'])): ?>
                        <?php
                        // TODO: Should we be accessing a model in a view rather than passing this data to the view via the controller?
                        $customerData = model(Customer::class)->get_info($suspendedSale['customer_id']);
                        ?>
                        <?= esc("{$customerData->first_name} {$customerData->last_name}") ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (isset($suspendedSale['employee_id'])): ?>
                        <?php $employeeData = model(Employee::class)->get_info($suspendedSale['employee_id']); ?>
                        <?= esc("{$employeeData->first_name} {$employeeData->last_name}") ?>
                    <?php endif; ?>
                </td>
                <td><?= esc($suspendedSale['comment']) ?></td>
                <td>
                    <?= form_open('sales/unsuspend') ?>
                    <?= form_hidden('suspended_sale_id', $suspendedSale['sale_id']) ?>
                    <button type="submit" name="submit" class="btn btn-primary btn-sm"><?= lang('Sales.unsuspend') ?></button>
                    <?= form_close() ?>
                </td>
            </tr>
        <?php } ?>
    </tbody>
</table>
</div>
