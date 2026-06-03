<?php

namespace App\Support;

class PanelPushPreferences
{
    /** @return array{pix: bool, boleto: bool, card: bool} */
    public static function defaults(): array
    {
        return [
            'pix' => true,
            'boleto' => true,
            'card' => true,
        ];
    }

    /**
     * @param  mixed  $raw
     * @return array{pix: bool, boleto: bool, card: bool}
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (! is_array($raw)) {
            return $defaults;
        }

        return [
            'pix' => filter_var($raw['pix'] ?? $defaults['pix'], FILTER_VALIDATE_BOOLEAN),
            'boleto' => filter_var($raw['boleto'] ?? $defaults['boleto'], FILTER_VALIDATE_BOOLEAN),
            'card' => filter_var($raw['card'] ?? $defaults['card'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  array{pix: bool, boleto: bool, card: bool}  $preferences
     */
    public static function allowsCategory(array $preferences, string $category): bool
    {
        return match ($category) {
            'boleto' => (bool) ($preferences['boleto'] ?? true),
            'card' => (bool) ($preferences['card'] ?? true),
            default => (bool) ($preferences['pix'] ?? true),
        };
    }
}
