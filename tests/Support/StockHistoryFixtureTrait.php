<?php

namespace Tests\Support;

use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\StockLedger;

/**
 * A stock with a history, for the tests that read it in chunks and must find what was there when it was read at
 * once: items whose names read the same, two locations in two branches, receipts and withdrawals over months
 * of days, a movement that was reversed, more that went out than came in, and a sale. A class that uses it
 * is a FamilyReportTestCase.
 */
trait StockHistoryFixtureTrait
{
    /**
     * @return array{from: string, to: string, branch: int, depot: int, location: int, items: array<string, int>}
     */
    protected function stockHistory(): array
    {
        $items = [
            'soap'      => $this->named('Soap', 'Cleaning'),
            'soap_same' => $this->named('soap', 'cleaning'),
            'cream'     => $this->named('Cream', 'Care'),
            'zinc'      => $this->named('Zinc', ''),
            'lotion'    => $this->named('Lotion', 'Care'),
        ];

        $branch = $this->createBranch('SH2', 'History branch');
        $depot  = $this->locationOfBranch($branch, 'History depot');
        $days   = [$this->ago(70), $this->ago(45), $this->ago(20), $this->ago(3), $this->today()];

        $this->bookStock($days[0], 1, $items['soap'], '10', '100.00');
        $this->bookStock($days[0], 1, $items['cream'], '5', '60.00');
        $this->bookStock($days[1], 1, $items['soap'], '-3', '-30.00');
        $this->bookStock($days[1], $branch, $items['soap'], '8', '96.00', $depot);
        $this->bookStock($days[2], 1, $items['soap_same'], '7', '70.00');
        $this->bookStock($days[2], 1, $items['cream'], '-2', '-24.00');
        $this->bookStock($days[3], 1, $items['zinc'], '4', '44.00');
        $this->bookStock($days[3], 1, $items['soap'], '6', '66.00');
        $this->bookStock($days[4], $branch, $items['cream'], '9', '90.00', $depot);
        $this->bookStock($days[4], 1, $items['soap'], '-20', '-200.00');
        $this->bookStock($days[4], 1, $items['zinc'], '2', '22.00');

        (new StockLedger())->reverseSource('purchase', $this->lastBookedSource(), JournalEntryType::General, $this->today(), null);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($items['lotion'], 0, '10', '50.00')]));
        $this->assertGreaterThan(0, $this->save([$this->line($items['lotion'], 0, '4', '100.00', '50.00')], [], [$this->payment(lang('Sales.cash'), '400')]));

        return ['from' => $days[0], 'to' => $this->today(), 'branch' => $branch, 'depot' => $depot, 'location' => self::LOCATION_ID, 'items' => $items];
    }
}
