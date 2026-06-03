<?php

namespace App\Support;

class CountryCatalog
{
    /** @var array<string, string> */
    private const NAMES = [
        'BR' => 'Brasil', 'US' => 'Estados Unidos', 'CA' => 'Canadá', 'PT' => 'Portugal',
        'AR' => 'Argentina', 'MX' => 'México', 'CO' => 'Colômbia', 'CL' => 'Chile', 'PE' => 'Peru',
        'ES' => 'Espanha', 'GB' => 'Reino Unido', 'DE' => 'Alemanha', 'FR' => 'França', 'IT' => 'Itália',
        'AU' => 'Austrália', 'UY' => 'Uruguai', 'PY' => 'Paraguai', 'BO' => 'Bolívia', 'EC' => 'Equador',
        'VE' => 'Venezuela', 'IN' => 'Índia', 'JP' => 'Japão', 'CN' => 'China', 'AO' => 'Angola',
        'MZ' => 'Moçambique', 'CV' => 'Cabo Verde', 'NL' => 'Holanda', 'BE' => 'Bélgica',
        'CH' => 'Suíça', 'AT' => 'Áustria', 'IE' => 'Irlanda', 'SE' => 'Suécia', 'NO' => 'Noruega',
        'DK' => 'Dinamarca', 'PL' => 'Polônia', 'CZ' => 'Rep. Tcheca', 'RO' => 'Romênia',
        'HU' => 'Hungria', 'GR' => 'Grécia', 'TR' => 'Turquia', 'ZA' => 'África do Sul',
        'AE' => 'Emirados Árabes', 'SA' => 'Arábia Saudita', 'IL' => 'Israel', 'KR' => 'Coreia do Sul',
        'PH' => 'Filipinas', 'ID' => 'Indonésia', 'MY' => 'Malásia', 'SG' => 'Singapura',
        'TH' => 'Tailândia', 'VN' => 'Vietnã', 'NZ' => 'Nova Zelândia', 'RU' => 'Rússia',
        'UA' => 'Ucrânia', 'NG' => 'Nigéria', 'EG' => 'Egito', 'MA' => 'Marrocos',
        'CR' => 'Costa Rica', 'PA' => 'Panamá', 'GT' => 'Guatemala', 'DO' => 'Rep. Dominicana',
        'PR' => 'Porto Rico', 'HN' => 'Honduras', 'NI' => 'Nicarágua', 'SV' => 'El Salvador',
        'CU' => 'Cuba', 'JM' => 'Jamaica', 'TT' => 'Trinidad e Tobago', 'LU' => 'Luxemburgo',
        'FI' => 'Finlândia', 'IS' => 'Islândia', 'HK' => 'Hong Kong', 'TW' => 'Taiwan',
    ];

