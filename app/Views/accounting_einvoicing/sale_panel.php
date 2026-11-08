<?php
/**
 * @var int $sale_id
 */

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoicePanel;
use App\Libraries\EInvoicing\EInvoiceRules;
use App\Models\Employee;

$einvoice = (new EInvoicePanel())->forSale((int) $sale_id, model(Employee::class)->has_grant(AccountingGuard::EINVOICING_MANAGE, session('person_id')));
?>
<?php if ($einvoice !== null): ?>
    <?= view('accounting_einvoicing/status_panel', ['einvoice' => $einvoice, 'sale_id' => (int) $sale_id, 'reasons' => EInvoiceRules::NOTE_REASONS]) ?>
<?php endif; ?>
