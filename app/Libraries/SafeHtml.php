<?php

namespace App\Libraries;

use Stringable;

final class SafeHtml implements Stringable
{
    public function __construct(private readonly string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
