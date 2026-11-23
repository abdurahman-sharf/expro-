<?php
/**
 * @var object                    $cheque
 * @var string                    $direction
 * @var string                    $number
 * @var list<object>              $events
 * @var list<object>              $journals
 * @var bool                      $journalLinks
 * @var bool                      $canManage
 * @var list<string>              $actions
 * @var callable(string): string  $amount
 * @var callable(?string): string $date
 * @var callable(string): string  $sourceLabel
 */

use App\Libraries\Accounting\ChequeStatus;

$id = (int) $cheque->cheque_id;
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm me-auto" href="<?= esc(base_url('accounting_cheques?direction=' . $direction), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cheques.back') ?></a>
    <?php if ($canManage): ?>
        <?php foreach (['deposit', 'collect', 'reject', 'deliver'] as $action): ?>
            <?php if (in_array($action, $actions, true)): ?>
                <button class="btn btn-outline-primary btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url("accounting_cheques/action_form/{$action}/{$id}"), 'attr') ?>" title="<?= esc(lang('Accounting_cheques.action_' . $action), 'attr') ?>"><?= lang('Accounting_cheques.action_' . $action) ?></button>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach (['undo', 'cancel', 'delete'] as $action): ?>
            <?php if (in_array($action, $actions, true)): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm acc-action" data-acc-action="<?= $action ?>" data-confirm="<?= esc(lang('Accounting_cheques.confirm_' . $action), 'attr') ?>"><?= lang('Accounting_cheques.action_' . $action) ?></button>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('.acc-action').on('click', function() {
            const action = $(this).data('acc-action');

            appConfirm($(this).data('confirm'), {tone: 'danger'}).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }

                $.post('accounting_cheques/' + action + '/<?= $id ?>', {}, function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (!response.success) {
                        return;
                    }

                    if (action === 'delete') {
                        window.location.href = '<?= esc(base_url('accounting_cheques?direction=' . $direction), 'js') ?>';
                    } else {
                        window.location.reload();
                    }
                }, 'json');
            });
        });
    });
</script>

<div class="px-2">
    <h5 class="mb-3"><bdi dir="ltr"><?= esc($number) ?></bdi> <span class="badge text-bg-secondary"><?= esc(lang(ChequeStatus::from($cheque->status)->labelKey())) ?></span></h5>

    <dl class="row" id="cheque_details">
        <dt class="col-sm-3"><?= lang($direction === 'received' ? 'Accounting_cheques.customer' : 'Accounting_cheques.supplier') ?></dt>
        <dd class="col-sm-9"><?= esc($cheque->party_name) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_cheques.cheque_number') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc($cheque->cheque_number) ?></bdi></dd>
        <dt class="col-sm-3"><?= lang('Accounting_cheques.amount') ?></dt>
        <dd class="col-sm-9"><?= $amount((string) $cheque->amount) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_cheques.bank') ?></dt>
        <dd class="col-sm-9"><?= esc($cheque->treasury_name) ?><?= $cheque->book_name === null ? '' : ' · ' . esc($cheque->book_name) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_cheques.issue_date') ?></dt>
        <dd class="col-sm-9"><?= esc($date($cheque->issue_date)) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_cheques.due_date') ?></dt>
        <dd class="col-sm-9"><?= esc($date($cheque->due_date)) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_cheques.branch') ?></dt>
        <dd class="col-sm-9"><?= esc($cheque->branch_code) ?></dd>
        <?php if ($cheque->drawer_name !== ''): ?>
            <dt class="col-sm-3"><?= lang('Accounting_cheques.drawer_name') ?></dt>
            <dd class="col-sm-9"><?= esc($cheque->drawer_name) ?></dd>
        <?php endif; ?>
        <?php if ($cheque->endorsed_to !== ''): ?>
            <dt class="col-sm-3"><?= lang('Accounting_cheques.endorsed_to') ?></dt>
            <dd class="col-sm-9"><?= esc($cheque->endorsed_to) ?></dd>
        <?php endif; ?>
        <?php if ($cheque->description !== ''): ?>
            <dt class="col-sm-3"><?= lang('Accounting_cheques.description') ?></dt>
            <dd class="col-sm-9"><?= esc($cheque->description) ?></dd>
        <?php endif; ?>
    </dl>

    <h6><?= lang('Accounting_cheques.history') ?></h6>
    <div class="table-responsive mb-3">
        <table class="table table-sm" id="cheque_events">
            <thead><tr>
                <th><?= lang('Accounting_cheques.action_date') ?></th>
                <th><?= lang('Accounting_cheques.change') ?></th>
                <th><?= lang('Accounting_cheques.deposit_fees') ?></th>
                <th><?= lang('Accounting_cheques.note') ?></th>
                <th><?= lang('Accounting_cheques.status') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr<?= $event->undone_at === null ? '' : ' class="text-decoration-line-through text-body-secondary"' ?>>
                    <td><?= esc($date($event->event_date)) ?></td>
                    <td><?= esc(lang('Accounting_cheques.event_' . $event->transition)) ?></td>
                    <td><?= bccomp((string) $event->fees, '0', 4) === 0 ? '' : $amount((string) $event->fees) ?></td>
                    <td><?= esc($event->note) ?></td>
                    <td><?= esc(lang(ChequeStatus::from($event->to_status)->labelKey())) ?><?= $event->undone_at === null ? '' : ' (' . esc(lang('Accounting_cheques.undone')) . ')' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h6><?= lang('Accounting_cheques.journals') ?></h6>
    <div class="table-responsive">
        <table class="table table-sm" id="cheque_journals">
            <thead><tr>
                <th><?= lang('Accounting_cheques.journal') ?></th>
                <th><?= lang('Accounting_cheques.action_date') ?></th>
                <th><?= lang('Accounting_cheques.journal_source') ?></th>
                <th><?= lang('Accounting_cheques.amount') ?></th>
                <th><?= lang('Accounting_cheques.status') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($journals as $journal): ?>
                <tr>
                    <td><?= $journalLinks ? anchor('accounting_journals/view/' . (int) $journal->journal_id, 'A-' . esc((string) $journal->number), ['dir' => 'ltr']) : '<bdi dir="ltr">A-' . esc((string) $journal->number) . '</bdi>' ?></td>
                    <td><?= esc($date($journal->journal_date)) ?></td>
                    <td><?= esc($sourceLabel($journal->source_type)) ?> (<?= (int) $journal->revision ?>)</td>
                    <td><?= $amount((string) $journal->total_debit) ?></td>
                    <td><?= esc(lang('Accounting.state_' . $journal->status)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= view('partial/footer') ?>
