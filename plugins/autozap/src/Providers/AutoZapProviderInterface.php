<?php

namespace Plugins\AutoZap\Providers;

interface AutoZapProviderInterface
{
    /**
     * @throws \Throwable on failure
     */
    public function testConnection(): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendText(string $toE164OrDigits, string $text, array $payload = []): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendMedia(string $toE164OrDigits, string $caption, string $mediaUrl, string $mimeType, array $payload = []): array;

    /**
     * Best-effort interactive messages (buttons/lists). Not all providers support it equally.
     *
     * @param  array<string, mixed>  $interactive
     * @param  array<string, mixed>  $payload
     */
    public function sendInteractive(string $toE164OrDigits, array $interactive, array $payload = []): array;
}

