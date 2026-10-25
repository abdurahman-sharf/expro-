<?php
/**
 * @var list<object> $employees
 * @var list<object> $treasuries
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('.default-treasury').on('change', function() {
            $.post('accounting_treasuries/default/' + $(this).data('person'), {treasury_id: $(this).val()}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <p class="mb-0 me-auto text-body-secondary"><?= lang('Accounting_treasuries.defaults_note') ?></p>
    <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries"><span class="bi bi-wallet2" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.treasuries') ?></a>
</div>

<div id="table_holder">
    <table class="table table-sm align-middle" id="defaults_table">
        <thead>
            <tr>
                <th scope="col"><?= lang('Accounting_treasuries.employee') ?></th>
                <th scope="col"><?= lang('Accounting_treasuries.treasuries') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $employee): ?>
                <tr>
                    <td><?= esc($employee->employee_name) ?></td>
                    <td>
                        <select class="form-select form-select-sm default-treasury" data-person="<?= (int) $employee->person_id ?>" aria-label="<?= esc(lang('Accounting_treasuries.treasuries'), 'attr') ?>">
                            <option value="0"><?= esc(lang('Accounting_treasuries.default_none')) ?></option>
                            <?php foreach ($treasuries as $treasury): ?>
                                <option value="<?= (int) $treasury->treasury_id ?>"<?= $employee->default_treasury_id !== null && (int) $employee->default_treasury_id === (int) $treasury->treasury_id ? ' selected' : '' ?>><?= esc($treasury->name . ' (' . $treasury->branch_code . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= view('partial/footer') ?>
