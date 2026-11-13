<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AccountingMessageTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{PostingError}>
     */
    public static function postingErrorProvider(): array
    {
        $cases = [];

        foreach (PostingError::cases() as $error) {
            $cases[$error->value] = [$error];
        }

        return $cases;
    }

    /**
     * @return array<string, array{AccountingError}>
     */
    public static function accountingErrorProvider(): array
    {
        $cases = [];

        foreach (AccountingError::cases() as $error) {
            $cases[$error->value] = [$error];
        }

        return $cases;
    }

    #[DataProvider('postingErrorProvider')]
    public function testOf_HasWordsForEveryReasonAPostingCanBeRefused(PostingError $error): void
    {
        $key = 'Accounting.posting_' . $error->value;

        $this->assertNotSame($key, AccountingMessage::of(new PostingException($error, 'x')));
    }

    #[DataProvider('accountingErrorProvider')]
    public function testOf_HasWordsForEveryReasonTheBooksCanRefuseAChange(AccountingError $error): void
    {
        $key = 'Accounting.error_' . $error->value;

        $this->assertNotSame($key, AccountingMessage::of(new AccountingException($error)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    private static function documentSourceNames(): array
    {
        return [
            'expense'          => ['an expense', 'المصروف'],
            'cashup'           => ['a cash-up', 'إقفال الصندوق'],
            'customer_opening' => ['an opening balance of a customer', 'الرصيد الافتتاحي للعميل'],
            'sales_debit_note' => ['a sales debit note', 'إشعار المدين للمبيعات'],
            'revenue'          => ['a receipt voucher', 'سند القبض'],
            'cheque'           => ['a cheque', 'الشيك'],
            'stock_permit'     => ['a stock permit', 'إذن المخزن'],
            'stocktake'        => ['a stocktake', 'الجرد'],
            'asset'            => ['a fixed asset, its depreciation or its disposal', 'الأصل الثابت أو إهلاكه أو التصرف فيه'],
        ];
    }

    public function testOf_NamesEverySourceOfADocumentJournalInEveryLocaleWithItsOwnWords(): void
    {
        $names = self::documentSourceNames();

        foreach (JournalService::DOCUMENT_SOURCES as $source) {
            $this->assertArrayHasKey(match (true) {
                str_starts_with($source, 'cheque_') => 'cheque',
                str_starts_with($source, 'asset') => 'asset',
                default => $source,
            }, $names, $source . ' is a locked document source the message does not name');
        }

        foreach (['en' => 0, 'en-GB' => 0, 'ar-EG' => 1, 'ar-LB' => 1] as $locale => $position) {
            $lines   = require ROOTPATH . 'app/Language/' . $locale . '/Accounting.php';
            $message = $lines['error_document_journal_locked'];

            foreach ($names as $source => $words) {
                $this->assertStringContainsString($words[$position], $message, $locale . ' does not name ' . $source);
            }
        }
    }

    public function testOf_FillsTheMessageWithTheContextOfTheFailure(): void
    {
        $message = AccountingMessage::of(new PostingException(PostingError::CreditNeedsCustomer, 'x', ['difference' => '40.0000']));

        $this->assertStringContainsString('40.0000', $message);
    }

    public function testOf_NamesTheLowestNextNumberOfANumberingRefusal(): void
    {
        $message = AccountingMessage::of(new AccountingException(AccountingError::NumberingNextTooLow, 'x', ['min' => 417]));

        $this->assertStringContainsString('417', $message);
    }

    public function testOf_SaysTheSameForAPermissionRefusalWhateverItsContext(): void
    {
        $this->assertSame(lang('Accounting.error_permission_denied'), AccountingMessage::of(new AccountingException(AccountingError::PermissionDenied, 'no', ['x' => 1])));
    }
}
