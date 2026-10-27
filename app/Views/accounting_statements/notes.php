<?php
/**
 * @var ReportScope   $scope
 * @var string|null   $error
 * @var string|null   $scope_label
 */

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;
?>
<?php if ($error !== null): ?>
    <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
<?php endif; ?>

<?php if ($scope_label !== null): ?>
    <?php if ($scope->kind === ReportScopeKind::Partial): ?>
        <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scope_label) ?></p>
    <?php else: ?>
        <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scope_label) ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($scope->isEmpty()): ?>
    <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
<?php endif; ?>
