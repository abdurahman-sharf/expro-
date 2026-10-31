<?php
/**
 * @var bool                $can_run
 * @var list<BackfillBatch> $batches
 * @var list<string>        $blockers
 * @var string|null         $notice
 * @var Closure             $date
 * @var Closure             $amount
 * @var Closure             $status_text
 */

use App\Libraries\Accounting\Backfill\BackfillBatch;
use App\Libraries\Accounting\Backfill\BackfillFamily;

?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_periods/tabs', ['active' => 'backfill']) ?>

<div class="container-fluid py-3" id="backfill_page">
    <h1 class="h4"><?= lang('Accounting_backfill.title') ?></h1>
    <p class="text-body-secondary"><?= lang('Accounting_backfill.intro') ?></p>

    <?php if (! $can_run): ?>
        <p class="text-body-secondary small" id="backfill_permission_note" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_backfill.permission_note') ?></p>
    <?php else: ?>
        <?php if ($blockers !== []): ?>
            <div class="alert alert-warning" id="backfill_blockers" role="note">
                <p class="mb-1"><?= lang('Accounting_backfill.blockers_intro') ?></p>
                <ul class="mb-0">
                    <?php foreach ($blockers as $blocker): ?>
                        <?php [$code, $subject] = array_pad(explode(':', $blocker, 2), 2, ''); ?>
                        <li><?= esc(lang('Accounting_backfill.blocker_' . $code, [$subject])) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p>
            <button type="button" id="backfill_new" class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_backfill.new_submit'), 'attr') ?>" data-href="<?= esc('accounting_periods/backfill/new_form', 'attr') ?>" title="<?= esc(lang('Accounting_backfill.new_title'), 'attr') ?>">
                <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_backfill.new') ?>
            </button>
        </p>

        <?php if ($batches === []): ?>
            <p class="text-body-secondary" id="backfill_none"><?= lang('Accounting_backfill.none') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm" id="backfill_batches">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col"><?= lang('Accounting_backfill.name') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.range') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.branch') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.families') ?></th>
                            <th scope="col"><?= lang('Accounting.status') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.ready') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.refused') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.journals') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td><a href="<?= esc(base_url('accounting_periods/backfill/view/' . $batch->id), 'attr') ?>">#<?= $batch->id ?></a></td>
                                <td><?= esc($batch->name) ?></td>
                                <td><bdi dir="ltr"><?= esc($date($batch->from)) ?> - <?= esc($date($batch->to)) ?></bdi></td>
                                <td><?= $batch->branchId === null ? esc(lang('Accounting_backfill.scope_company')) : '<bdi dir="ltr">' . esc((string) $batch->branchCode) . '</bdi>' ?></td>
                                <td><?= esc(implode(', ', array_map(static fn (BackfillFamily $family): string => lang('Accounting_backfill.family_' . $family->value), $batch->families))) ?></td>
                                <td><span class="badge text-bg-secondary"><?= esc($status_text($batch)) ?></span></td>
                                <td class="text-end"><?= $batch->readyCount ?></td>
                                <td class="text-end"><?= $batch->refusedCount ?></td>
                                <td class="text-end"><?= $batch->journalCount ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
