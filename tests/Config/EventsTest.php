<?php

namespace Tests\Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;

class EventsTest extends CIUnitTestCase
{
    public function testPreSystem_StartsEveryTestRequestWithoutTheViewDataOfTheRequestBefore(): void
    {
        service('renderer')->setData(['amount_change' => '5.00'])->renderString('<?= $amount_change ?>', null, true);

        $this->assertArrayHasKey('amount_change', service('renderer')->getData());

        Events::trigger('pre_system');

        $this->assertSame([], service('renderer')->getData());
    }
}
