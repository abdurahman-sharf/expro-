<?php

namespace Tests\Libraries\PurchaseCycle;

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;
use CodeIgniter\Test\CIUnitTestCase;

class PurchaseDocumentStatusTest extends CIUnitTestCase
{
    public function testIsStored_IsTrueForTheFourStatusesThatAreWrittenAndFalseForTheThreeThatAreRead(): void
    {
        $stored = array_filter(PurchaseDocumentStatus::cases(), static fn (PurchaseDocumentStatus $status): bool => $status->isStored());

        $this->assertSame(['open', 'sent', 'approved', 'cancelled'], array_values(array_map(static fn (PurchaseDocumentStatus $status): string => $status->value, $stored)));
    }

    public function testManualNext_AllowsEachOfTheThreeLabelsToMoveToTheOtherTwoAndNothingElseMoves(): void
    {
        foreach ([PurchaseDocumentStatus::Open, PurchaseDocumentStatus::Sent, PurchaseDocumentStatus::Approved] as $label) {
            $next = $label->manualNext();

            $this->assertCount(2, $next, $label->value);
            $this->assertNotContains($label, $next);

            foreach ($next as $to) {
                $this->assertTrue($to->isLabel(), $label->value . ' to ' . $to->value);
            }
        }

        foreach ([PurchaseDocumentStatus::Converted, PurchaseDocumentStatus::PartlyReceived, PurchaseDocumentStatus::Received, PurchaseDocumentStatus::Cancelled] as $status) {
            $this->assertSame([], $status->manualNext(), $status->value);
        }
    }

    public function testCanConvert_IsTrueForTheThreeLabelsAndForAnOrderReceivedInPartOnly(): void
    {
        foreach (PurchaseDocumentKind::cases() as $kind) {
            foreach ([PurchaseDocumentStatus::Open, PurchaseDocumentStatus::Sent, PurchaseDocumentStatus::Approved] as $label) {
                $this->assertTrue($label->canConvert($kind), $kind->value . ' ' . $label->value);
            }

            foreach ([PurchaseDocumentStatus::Converted, PurchaseDocumentStatus::Received, PurchaseDocumentStatus::Cancelled] as $status) {
                $this->assertFalse($status->canConvert($kind), $kind->value . ' ' . $status->value);
            }

            $this->assertSame($kind === PurchaseDocumentKind::Order, PurchaseDocumentStatus::PartlyReceived->canConvert($kind), $kind->value);
        }
    }

    public function testIsEditable_IsTrueForTheThreeLabelsOnly(): void
    {
        foreach (PurchaseDocumentStatus::cases() as $status) {
            $this->assertSame(in_array($status->value, ['open', 'sent', 'approved'], true), $status->isEditable(), $status->value);
        }
    }

    public function testCanCancel_IsTrueForTheThreeLabelsAndForAnOrderReceivedInPart(): void
    {
        foreach (PurchaseDocumentStatus::cases() as $status) {
            $this->assertSame(in_array($status->value, ['open', 'sent', 'approved', 'partly_received'], true), $status->canCancel(), $status->value);
        }
    }

    public function testLanguageKey_NamesALanguageLineForEveryStatus(): void
    {
        foreach (PurchaseDocumentStatus::cases() as $status) {
            $this->assertNotSame($status->languageKey(), lang($status->languageKey()), $status->value);
        }
    }
}
