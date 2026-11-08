<?php

namespace App\Libraries\Accounting;

/**
 * What a journal line carries beside its account and its amount, as one value: where it is charged
 * (a single cost center, which is the representation older lines have, or a distribution of rows
 * when it is split or goes over 100), its tags, and the tax it notes. A line is charged to one
 * cost center or to a distribution, never both, so a single center at 100 percent is held in the
 * line and a distribution holds only what the line cannot say.
 */
final readonly class LineAnalysis
{
    /**
     * @param list<array{cost_center_id: int, percent: numeric-string, amount: numeric-string}>         $distribution
     * @param list<string>                                                                              $tags
     * @param array{code: string, percent: numeric-string, included: bool, amount: numeric-string}|null $tax
     */
    public function __construct(
        public ?int $costCenterId = null,
        public array $distribution = [],
        public array $tags = [],
        public ?array $tax = null,
    ) {
    }

    /**
     * @param list<array{cost_center_id: int, percent: numeric-string, amount: numeric-string}> $shares
     */
    public static function charged(array $shares): self
    {
        if (count($shares) === 1 && bccomp($shares[0]['percent'], '100', 4) === 0) {
            return new self($shares[0]['cost_center_id']);
        }

        return new self(null, $shares);
    }

    public function withCharge(self $other): self
    {
        return new self($other->costCenterId, $other->distribution, $this->tags, $this->tax);
    }

    public function hasCharge(): bool
    {
        return $this->costCenterId !== null || $this->distribution !== [];
    }

    public function sameCharge(self $other): bool
    {
        return $this->costCenterId === $other->costCenterId && $this->shares() === $other->shares();
    }

    public function sameAs(self $other): bool
    {
        return $this->sameCharge($other) && $this->tags === $other->tags && $this->tax === $other->tax;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return array_filter([
            'cost_center_id' => $this->costCenterId,
            'distribution'   => $this->shares(),
            'tags'           => $this->tags,
            'tax'            => $this->tax,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return list<array{cost_center_id: int, percent: numeric-string}>
     */
    private function shares(): array
    {
        return array_map(static fn (array $row): array => ['cost_center_id' => $row['cost_center_id'], 'percent' => $row['percent']], $this->distribution);
    }
}
