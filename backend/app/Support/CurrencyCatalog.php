<?php

namespace App\Support;

class CurrencyCatalog
{
    /**
     * @return array<string, array{name: string, name_fr: string, symbol: string}>
     */
    public static function all(): array
    {
        return [
            'USD' => ['name' => 'US Dollar', 'name_fr' => 'Dollar américain', 'symbol' => '$'],
            'EUR' => ['name' => 'Euro', 'name_fr' => 'Euro', 'symbol' => '€'],
            'GBP' => ['name' => 'Pound Sterling', 'name_fr' => 'Livre sterling', 'symbol' => '£'],
            'CDF' => ['name' => 'Congolese Franc', 'name_fr' => 'Franc congolais', 'symbol' => 'FC'],
            'TZS' => ['name' => 'Tanzanian Shilling', 'name_fr' => 'Shilling tanzanien', 'symbol' => 'TSh'],
            'KES' => ['name' => 'Kenyan Shilling', 'name_fr' => 'Shilling kényan', 'symbol' => 'KSh'],
            'UGX' => ['name' => 'Ugandan Shilling', 'name_fr' => 'Shilling ougandais', 'symbol' => 'USh'],
            'RWF' => ['name' => 'Rwandan Franc', 'name_fr' => 'Franc rwandais', 'symbol' => 'FRw'],
            'BIF' => ['name' => 'Burundian Franc', 'name_fr' => 'Franc burundais', 'symbol' => 'FBu'],
            'ZAR' => ['name' => 'South African Rand', 'name_fr' => 'Rand sud-africain', 'symbol' => 'R'],
            'XAF' => ['name' => 'Central African CFA Franc', 'name_fr' => 'Franc CFA (BEAC)', 'symbol' => 'FCFA'],
            'XOF' => ['name' => 'West African CFA Franc', 'name_fr' => 'Franc CFA (UEMOA)', 'symbol' => 'CFA'],
            'NGN' => ['name' => 'Nigerian Naira', 'name_fr' => 'Naira nigérian', 'symbol' => '₦'],
            'GHS' => ['name' => 'Ghanaian Cedi', 'name_fr' => 'Cedi ghanéen', 'symbol' => 'GH₵'],
            'MAD' => ['name' => 'Moroccan Dirham', 'name_fr' => 'Dirham marocain', 'symbol' => 'DH'],
            'TND' => ['name' => 'Tunisian Dinar', 'name_fr' => 'Dinar tunisien', 'symbol' => 'DT'],
            'EGP' => ['name' => 'Egyptian Pound', 'name_fr' => 'Livre égyptienne', 'symbol' => 'E£'],
            'AED' => ['name' => 'UAE Dirham', 'name_fr' => 'Dirham des EAU', 'symbol' => 'د.إ'],
            'SAR' => ['name' => 'Saudi Riyal', 'name_fr' => 'Riyal saoudien', 'symbol' => '﷼'],
            'CNY' => ['name' => 'Chinese Yuan', 'name_fr' => 'Yuan chinois', 'symbol' => '¥'],
            'JPY' => ['name' => 'Japanese Yen', 'name_fr' => 'Yen japonais', 'symbol' => '¥'],
            'CHF' => ['name' => 'Swiss Franc', 'name_fr' => 'Franc suisse', 'symbol' => 'CHF'],
            'CAD' => ['name' => 'Canadian Dollar', 'name_fr' => 'Dollar canadien', 'symbol' => 'CA$'],
            'AUD' => ['name' => 'Australian Dollar', 'name_fr' => 'Dollar australien', 'symbol' => 'A$'],
            'INR' => ['name' => 'Indian Rupee', 'name_fr' => 'Roupie indienne', 'symbol' => '₹'],
            'BRL' => ['name' => 'Brazilian Real', 'name_fr' => 'Réal brésilien', 'symbol' => 'R$'],
            'TRY' => ['name' => 'Turkish Lira', 'name_fr' => 'Livre turque', 'symbol' => '₺'],
            'SEK' => ['name' => 'Swedish Krona', 'name_fr' => 'Couronne suédoise', 'symbol' => 'kr'],
            'NOK' => ['name' => 'Norwegian Krone', 'name_fr' => 'Couronne norvégienne', 'symbol' => 'kr'],
            'DKK' => ['name' => 'Danish Krone', 'name_fr' => 'Couronne danoise', 'symbol' => 'kr'],
            'PLN' => ['name' => 'Polish Zloty', 'name_fr' => 'Zloty polonais', 'symbol' => 'zł'],
            'SGD' => ['name' => 'Singapore Dollar', 'name_fr' => 'Dollar de Singapour', 'symbol' => 'S$'],
            'HKD' => ['name' => 'Hong Kong Dollar', 'name_fr' => 'Dollar de Hong Kong', 'symbol' => 'HK$'],
            'KRW' => ['name' => 'South Korean Won', 'name_fr' => 'Won sud-coréen', 'symbol' => '₩'],
            'MXN' => ['name' => 'Mexican Peso', 'name_fr' => 'Peso mexicain', 'symbol' => 'MX$'],
            'NZD' => ['name' => 'New Zealand Dollar', 'name_fr' => 'Dollar néo-zélandais', 'symbol' => 'NZ$'],
            'AOA' => ['name' => 'Angolan Kwanza', 'name_fr' => 'Kwanza angolais', 'symbol' => 'Kz'],
            'ZMW' => ['name' => 'Zambian Kwacha', 'name_fr' => 'Kwacha zambien', 'symbol' => 'ZK'],
            'MZN' => ['name' => 'Mozambican Metical', 'name_fr' => 'Metical mozambicain', 'symbol' => 'MT'],
        ];
    }

    /**
     * @return array{name: string, name_fr: string, symbol: string}
     */
    public static function find(string $code): array
    {
        $code = strtoupper($code);
        $all = self::all();

        return $all[$code] ?? [
            'name' => $code,
            'name_fr' => $code,
            'symbol' => $code,
        ];
    }
}
