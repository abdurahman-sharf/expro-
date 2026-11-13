<?php
/**
 * @var list<object>                                                                    $rows
 * @var int                                                                             $total
 * @var int                                                                             $offset
 * @var int                                                                             $page_size
 * @var array{status: string, rule: string, severity: string, from: string, to: string} $filters
 * @var list<string>                                                                    $rules
 * @var array<string, int>                                                              $counts
 * @var bool                                                                            $enabled
 * @var bool                                                                            $canViewJournals
 * @var string                                                                          $csvUrl
 * @var bool                                                                            $scopeNotice
 * @var string|null                                                                     $notice
 */

use App\Libraries\Accounting\Ai\FindingService;

$query = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('.ai-finding-decide').on('click', function() {
            dialog_support.init(this);
        });

        $('#ai_scan').on('click', function() {
            $.post(<?= json_encode(base_url('accounting_ai/scan')) ?>, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.href = response.redirect;
                }
            }, 'json');
        });
    });
</script>

<?= view('accounting_ai/tabs', ['active' => 'findings']) ?>

<div class="container-fluid py-3" id="ai_findings">
    <div class="d-flex align-items-center gap-2 mb-3">
        <h1 class="h5 me-auto mb-0"><?= lang('Accounting_ai.findings_title') ?></h1>
        <a class="btn btn-outline-secondary btn-sm" id="ai_findings_csv" href="<?= esc($csvUrl, 'attr') ?>"><span class="bi bi-download" aria-hidden="true">&nbsp;</span><?= lang('Accounting_ai.csv') ?></a>
        <?php if ($enabled): ?>
            <button type="button" class="btn btn-primary btn-sm" id="ai_scan"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_ai.run_scan') ?></button>
        <?php endif; ?>
    </div>

    <?php if (! $enabled): ?>
        <div class="alert alert-warning" id="ai_off" role="alert"><?= lang('Accounting_ai.off_notice') ?></div>
    <?php endif; ?>

    <?php if ($scopeNotice): ?>
        <div class="alert alert-secondary" id="ai_scope"><?= lang('Accounting_ai.scope_notice') ?></div>
    <?php endif; ?>

    <p class="text-body-secondary small" id="ai_findings_note"><?= lang('Accounting_ai.findings_read_only') ?></p>

    <ul class="list-inline" id="ai_finding_counts">
        <?php foreach (FindingService::STATUSES as $status): ?>
            <li class="list-inline-item"><span class="badge text-bg-light"><?= lang('Accounting_ai.finding_status_' . $status) ?>: <?= (int) ($counts[$status] ?? 0) ?></span></li>
        <?php endforeach; ?>
    </ul>

    <form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="ai_finding_filters">
        <div>
            <label class="form-label" for="f_status"><?= lang('Accounting_ai.status') ?></label>
            <select class="form-select form-select-sm" id="f_status" name="status">
                <option value=""><?= lang('Accounting_ai.all') ?></option>
                <?php foreach (FindingService::STATUSES as $status): ?>
                    <option value="<?= esc($status, 'attr') ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= lang('Accounting_ai.finding_status_' . $status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="f_rule"><?= lang('Accounting_ai.rule') ?></label>
            <select class="form-select form-select-sm" id="f_rule" name="rule">
                <option value=""><?= lang('Accounting_ai.all') ?></option>
                <?php foreach ($rules as $rule): ?>
                    <option value="<?= esc($rule, 'attr') ?>"<?= $filters['rule'] === $rule ? ' selected' : '' ?>><?= esc($rule) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="f_severity"><?= lang('Accounting_ai.severity') ?></label>
            <select class="form-select form-select-sm" id="f_severity" name="severity">
                <option value=""><?= lang('Accounting_ai.all') ?></option>
                <?php foreach (FindingService::SEVERITIES as $severity): ?>
                    <option value="<?= esc($severity, 'attr') ?>"<?= $filters['severity'] === $severity ? ' selected' : '' ?>><?= lang('Accounting_ai.severity_' . $severity) ?></option>
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

    <?php if ($rows === []): ?>
        <p class="text-body-secondary" id="ai_findings_empty"><?= lang('Accounting_ai.no_findings') ?></p>
    <?php endif; ?>

    <?php foreach ($rows as $row): ?>
        <?php
        $badge = match ($row->severity) {
            'high'   => 'text-bg-danger',
            'medium' => 'text-bg-warning',
            default  => 'text-bg-secondary',
        };
        ?>
        <article class="card mb-3 ai-finding" id="ai_finding_<?= (int) $row->finding_id ?>" data-status="<?= esc($row->status, 'attr') ?>" data-rule="<?= esc($row->rule_code, 'attr') ?>">
            <div class="card-body">
                <h2 class="h6">
                    <span class="badge <?= $badge ?>"><?= lang('Accounting_ai.severity_' . $row->severity) ?></span>
                    <bdi dir="ltr"><?= esc($row->rule_code) ?></bdi>
                    <span class="badge text-bg-light"><?= lang('Accounting_ai.finding_status_' . $row->status) ?></span>
                    <small class="text-body-secondary"><bdi dir="ltr"><?= esc((string) $row->branch_code) ?> <?= esc((string) $row->occurred_on) ?></bdi></small>
                </h2>
                <p class="mb-2"><?= esc((string) $row->message) ?></p>
                <?= view('accounting_ai/evidence', ['rows' => $row->evidence_rows, 'canViewJournals' => $canViewJournals]) ?>
                <?php if ($row->status !== 'open'): ?>
                    <p class="small mb-0 ai-finding-decision"><?= esc(lang('Accounting_ai.decision_line', [(string) $row->decider_name, (string) $row->decided_at, (string) $row->decision_reason])) ?></p>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm modal-dlg ai-finding-decide" data-btn-submit="<?= esc(lang('Accounting_ai.decide_submit'), 'attr') ?>" data-href="<?= esc(base_url('accounting_ai/decide_form/' . (int) $row->finding_id), 'attr') ?>" title="<?= esc(lang('Accounting_ai.decide_title'), 'attr') ?>"><?= lang('Accounting_ai.decide') ?></button>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>

    <nav class="d-flex gap-2 align-items-center" aria-label="<?= esc(lang('Accounting_ai.findings_title'), 'attr') ?>">
        <span class="text-body-secondary small"><?= lang('Accounting_ai.count', [$total]) ?></span>
        <?php if ($offset > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_ai/findings?' . $query(['offset' => max(0, $offset - $page_size)])), 'attr') ?>"><?= lang('Accounting_ai.previous') ?></a>
        <?php endif; ?>
        <?php if ($offset + $page_size < $total): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_ai/findings?' . $query(['offset' => $offset + $page_size])), 'attr') ?>"><?= lang('Accounting_ai.next') ?></a>
        <?php endif; ?>
    </nav>
</div>

<?= view('partial/footer') ?>
