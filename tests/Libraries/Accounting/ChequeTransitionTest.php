<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ChequeDirection;
use App\Libraries\Accounting\ChequeSources;
use App\Libraries\Accounting\ChequeTransition;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The status changes of a cheque and the journal source each one writes under.
 */
class ChequeTransitionTest extends CIUnitTestCase
{
    public function testSource_MapsEveryStatusChangeToItsOwnJournalSource(): void
    {
        $map = [];

        foreach (ChequeTransition::cases() as $transition) {
            if ($transition->source() !== null) {
                $map[$transition->value] = $transition->source();
            }
        }

        $this->assertSame([
            'receive'  => 'cheque_in_received',
            'deposit'  => 'cheque_in_deposited',
            'collect'  => 'cheque_in_collected',
            'dcollect' => 'cheque_in_dep_collected',
            'reject'   => 'cheque_in_rejected',
            'dreject'  => 'cheque_in_dep_rejected',
            'deliver'  => 'cheque_out_delivered',
            'pcollect' => 'cheque_out_collected',
            'preject'  => 'cheque_out_rejected',
        ], $map);
        $this->assertCount(9, array_unique(ChequeSources::ALL));
        $this->assertEqualsCanonicalizing(ChequeSources::ALL, array_values($map));
        $this->assertSame(['cheque_in_received', 'cheque_in_deposited', 'cheque_in_collected', 'cheque_in_dep_collected', 'cheque_in_rejected', 'cheque_in_dep_rejected'], ChequeTransition::sourcesOf(ChequeDirection::Received));
        $this->assertSame(['cheque_out_delivered', 'cheque_out_collected', 'cheque_out_rejected'], ChequeTransition::sourcesOf(ChequeDirection::Paid));
    }
}
