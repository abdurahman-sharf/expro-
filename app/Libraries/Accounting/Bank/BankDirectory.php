<?php

namespace App\Libraries\Accounting\Bank;

/**
 * The common banks of Saudi Arabia and the Gulf, by code, with their names in English and Arabic,
 * so that a bank account is opened under a bank picked from a list and not typed. The list is kept in
 * the code: it needs no network and no service, a change of it is a change that is reviewed, and a
 * code never changes once it is given, so a bank that is renamed or merged keeps the accounts opened
 * under it. A bank outside the list is still typed by hand, and so are the bank accounts opened before
 * the list: their name is kept as it was written. Nothing here is a bank identifier of any kind: the
 * list is a convenience for people, not a way to reach a bank.
 */
final class BankDirectory
{
    public const COUNTRIES = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];

    /**
     * @var array<string, array{country: string, en: string, ar: string, keywords: string}>
     */
    private const BANKS = [
        'SA_SNB'      => ['country' => 'SA', 'en' => 'Saudi National Bank', 'ar' => 'البنك الأهلي السعودي', 'keywords' => 'SNB NCB Samba National Commercial Bank سامبا الأهلي التجاري'],
        'SA_RAJHI'    => ['country' => 'SA', 'en' => 'Al Rajhi Bank', 'ar' => 'مصرف الراجحي', 'keywords' => 'Rajhi الراجحي'],
        'SA_RIYAD'    => ['country' => 'SA', 'en' => 'Riyad Bank', 'ar' => 'بنك الرياض', 'keywords' => 'Riyadh'],
        'SA_SAB'      => ['country' => 'SA', 'en' => 'Saudi Awwal Bank', 'ar' => 'البنك السعودي الأول', 'keywords' => 'SAB SABB Alawwal Saudi British Bank ساب الأول البريطاني'],
        'SA_ALINMA'   => ['country' => 'SA', 'en' => 'Alinma Bank', 'ar' => 'مصرف الإنماء', 'keywords' => 'Inma الانماء'],
        'SA_BSF'      => ['country' => 'SA', 'en' => 'Banque Saudi Fransi', 'ar' => 'البنك السعودي الفرنسي', 'keywords' => 'BSF Fransi French'],
        'SA_SAIB'     => ['country' => 'SA', 'en' => 'Saudi Investment Bank', 'ar' => 'البنك السعودي للاستثمار', 'keywords' => 'SAIB SIB'],
        'SA_ANB'      => ['country' => 'SA', 'en' => 'Arab National Bank', 'ar' => 'البنك العربي الوطني', 'keywords' => 'ANB'],
        'SA_ALBILAD'  => ['country' => 'SA', 'en' => 'Bank Albilad', 'ar' => 'بنك البلاد', 'keywords' => 'Al Bilad'],
        'SA_ALJAZIRA' => ['country' => 'SA', 'en' => 'Bank AlJazira', 'ar' => 'بنك الجزيرة', 'keywords' => 'Al Jazira Jazirah'],
        'SA_STC'      => ['country' => 'SA', 'en' => 'STC Bank', 'ar' => 'بنك إس تي سي', 'keywords' => 'stc pay'],
        'SA_D360'     => ['country' => 'SA', 'en' => 'D360 Bank', 'ar' => 'بنك دي 360', 'keywords' => 'D360 digital'],

        'AE_ENBD'     => ['country' => 'AE', 'en' => 'Emirates NBD', 'ar' => 'بنك الإمارات دبي الوطني', 'keywords' => 'ENBD'],
        'AE_FAB'      => ['country' => 'AE', 'en' => 'First Abu Dhabi Bank', 'ar' => 'بنك أبوظبي الأول', 'keywords' => 'FAB'],
        'AE_ADCB'     => ['country' => 'AE', 'en' => 'Abu Dhabi Commercial Bank', 'ar' => 'بنك أبوظبي التجاري', 'keywords' => 'ADCB'],
        'AE_DIB'      => ['country' => 'AE', 'en' => 'Dubai Islamic Bank', 'ar' => 'بنك دبي الإسلامي', 'keywords' => 'DIB'],
        'AE_MASHREQ'  => ['country' => 'AE', 'en' => 'Mashreq Bank', 'ar' => 'بنك المشرق', 'keywords' => 'Mashreq'],
        'AE_CBD'      => ['country' => 'AE', 'en' => 'Commercial Bank of Dubai', 'ar' => 'بنك دبي التجاري', 'keywords' => 'CBD'],
        'AE_RAKBANK'  => ['country' => 'AE', 'en' => 'RAKBANK', 'ar' => 'بنك رأس الخيمة الوطني', 'keywords' => 'National Bank of Ras Al Khaimah'],
        'AE_ADIB'     => ['country' => 'AE', 'en' => 'Abu Dhabi Islamic Bank', 'ar' => 'مصرف أبوظبي الإسلامي', 'keywords' => 'ADIB'],
        'AE_EIB'      => ['country' => 'AE', 'en' => 'Emirates Islamic', 'ar' => 'الإمارات الإسلامي', 'keywords' => 'Emirates Islamic Bank'],
        'AE_SIB'      => ['country' => 'AE', 'en' => 'Sharjah Islamic Bank', 'ar' => 'مصرف الشارقة الإسلامي', 'keywords' => 'SIB'],
        'AE_AJMAN'    => ['country' => 'AE', 'en' => 'Ajman Bank', 'ar' => 'بنك عجمان', 'keywords' => ''],
        'AE_NBF'      => ['country' => 'AE', 'en' => 'National Bank of Fujairah', 'ar' => 'بنك الفجيرة الوطني', 'keywords' => 'NBF'],
        'AE_UAB'      => ['country' => 'AE', 'en' => 'United Arab Bank', 'ar' => 'البنك العربي المتحد', 'keywords' => 'UAB'],

        'KW_NBK'      => ['country' => 'KW', 'en' => 'National Bank of Kuwait', 'ar' => 'بنك الكويت الوطني', 'keywords' => 'NBK'],
        'KW_KFH'      => ['country' => 'KW', 'en' => 'Kuwait Finance House', 'ar' => 'بيت التمويل الكويتي', 'keywords' => 'KFH Bayt'],
        'KW_GULF'     => ['country' => 'KW', 'en' => 'Gulf Bank', 'ar' => 'بنك الخليج', 'keywords' => ''],
        'KW_BURGAN'   => ['country' => 'KW', 'en' => 'Burgan Bank', 'ar' => 'بنك برقان', 'keywords' => ''],
        'KW_CBK'      => ['country' => 'KW', 'en' => 'Commercial Bank of Kuwait', 'ar' => 'البنك التجاري الكويتي', 'keywords' => 'CBK'],
        'KW_ABK'      => ['country' => 'KW', 'en' => 'Al Ahli Bank of Kuwait', 'ar' => 'البنك الأهلي الكويتي', 'keywords' => 'ABK'],
        'KW_BOUBYAN'  => ['country' => 'KW', 'en' => 'Boubyan Bank', 'ar' => 'بنك بوبيان', 'keywords' => ''],
        'KW_WARBA'    => ['country' => 'KW', 'en' => 'Warba Bank', 'ar' => 'بنك وربة', 'keywords' => ''],
        'KW_KIB'      => ['country' => 'KW', 'en' => 'Kuwait International Bank', 'ar' => 'بنك الكويت الدولي', 'keywords' => 'KIB'],

        'QA_QNB'      => ['country' => 'QA', 'en' => 'Qatar National Bank', 'ar' => 'بنك قطر الوطني', 'keywords' => 'QNB'],
        'QA_CBQ'      => ['country' => 'QA', 'en' => 'Commercial Bank of Qatar', 'ar' => 'البنك التجاري القطري', 'keywords' => 'CBQ'],
        'QA_DOHA'     => ['country' => 'QA', 'en' => 'Doha Bank', 'ar' => 'بنك الدوحة', 'keywords' => ''],
        'QA_QIB'      => ['country' => 'QA', 'en' => 'Qatar Islamic Bank', 'ar' => 'مصرف قطر الإسلامي', 'keywords' => 'QIB'],
        'QA_RAYAN'    => ['country' => 'QA', 'en' => 'Masraf Al Rayan', 'ar' => 'مصرف الريان', 'keywords' => 'Rayan'],
        'QA_DUKHAN'   => ['country' => 'QA', 'en' => 'Dukhan Bank', 'ar' => 'بنك دخان', 'keywords' => 'Barwa International Bank of Qatar'],
        'QA_AHLI'     => ['country' => 'QA', 'en' => 'Ahlibank Qatar', 'ar' => 'البنك الأهلي القطري', 'keywords' => 'Ahli Bank'],
        'QA_QIIB'     => ['country' => 'QA', 'en' => 'Qatar International Islamic Bank', 'ar' => 'بنك قطر الدولي الإسلامي', 'keywords' => 'QIIB'],

        'BH_NBB'      => ['country' => 'BH', 'en' => 'National Bank of Bahrain', 'ar' => 'بنك البحرين الوطني', 'keywords' => 'NBB'],
        'BH_BBK'      => ['country' => 'BH', 'en' => 'Bank of Bahrain and Kuwait', 'ar' => 'بنك البحرين والكويت', 'keywords' => 'BBK'],
        'BH_AUB'      => ['country' => 'BH', 'en' => 'Ahli United Bank', 'ar' => 'البنك الأهلي المتحد', 'keywords' => 'AUB'],
        'BH_BISB'     => ['country' => 'BH', 'en' => 'Bahrain Islamic Bank', 'ar' => 'بنك البحرين الإسلامي', 'keywords' => 'BisB'],
        'BH_SALAM'    => ['country' => 'BH', 'en' => 'Al Salam Bank', 'ar' => 'مصرف السلام', 'keywords' => ''],
        'BH_GIB'      => ['country' => 'BH', 'en' => 'Gulf International Bank', 'ar' => 'بنك الخليج الدولي', 'keywords' => 'GIB'],

        'OM_MUSCAT'   => ['country' => 'OM', 'en' => 'Bank Muscat', 'ar' => 'بنك مسقط', 'keywords' => ''],
        'OM_DHOFAR'   => ['country' => 'OM', 'en' => 'Bank Dhofar', 'ar' => 'بنك ظفار', 'keywords' => ''],
        'OM_NBO'      => ['country' => 'OM', 'en' => 'National Bank of Oman', 'ar' => 'البنك الوطني العماني', 'keywords' => 'NBO'],
        'OM_SOHAR'    => ['country' => 'OM', 'en' => 'Sohar International Bank', 'ar' => 'بنك صحار الدولي', 'keywords' => ''],
        'OM_AHLI'     => ['country' => 'OM', 'en' => 'Ahli Bank Oman', 'ar' => 'البنك الأهلي العماني', 'keywords' => ''],
        'OM_OAB'      => ['country' => 'OM', 'en' => 'Oman Arab Bank', 'ar' => 'البنك العربي العماني', 'keywords' => 'OAB'],
        'OM_NIZWA'    => ['country' => 'OM', 'en' => 'Bank Nizwa', 'ar' => 'بنك نزوى', 'keywords' => ''],
        'OM_ALIZZ'    => ['country' => 'OM', 'en' => 'Alizz Islamic Bank', 'ar' => 'بنك العز الإسلامي', 'keywords' => ''],
    ];

    public static function has(string $code): bool
    {
        return isset(self::BANKS[$code]);
    }

    /**
     * @return array{code: string, country: string, en: string, ar: string}|null
     */
    public static function find(string $code): ?array
    {
        $bank = self::BANKS[$code] ?? null;

        return $bank === null ? null : ['code' => $code, 'country' => $bank['country'], 'en' => $bank['en'], 'ar' => $bank['ar']];
    }

    /**
     * The bank whose name, in either language, is the text given, found by the English name in
     * the way the accounts opened by hand wrote it.
     */
    public static function codeOfName(string $name): ?string
    {
        $wanted = BankText::normalised($name);

        if ($wanted === '') {
            return null;
        }

        foreach (self::BANKS as $code => $bank) {
            if (BankText::normalised($bank['en']) === $wanted || BankText::normalised($bank['ar']) === $wanted) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The name of the bank in the language given, which is Arabic for any Arabic locale and English
     * for the others, or null for a code that is not in the list.
     */
    public static function nameOf(string $code, string $locale): ?string
    {
        $bank = self::BANKS[$code] ?? null;

        if ($bank === null) {
            return null;
        }

        return str_starts_with(strtolower($locale), 'ar') ? $bank['ar'] : $bank['en'];
    }

    /**
     * The English name a bank account keeps as its bank name when the bank is picked from the list.
     */
    public static function storedNameOf(string $code): ?string
    {
        return self::BANKS[$code]['en'] ?? null;
    }

    /**
     * The banks whose name, code or other names contain every word of the term, in either language,
     * the Saudi banks first and then the other countries in the order of COUNTRIES, each by English
     * name. An empty term returns the whole list.
     *
     * @return list<array{code: string, country: string, en: string, ar: string}>
     */
    public static function search(string $term = ''): array
    {
        $words  = array_values(array_filter(explode(' ', BankText::normalised($term)), static fn (string $word): bool => $word !== ''));
        $found  = [];
        $orders = array_flip(self::COUNTRIES);

        foreach (self::BANKS as $code => $bank) {
            $haystack = BankText::normalised($code . ' ' . $bank['en'] . ' ' . $bank['ar'] . ' ' . $bank['keywords']);

            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    continue 2;
                }
            }

            $found[] = ['code' => $code, 'country' => $bank['country'], 'en' => $bank['en'], 'ar' => $bank['ar']];
        }

        usort($found, static fn (array $first, array $second): int => [$orders[$first['country']], $first['en']] <=> [$orders[$second['country']], $second['en']]);

        return $found;
    }
}
