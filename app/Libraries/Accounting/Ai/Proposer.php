<?php

namespace App\Libraries\Accounting\Ai;

use DateTimeImmutable;

/**
 * A rule that looks at the books and suggests a journal. It is local and deterministic: the same books on the
 * same day give the same suggestions, it reads only the database and it writes nothing. What it suggests
 * becomes a proposal only through ProposalService, which checks it as a manual journal is checked.
 */
interface Proposer
{
    /**
     * The kind of proposal it makes, which names the templates of its title and its rationale.
     */
    public function kind(): string;

    /**
     * The name the proposals carry as the one who proposed them.
     */
    public function name(): string;

    /**
     * @param list<int>|null $branchIds The branches it may look at, null for every branch.
     *
     * @return list<ProposalDraft>
     */
    public function propose(DateTimeImmutable $today, ?array $branchIds): array;
}
