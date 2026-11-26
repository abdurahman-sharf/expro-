<?php

namespace App\Libraries\Accounting;

/**
 * The currencies the books offer to start from, with the decimals the ISO 4217 table gives each one.
 * Only the zero, two and three decimals the books keep are listed.
 */
final class CurrencyCatalog
{
    /**
     * Code => [English name, Arabic name, decimals].
     */
    public const CURRENCIES = [
        'AED' => ['UAE Dirham', 'الدرهم الإماراتي', 2],
        'AFN' => ['Afghan Afghani', 'الأفغاني', 2],
        'ARS' => ['Argentine Peso', 'البيزو الأرجنتيني', 2],
        'AUD' => ['Australian Dollar', 'الدولار الأسترالي', 2],
        'BDT' => ['Bangladeshi Taka', 'التاكا البنغلاديشية', 2],
        'BGN' => ['Bulgarian Lev', 'الليف البلغاري', 2],
        'BHD' => ['Bahraini Dinar', 'الدينار البحريني', 3],
        'BIF' => ['Burundian Franc', 'الفرنك البوروندي', 0],
        'BRL' => ['Brazilian Real', 'الريال البرازيلي', 2],
        'CAD' => ['Canadian Dollar', 'الدولار الكندي', 2],
        'CHF' => ['Swiss Franc', 'الفرنك السويسري', 2],
        'CLP' => ['Chilean Peso', 'البيزو التشيلي', 0],
        'CNY' => ['Chinese Yuan', 'اليوان الصيني', 2],
        'COP' => ['Colombian Peso', 'البيزو الكولومبي', 2],
        'CZK' => ['Czech Koruna', 'الكرونة التشيكية', 2],
        'DJF' => ['Djiboutian Franc', 'الفرنك الجيبوتي', 0],
        'DKK' => ['Danish Krone', 'الكرونة الدنماركية', 2],
        'DZD' => ['Algerian Dinar', 'الدينار الجزائري', 2],
        'EGP' => ['Egyptian Pound', 'الجنيه المصري', 2],
        'ETB' => ['Ethiopian Birr', 'البر الإثيوبي', 2],
        'EUR' => ['Euro', 'اليورو', 2],
        'GBP' => ['Pound Sterling', 'الجنيه الإسترليني', 2],
        'GHS' => ['Ghanaian Cedi', 'السيدي الغاني', 2],
        'GNF' => ['Guinean Franc', 'الفرنك الغيني', 0],
        'HKD' => ['Hong Kong Dollar', 'دولار هونغ كونغ', 2],
        'HUF' => ['Hungarian Forint', 'الفورنت المجري', 2],
        'IDR' => ['Indonesian Rupiah', 'الروبية الإندونيسية', 2],
        'ILS' => ['Israeli New Shekel', 'الشيكل الإسرائيلي', 2],
        'INR' => ['Indian Rupee', 'الروبية الهندية', 2],
        'IQD' => ['Iraqi Dinar', 'الدينار العراقي', 3],
        'ISK' => ['Icelandic Krona', 'الكرونة الأيسلندية', 0],
        'JOD' => ['Jordanian Dinar', 'الدينار الأردني', 3],
        'JPY' => ['Japanese Yen', 'الين الياباني', 0],
        'KES' => ['Kenyan Shilling', 'الشلن الكيني', 2],
        'KMF' => ['Comorian Franc', 'الفرنك القمري', 0],
        'KRW' => ['South Korean Won', 'الوون الكوري الجنوبي', 0],
        'KWD' => ['Kuwaiti Dinar', 'الدينار الكويتي', 3],
        'LBP' => ['Lebanese Pound', 'الليرة اللبنانية', 2],
        'LKR' => ['Sri Lankan Rupee', 'الروبية السريلانكية', 2],
        'LYD' => ['Libyan Dinar', 'الدينار الليبي', 3],
        'MAD' => ['Moroccan Dirham', 'الدرهم المغربي', 2],
        'MMK' => ['Myanmar Kyat', 'الكيات الميانماري', 2],
        'MRU' => ['Mauritanian Ouguiya', 'الأوقية الموريتانية', 2],
        'MXN' => ['Mexican Peso', 'البيزو المكسيكي', 2],
        'MYR' => ['Malaysian Ringgit', 'الرينغيت الماليزي', 2],
        'NGN' => ['Nigerian Naira', 'النايرا النيجيرية', 2],
        'NOK' => ['Norwegian Krone', 'الكرونة النرويجية', 2],
        'NPR' => ['Nepalese Rupee', 'الروبية النيبالية', 2],
        'NZD' => ['New Zealand Dollar', 'الدولار النيوزيلندي', 2],
        'OMR' => ['Omani Rial', 'الريال العماني', 3],
        'PHP' => ['Philippine Peso', 'البيزو الفلبيني', 2],
        'PKR' => ['Pakistani Rupee', 'الروبية الباكستانية', 2],
        'PLN' => ['Polish Zloty', 'الزلوتي البولندي', 2],
        'PYG' => ['Paraguayan Guarani', 'الغواراني الباراغوايي', 0],
        'QAR' => ['Qatari Riyal', 'الريال القطري', 2],
        'RON' => ['Romanian Leu', 'الليو الروماني', 2],
        'RUB' => ['Russian Ruble', 'الروبل الروسي', 2],
        'RWF' => ['Rwandan Franc', 'الفرنك الرواندي', 0],
        'SAR' => ['Saudi Riyal', 'الريال السعودي', 2],
        'SDG' => ['Sudanese Pound', 'الجنيه السوداني', 2],
        'SEK' => ['Swedish Krona', 'الكرونة السويدية', 2],
        'SGD' => ['Singapore Dollar', 'الدولار السنغافوري', 2],
        'SYP' => ['Syrian Pound', 'الليرة السورية', 2],
        'THB' => ['Thai Baht', 'الباخت التايلندي', 2],
        'TND' => ['Tunisian Dinar', 'الدينار التونسي', 3],
        'TRY' => ['Turkish Lira', 'الليرة التركية', 2],
        'TWD' => ['New Taiwan Dollar', 'الدولار التايواني الجديد', 2],
        'TZS' => ['Tanzanian Shilling', 'الشلن التنزاني', 2],
        'UAH' => ['Ukrainian Hryvnia', 'الهريفنيا الأوكرانية', 2],
        'UGX' => ['Ugandan Shilling', 'الشلن الأوغندي', 0],
        'USD' => ['US Dollar', 'الدولار الأمريكي', 2],
        'VND' => ['Vietnamese Dong', 'الدونغ الفيتنامي', 0],
        'XAF' => ['Central African CFA Franc', 'الفرنك الوسط أفريقي', 0],
        'XOF' => ['West African CFA Franc', 'الفرنك الغرب أفريقي', 0],
        'YER' => ['Yemeni Rial', 'الريال اليمني', 2],
        'ZAR' => ['South African Rand', 'الراند الجنوب أفريقي', 2],
    ];

    public const DECIMALS = [0, 2, 3];

    public static function has(string $code): bool
    {
        return isset(self::CURRENCIES[$code]);
    }

    public static function decimalsOf(string $code): int
    {
        return self::CURRENCIES[$code][2] ?? 2;
    }
}