    /** @var array<string, array{lat: float, lng: float}> */
    private const COORDS = [
        'BR' => ['lat' => -14.235, 'lng' => -51.925],
        'US' => ['lat' => 37.09, 'lng' => -95.71],
        'CA' => ['lat' => 56.13, 'lng' => -106.35],
        'PT' => ['lat' => 39.4, 'lng' => -8.22],
        'AR' => ['lat' => -38.42, 'lng' => -63.62],
        'MX' => ['lat' => 23.63, 'lng' => -102.55],
        'CO' => ['lat' => 4.57, 'lng' => -74.3],
        'CL' => ['lat' => -35.68, 'lng' => -71.54],
        'PE' => ['lat' => -9.19, 'lng' => -75.02],
        'ES' => ['lat' => 40.46, 'lng' => -3.75],
        'GB' => ['lat' => 55.38, 'lng' => -3.44],
        'DE' => ['lat' => 51.17, 'lng' => 10.45],
        'FR' => ['lat' => 46.23, 'lng' => 2.21],
        'IT' => ['lat' => 41.87, 'lng' => 12.57],
        'AU' => ['lat' => -25.27, 'lng' => 133.78],
        'UY' => ['lat' => -32.52, 'lng' => -55.77],
        'PY' => ['lat' => -23.44, 'lng' => -58.44],
        'BO' => ['lat' => -16.29, 'lng' => -63.59],
        'EC' => ['lat' => -1.83, 'lng' => -78.18],
        'VE' => ['lat' => 6.42, 'lng' => -66.59],
        'IN' => ['lat' => 20.59, 'lng' => 78.96],
        'JP' => ['lat' => 36.2, 'lng' => 138.25],
        'CN' => ['lat' => 35.86, 'lng' => 104.2],
        'AO' => ['lat' => -11.2, 'lng' => 17.87],
        'MZ' => ['lat' => -18.67, 'lng' => 35.53],
        'CV' => ['lat' => 16.0, 'lng' => -24.0],
        'NL' => ['lat' => 52.13, 'lng' => 5.29],
        'BE' => ['lat' => 50.5, 'lng' => 4.47],
        'CH' => ['lat' => 46.82, 'lng' => 8.23],
        'AT' => ['lat' => 47.52, 'lng' => 14.55],
        'IE' => ['lat' => 53.14, 'lng' => -7.69],
        'SE' => ['lat' => 60.13, 'lng' => 18.64],
        'NO' => ['lat' => 60.47, 'lng' => 8.47],
        'DK' => ['lat' => 56.26, 'lng' => 9.5],
        'PL' => ['lat' => 51.92, 'lng' => 19.15],
        'CZ' => ['lat' => 49.82, 'lng' => 15.47],
        'RO' => ['lat' => 45.94, 'lng' => 24.97],
        'HU' => ['lat' => 47.16, 'lng' => 19.5],
        'GR' => ['lat' => 39.07, 'lng' => 21.82],
        'TR' => ['lat' => 38.96, 'lng' => 35.24],
        'ZA' => ['lat' => -30.56, 'lng' => 22.94],
        'AE' => ['lat' => 23.42, 'lng' => 53.85],
        'SA' => ['lat' => 23.89, 'lng' => 45.08],
        'IL' => ['lat' => 31.05, 'lng' => 34.85],
        'KR' => ['lat' => 35.91, 'lng' => 127.77],
        'PH' => ['lat' => 12.88, 'lng' => 121.77],
        'ID' => ['lat' => -0.79, 'lng' => 113.92],
        'MY' => ['lat' => 4.21, 'lng' => 101.98],
        'SG' => ['lat' => 1.35, 'lng' => 103.82],
        'TH' => ['lat' => 15.87, 'lng' => 100.99],
        'VN' => ['lat' => 14.06, 'lng' => 108.28],
        'NZ' => ['lat' => -40.9, 'lng' => 174.89],
        'RU' => ['lat' => 61.52, 'lng' => 105.32],
        'UA' => ['lat' => 48.38, 'lng' => 31.17],
        'NG' => ['lat' => 9.08, 'lng' => 8.68],
        'EG' => ['lat' => 26.82, 'lng' => 30.8],
        'MA' => ['lat' => 31.79, 'lng' => -7.09],
        'CR' => ['lat' => 9.75, 'lng' => -83.75],
        'PA' => ['lat' => 8.54, 'lng' => -80.78],
        'GT' => ['lat' => 15.78, 'lng' => -90.23],
        'DO' => ['lat' => 18.74, 'lng' => -70.16],
        'PR' => ['lat' => 18.22, 'lng' => -66.59],
        'HN' => ['lat' => 15.2, 'lng' => -86.24],
        'NI' => ['lat' => 12.87, 'lng' => -85.21],
        'SV' => ['lat' => 13.79, 'lng' => -88.9],
        'CU' => ['lat' => 21.52, 'lng' => -77.78],
        'JM' => ['lat' => 18.11, 'lng' => -77.3],
        'TT' => ['lat' => 10.69, 'lng' => -61.22],
        'LU' => ['lat' => 49.82, 'lng' => 6.13],
        'FI' => ['lat' => 61.92, 'lng' => 25.75],
        'IS' => ['lat' => 64.96, 'lng' => -19.02],
        'HK' => ['lat' => 22.32, 'lng' => 114.17],
        'TW' => ['lat' => 23.7, 'lng' => 120.96],
    ];

    public static function name(?string $code): string
    {
        if ($code === null || strlen(trim($code)) !== 2) {
            return 'Desconhecido';
        }

        $upper = strtoupper(trim($code));

        return self::NAMES[$upper] ?? $upper;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public static function coordinates(?string $code): ?array
    {
        if ($code === null || strlen(trim($code)) !== 2) {
            return null;
        }

        $upper = strtoupper(trim($code));

        return self::COORDS[$upper] ?? null;
    }

    public static function isValidIso(?string $code): bool
    {
        return is_string($code) && strlen(trim($code)) === 2;
    }

    public static function normalize(?string $code): ?string
    {
        if (! self::isValidIso($code)) {
            return null;
        }

        return strtoupper(trim($code));
    }
}
