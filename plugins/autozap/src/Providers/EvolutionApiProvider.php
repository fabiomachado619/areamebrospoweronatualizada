<?php

namespace Plugins\AutoZap\Providers;

use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/AutoZapProviderInterface.php';

class EvolutionApiProvider implements AutoZapProviderInterface
{
    /**
     * @param  array{base_url?: string, apikey?: string, instance?: string}  $credentials
     */
    public function __construct(private array $credentials)
    {
    }

    private function baseUrl(): string
    {
        $base = trim((string) ($this->credentials['base_url'] ?? ''));
        if ($base === '') {
            throw new \RuntimeException('Evolution API: informe a base_url.');
        }
        return rtrim($base, '/');
    }

    private function apiKey(): string
    {
        $key = trim((string) ($this->credentials['apikey'] ?? $this->credentials['api_key'] ?? ''));
        if ($key === '') {
            throw new \RuntimeException('Evolution API: informe a apikey.');
        }
        return $key;
    }

    private function instance(): string
    {
        $instance = trim((string) ($this->credentials['instance'] ?? ''));
        if ($instance === '') {
            throw new \RuntimeException('Evolution API: informe a instance.');
        }
        return $instance;
    }

    private function client()
    {
        return Http::timeout(20)
            ->connectTimeout(5)
            ->withHeaders(['apikey' => $this->apiKey()]);
    }

    public function testConnection(): void
    {
        // Most basic: GET / (info) or GET /{instance} depending on server; doc shows GET base returns welcome.
        $res = $this->client()->get($this->baseUrl() . '/');
        if (! $res->successful()) {
            throw new \RuntimeException('Evolution API: falha ao conectar (HTTP ' . $res->status() . ').');
        }
    }

    public function sendText(string $toE164OrDigits, string $text, array $payload = []): array
    {
        $url = $this->baseUrl() . '/message/sendText/' . $this->instance();
        $body = [
            'number' => $toE164OrDigits,
            'text' => $text,
        ];
        $res = $this->client()->post($url, $body);
        if (! $res->successful()) {
            throw new \RuntimeException('Evolution API: erro ao enviar mensagem (HTTP ' . $res->status() . ').');
        }
        return (array) ($res->json() ?? []);
    }

    public function sendMedia(string $toE164OrDigits, string $caption, string $mediaUrl, string $mimeType, array $payload = []): array
    {
        $url = $this->baseUrl() . '/message/sendMedia/' . $this->instance();
        $body = [
            'number' => $toE164OrDigits,
            'mediatype' => 'document',
            'mimetype' => $mimeType,
            'caption' => $caption,
            'media' => $mediaUrl,
        ];
        $res = $this->client()->post($url, $body);
        if (! $res->successful()) {
            throw new \RuntimeException('Evolution API: erro ao enviar mídia (HTTP ' . $res->status() . ').');
        }
        return (array) ($res->json() ?? []);
    }

    public function sendInteractive(string $toE164OrDigits, array $interactive, array $payload = []): array
    {
        // Evolution API suporta templates/interactive dependendo da versão; no MVP, degradar para texto + links.
        $text = (string) ($interactive['text'] ?? '[Mensagem]');
        return $this->sendText($toE164OrDigits, $text, $payload);
    }
}

