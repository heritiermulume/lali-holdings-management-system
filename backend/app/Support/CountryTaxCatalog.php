<?php

namespace App\Support;

class CountryTaxCatalog
{
    /**
     * @return array{tax_name: string, tax_rate: float}
     */
    public static function for(string $iso): array
    {
        $iso = strtoupper($iso);

        $map = [
            'CD' => ['tax_name' => 'TVA', 'tax_rate' => 16],
            'CG' => ['tax_name' => 'TVA', 'tax_rate' => 18.9],
            'TZ' => ['tax_name' => 'VAT', 'tax_rate' => 18],
            'RW' => ['tax_name' => 'VAT', 'tax_rate' => 18],
            'BI' => ['tax_name' => 'TVA', 'tax_rate' => 18],
            'KE' => ['tax_name' => 'VAT', 'tax_rate' => 16],
            'UG' => ['tax_name' => 'VAT', 'tax_rate' => 18],
            'ZA' => ['tax_name' => 'VAT', 'tax_rate' => 15],
            'AO' => ['tax_name' => 'IVA', 'tax_rate' => 14],
            'ZM' => ['tax_name' => 'VAT', 'tax_rate' => 16],
            'FR' => ['tax_name' => 'TVA', 'tax_rate' => 20],
            'BE' => ['tax_name' => 'TVA', 'tax_rate' => 21],
            'DE' => ['tax_name' => 'MwSt', 'tax_rate' => 19],
            'GB' => ['tax_name' => 'VAT', 'tax_rate' => 20],
            'US' => ['tax_name' => 'Sales tax', 'tax_rate' => 0],
            'AE' => ['tax_name' => 'VAT', 'tax_rate' => 5],
            'CN' => ['tax_name' => 'VAT', 'tax_rate' => 13],
        ];

        return $map[$iso] ?? ['tax_name' => 'TVA', 'tax_rate' => 0];
    }
}
