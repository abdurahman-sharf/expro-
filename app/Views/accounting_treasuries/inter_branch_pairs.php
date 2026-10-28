<?php
/**
 * @var list<array<string, mixed>> $rows
 * @var list<array<string, mixed>> $unattributed
 * @var string|null                $difference
 * @var string|null                $error
 * @var string                     $asOf
 * @var string|null                $scopeLabel
 * @var bool                       $scopeEmpty
 * @var bool                       $scopePartial
 * @var bool                       $canSettle
 */
?>

<?php if ($error !== null): ?>
    <p class="alert alert-danger" id="pairs_error" role="alert"><?= esc($error) ?></p>
<?php endif; ?>

<?php if ($scopeLabel !== null): ?>
    <?php if ($scopePartial): ?>
        <p class="alert alert-warning" id="pairs_scope" role="note"><?= esc($scopeLabel) ?></p>
    <?php else: ?>
        <p class="text-secondary small" id="pairs_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scopeLabel) ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($scopeEmpty): ?>
    <p class="alert alert-warning" id="pairs_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
<?php endif; ?>

<?php if ($difference !== null): ?>
    <p class="alert alert-warning" id="pairs_difference" role="alert"><?= esc(lang('Accounting_treasuries.company_difference', [$difference])) ?></p>
<?php endif; ?>

<?php if ($rows === []): ?>
    <p class="text-body-secondary" id="pairs_none"><?= lang('Accounting_treasuries.pair_none') ?></p>
<?php else: ?>
    <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered w-auto mb-0" id="pair_balances">
            <caption class="visually-hidden"><?= esc(lang('Accounting_treasuries.inter_branch')) ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_branch') ?></th>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_with') ?></th>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_owed') ?></th>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_owing') ?></th>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_net') ?></th>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_basis') ?></th>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_agrees') ?></th>
                    <?php if ($canSettle): ?>
                        <th scope="col"><span class="visually-hidden"><?= lang('Accounting_treasuries.settle') ?></span></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <th scope="row"><bdi dir="ltr"><?= esc($row['code']) ?></bdi></th>
                        <td><bdi dir="ltr"><?= esc($row['counterpart']) ?></bdi></td>
                        <td><bdi dir="ltr"><?= esc($row['receivable']) ?></bdi></td>
                        <td><bdi dir="ltr"><?= esc($row['payable']) ?></bdi></td>
                        <td><bdi dir="ltr"><?= esc($row['owed']) ?></bdi> <span class="small text-body-secondary"><?= $row['sign'] > 0 ? esc(lang('Accounting_treasuries.pair_net_owed')) : ($row['sign'] < 0 ? esc(lang('Accounting_treasuries.pair_net_owing')) : '') ?></span></td>
                        <td><span class="badge text-bg-secondary"><?= esc(lang('Accounting_treasuries.pair_basis_' . $row['basis'])) ?></span></td>
                        <td><span class="badge <?= $row['agrees'] ? 'text-bg-success' : 'text-bg-danger' ?>"><?= esc(lang($row['agrees'] ? 'Accounting_treasuries.pair_agrees_yes' : 'Accounting_treasuries.pair_agrees_no')) ?></span></td>
                        <?php if ($canSettle): ?>
                            <td>
                                <?php if ($row['settleUrl'] !== null): ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_treasuries.settle_submit'), 'attr') ?>" data-href="<?= esc($row['settleUrl'], 'attr') ?>" title="<?= esc(lang('Accounting_treasuries.settle_title'), 'attr') ?>"><?= lang('Accounting_treasuries.settle') ?></button>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($unattributed !== []): ?>
    <div class="mb-3" id="pairs_unattributed">
        <h2 class="h6"><?= lang('Accounting_treasuries.unattributed_title') ?></h2>
        <p class="form-text mb-1"><?= esc(lang('Accounting_treasuries.unattributed_note')) ?></p>
        <table class="table table-sm table-bordered w-auto mb-0">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_branch') ?></th>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_owed') ?></th>
                    <th scope="col"><?= lang('Accounting_treasuries.pair_owing') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unattributed as $row): ?>
                    <tr>
                        <th scope="row"><bdi dir="ltr"><?= esc($row['code']) ?></bdi></th>
                        <td><bdi dir="ltr"><?= esc($row['receivable']) ?></bdi></td>
                        <td><bdi dir="ltr"><?= esc($row['payable']) ?></bdi></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
