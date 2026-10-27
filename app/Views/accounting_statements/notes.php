<?php
/**
 * @var ReportScope $scope
 * @var string|null $error
 * @var string|null $scopeLabel
 */

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;

?>
<?php if ($error !== null): ?>
    <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
<?php endif; ?>

<?php if ($scopeLabel !== null): ?>
    <?php if ($scope->kind === ReportScopeKind::Partial): ?>
        <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scopeLabel) ?></p>
    <?php else: ?>
        <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scopeLabel) ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($scope->isEmpty()): ?>
    <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
<?php endif; ?>
