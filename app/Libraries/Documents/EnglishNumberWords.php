<?php

namespace App\Libraries\Documents;

final class EnglishNumberWords
{
    private const ONES   = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen'];
    private const TENS   = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];
    private const SCALES = [3 => 'billion', 2 => 'million', 1 => 'thousand'];

    /**
     * @param int<1, max> $number Below 1,000,000,000,000.
     */
    public static function words(int $number): string
    {
        $parts = [];

        foreach (self::SCALES as $scale => $name) {
            $group = intdiv($number, 1000 ** $scale) % 1000;

            if ($group > 0) {
                $parts[] = self::group($group) . ' ' . $name;
            }
        }

        if ($number % 1000 > 0) {
            $parts[] = self::group($number % 1000);
        }

        return implode(' ', $parts);
    }

    private static function group(int $group): string
    {
        $parts = [];

        if ($group >= 100) {
            $parts[] = self::ONES[intdiv($group, 100)] . ' hundred';
        }

        $rest = $group % 100;

        if ($rest > 0) {
            $parts[] = $rest < 20 ? self::ONES[$rest] : self::TENS[intdiv($rest, 10)] . ($rest % 10 === 0 ? '' : '-' . self::ONES[$rest % 10]);
        }

        return implode(' ', $parts);
    }
}
