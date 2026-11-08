<?php
/**
 * @var list<object>                                                    $rows
 * @var int                                                             $total
 * @var int                                                             $offset
 * @var int                                                             $page_size
 * @var array{status: string, kind: string, from: string, to: string}   $filters
 * @var list<string>                                                    $kinds
 * @var array<string, int>                                              $counts
 * @var bool                                                            $enabled
 * @var bool                                                            $scopeNotice
 * @var string|null                                                     $notice
 */

use App\Libraries\Accounting\Ai\ProposalStatus;

$query = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#ai_propose').on('click', function() {
            $.post(<?= json_encode(base_url('accounting_ai/propose')) ?>, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.href = response.redirect;
                }
            }, 'json');
        });
    });
</script>

<?= view('accounting_ai/tabs', ['active' => 'proposals']) ?>

<div class="container-fluid py-3" id="ai_proposals">
    <div class="d-flex align-items-center gap-2 mb-3">
        <h1 class="h5 me-auto mb-0"><?= lang('Accounting_ai.proposals_title') ?></h1>
        <?php if ($enabled): ?>
            <button type="button" class="btn btn-primary btn-sm" id="ai_propose"><span class="bi bi-lightbulb" aria-hidden="true">&nbsp;</span><?= lang('Accounting_ai.run_proposers') ?></button>
        <?php endif; ?>
    </div>

    <?php if (! $enabled): ?>
        <div class="alert alert-warning" id="ai_off" role="alert"><?= lang('Accounting_ai.off_notice') ?></div>
    <?php endif; ?>

    <?php if ($scopeNotice): ?>
        <div class="alert alert-secondary" id="ai_scope"><?= lang('Accounting_ai.scope_notice') ?></div>
    <?php endif; ?>

    <p class="text-body-secondary small" id="ai_agent_note"><?= lang('Accounting_ai.proposes_only') ?></p>

    <ul class="list-inline" id="ai_counts">
        <?php foreach (ProposalStatus::cases() as $status): ?>
            <li class="list-inline-item"><span class="badge text-bg-light"><?= lang('Accounting_ai.status_' . $status->value) ?>: <?= (int) ($counts[$status->value] ?? 0) ?></span></li>
        <?php endforeach; ?>
    </ul>

    <form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="ai_proposal_filters">
        <div>
            <label class="form-label" for="f_status"><?= lang('Accounting_ai.status') ?></label>
            <select class="form-select form-select-sm" id="f_status" name="status">
                <option value=""><?= lang('Accounting_ai.all') ?></option>
                <?php foreach (ProposalStatus::cases() as $status): ?>
                    <option value="<?= esc($status->value, 'attr') ?>"<?= $filters['status'] === $status->value ? ' selected' : '' ?>><?= lang('Accounting_ai.status_' . $status->value) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="f_kind"><?= lang('Accounting_ai.kind') ?></label>
            <select class="form-select form-select-sm" id="f_kind" name="kind">
                <option value=""><?= lang('Accounting_ai.all') ?></option>
                <?php foreach ($kinds as $kind): ?>
                    <?php $kindKey = 'Accounting_ai.kind_' . $kind; $kindText = lang($kindKey); ?>
                    <option value="<?= esc($kind, 'attr') ?>"<?= $filters['kind'] === $kind ? ' selected' : '' ?>><?= esc($kindText === $kindKey ? $kind : $kindText) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="f_from"><?= lang('Accounting_ai.date_from') ?></label>
            <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_from" name="from" value="<?= esc($filters['from'], 'attr') ?>">
        </div>
        <div>
            <label class="form-label" for="f_to"><?= lang('Accounting_ai.date_to') ?></label>
            <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_to" name="to" value="<?= esc($filters['to'], 'attr') ?>">
        </div>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_ai.filter') ?></button>
    </form>

    <div class="table-responsive">
        <table class="table table-sm align-middle" id="ai_proposals_table">
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col"><?= lang('Accounting_ai.title') ?></th>
                    <th scope="col"><?= lang('Accounting_ai.kind') ?></th>
                    <th scope="col"><?= lang('Accounting_ai.branch') ?></th>
                    <th scope="col"><?= lang('Accounting_ai.proposal_date') ?></th>
                    <th scope="col"><?= lang('Accounting_ai.status') ?></th>
                    <th scope="col"><?= lang('Accounting_ai.created_at') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-body-secondary" id="ai_proposals_empty"><?= lang('Accounting_ai.no_proposals') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $expired   = $row->status === 'proposed' && (int) $row->is_expired === 1;
                    $kindKey   = 'Accounting_ai.kind_' . $row->kind;
                    $kindText  = lang($kindKey);
                    $badge     = match ($row->status) {
                        'approved'   => 'text-bg-success',
                        'proposed'   => $expired ? 'text-bg-secondary' : 'text-bg-warning',
                        'rejected'   => 'text-bg-danger',
                        default      => 'text-bg-secondary',
                    };
                    ?>
                    <tr id="ai_proposal_<?= (int) $row->proposal_id ?>">
                        <td><a href="<?= esc(base_url('accounting_ai/proposal/' . (int) $row->proposal_id), 'attr') ?>"><bdi dir="ltr"><?= (int) $row->proposal_id ?></bdi></a></td>
                        <td><?= esc((string) $row->title_text) ?></td>
                        <td><?= esc($kindText === $kindKey ? (string) $row->kind : $kindText) ?></td>
                        <td><bdi dir="ltr"><?= esc((string) $row->branch_code) ?></bdi></td>
                        <td><bdi dir="ltr"><?= esc((string) $row->proposal_date) ?></bdi></td>
                        <td><span class="badge <?= $badge ?>"><?= lang('Accounting_ai.status_' . ($expired ? 'expired' : $row->status)) ?></span></td>
                        <td><bdi dir="ltr"><?= esc((string) $row->created_at) ?></bdi></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <nav class="d-flex gap-2 align-items-center" aria-label="<?= esc(lang('Accounting_ai.proposals_title'), 'attr') ?>">
        <span class="text-body-secondary small"><?= lang('Accounting_ai.count', [$total]) ?></span>
        <?php if ($offset > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_ai?' . $query(['offset' => max(0, $offset - $page_size)])), 'attr') ?>"><?= lang('Accounting_ai.previous') ?></a>
        <?php endif; ?>
        <?php if ($offset + $page_size < $total): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_ai?' . $query(['offset' => $offset + $page_size])), 'attr') ?>"><?= lang('Accounting_ai.next') ?></a>
        <?php endif; ?>
    </nav>
</div>

<?= view('partial/footer') ?>
