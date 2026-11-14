<?php

namespace Tests;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Documents\CustomFieldType;
use App\Libraries\Documents\DocumentError;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\PartyIdentificationType;
use App\Libraries\Integrations\ApiScope;
use App\Libraries\Integrations\IntegrationError;
use App\Libraries\Integrations\NotificationRuleKind;
use App\Libraries\Integrations\TranslationKind;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;
use App\Libraries\Recurring\RecurrenceTerms;
use App\Libraries\Recurring\RecurrenceUnit;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\ScheduleStatus;
use App\Libraries\SalesOrders\SalesOrderStatus;
use App\Libraries\Roles\RoleError;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockUnitPermissions;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LanguageFilesTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function languageFileProvider(): array
    {
        $cases = [];
        $languageRoot = realpath(__DIR__ . '/../app/Language');

        foreach (glob($languageRoot . '/*', GLOB_ONLYDIR) as $localeDir) {
            foreach (glob($localeDir . '/*.php') as $file) {
                $cases[substr($file, strlen($languageRoot) + 1)] = [$file];
            }
        }

        return $cases;
    }

    #[DataProvider('languageFileProvider')]
    public function testLanguageFile_ParsesToArray(string $file): void
    {
        try {
            $result = include $file;
        } catch (\ParseError $parseError) {
            $this->fail("Failed to parse {$file}: {$parseError->getMessage()}");
        }

        $this->assertIsArray($result, "{$file} must return an array");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function translatedLocaleProvider(): array
    {
        return ['English' => ['en'], 'British English' => ['en-GB'], 'Arabic (Egypt)' => ['ar-EG'], 'Arabic (Lebanon)' => ['ar-LB']];
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingFile_DefinesAMessageForEveryError(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting.php";

        foreach (AccountingError::cases() as $error) {
            $this->assertNotSame('', $messages['error_' . $error->value] ?? '', "{$locale} lacks the message for {$error->value}");
        }

        foreach (PostingError::cases() as $error) {
            $this->assertNotSame('', $messages['posting_' . $error->value] ?? '', "{$locale} lacks the posting message for {$error->value}");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingFile_LabelsTheNumberingAuditEntries(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting.php";

        foreach (['action_numbering_configured', 'entity_document_numbering'] as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testNumberingFile_LabelsEveryKindOfDocumentAndEveryLineOfTheScreen(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Numbering.php";
        $english  = include __DIR__ . '/../app/Language/en/Numbering.php';

        foreach (DocumentType::cases() as $type) {
            $this->assertNotSame('', $messages['type_' . $type->value] ?? '', "{$locale} lacks the label of {$type->value}");
        }

        foreach (array_keys($english) as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testTreasuriesFile_LabelsThePermissionToReconcileBankAccounts(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_treasuries.php";

        $this->assertNotSame('', $messages['bank_reconcile'] ?? '', "{$locale} lacks the label of the permission bank_reconcile");
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testBranchesFile_DefinesAMessageForEveryError(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Branches.php";

        foreach (BranchError::cases() as $error) {
            $this->assertNotSame('', $messages['error_' . $error->value] ?? '', "{$locale} lacks the message for {$error->value}");
        }

        foreach (['branches_manage', 'scope_manage', 'all_branches'] as $permission) {
            $this->assertNotSame('', $messages[$permission] ?? '', "{$locale} lacks the label of the permission {$permission}");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testRolesFile_DefinesAMessageForEveryErrorAndLabelsThePermissionAndTheModule(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Roles.php";
        $module   = include __DIR__ . "/../app/Language/{$locale}/Module.php";
        $audit    = include __DIR__ . "/../app/Language/{$locale}/Accounting.php";

        foreach (RoleError::cases() as $error) {
            $this->assertNotSame('', $messages['error_' . $error->value] ?? '', "{$locale} lacks the message for {$error->value}");
        }

        $this->assertNotSame('', $messages['roles_manage'] ?? '', "{$locale} lacks the label of the permission to manage roles");

        foreach (['roles', 'roles_desc'] as $key) {
            $this->assertNotSame('', $module[$key] ?? '', "{$locale} lacks the module label {$key}");
        }

        foreach (['action_role_created', 'action_role_updated', 'action_role_permissions_changed', 'action_role_deleted', 'action_employee_roles_changed', 'action_employee_record_scope_changed', 'action_default_location_set', 'entity_role', 'entity_employee'] as $key) {
            $this->assertNotSame('', $audit[$key] ?? '', "{$locale} lacks the audit label {$key}");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingRoutingFile_LabelsEveryRowStatusAndPaymentType(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_routing.php";

        foreach (RoutingKey::editable() as $key) {
            $line = 'item_' . str_replace('.', '_', $key->value);

            $this->assertNotSame('', $messages[$line] ?? '', "{$locale} lacks {$line}");
        }

        foreach ([...RoutingKey::GROUPS, 'payments'] as $group) {
            foreach (['title', 'desc'] as $part) {
                $this->assertNotSame('', $messages["{$group}_{$part}"] ?? '', "{$locale} lacks {$group}_{$part}");
            }
        }

        foreach (RoutingType::cases() as $type) {
            $this->assertNotSame('', $messages['type_' . $type->value] ?? '', "{$locale} lacks the label of the routing type {$type->value}");
        }

        $this->assertNotSame('', $messages['type_none'] ?? '', "{$locale} lacks the no routing label");

        foreach (PaymentType::cases() as $type) {
            $this->assertNotSame('', $messages['payment_' . $type->value] ?? '', "{$locale} lacks the payment label of {$type->value}");
        }

        foreach (['complete', 'invalid', 'missing', 'optional', 'unsupported'] as $status) {
            $this->assertNotSame('', $messages['status_' . $status] ?? '', "{$locale} lacks the status {$status}");
        }

        $this->assertNotSame('', $messages['routing_manage'] ?? '', "{$locale} lacks the label of the permission routing_manage");
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingDebitNotesFile_LabelsThePermissionTheEmployeesFormListsUnderTheModule(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_debit_notes.php";
        $module   = include __DIR__ . "/../app/Language/{$locale}/Module.php";

        $this->assertNotSame('', $messages['debit_notes_manage'] ?? '', "{$locale} lacks the label of the permission acc_debit_notes_manage");
        $this->assertNotSame('', $module['accounting_debit_notes'] ?? '', $locale);
        $this->assertNotSame('', $module['accounting_debit_notes_desc'] ?? '', $locale);
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingEInvoicingFile_DefinesAMessageForEveryErrorAndNamesEveryIdentificationScheme(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_einvoicing.php";
        $module   = include __DIR__ . "/../app/Language/{$locale}/Module.php";

        foreach (EInvoiceError::cases() as $error) {
            $this->assertNotSame('', $messages['error_' . $error->value] ?? '', "{$locale} lacks the message for {$error->value}");
        }

        foreach (PartyIdentificationType::cases() as $type) {
            $this->assertNotSame('', $messages['identification_' . $type->value] ?? '', "{$locale} lacks the name of the scheme {$type->value}");
        }

        foreach (['einvoicing_manage', 'candidate_title', 'candidate_text', 'candidate_badge', 'state_not_generated', 'state_generated', 'state_signed', 'submission_not_submitted'] as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
        }

        $this->assertNotSame('', $module['accounting_einvoicing'] ?? '', $locale);
        $this->assertNotSame('', $module['accounting_einvoicing_desc'] ?? '', $locale);
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingEInvoicingFile_KeepsThePlaceholdersOfTheMessagesThatCarryThem(string $locale): void
    {
        $english = include __DIR__ . '/../app/Language/en/Accounting_einvoicing.php';
        $other   = include __DIR__ . "/../app/Language/{$locale}/Accounting_einvoicing.php";

        foreach ($english as $key => $text) {
            preg_match_all('/\{\d+\}/', $text, $placeholders);

            foreach ($placeholders[0] as $placeholder) {
                $this->assertStringContainsString($placeholder, $other[$key], "{$locale} loses {$placeholder} in {$key}");
            }
        }
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testAccountingEInvoicingFile_IsTranslatedIntoArabicAndSaysItIsACandidate(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_einvoicing.php";

        foreach ($messages as $key => $text) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, "{$locale} leaves {$key} untranslated");
        }

        $this->assertStringContainsString('مرشَّحة', $messages['candidate_text']);
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingSettingsFile_DescribesEveryReadinessCode(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_settings.php";

        foreach (ReadinessCode::cases() as $code) {
            $this->assertNotSame('', $messages['readiness_' . $code->value] ?? '', "{$locale} lacks the readiness message for {$code->value}");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testModuleFile_NamesTheRoutingModule(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Module.php";

        $this->assertNotSame('', $messages['accounting_routing'] ?? '', $locale);
        $this->assertNotSame('', $messages['accounting_routing_desc'] ?? '', $locale);
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testSalesFile_ExplainsWhyABranchRefusesASale(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Sales.php";

        $this->assertNotSame('', $messages['branch_not_allowed'] ?? '', $locale);
        $this->assertNotSame('', $messages['mixed_branches'] ?? '', $locale);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function arabicLocaleProvider(): array
    {
        return ['Arabic (Egypt)' => ['ar-EG'], 'Arabic (Lebanon)' => ['ar-LB']];
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testCustomersFile_LabelsTheSidesOfAnOpeningBalanceByWhoOwesWhomAndSpeaksOfOneCustomer(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Customers.php";

        $this->assertSame('العميل مدين لنا', $messages['opening_owes_us'], $locale);
        $this->assertSame('نحن مدينون للعميل', $messages['opening_we_owe'], $locale);
        $this->assertStringNotContainsString('لهم', $messages['opening_balance_note'], $locale);
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testAccountingReceivablesFile_LabelsTheSidesOfAnOpeningBalanceByWhoOwesWhomAndSpeaksOfOneCustomer(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_receivables.php";

        $this->assertSame('العميل مدين لنا', $messages['opening_owes_us'], $locale);
        $this->assertSame('نحن مدينون للعميل', $messages['opening_we_owe'], $locale);
        $this->assertStringNotContainsString('لهم', $messages['opening_note'], $locale);
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingPeriodsFile_PromisesNothingAboutTheReconciliationForADeletedLocationThatHoldsStock(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_periods.php";

        $this->assertDoesNotMatchRegularExpression('/reconciliation|مطابقة|بلا ترحيل/u', $messages['opening_stock_deleted_notice'], $locale);
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testStockDocumentsFile_LabelsThePermissionsTheEmployeesFormListsUnderTheModuleAndEveryLineOfTheScreens(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Stock_documents.php";
        $english  = include __DIR__ . '/../app/Language/en/Stock_documents.php';
        $module   = include __DIR__ . "/../app/Language/{$locale}/Module.php";
        $audit    = include __DIR__ . "/../app/Language/{$locale}/Accounting.php";

        foreach (StockPermissions::ALL as $permission) {
            $this->assertNotSame('', $messages[explode('_', $permission, 2)[1]] ?? '', "{$locale} lacks the label of the permission {$permission}");
        }

        foreach (array_keys($english) as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
        }

        foreach (['stock_documents', 'stock_documents_desc'] as $key) {
            $this->assertNotSame('', $module[$key] ?? '', "{$locale} lacks the module label {$key}");
        }

        foreach (['action_stock_permit_created', 'action_stock_permit_edited', 'action_stock_permit_cancelled', 'action_stocktake_started', 'action_stocktake_counted', 'action_stocktake_items_added', 'action_stocktake_item_removed', 'action_stocktake_approved', 'action_stocktake_cancelled', 'action_stock_request_created', 'action_stock_request_approved', 'action_stock_request_fulfilled', 'action_stock_request_cancelled', 'entity_stock_permit', 'entity_stocktake', 'entity_stock_request', 'source_stock_permit', 'source_stock_transfer_permit', 'source_stock_transfer_permit_receipt', 'source_stocktake'] as $key) {
            $this->assertNotSame('', $audit[$key] ?? '', "{$locale} lacks {$key}");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testStockUnitsFile_LabelsThePermissionsOfTheModuleAndEveryLineOfTheScreens(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Stock_units.php";
        $english  = include __DIR__ . '/../app/Language/en/Stock_units.php';
        $module   = include __DIR__ . "/../app/Language/{$locale}/Module.php";

        foreach (StockUnitPermissions::ALL as $permission) {
            $this->assertNotSame('', $messages[explode('_', $permission, 2)[1]] ?? '', "{$locale} lacks the label of the permission {$permission}");
        }

        foreach (array_keys($english) as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
        }

        foreach (['stock_units', 'stock_units_desc'] as $key) {
            $this->assertNotSame('', $module[$key] ?? '', "{$locale} lacks the module label {$key}");
        }
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testStockUnitsFile_IsTranslatedIntoArabic(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Stock_units.php";
        $english  = include __DIR__ . '/../app/Language/en/Stock_units.php';

        foreach ($english as $key => $line) {
            $this->assertNotSame($line, $messages[$key], "{$locale} shows English for {$key}");
        }
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testStockDocumentsFile_IsTranslatedIntoArabicAndKeepsItsPlaceholders(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Stock_documents.php";
        $english  = include __DIR__ . '/../app/Language/en/Stock_documents.php';

        foreach ($english as $key => $line) {
            $this->assertNotSame($line, $messages[$key], "{$locale} shows English for {$key}");
            $this->assertSame(substr_count($line, '{0}'), substr_count($messages[$key], '{0}'), "{$locale} keeps the placeholder of {$key}");
        }

        $this->assertStringNotContainsString('مستندًا', $messages['count'], "{$locale}: a counted noun in the accusative is wrong for most numbers");
        $this->assertStringNotContainsString('إهمال', $messages['stocktake_manage'], "{$locale}: discarding a stocktake is cancelling it, not neglecting it");
        $this->assertStringContainsString('ذلك الحين', $messages['cancel_approved_note'], $locale);
        $this->assertStringContainsString('يُفعَّلا', $messages['settings_note'], "{$locale}: both of them are turned on");
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testAccountingFile_KeepsThePlaceholdersOfTheStocktakeRefusalsInArabic(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting.php";
        $english  = include __DIR__ . '/../app/Language/en/Accounting.php';

        foreach (['error_stocktake_changed', 'error_stocktake_count_changed', 'error_stocktake_item_open', 'error_stocktake_items_invalid'] as $key) {
            $this->assertNotSame($english[$key], $messages[$key], "{$locale} shows English for {$key}");

            foreach (['{0}', '{1}'] as $placeholder) {
                $this->assertSame(substr_count($english[$key], $placeholder), substr_count($messages[$key], $placeholder), "{$locale} keeps {$placeholder} of {$key}");
            }
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testOrderToCashFiles_NameTheModulesLabelThePermissionsAndHaveEveryLineOfTheScreens(string $locale): void
    {
        $module = include __DIR__ . "/../app/Language/{$locale}/Module.php";

        foreach (['customer_orders', 'recurring_invoices', 'recurring_expenses'] as $name) {
            $this->assertNotSame('', $module[$name] ?? '', "{$locale} lacks the module label {$name}");
            $this->assertNotSame('', $module[$name . '_desc'] ?? '', "{$locale} lacks the module description {$name}");
        }

        foreach (['Customer_orders', 'Sales_orders', 'Recurring', 'Recurring_invoices', 'Recurring_expenses'] as $file) {
            $english  = include __DIR__ . "/../app/Language/en/{$file}.php";
            $messages = include __DIR__ . "/../app/Language/{$locale}/{$file}.php";

            foreach (array_keys($english) as $key) {
                $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$file}.{$key}");
            }
        }

        $orders = include __DIR__ . "/../app/Language/{$locale}/Customer_orders.php";

        foreach (['so_add', 'so_edit', 'so_delete', 'so_view_all', 'so_convert'] as $permission) {
            $this->assertNotSame('', $orders[explode('_', $permission, 2)[1]] ?? '', "{$locale} lacks the label of the permission {$permission}, which the employee form looks up as Customer_orders.{key}");
        }

        foreach (RecurringKind::cases() as $kind) {
            $labels = include __DIR__ . "/../app/Language/{$locale}/Recurring_" . $kind->value . 's.php';

            foreach (['view', 'manage'] as $part) {
                $this->assertNotSame('', $labels[$kind->value . 's_' . $part] ?? '', "{$locale} lacks the label of the permission of {$kind->value} {$part}");
            }
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testOrderToCashFiles_NameEveryStatusUnitFrequencyAndRefusalTheCodeCanShow(string $locale): void
    {
        $orders    = include __DIR__ . "/../app/Language/{$locale}/Sales_orders.php";
        $recurring = include __DIR__ . "/../app/Language/{$locale}/Recurring.php";

        foreach (SalesOrderStatus::cases() as $status) {
            $this->assertNotSame('', $orders[str_replace('Sales_orders.', '', $status->languageKey())] ?? '', "{$locale} lacks the status {$status->value}");
        }

        foreach (ScheduleStatus::cases() as $status) {
            $this->assertNotSame('', $recurring[str_replace('Recurring.', '', $status->languageKey())] ?? '', "{$locale} lacks the status {$status->value}");
            $this->assertNotSame('', $recurring['move_to_' . $status->value] ?? '', "{$locale} lacks the move to {$status->value}");
        }

        foreach (RecurrenceUnit::cases() as $unit) {
            $this->assertNotSame('', $recurring[str_replace('Recurring.', '', $unit->languageKey())] ?? '', "{$locale} lacks the unit {$unit->value}");
        }

        foreach (array_keys(RecurrenceTerms::EXPENSE_FREQUENCIES) as $frequency) {
            $this->assertNotSame('', $recurring['frequency_' . $frequency] ?? '', "{$locale} lacks the frequency {$frequency}");
        }

        foreach (['insufficient_stock', 'discount_invalid', 'save_failed', 'invoice_number_duplicate'] as $code) {
            $this->assertNotSame('', $recurring['refusal_' . $code] ?? '', "{$locale} lacks the refusal {$code}");
        }

        foreach (['manual', 'end_date', 'max_repeats'] as $reason) {
            $this->assertNotSame('', $recurring['ended_' . $reason] ?? '', "{$locale} lacks the reason {$reason}");
        }

        foreach (range(0, 4) as $when) {
            $this->assertNotSame('', $recurring['reminder_when_' . $when] ?? '', "{$locale} lacks the reminder {$when}");
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyLocaleProvider(): array
    {
        $cases = [];

        foreach (glob(realpath(__DIR__ . '/../app/Language') . '/*', GLOB_ONLYDIR) as $localeDir) {
            $cases[basename($localeDir)] = [basename($localeDir)];
        }

        return $cases;
    }

    #[DataProvider('everyLocaleProvider')]
    public function testOrderToCashFiles_KeepTheKeysOfEnglishInTheirOrderAndLeaveWhatIsNotTranslatedEmpty(string $locale): void
    {
        foreach (['Customer_orders', 'Sales_orders', 'Recurring', 'Recurring_invoices', 'Recurring_expenses'] as $file) {
            $english  = include __DIR__ . "/../app/Language/en/{$file}.php";
            $messages = include __DIR__ . "/../app/Language/{$locale}/{$file}.php";

            $this->assertSame(array_keys($english), array_keys($messages), "{$locale} {$file}: the keys of English, in the same order");

            if (! in_array($locale, ['en', 'en-GB', 'ar-EG', 'ar-LB'], true)) {
                $this->assertSame([''], array_values(array_unique($messages)), "{$locale} {$file}: a language with no translation yet falls back to English and never holds English text");
            }
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingOperationalReportsFile_ExplainsItsRulesWithoutNamingAnotherSystem(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_operational_reports.php";

        foreach (['note_profit', 'note_revenue_rule'] as $key) {
            $this->assertNotSame('', $messages[$key], "{$locale} lacks {$key}");
            $this->assertDoesNotMatchRegularExpression('/دفترة|daftra|reference system/iu', $messages[$key], "{$locale} names another system in {$key}");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testPurchaseCycleFiles_NameTheModulesLabelThePermissionsAndHaveEveryLineOfTheScreens(string $locale): void
    {
        $module  = include __DIR__ . "/../app/Language/{$locale}/Module.php";
        $english = include __DIR__ . '/../app/Language/en/Purchase_cycle.php';
        $screens = include __DIR__ . "/../app/Language/{$locale}/Purchase_cycle.php";

        foreach (array_keys($english) as $key) {
            $this->assertNotSame('', $screens[$key] ?? '', "{$locale} lacks Purchase_cycle.{$key}");
        }

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $this->assertNotSame('', $module[$kind->module()] ?? '', "{$locale} lacks the module label {$kind->module()}");
            $this->assertNotSame('', $module[$kind->module() . '_desc'] ?? '', "{$locale} lacks the module description {$kind->module()}");

            $permissions = include __DIR__ . "/../app/Language/{$locale}/" . ucfirst($kind->module()) . '.php';

            foreach (['add', 'edit', 'delete', 'view_all', 'convert'] as $action) {
                $this->assertNotSame('', $permissions[$action] ?? '', "{$locale} lacks the label of the permission {$kind->permission($action)}, which the employee form looks up as " . ucfirst($kind->module()) . ".{$action}");
            }

            foreach ([$kind->labelKey(), $kind->dueLabelKey(), 'Purchase_cycle.title_' . $kind->value, 'Purchase_cycle.new_' . $kind->value, 'Purchase_cycle.edit_' . $kind->value, 'Purchase_cycle.setting_manual_' . $kind->value] as $line) {
                $this->assertNotSame('', $screens[substr($line, strlen('Purchase_cycle.'))] ?? '', "{$locale} lacks {$line}");
            }

            if ($kind->next() !== null) {
                $this->assertNotSame('', $screens['convert_to_' . $kind->next()->value] ?? '', "{$locale} lacks the conversion to {$kind->next()->value}");
            }
        }

        foreach (PurchaseDocumentStatus::cases() as $status) {
            $this->assertNotSame('', $screens[substr($status->languageKey(), strlen('Purchase_cycle.'))] ?? '', "{$locale} lacks the status {$status->value}");

            if ($status->isLabel()) {
                $this->assertNotSame('', $screens['move_to_' . $status->value] ?? '', "{$locale} lacks the move to {$status->value}");
            }
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testPurchaseCycleFiles_NameEveryAuditActionAndKindOfRecordTheCycleWrites(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting.php";

        foreach (['created', 'updated', 'cancelled', 'status_changed', 'converted', 'received', 'receipt_changed', 'receipt_released', 'settings_updated'] as $action) {
            $this->assertNotSame('', $messages['action_' . $action] ?? '', "{$locale} lacks the audit action {$action}");
        }

        foreach ([...array_column(PurchaseDocumentKind::cases(), 'value'), 'purchase_cycle_settings'] as $entity) {
            $this->assertNotSame('', $messages['entity_' . $entity] ?? '', "{$locale} lacks the audit record kind {$entity}");
        }
    }

    #[DataProvider('everyLocaleProvider')]
    public function testPurchaseCycleFiles_KeepTheKeysOfEnglishInTheirOrderAndLeaveWhatIsNotTranslatedEmpty(string $locale): void
    {
        foreach (['Purchase_cycle', 'Purchase_requests', 'Quotation_requests', 'Supplier_quotations', 'Purchase_orders'] as $file) {
            $english  = include __DIR__ . "/../app/Language/en/{$file}.php";
            $messages = include __DIR__ . "/../app/Language/{$locale}/{$file}.php";

            $this->assertSame(array_keys($english), array_keys($messages), "{$locale} {$file}: the keys of English, in the same order");

            if (! in_array($locale, ['en', 'en-GB', 'ar-EG', 'ar-LB'], true)) {
                $this->assertSame([''], array_values(array_unique($messages)), "{$locale} {$file}: a language with no translation yet falls back to English and never holds English text");
            }
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testPurchaseCycleFiles_KeepThePlaceholdersOfTheMessagesOfEnglishInTheArabicOnes(string $locale): void
    {
        $english  = include __DIR__ . '/../app/Language/en/Accounting.php';
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting.php";

        foreach (array_keys($english) as $key) {
            if (! str_starts_with($key, 'error_purchase_')) {
                continue;
            }

            preg_match_all('/\{\d+\}/', $english[$key], $wanted);
            preg_match_all('/\{\d+\}/', $messages[$key], $given);

            $this->assertEqualsCanonicalizing($wanted[0], $given[0], "{$locale} {$key}: the placeholders are kept");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testDocumentsFile_DefinesAMessageForEveryErrorOfTheDocuments(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Documents.php";

        foreach (DocumentError::cases() as $error) {
            $this->assertNotSame('', $messages['error_' . $error->value] ?? '', "{$locale} lacks the message for {$error->value}");
        }
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testDocumentsFile_IsTranslatedIntoArabicWithoutEnglishText(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Documents.php";
        $english  = include __DIR__ . '/../app/Language/en/Documents.php';

        foreach ($english as $key => $line) {
            $this->assertNotSame($line, $messages[$key], "{$locale} shows English for {$key}");
            $this->assertNotSame('', $messages[$key], "{$locale} lacks {$key}");
        }
    }

    #[DataProvider('everyLocaleProvider')]
    public function testDocumentsFile_KeepsTheKeysOfEnglishInTheirOrderAndLeavesWhatIsNotTranslatedEmpty(string $locale): void
    {
        $english  = include __DIR__ . '/../app/Language/en/Documents.php';
        $messages = include __DIR__ . "/../app/Language/{$locale}/Documents.php";

        $this->assertSame(array_keys($english), array_keys($messages), $locale);
        $keys = array_keys($english);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'The keys of the English file are in alphabetical order.');

        if (! in_array($locale, ['en', 'en-GB', 'ar-EG', 'ar-LB'], true)) {
            $this->assertSame([''], array_values(array_unique($messages)), "{$locale} holds text that is not translated");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testCustomFieldsFiles_NameTheModuleLabelThePermissionAndTheTypesOfField(string $locale): void
    {
        $module   = include __DIR__ . "/../app/Language/{$locale}/Module.php";
        $messages = include __DIR__ . "/../app/Language/{$locale}/Custom_fields.php";
        $english  = include __DIR__ . '/../app/Language/en/Custom_fields.php';

        foreach (['custom_fields', 'custom_fields_desc'] as $key) {
            $this->assertNotSame('', $module[$key] ?? '', "{$locale} lacks the module label {$key}");
        }

        $this->assertNotSame('', $messages['custom_fields_manage'] ?? '', "{$locale} lacks the label of the permission acc_custom_fields_manage");

        foreach (CustomFieldType::cases() as $type) {
            $this->assertNotSame('', $messages[str_replace('Custom_fields.', '', $type->languageKey())] ?? '', "{$locale} lacks the type {$type->value}");
        }

        foreach (array_keys($english) as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
        }

        foreach (['entity_item', 'entity_sale'] as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
        }
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testCustomFieldsFile_IsTranslatedIntoArabicWithoutEnglishText(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Custom_fields.php";
        $english  = include __DIR__ . '/../app/Language/en/Custom_fields.php';

        foreach ($english as $key => $line) {
            $this->assertNotSame($line, $messages[$key], "{$locale} shows English for {$key}");
        }
    }

    #[DataProvider('everyLocaleProvider')]
    public function testCustomFieldsFile_KeepsTheKeysOfEnglishInTheirOrderAndLeavesWhatIsNotTranslatedEmpty(string $locale): void
    {
        $english  = include __DIR__ . '/../app/Language/en/Custom_fields.php';
        $messages = include __DIR__ . "/../app/Language/{$locale}/Custom_fields.php";
        $keys     = array_keys($english);
        $sorted   = $keys;
        sort($sorted);

        $this->assertSame($keys, array_keys($messages), $locale);
        $this->assertSame($sorted, $keys, 'The keys of the English file are in alphabetical order.');

        if (! in_array($locale, ['en', 'en-GB', 'ar-EG', 'ar-LB'], true)) {
            $this->assertSame([''], array_values(array_unique($messages)), "{$locale} holds text that is not translated");
        }
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testAccountingPickerFile_IsTranslatedIntoArabicAndSpeaksTheNumberOfResultsWithoutACountedNoun(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting_picker.php";
        $english  = include __DIR__ . '/../app/Language/en/Accounting_picker.php';

        foreach ($english as $key => $line) {
            $this->assertNotSame($line, $messages[$key], "{$locale} shows English for {$key}");
            $this->assertSame(substr_count($line, '{0}'), substr_count($messages[$key], '{0}'), "{$locale} keeps the placeholder of {$key}");
        }

        $this->assertStringContainsString('{0}', $messages['count']);
        $this->assertDoesNotMatchRegularExpression('/\{0\}\s*نتيجة/u', $messages['count'], "{$locale}: a counted noun after the number is wrong for most numbers");
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testIntegrationsFiles_NameTheModuleLabelsThePermissionsAndEveryWordTheScreensUse(string $locale): void
    {
        $module   = include __DIR__ . "/../app/Language/{$locale}/Module.php";
        $messages = include __DIR__ . "/../app/Language/{$locale}/Integrations.php";
        $english  = include __DIR__ . '/../app/Language/en/Integrations.php';

        foreach (['integrations', 'integrations_desc', 'notifications', 'notifications_desc'] as $key) {
            $this->assertNotSame('', $module[$key] ?? '', "{$locale} lacks the module label {$key}");
        }

        foreach (['integrations_manage', 'keys_manage'] as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks the label of a permission: {$key}");
        }

        foreach (IntegrationError::cases() as $error) {
            $this->assertNotSame('', $messages['error_' . $error->value] ?? '', "{$locale} lacks the message for {$error->value}");
        }

        foreach (ApiScope::cases() as $scope) {
            $this->assertNotSame('', $messages[str_replace('Integrations.', '', $scope->languageKey())] ?? '', "{$locale} lacks the scope {$scope->value}");
        }

        foreach (NotificationRuleKind::cases() as $kind) {
            foreach ([$kind->languageKey(), $kind->noticeKey()] as $key) {
                $this->assertNotSame('', $messages[str_replace('Integrations.', '', $key)] ?? '', "{$locale} lacks {$key}");
            }
        }

        foreach (TranslationKind::cases() as $kind) {
            $this->assertNotSame('', $messages[str_replace('Integrations.', '', $kind->languageKey())] ?? '', "{$locale} lacks {$kind->languageKey()}");
        }

        foreach (array_keys($english) as $key) {
            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} lacks {$key}");
        }
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testIntegrationsFile_IsTranslatedIntoArabicWithoutEnglishTextAndKeepsEveryPlaceholder(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Integrations.php";
        $english  = include __DIR__ . '/../app/Language/en/Integrations.php';

        foreach ($english as $key => $line) {
            $this->assertNotSame($line, $messages[$key], "{$locale} shows English for {$key}");
            $this->assertNotSame('', $messages[$key], "{$locale} lacks {$key}");

            preg_match_all('/\{[A-Za-z0-9_]+\}/', $line, $placeholders);

            foreach ($placeholders[0] as $placeholder) {
                $this->assertStringContainsString($placeholder, $messages[$key], "{$locale} lost {$placeholder} in {$key}");
            }
        }
    }

    #[DataProvider('everyLocaleProvider')]
    public function testIntegrationsFile_KeepsTheKeysOfEnglishInTheirOrderAndLeavesWhatIsNotTranslatedEmpty(string $locale): void
    {
        $english  = include __DIR__ . '/../app/Language/en/Integrations.php';
        $messages = include __DIR__ . "/../app/Language/{$locale}/Integrations.php";
        $keys     = array_keys($english);
        $sorted   = $keys;
        sort($sorted);

        $this->assertSame($keys, array_keys($messages), $locale);
        $this->assertSame($sorted, $keys, 'The keys of the English file are in alphabetical order.');

        if (! in_array($locale, ['en', 'en-GB', 'ar-EG', 'ar-LB'], true)) {
            $this->assertSame([''], array_values(array_unique($messages)), "{$locale} holds text that is not translated");
        }
    }

    #[DataProvider('translatedLocaleProvider')]
    public function testAccountingFile_NamesTheActionsAndTheRecordsOfTheIntegrationsInTheAuditLog(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Accounting.php";

        foreach (['api_auth_failed', 'api_auth_throttled', 'api_key_created', 'api_key_revoked', 'entity_translation_deleted', 'entity_translation_saved', 'integration_options_updated', 'notification_rule_activated', 'notification_rule_created', 'notification_rule_deactivated', 'notification_rule_deleted', 'notification_rule_updated', 'payment_methods_updated'] as $action) {
            $this->assertNotSame('', $messages['action_' . $action] ?? '', "{$locale} lacks the audit action {$action}");
        }

        foreach (['api_key', 'entity_translation', 'integration', 'notification_rule', 'payment_methods'] as $entity) {
            $this->assertNotSame('', $messages['entity_' . $entity] ?? '', "{$locale} lacks the audit record {$entity}");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function emptyTableMessageProvider(): array
    {
        return [
            'roles'              => ['Roles', 'no_roles_to_display'],
            'price lists'        => ['Price_lists', 'no_price_lists_to_display'],
            'revenue categories' => ['Revenue_categories', 'no_revenue_categories_to_display'],
            'custom fields'      => ['Custom_fields', 'no_custom_fields_to_display'],
        ];
    }

    #[DataProvider('emptyTableMessageProvider')]
    public function testTableFiles_SayWhenTheirTableIsEmpty(string $file, string $key): void
    {
        foreach (['en', 'en-GB', 'ar-EG', 'ar-LB'] as $locale) {
            $messages = include __DIR__ . "/../app/Language/{$locale}/{$file}.php";

            $this->assertNotSame('', $messages[$key] ?? '', "{$locale} {$file} lacks {$key}, which the empty table shows as it is");
        }
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testLoginFile_SeparatesTheNameOfTheProductFromTheWordBeforeIt(string $locale): void
    {
        $messages = include __DIR__ . "/../app/Language/{$locale}/Login.php";

        foreach (['welcome', 'migration_needed'] as $key) {
            $this->assertMatchesRegularExpression('/\p{Arabic} \{0\}/u', $messages[$key], "{$locale} {$key} runs the name of the product into the word before it");
        }
    }
}
