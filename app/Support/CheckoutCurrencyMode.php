<?php

namespace App\Support;

class CheckoutCurrencyMode
{
    /**
     * @param  array<string, mixed>|null  $checkoutConfig
     * @return array{mode: 'global'|'fixed', currency: string}
     */
    public static function resolve(?array $checkoutConfig): array
    {
        $config = is_array($checkoutConfig) ? $checkoutConfig : [];
        $cc = $config['checkout_currency'] ?? [];
        if (is_array($cc) && in_array($cc['mode'] ?? '', ['global', 'fixed'], true)) {
            $currency = strtoupper(trim((string) ($cc['currency'] ?? 'BRL')));

            return [
                'mode' => $cc['mode'] === 'fixed' ? 'fixed' : 'global',
                'currency' => $currency !== '' ? $currency : 'BRL',
            ];
        }

        $force = $config['checkout_force'] ?? [];
        if (is_array($force) && ! empty($force['enabled'])) {
            $currency = strtoupper(trim((string) ($force['currency'] ?? '')));
            if ($currency !== '') {
                return ['mode' => 'fixed', 'currency' => $currency];
            }
        }

        return ['mode' => 'global', 'currency' => 'BRL'];
    }

    public static function isFixed(?array $checkoutConfig): bool
    {
        return self::resolve($checkoutConfig)['mode'] === 'fixed';
    }

    /**
     * @param  list<array{code: string, symbol: string, label: string, rate_to_brl: float}>  $allCurrencies
     * @return list<array{code: string, symbol: string, label: string, rate_to_brl: float}>
     */
    public static function filterCurrenciesForCheckout(?array $checkoutConfig, array $allCurrencies): array
    {
        $resolved = self::resolve($checkoutConfig);
        if ($resolved['mode'] !== 'fixed') {
            return $allCurrencies;
        }

        $code = $resolved['currency'];
        foreach ($allCurrencies as $row) {
            if (strtoupper((string) ($row['code'] ?? '')) === $code) {
                return [$row];
            }
        }

        $meta = CheckoutCurrencyCatalog::metadataFor($code);

        return [[
            'code' => $code,
            'symbol' => $meta['symbol'],
            'label' => $meta['label'],
            'rate_to_brl' => max(0.0, CheckoutCurrencyCatalog::fallbackRateToBrl($code)),
        ]];
    }
}
