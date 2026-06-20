<?php

namespace Plugins\AutoZap\Services;

class AutoZapTemplate
{
    /**
     * Render template with {{path.to.value}} and legacy {nome_cliente}/{nome_produto}/{link_checkout}/{link_acesso}.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function render(string $template, array $payload): string
    {
        $out = $template;

        // Legacy placeholders used in email templates (quick win for users).
        $legacy = [
            '{nome_cliente}' => (string) ($payload['customer']['name'] ?? ''),
            '{email_cliente}' => (string) ($payload['customer']['email'] ?? ''),
            '{telefone_cliente}' => (string) ($payload['customer']['phone'] ?? ''),
            '{link_checkout}' => (string) ($payload['checkout_link'] ?? ''),
            '{link_acesso}' => (string) ($payload['checkout_link'] ?? ''),
            '{nome_produto}' => (string) ($payload['order']['product']['name'] ?? $payload['subscription']['product']['name'] ?? ''),
        ];
        $out = strtr($out, $legacy);

        // {{a.b.c}} placeholders.
        $out = preg_replace_callback('/\\{\\{\\s*([a-zA-Z0-9_\\.]+)\\s*\\}\\}/', function ($m) use ($payload) {
            $path = $m[1] ?? '';
            $val = self::getByPath($payload, $path);
            if (is_array($val) || is_object($val)) return '';
            return $val === null ? '' : (string) $val;
        }, $out) ?? $out;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function getByPath(array $payload, string $path): mixed
    {
        $cur = $payload;
        foreach (explode('.', $path) as $k) {
            if (is_array($cur) && array_key_exists($k, $cur)) {
                $cur = $cur[$k];
            } else {
                return null;
            }
        }
        return $cur;
    }
}

