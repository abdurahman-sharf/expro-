<?php

namespace App\Libraries\Accounting;

/**
 * Who owes whom between the branches, as of a day: a row for every ordered pair of branches that
 * has anything between them, what no journal says the other branch of, and the difference the
 * whole company shows between what is owed to the branches and what they owe.
 */
final readonly class PairwiseReport
{
    /**
     * @param list<PairBalance>                                                               $rows
     * @param list<object{branch_id: int, code: string, receivable: string, payable: string}> $unattributed What journals on the accounts between branches that name no other branch put on each branch.
     * @param numeric-string|null                                                             $difference   Null when the reader does not see every branch.
     */
    public function __construct(
        public array $rows,
        public array $unattributed,
        public ?string $difference,
        public ?string $asOf,
    ) {
    }
}
