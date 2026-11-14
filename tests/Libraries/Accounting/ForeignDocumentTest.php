<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ForeignDocument;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ForeignDocumentTest extends CIUnitTestCase
{
    public function testLabel_JoinsTheNumberAndTheCurrency(): void
    {
        $this->assertSame('POS 12 (USD)', (new ForeignDocument('POS 12', 'USD'))->label());
    }
}
