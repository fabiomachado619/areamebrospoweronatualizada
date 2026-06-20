<?php

namespace Plugins\AutoZap\Providers;

use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/AutoZapProviderInterface.php';

class ZApiProvider implements AutoZapProviderInterface
{
    /**
     * @param  array{instance_id?: string, token?: string, client_token?: string, base_url?: string}  $credentials
     */
    public function __construct(private array $credentials)
    {
    }

    private function baseUrl(): string
    {
        $base = trim((string) ($this->credentials['base_url'] ?? 'https://api.z-api.io'));
        return rtrim($base, '/');
    }

    private function instanceId(): string
    {
        return trim((string) ($this->credentials['instance_id'] ?? ''));
    }

    private function token(): string
    {
        return trim((string) ($this->credentials['token'] ?? ''));
    }

    private function withSecurityHeader($req)
    {
        $clientToken = trim((string) ($this->credentials['client_token'] ?? ''));
        return $clientToken !== '' ? $req->withHeaders(['Client-Token' => $clientToken]) : $req;
    }

    public function testConnection(): void
    {
        // Minimal check: hit a lightweight endpoint (instance status is documented in Z-API; when not available, fallback to send-text dry-run is not safe).
        $instance = $this->instanceId();
        $token = $this->token();
        if ($instance === '' || $token === '') {
            throw new \RuntimeException('Z-API: informe instance_id e token.');
        }

        $url = $this->baseUrl() . "/instances/{$instance}/token/{$token}/status";
        $req = Http::timeout(10)->connectTimeout(5);
        $req = $this->withSecurityHeader($req);
        $res = $req->get($url);

        if (! $res->successful()) {
            throw new \RuntimeException('Z-API: falha ao conectar (HTTP ' . $res->status() . ').');
        }
    }

    public function sendText(string $toE164OrDigits, string $text, array $payload = []): array
    {
        $instance = $this->instanceId();
        $token = $this->token();
        if ($instance === '' || $token === '') {
            throw new \RuntimeException('Z-API: credenciais ausentes.');
        }

        $url = $this->baseUrl() . "/instances/{$instance}/token/{$token}/send-text";
        $body = [
            'phone' => $toE164OrDigits,
            'message' => $text,
        ];
        $req = Http::timeout(20)->connectTimeout(5);
        $req = $this->withSecurityHeader($req);
        $res = $req->post($url, $body);

        if (! $res->successful()) {
            throw new \RuntimeException('Z-API: erro ao enviar mensagem (HTTP ' . $res->status() . ').');
        }
        return (array) ($res->json() ?? []);
    }

    public function sendMedia(string $toE164OrDigits, string $caption, string $mediaUrl, string $mimeType, array $payload = []): array
    {
        $instance = $this->instanceId();
        $token = $this->token();
        if ($instance === '' || $token === '') {
            throw new \RuntimeException('Z-API: credenciais ausentes.');
        }

        // Z-API tem endpoints diferentes (send-image/send-document/send-video). Para MVP: usar send-file (quando existir) ou document como fallback.
        $url = $this->baseUrl() . "/instances/{$instance}/token/{$token}/send-document";
        $body = [
            'phone' => $toE164OrDigits,
            'document' => $mediaUrl,
            'caption' => $caption,
        ];
        $req = Http::timeout(25)->connectTimeout(5);
        $req = $this->withSecurityHeader($req);
        $res = $req->post($url, $body);

        if (! $res->successful()) {
            throw new \RuntimeException('Z-API: erro ao enviar mídia (HTTP ' . $res->status() . ').');
        }
        return (array) ($res->json() ?? []);
    }

    public function sendInteractive(string $toE164OrDigits, array $interactive, array $payload = []): array
    {
        // MVP: Z-API interactive varia por endpoint/plano; manter best-effort como texto.
        $text = (string) ($interactive['text'] ?? '[Mensagem]');
        return $this->sendText($toE164OrDigits, $text, $payload);
    }
}

