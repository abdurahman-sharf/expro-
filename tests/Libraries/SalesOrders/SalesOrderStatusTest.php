<?php

namespace Tests\Libraries\SalesOrders;

use App\Libraries\SalesOrders\SalesOrderStatus;
use CodeIgniter\Test\CIUnitTestCase;

class SalesOrderStatusTest extends CIUnitTestCase
{
    public function testIsStored_IsTrueForTheThreeStatesThatAreSetAndNotForTheOnesTheInvoicesSay(): void
    {
        $stored = array_values(array_filter(SalesOrderStatus::cases(), static fn (SalesOrderStatus $status): bool => $status->isStored()));

        $this->assertSame([SalesOrderStatus::Open, SalesOrderStatus::Approved, SalesOrderStatus::Cancelled], $stored);
    }

    public function testCanConvert_IsTrueForAnApprovedOrderAndOneInvoicedInPartOnly(): void
    {
        $convertible = array_values(array_filter(SalesOrderStatus::cases(), static fn (SalesOrderStatus $status): bool => $status->canConvert()));

        $this->assertSame([SalesOrderStatus::Approved, SalesOrderStatus::PartlyInvoiced], $convertible);
    }

    public function testIsEditable_IsTrueForAnOpenAndAnApprovedOrderOnly(): void
    {
        $editable = array_values(array_filter(SalesOrderStatus::cases(), static fn (SalesOrderStatus $status): bool => $status->isEditable()));

        $this->assertSame([SalesOrderStatus::Open, SalesOrderStatus::Approved], $editable);
    }

    public function testManualNext_MovesBetweenOpenAndApprovedAndNowhereElse(): void
    {
        $this->assertSame([SalesOrderStatus::Approved], SalesOrderStatus::Open->manualNext());
        $this->assertSame([SalesOrderStatus::Open], SalesOrderStatus::Approved->manualNext());
        $this->assertSame([], SalesOrderStatus::PartlyInvoiced->manualNext());
        $this->assertSame([], SalesOrderStatus::Invoiced->manualNext());
        $this->assertSame([], SalesOrderStatus::Cancelled->manualNext());
    }

    public function testCanCancel_IsFalseForAnInvoicedAndACancelledOrder(): void
    {
        $cancellable = array_values(array_filter(SalesOrderStatus::cases(), static fn (SalesOrderStatus $status): bool => $status->canCancel()));

        $this->assertSame([SalesOrderStatus::Open, SalesOrderStatus::Approved, SalesOrderStatus::PartlyInvoiced], $cancellable);
    }

    public function testLanguageKey_NamesALanguageLineForEveryStatus(): void
    {
        foreach (SalesOrderStatus::cases() as $status) {
            $this->assertNotSame($status->languageKey(), lang($status->languageKey()), $status->value);
        }
    }
}
