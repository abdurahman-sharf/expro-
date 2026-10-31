<?php
/**
 * @var string $otherUrl
 * @var string $otherLabel
 */
?>
<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" id="other_statement" href="<?= esc($otherUrl, 'attr') ?>">
        <span class="bi bi-file-earmark-bar-graph" aria-hidden="true">&nbsp;</span><?= esc($otherLabel) ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_profit_loss" href="<?= esc(base_url('accounting_statements/profit_loss'), 'attr') ?>">
        <span class="bi bi-graph-up-arrow" aria-hidden="true">&nbsp;</span><?= lang('Accounting_profit_loss.title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_cash_flow" href="<?= esc(base_url('accounting_statements/cash_flow'), 'attr') ?>">
        <span class="bi bi-cash-stack" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cash_flow.title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_reports'), 'attr') ?>">
        <span class="bi bi-table" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.trial_balance') ?>
    </a>
</div>
