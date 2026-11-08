<?php
/**
 * @var object      $proposal
 * @var bool        $enabled
 * @var bool        $canApprove
 * @var bool        $canReject
 * @var bool        $canViewJournals
 * @var string|null $notice
 * @var Closure     $date
 * @var Closure     $dateTime
 * @var Closure     $amount
 */
$expired = $proposal->status === 'proposed' && (int) $proposal->is_expired === 1;
$status  = $expired ? 'expired' : (string) $proposal->status;
$badge   = match ($status) {
    'approved' => 'text-bg-success',
    'proposed' => 'text-bg-warning',
    'rejected' => 'text-bg-danger',
    default    => 'text-bg-secondary',
};
$kindKey     = 'Accounting_ai.kind_' . $proposal->kind;
$kindText    = lang($kindKey);
$totalDebit  = '0';
$totalCredit = '0';

foreach ($proposal->lines as $line) {
    $totalDebit  = bcadd($totalDebit, (string) $line['debit'], 4);
    $totalCredit = bcadd($totalCredit, (string) $line['credit'], 4);
}
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('.ai-decide').on('click', function() {
            dialog_support.init(this);
        });
    });
</script>

<?= view('accounting_ai/tabs', ['active' => 'proposals']) ?>

<div class="container-fluid py-3" id="ai_proposal" data-status="<?= esc($status, 'attr') ?>">
    <p><a href="<?= esc(base_url('accounting_ai'), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true"></span> <?= lang('Accounting_ai.proposals_title') ?></a></p>

    <h1 class="h4">#<?= (int) $proposal->proposal_id ?> <?= esc((string) $proposal->title_text) ?> <span class="badge <?= $badge ?>" id="ai_proposal_status"><?= lang('Accounting_ai.status_' . $status) ?></span></h1>

    <?php if (! $enabled): ?>
        <div class="alert alert-warning" id="ai_off" role="alert"><?= lang('Accounting_ai.off_notice') ?></div>
    <?php endif; ?>

    <p class="lead" id="ai_rationale"><?= esc((string) $proposal->rationale_text) ?></p>

    <dl class="row" id="ai_proposal_header">
        <dt class="col-sm-3"><?= lang('Accounting_ai.kind') ?></dt>
        <dd class="col-sm-9"><?= esc($kindText === $kindKey ? (string) $proposal->kind : $kindText) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_ai.proposer') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $proposal->proposer) ?></bdi></dd>
        <dt class="col-sm-3"><?= lang('Accounting_ai.branch') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $proposal->branch_code) ?></bdi></dd>
        <dt class="col-sm-3"><?= lang('Accounting_ai.proposal_date') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc($date($proposal->proposal_date)) ?></bdi></dd>
        <dt class="col-sm-3"><?= lang('Accounting_ai.created_at') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc($dateTime($proposal->created_at)) ?></bdi><?= $proposal->requester_name !== null && $proposal->requester_name !== '' ? ' (' . esc((string) $proposal->requester_name) . ')' : '' ?></dd>
        <?php if ($proposal->expires_at !== null): ?>
            <dt class="col-sm-3"><?= lang('Accounting_ai.expires_at') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc($dateTime($proposal->expires_at)) ?></bdi></dd>
        <?php endif; ?>
        <?php if ($proposal->decided_at !== null): ?>
            <dt class="col-sm-3"><?= lang('Accounting_ai.decided_at') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc($dateTime($proposal->decided_at)) ?></bdi><?= $proposal->decider_name !== null && $proposal->decider_name !== '' ? ' (' . esc((string) $proposal->decider_name) . ')' : '' ?></dd>
        <?php endif; ?>
        <?php if ($proposal->reject_reason !== null): ?>
            <dt class="col-sm-3"><?= lang('Accounting_ai.reject_reason') ?></dt>
            <dd class="col-sm-9" id="ai_reject_reason"><?= esc((string) $proposal->reject_reason) ?></dd>
        <?php endif; ?>
        <?php if ($proposal->stale_reason !== null): ?>
            <dt class="col-sm-3"><?= lang('Accounting_ai.stale_reason') ?></dt>
            <dd class="col-sm-9" id="ai_stale_reason"><?= esc(lang('Accounting_ai.stale_' . $proposal->stale_reason) === 'Accounting_ai.stale_' . $proposal->stale_reason ? (string) $proposal->stale_reason : lang('Accounting_ai.stale_' . $proposal->stale_reason)) ?></dd>
        <?php endif; ?>
        <?php if ($proposal->journal_id !== null): ?>
            <dt class="col-sm-3"><?= lang('Accounting_ai.journal') ?></dt>
            <dd class="col-sm-9" id="ai_journal_link">
                <?php if ($canViewJournals): ?>
                    <a href="<?= esc(base_url('accounting_journals/view/' . (int) $proposal->journal_id), 'attr') ?>"><bdi dir="ltr">A-<?= esc((string) $proposal->journal_number) ?></bdi></a>
                <?php else: ?>
                    <bdi dir="ltr">A-<?= esc((string) $proposal->journal_number) ?></bdi>
                <?php endif; ?>
            </dd>
        <?php endif; ?>
    </dl>

    <div class="print_hide mb-3" id="ai_actions">
        <?php if ($canApprove): ?>
            <button type="button" id="ai_approve" class="btn btn-primary btn-sm modal-dlg ai-decide" data-btn-submit="<?= esc(lang('Accounting_ai.approve_submit'), 'attr') ?>" data-href="<?= esc(base_url('accounting_ai/approve_form/' . (int) $proposal->proposal_id), 'attr') ?>" title="<?= esc(lang('Accounting_ai.approve_title'), 'attr') ?>">
                <span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_ai.approve') ?>
            </button>
        <?php endif; ?>
        <?php if ($canReject): ?>
            <button type="button" id="ai_reject" class="btn btn-outline-danger btn-sm modal-dlg ai-decide" data-btn-submit="<?= esc(lang('Accounting_ai.reject_submit'), 'attr') ?>" data-href="<?= esc(base_url('accounting_ai/reject_form/' . (int) $proposal->proposal_id), 'attr') ?>" title="<?= esc(lang('Accounting_ai.reject_title'), 'attr') ?>">
                <span class="bi bi-x-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_ai.reject') ?>
            </button>
        <?php endif; ?>
    </div>

    <section class="mt-4" id="ai_journal" aria-label="<?= esc(lang('Accounting_ai.proposed_journal'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_ai.proposed_journal') ?></h2>
        <p class="text-body-secondary"><?= esc((string) ($proposal->payload_data['description'] ?? '')) ?></p>
        <div class="table-responsive">
            <table class="table table-sm" id="ai_lines_table">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col"><?= lang('Accounting_ai.account') ?></th>
                        <th scope="col"><?= lang('Accounting_ai.line_description') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_journals.debit') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_journals.credit') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proposal->lines as $index => $line): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><bdi dir="ltr"><?= esc((string) $line['account_code']) ?></bdi> <?= esc((string) $line['account_name']) ?></td>
                            <td><?= esc((string) ($line['description'] ?? '')) ?></td>
                            <td class="text-end"><?= bccomp((string) $line['debit'], '0', 4) > 0 ? $amount((string) $line['debit']) : '' ?></td>
                            <td class="text-end"><?= bccomp((string) $line['credit'], '0', 4) > 0 ? $amount((string) $line['credit']) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="3"><?= lang('Accounting_ai.total') ?></th>
                        <td class="text-end"><?= $amount($totalDebit) ?></td>
                        <td class="text-end"><?= $amount($totalCredit) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <section class="mt-4" id="ai_evidence" aria-label="<?= esc(lang('Accounting_ai.evidence'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_ai.evidence') ?></h2>
        <?= view('accounting_ai/evidence', ['rows' => $proposal->evidence_rows, 'canViewJournals' => $canViewJournals]) ?>
    </section>
</div>

<?= view('partial/footer') ?>
