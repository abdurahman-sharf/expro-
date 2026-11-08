<?php

namespace App\Libraries\Documents;

/**
 * The currencies an amount can be spelled in. A currency that is not here is not spelled: its names and
 * their forms after a number are not guessed, and a document leaves the spelled amount out.
 */
final class WordsCurrencies
{
    public static function find(string $code): ?WordsCurrency
    {
        $code = strtoupper(trim($code));
        $row  = self::table()[$code] ?? null;

        if ($row === null) {
            return null;
        }

        [$decimals, $major, $minor] = $row;

        return new WordsCurrency($code, $decimals, $major, $minor);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::table());
    }

    /**
     * @return array<string, array{0: int, 1: WordsUnit, 2: WordsUnit}>
     */
    private static function table(): array
    {
        $fils = new WordsUnit(false, ['فلس', 'فلسان', 'فلوس', 'فلسًا'], ['Fils', 'Fils']);
        $cent = new WordsUnit(false, ['سنت', 'سنتان', 'سنتات', 'سنتًا'], ['Cent', 'Cents']);

        return [
            'SAR' => [2, new WordsUnit(false, ['ريال سعودي', 'ريالان سعوديان', 'ريالات سعودية', 'ريالًا سعوديًا'], ['Saudi Riyal', 'Saudi Riyals']), new WordsUnit(true, ['هللة', 'هللتان', 'هللات', 'هللةً'], ['Halala', 'Halalas'])],
            'AED' => [2, new WordsUnit(false, ['درهم إماراتي', 'درهمان إماراتيان', 'دراهم إماراتية', 'درهمًا إماراتيًا'], ['UAE Dirham', 'UAE Dirhams']), $fils],
            'KWD' => [3, new WordsUnit(false, ['دينار كويتي', 'ديناران كويتيان', 'دنانير كويتية', 'دينارًا كويتيًا'], ['Kuwaiti Dinar', 'Kuwaiti Dinars']), $fils],
            'BHD' => [3, new WordsUnit(false, ['دينار بحريني', 'ديناران بحرينيان', 'دنانير بحرينية', 'دينارًا بحرينيًا'], ['Bahraini Dinar', 'Bahraini Dinars']), $fils],
            'OMR' => [3, new WordsUnit(false, ['ريال عماني', 'ريالان عمانيان', 'ريالات عمانية', 'ريالًا عمانيًا'], ['Omani Rial', 'Omani Rials']), new WordsUnit(true, ['بيسة', 'بيستان', 'بيسات', 'بيسةً'], ['Baisa', 'Baisas'])],
            'QAR' => [2, new WordsUnit(false, ['ريال قطري', 'ريالان قطريان', 'ريالات قطرية', 'ريالًا قطريًا'], ['Qatari Riyal', 'Qatari Riyals']), new WordsUnit(false, ['درهم', 'درهمان', 'دراهم', 'درهمًا'], ['Dirham', 'Dirhams'])],
            'EGP' => [2, new WordsUnit(false, ['جنيه مصري', 'جنيهان مصريان', 'جنيهات مصرية', 'جنيهًا مصريًا'], ['Egyptian Pound', 'Egyptian Pounds']), new WordsUnit(false, ['قرش', 'قرشان', 'قروش', 'قرشًا'], ['Piastre', 'Piastres'])],
            'JOD' => [3, new WordsUnit(false, ['دينار أردني', 'ديناران أردنيان', 'دنانير أردنية', 'دينارًا أردنيًا'], ['Jordanian Dinar', 'Jordanian Dinars']), $fils],
            'LBP' => [2, new WordsUnit(true, ['ليرة لبنانية', 'ليرتان لبنانيتان', 'ليرات لبنانية', 'ليرةً لبنانيةً'], ['Lebanese Pound', 'Lebanese Pounds']), new WordsUnit(false, ['قرش', 'قرشان', 'قروش', 'قرشًا'], ['Piastre', 'Piastres'])],
            'USD' => [2, new WordsUnit(false, ['دولار أمريكي', 'دولاران أمريكيان', 'دولارات أمريكية', 'دولارًا أمريكيًا'], ['US Dollar', 'US Dollars']), $cent],
            'EUR' => [2, new WordsUnit(false, ['يورو', 'يوروان', 'يوروهات', 'يورو'], ['Euro', 'Euros']), $cent],
            'GBP' => [2, new WordsUnit(false, ['جنيه إسترليني', 'جنيهان إسترلينيان', 'جنيهات إسترلينية', 'جنيهًا إسترلينيًا'], ['Pound Sterling', 'Pounds Sterling']), new WordsUnit(false, ['بنس', 'بنسان', 'بنسات', 'بنسًا'], ['Penny', 'Pence'])],
        ];
    }
}
