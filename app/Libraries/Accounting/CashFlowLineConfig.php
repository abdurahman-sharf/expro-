<?php

namespace App\Libraries\Accounting;

/**
 * A line of the cash flow report as it is set up: where it is drawn, what it is called, which
 * accounts it takes from and how, and whether it is shown. A line the report starts with can be
 * renamed, pointed at other accounts, given another formula and hidden, but is never deleted; a line
 * added is deleted by removing it. An empty title is the name the report gives the line, and no
 * accounts is the accounts it starts with.
 */
final readonly class CashFlowLineConfig
{
    /**
     * @param list<int> $accountIds
     */
    public function __construct(
        public string $key,
        public CashFlowSection $section,
        public string $title,
        public array $accountIds,
        public CashFlowFormula $formula,
        public bool $visible,
        public bool $custom,
    ) {
    }

    /**
     * @return array{key: string, section: string, title: string, accounts: list<int>, formula: string, visible: bool, custom: bool}
     */
    public function toArray(): array
    {
        return [
            'key'      => $this->key,
            'section'  => $this->section->value,
            'title'    => $this->title,
            'accounts' => $this->accountIds,
            'formula'  => $this->formula->value,
            'visible'  => $this->visible,
            'custom'   => $this->custom,
        ];
    }
}
