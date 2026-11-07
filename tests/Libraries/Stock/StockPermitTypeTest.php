<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountType;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Stock\StockPermitType;
use CodeIgniter\Test\CIUnitTestCase;

class StockPermitTypeTest extends CIUnitTestCase
{
    public function testDocumentType_GivesEachKindOfPermitTheSequenceOfItsOwn(): void
    {
        $this->assertSame(DocumentType::StockPermitAdd, StockPermitType::Add->documentType());
        $this->assertSame(DocumentType::StockPermitIssue, StockPermitType::Issue->documentType());
        $this->assertSame(DocumentType::StockPermitTransfer, StockPermitType::Transfer->documentType());
        $this->assertCount(3, array_unique(array_map(static fn (StockPermitType $type): string => $type->documentType()->value, StockPermitType::cases())));
    }

    public function testLabelKey_NamesALanguageLineForEveryKind(): void
    {
        foreach (StockPermitType::cases() as $type) {
            $this->assertNotSame($type->labelKey(), lang($type->labelKey()), $type->value);
        }
    }

    public function testAccountTypes_FitsTheCreditSideOfAnAddPermitTheDebitSideOfAnIssuePermitAndNothingForATransfer(): void
    {
        $this->assertSame([AccountType::Liability, AccountType::Equity, AccountType::Revenue, AccountType::Expense], StockPermitType::Add->accountTypes());
        $this->assertSame([AccountType::Asset, AccountType::Equity, AccountType::Expense], StockPermitType::Issue->accountTypes());
        $this->assertSame([], StockPermitType::Transfer->accountTypes());
    }

    public function testTryFrom_KnowsOnlyTheThreeKinds(): void
    {
        $this->assertSame(['add', 'issue', 'transfer'], array_map(static fn (StockPermitType $type): string => $type->value, StockPermitType::cases()));
        $this->assertNull(StockPermitType::tryFrom('swap'));
    }
}
