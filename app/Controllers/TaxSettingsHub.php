<?php

namespace App\Controllers;

use App\Libraries\EInvoicing\EInvoiceReadiness;
use App\Libraries\EInvoicing\ReadinessCheck;
use App\Libraries\Navigation\SidebarMenu;
use App\Libraries\Tax\CompanyTaxProfile;
use App\Libraries\Tax\TaxSettingsCards;
use App\Models\Employee;
use App\Models\Module;
use CodeIgniter\HTTP\Exceptions\RedirectException;

/**
 * The center of the tax settings and electronic invoicing (§6.1): a page of links and state that stores nothing. It
 * opens for whoever holds any one of the modules of the screens it links to, and shows each card only to whoever
 * holds its own module, so it gives nobody a screen they could not open before. Whoever holds electronic invoicing
 * also sees what the Saudi rules still miss, read from the readiness checks and never written from here (§7.1).
 */
class TaxSettingsHub extends Secure_Controller
{
    /**
     * @var list<string>
     */
    private array $held;

    public function __construct()
    {
        $employee   = model(Employee::class);
        $personId   = (int) $employee->get_logged_in_employee_info()->person_id;
        $allowed    = array_map(static fn (object $module): string => (string) $module->module_id, model(Module::class)->getAllowedModules($personId)->getResult());
        $this->held = array_values(array_filter(SidebarMenu::TAX_SETTINGS_GATES, static fn (string $module): bool => in_array($module, $allowed, true) && $employee->has_module_grant($module, $personId)));

        if ($this->held === []) {
            throw new RedirectException('no_access/taxes');
        }

        parent::__construct($this->held[0]);
    }

    public function getIndex(): string
    {
        return view('tax_settings/hub', [
            'cards'  => TaxSettingsCards::visibleFor($this->held),
            'saudi'  => (new CompanyTaxProfile())->isSaudiMode(),
            'checks' => in_array('accounting_einvoicing', $this->held, true) ? array_values(array_filter((new EInvoiceReadiness())->checks(), static fn (ReadinessCheck $check): bool => $check->status !== ReadinessCheck::PASS)) : null,
        ]);
    }
}
