<?php

namespace App\Libraries\Documents;

/**
 * A whole number in Arabic words, in the case after "and" and the gender of the thing it counts. Words
 * for 3 to 10 take the other gender than the thing counted, as the grammar has it.
 */
final class ArabicNumberWords
{
    private const FOR_MASCULINE = [1 => 'واحد', 2 => 'اثنان', 3 => 'ثلاثة', 4 => 'أربعة', 5 => 'خمسة', 6 => 'ستة', 7 => 'سبعة', 8 => 'ثمانية', 9 => 'تسعة', 10 => 'عشرة'];
    private const FOR_FEMININE  = [1 => 'واحدة', 2 => 'اثنتان', 3 => 'ثلاث', 4 => 'أربع', 5 => 'خمس', 6 => 'ست', 7 => 'سبع', 8 => 'ثمان', 9 => 'تسع', 10 => 'عشر'];
    private const TEENS_MASC    = [11 => 'أحد عشر', 12 => 'اثنا عشر', 13 => 'ثلاثة عشر', 14 => 'أربعة عشر', 15 => 'خمسة عشر', 16 => 'ستة عشر', 17 => 'سبعة عشر', 18 => 'ثمانية عشر', 19 => 'تسعة عشر'];
    private const TEENS_FEM     = [11 => 'إحدى عشرة', 12 => 'اثنتا عشرة', 13 => 'ثلاث عشرة', 14 => 'أربع عشرة', 15 => 'خمس عشرة', 16 => 'ست عشرة', 17 => 'سبع عشرة', 18 => 'ثماني عشرة', 19 => 'تسع عشرة'];
    private const TENS          = [2 => 'عشرون', 3 => 'ثلاثون', 4 => 'أربعون', 5 => 'خمسون', 6 => 'ستون', 7 => 'سبعون', 8 => 'ثمانون', 9 => 'تسعون'];
    private const HUNDREDS      = [1 => 'مائة', 2 => 'مائتان', 3 => 'ثلاثمائة', 4 => 'أربعمائة', 5 => 'خمسمائة', 6 => 'ستمائة', 7 => 'سبعمائة', 8 => 'ثمانمائة', 9 => 'تسعمائة'];
    private const SCALES        = [
        1 => ['ألف', 'ألفان', 'آلاف', 'ألفًا'],
        2 => ['مليون', 'مليونان', 'ملايين', 'مليونًا'],
        3 => ['مليار', 'ملياران', 'مليارات', 'مليارًا'],
    ];

    /**
     * The words of a number a noun follows. The noun is counted by the last two digits, so when a
     * thousand, million or billion is the last thing said before it, the count of that scale is joined to
     * the noun in the singular and not to the one that closes a number.
     *
     * @param int<1, max> $number Below 1,000,000,000,000.
     */
    public static function words(int $number, bool $feminine): string
    {
        $parts = [];
        $units = $number % 1000;

        for ($scale = 3; $scale >= 1; $scale--) {
            $group = intdiv($number, 1000 ** $scale) % 1000;

            if ($group > 0) {
                $parts[] = self::scaled($group, $scale, $number % (1000 ** $scale) === 0);
            }
        }

        if ($units > 0) {
            $parts[] = self::group($units, $feminine);
        }

        return implode(' و', $parts);
    }

    private static function scaled(int $group, int $scale, bool $lastBeforeNoun): string
    {
        $names = self::SCALES[$scale];

        if ($group === 1) {
            return $names[0];
        }

        if ($group === 2) {
            return $names[1];
        }

        $rest  = $group % 100;
        $count = self::group($group, false);

        if ($group === 200) {
            return 'مائتا ' . $names[0];
        }

        return $count . ' ' . match (true) {
            $rest >= 3 && $rest <= 10        => $names[2],
            $rest >= 11 && ! $lastBeforeNoun => $names[3],
            default                          => $names[0],
        };
    }

    private static function group(int $group, bool $feminine): string
    {
        $parts   = [];
        $hundred = intdiv($group, 100);
        $rest    = $group % 100;

        if ($hundred > 0) {
            $parts[] = self::HUNDREDS[$hundred];
        }

        if ($rest > 0) {
            $parts[] = self::belowHundred($rest, $feminine);
        }

        return implode(' و', $parts);
    }

    private static function belowHundred(int $number, bool $feminine): string
    {
        $ones = $feminine ? self::FOR_FEMININE : self::FOR_MASCULINE;

        if ($number <= 10) {
            return $ones[$number];
        }

        if ($number <= 19) {
            return ($feminine ? self::TEENS_FEM : self::TEENS_MASC)[$number];
        }

        $unit = $number % 10;
        $tens = self::TENS[intdiv($number, 10)];

        return $unit === 0 ? $tens : $ones[$unit] . ' و' . $tens;
    }
}
