<?php

namespace Tests;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Organization\BranchError;
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
}
