<?php

namespace App\Libraries\Documents;

final readonly class WordsCurrency
{
    public function __construct(
        public string $code,
        public int $decimals,
        public WordsUnit $major,
        public WordsUnit $minor,
    ) {
    }
}
