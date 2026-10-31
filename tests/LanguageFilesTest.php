<?php

namespace Tests;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\Roles\RoleError;
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
}
