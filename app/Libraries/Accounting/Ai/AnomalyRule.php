<?php

namespace App\Libraries\Accounting\Ai;

/**
 * A rule over the books that finds what a person should look at. It only reads: it never posts, never changes
 * a journal and never blocks one. The same books give the same findings, and each finding names the records it
 * rests on, so that the same thing found again is the same finding.
 */
interface AnomalyRule
{
    /**
     * The code the findings carry, such as AI-ROUND, which also names the template of their text.
     */
    public function code(): string;

    /**
     * @return list<Finding>
     */
    public function detect(AnomalyContext $context): array;
}
