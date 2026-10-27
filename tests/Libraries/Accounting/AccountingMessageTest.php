<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
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
