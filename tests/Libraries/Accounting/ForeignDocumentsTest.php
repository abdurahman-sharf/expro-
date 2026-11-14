<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ForeignDocument;
use App\Libraries\Accounting\ForeignDocuments;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ForeignDocumentsTest extends CIUnitTestCase
{
    /**
     * @return list<ForeignDocument>
     */
    private function documents(int $count): array
    {
        return array_map(static fn (int $number): ForeignDocument => new ForeignDocument('INV ' . $number, 'USD'), range(1, $count));
    }

    public function testUnique_KeepsEachDocumentOnceInTheOrderGiven(): void
    {
        $unique = ForeignDocuments::unique([new ForeignDocument('INV 2', 'USD'), new ForeignDocument('INV 1', 'EUR'), new ForeignDocument('INV 2', 'USD')]);

        $this->assertSame(['INV 2 (USD)', 'INV 1 (EUR)'], array_map(static fn (ForeignDocument $document): string => $document->label(), $unique));
    }

    public function testUnique_KeepsTheSameNumberInTwoCurrenciesAsTwoDocuments(): void
    {
        $this->assertCount(2, ForeignDocuments::unique([new ForeignDocument('INV 2', 'USD'), new ForeignDocument('INV 2', 'EUR')]));
    }

    public function testDescribe_NamesEveryDocumentWhenThereAreFewOfThem(): void
    {
        $this->assertSame('INV 1 (USD), INV 2 (USD)', ForeignDocuments::describe($this->documents(2)));
        $this->assertSame('', ForeignDocuments::describe([]));
    }

    public function testDescribe_NamesTheFirstFiveAndSaysHowManyMore(): void
    {
        $this->assertSame('INV 1 (USD), INV 2 (USD), INV 3 (USD), INV 4 (USD), INV 5 (USD)', ForeignDocuments::describe($this->documents(5)));
        $this->assertSame('INV 1 (USD), INV 2 (USD), INV 3 (USD), INV 4 (USD), INV 5 (USD) +3', ForeignDocuments::describe($this->documents(8)));
    }

    public function testDescribe_CountsADocumentOnceWhateverTimesItIsGiven(): void
    {
        $this->assertSame('INV 1 (USD)', ForeignDocuments::describe([...$this->documents(1), ...$this->documents(1)]));
    }
}
