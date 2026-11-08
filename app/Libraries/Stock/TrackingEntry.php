<?php

namespace App\Libraries\Stock;

/**
 * What a document line says about the serial numbers or the batches it moves: a list of serial numbers,
 * one for each unit, or batches with their number, their expiry date when they come in, and the quantity
 * taken from or put into each. A line with no entry leaves it to the rules of the tracking type: a sale
 * of a batch item takes the batch that expires first.
 */
final readonly class TrackingEntry
{
    public const SERIAL_MAX = 30;
    public const BATCH_MAX  = 60;

    /**
     * @param list<string>                                                   $serials
     * @param list<array{number: string, expiry: ?string, quantity: string}> $batches
     */
    public function __construct(
        public array $serials = [],
        public array $batches = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $line A cart or document line; its `tracking` key holds the entry, and the single serial number of a line counts for a line of one unit.
     */
    public static function ofLine(array $line): self
    {
        $given = $line['tracking'] ?? null;

        if ($given instanceof self) {
            return $given;
        }

        $serials = [];
        $batches = [];

        if (is_array($given)) {
            $serials = self::serialsOf($given['serials'] ?? []);

            foreach ($given['batches'] ?? [] as $batch) {
                if (is_array($batch)) {
                    $batches[] = ['number' => trim((string) ($batch['number'] ?? '')), 'expiry' => isset($batch['expiry']) && $batch['expiry'] !== '' ? trim((string) $batch['expiry']) : null, 'quantity' => trim((string) ($batch['quantity'] ?? ''))];
                }
            }
        }

        if ($serials === [] && $batches === [] && trim((string) ($line['serialnumber'] ?? '')) !== '') {
            $serials = [trim((string) $line['serialnumber'])];
        }

        return new self($serials, $batches);
    }

    /**
     * @return list<string>
     */
    public static function serialsOf(mixed $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\r\n,;]+/', (string) $value);
        $found = [];

        foreach ($parts ?: [] as $part) {
            $serial = trim((string) $part);

            if ($serial !== '') {
                $found[] = $serial;
            }
        }

        return $found;
    }

    public function isEmpty(): bool
    {
        return $this->serials === [] && $this->batches === [];
    }
}
