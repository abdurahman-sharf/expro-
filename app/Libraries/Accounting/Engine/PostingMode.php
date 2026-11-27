<?php

namespace App\Libraries\Accounting\Engine;

/**
 * How an operation is posted. Legacy posts the journal its document's own code builds. Shadow posts
 * that journal too, builds the journal of the engine beside it without posting it, and writes down
 * every difference. Engine posts the journal of the engine.
 */
enum PostingMode: string
{
    case Legacy = 'legacy';
    case Shadow = 'shadow';
    case Engine = 'engine';
}
