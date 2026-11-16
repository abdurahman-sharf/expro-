<?php
/**
 * @var object       $account
 * @var list<string> $actions
 */

use App\Libraries\Accounting\ChartRowActions;

$id = (int) $account->account_id;
?>
<?php foreach ($actions as $action): ?>
    <?php if ($action === ChartRowActions::ADD_CHILD): ?>
        <button type="button" class="btn btn-sm acc-act acc-act-add modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc("accounting_chart/view?parent_id={$id}&lock_parent=1", 'attr') ?>" title="<?= esc(lang('Accounting_chart.add_child'), 'attr') ?>"><span class="bi bi-plus-circle" aria-hidden="true"></span><span class="acc-act-text"><?= lang('Accounting_chart.add_child') ?></span></button>
    <?php elseif ($action === ChartRowActions::EDIT): ?>
        <button type="button" class="btn btn-sm acc-act modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc("accounting_chart/view/{$id}", 'attr') ?>" title="<?= esc(lang('Accounting_chart.update'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_chart.update'), 'attr') ?>"><span class="bi bi-pencil-square" aria-hidden="true"></span></button>
    <?php elseif ($action === ChartRowActions::DEACTIVATE): ?>
        <button type="button" class="btn btn-sm acc-act acc-action" data-acc-action="deactivate" data-acc-id="<?= $id ?>" title="<?= esc(lang('Accounting_chart.deactivate'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_chart.deactivate'), 'attr') ?>"><span class="bi bi-slash-circle" aria-hidden="true"></span></button>
    <?php elseif ($action === ChartRowActions::ACTIVATE): ?>
        <button type="button" class="btn btn-sm acc-act acc-action" data-acc-action="activate" data-acc-id="<?= $id ?>" title="<?= esc(lang('Accounting_chart.activate'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_chart.activate'), 'attr') ?>"><span class="bi bi-check-circle" aria-hidden="true"></span></button>
    <?php elseif ($action === ChartRowActions::DELETE): ?>
        <button type="button" class="btn btn-sm acc-act acc-action text-danger" data-acc-action="delete" data-acc-id="<?= $id ?>" title="<?= esc(lang('Common.delete'), 'attr') ?>" aria-label="<?= esc(lang('Common.delete'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button>
    <?php elseif ($action === ChartRowActions::LEDGER): ?>
        <a class="btn btn-sm acc-act" href="<?= esc(base_url('accounting_reports/ledger?account=' . $id), 'attr') ?>" title="<?= esc(lang('Accounting_chart.ledger'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_chart.ledger'), 'attr') ?>"><span class="bi bi-journal-text" aria-hidden="true"></span></a>
    <?php endif; ?>
<?php endforeach; ?>
