<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Numbering\DocumentType;
use App\Libraries\Stock\StockDocumentSearch;
use CodeIgniter\Test\CIUnitTestCase;

class StockDocumentSearchTest extends CIUnitTestCase
{
    public function testLabelled_AcceptsTheFallbackLabelOfAKindWhateverItsCaseAndSpacing(): void
    {
        $types = [DocumentType::StockPermitAdd, DocumentType::StockPermitIssue];

        $this->assertSame(['type' => DocumentType::StockPermitAdd, 'id' => 12], StockDocumentSearch::labelled('STK-IN 12', $types));
        $this->assertSame(['type' => DocumentType::StockPermitIssue, 'id' => 7], StockDocumentSearch::labelled('  stk-out 7 ', $types));
        $this->assertSame(['type' => DocumentType::Stocktake, 'id' => 3], StockDocumentSearch::labelled('#STKT 3', [DocumentType::Stocktake]));
    }

    public function testLabelled_AcceptsAnIdAloneWithoutAKind(): void
    {
        $this->assertSame(['type' => null, 'id' => 12], StockDocumentSearch::labelled('12', [DocumentType::StockPermitAdd]));
        $this->assertSame(['type' => null, 'id' => 12], StockDocumentSearch::labelled('#12', [DocumentType::StockPermitAdd]));
        $this->assertSame(['type' => null, 'id' => 0], StockDocumentSearch::labelled('0', [DocumentType::StockPermitAdd]));
    }

    public function testLabelled_RejectsATextThatIsNoLabelAndAnIdTooLongToBeOne(): void
    {
        $types = [DocumentType::StockPermitAdd];

        $this->assertNull(StockDocumentSearch::labelled('shop', $types));
        $this->assertNull(StockDocumentSearch::labelled('STK-IN', $types));
        $this->assertNull(StockDocumentSearch::labelled('STK-IN 1a', $types));
        $this->assertNull(StockDocumentSearch::labelled('STK-OUT 12', $types), 'a kind the search was not asked about');
        $this->assertNull(StockDocumentSearch::labelled('1234567890', $types));
        $this->assertNull(StockDocumentSearch::labelled('', $types));
    }
}
