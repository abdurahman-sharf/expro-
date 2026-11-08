<?php
/**
 * @var list<object> $categories
 * @var bool         $can_manage
 * @var string|null  $notice
 */
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_assets/tabs', ['active' => 'categories']) ?>

<div class="container-fluid py-3" id="categories_page">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h1 class="h4 me-auto mb-0"><?= lang('Accounting_assets.categories_title') ?></h1>
        <?php if ($can_manage): ?>
            <button type="button" class="btn btn-primary btn-sm modal-dlg" id="new_category" data-btn-submit="<?= esc(lang('Accounting_assets.save'), 'attr') ?>" data-href="<?= esc('accounting_assets/category_form', 'attr') ?>" title="<?= esc(lang('Accounting_assets.category_new'), 'attr') ?>"><span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_assets.category_new') ?></button>
        <?php endif; ?>
    </div>
    <p class="text-body-secondary"><?= lang('Accounting_assets.categories_intro') ?></p>

    <?php if ($categories === []): ?>
        <p class="alert alert-info" id="categories_empty"><?= lang('Accounting_assets.categories_empty') ?></p>
    <?php else: ?>
        <div class="acc-lines-wrap">
            <table class="table table-sm acc-lines" id="categories_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_assets.category_name') ?></th>
                        <th scope="col"><?= lang('Accounting_assets.field_cost_account') ?></th>
                        <th scope="col"><?= lang('Accounting_assets.field_accumulated_account') ?></th>
                        <th scope="col"><?= lang('Accounting_assets.field_expense_account') ?></th>
                        <th scope="col"><?= lang('Accounting_assets.field_method') ?></th>
                        <th scope="col"><?= lang('Accounting_assets.category_assets') ?></th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr class="category-row<?= (int) $category->active === 1 ? '' : ' text-body-secondary' ?>" data-category="<?= (int) $category->category_id ?>">
                            <td data-label="<?= esc(lang('Accounting_assets.category_name'), 'attr') ?>"><?= esc($category->name) ?><?= (int) $category->active === 1 ? '' : ' <span class="badge text-bg-secondary">' . esc(lang('Accounting_assets.category_off')) . '</span>' ?></td>
                            <td data-label="<?= esc(lang('Accounting_assets.field_cost_account'), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $category->cost_code) ?></bdi> <?= esc((string) $category->cost_name) ?></td>
                            <td data-label="<?= esc(lang('Accounting_assets.field_accumulated_account'), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $category->accumulated_code) ?></bdi> <?= esc((string) $category->accumulated_name) ?></td>
                            <td data-label="<?= esc(lang('Accounting_assets.field_expense_account'), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $category->expense_code) ?></bdi> <?= esc((string) $category->expense_name) ?></td>
                            <td data-label="<?= esc(lang('Accounting_assets.field_method'), 'attr') ?>"><?= $category->method === null ? '' : lang('Accounting_assets.method_' . $category->method) ?></td>
                            <td data-label="<?= esc(lang('Accounting_assets.category_assets'), 'attr') ?>"><?= (int) $category->asset_count ?></td>
                            <td class="print_hide">
                                <?php if ($can_manage): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_assets.save'), 'attr') ?>" data-href="<?= esc('accounting_assets/category_form/' . (int) $category->category_id, 'attr') ?>" title="<?= esc(lang('Accounting_assets.category_edit'), 'attr') ?>"><?= lang('Accounting_assets.category_edit') ?></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm category-post" data-url="<?= esc(base_url('accounting_assets/category_active/' . (int) $category->category_id . '/' . ((int) $category->active === 1 ? 0 : 1)), 'attr') ?>"><?= lang((int) $category->active === 1 ? 'Accounting_assets.deactivate' : 'Accounting_assets.activate') ?></button>
                                    <?php if ((int) $category->asset_count === 0): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm category-post" data-confirm="<?= esc(lang('Accounting_assets.category_delete_confirm'), 'attr') ?>" data-url="<?= esc(base_url('accounting_assets/delete_category/' . (int) $category->category_id), 'attr') ?>"><?= lang('Accounting_assets.delete') ?></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('.category-post').on('click', function() {
            const button = $(this);
            const message = button.data('confirm');

            if (message && !window.confirm(message)) {
                return;
            }

            $.post(button.data('url'), {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });
    });
</script>

<?= view('partial/footer') ?>
