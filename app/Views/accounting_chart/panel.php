<?php
/**
 * @var object|null $account
 * @var list<object> $path
 * @var string $balance
 * @var bool $can_manage
 */
?>
<nav class="acc-breadcrumb" aria-label="<?= lang('Accounting_chart.path') ?>">
    <ol class="breadcrumb mb-2">
        <li class="breadcrumb-item"><a href="<?= esc(base_url('accounting_chart'), 'attr') ?>"><?= lang('Accounting_chart.all_accounts') ?></a></li>
        <?php foreach ($path as $index => $step): ?>
            <?php if ($index === array_key_last($path)): ?>
                <li class="breadcrumb-item active" aria-current="page"><?= esc($step->name) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= esc(base_url('accounting_chart?parent=' . (int) $step->account_id), 'attr') ?>"><?= esc($step->name) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>

<?php if ($account === null): ?>
    <p class="text-body-secondary"><?= lang('Accounting_chart.select_hint') ?></p>
<?php else: ?>
    <dl class="acc-summary">
        <div>
            <dt><?= lang('Accounting_chart.code') ?></dt>
            <dd><bdi dir="ltr"><?= esc($account->code) ?></bdi></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_chart.type') ?></dt>
            <dd><?= esc(lang('Accounting.account_type_' . $account->account_type)) ?></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_chart.direction') ?></dt>
            <dd><?= esc(lang('Accounting.direction_' . $account->normal_balance)) ?></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_chart.balance') ?></dt>
            <dd><bdi dir="ltr"><?= esc(to_currency_no_money($balance)) ?></bdi></dd>
        </div>
        <div>
            <dt><?= lang('Accounting.status') ?></dt>
            <dd>
                <span class="badge <?= (int) $account->active === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= esc(lang('Accounting.state_' . ((int) $account->active === 1 ? 'active' : 'inactive'))) ?></span>
                <?php if ((int) $account->is_system === 1): ?>
                    <span class="badge text-bg-primary"><?= esc(lang('Accounting.state_system')) ?></span>
                <?php endif; ?>
            </dd>
        </div>
    </dl>
<?php endif; ?>
