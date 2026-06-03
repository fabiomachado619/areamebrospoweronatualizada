<?php

namespace Tests\Unit;

use App\Support\CheckoutCurrencyMode;
use Tests\TestCase;

class CheckoutCurrencyModeTest extends TestCase
{
    public function test_resolve_global_by_default(): void
    {
        $this->assertSame(
            ['mode' => 'global', 'currency' => 'BRL'],
            CheckoutCurrencyMode::resolve(null)
        );
    }

    public function test_resolve_fixed_from_checkout_currency_config(): void
    {
        $this->assertSame(
            ['mode' => 'fixed', 'currency' => 'USD'],
            CheckoutCurrencyMode::resolve([
                'checkout_currency' => ['mode' => 'fixed', 'currency' => 'usd'],
            ])
        );
    }

    public function test_filter_currencies_to_single_when_fixed(): void
    {
        $all = [
            ['code' => 'BRL', 'symbol' => 'R$', 'label' => 'Real', 'rate_to_brl' => 1.0],
            ['code' => 'USD', 'symbol' => '$', 'label' => 'Dólar', 'rate_to_brl' => 0.18],
        ];

        $filtered = CheckoutCurrencyMode::filterCurrenciesForCheckout(
            ['checkout_currency' => ['mode' => 'fixed', 'currency' => 'USD']],
            $all
        );

        $this->assertCount(1, $filtered);
        $this->assertSame('USD', $filtered[0]['code']);
    }
}
