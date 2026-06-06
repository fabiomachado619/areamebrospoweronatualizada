<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Services\MemberHubService;

$slug = $argv[1] ?? 'hub-validacao';
$hub = Product::query()->where('checkout_slug', $slug)->first();
if (! $hub) {
    echo "Hub não encontrado: {$slug}\n";
    exit(1);
}

$tenantHub = app(MemberHubService::class)->hubForTenant($hub->tenant_id);

echo "=== PWA Audit ===\n";
echo "Product slug: {$hub->checkout_slug}\n";
echo "Product name: {$hub->name}\n";
echo "is_member_hub: ".($hub->isMemberHub() ? 'sim' : 'não')."\n";
echo "tenant hub id: ".($tenantHub?->id ?? 'null')."\n";
echo "same as tenant hub: ".($tenantHub && (string) $tenantHub->id === (string) $hub->id ? 'sim' : 'não')."\n\n";

$config = $hub->member_area_config ?? [];
$pwa = $config['pwa'] ?? [];
$logos = $config['logos'] ?? [];
$theme = $config['theme'] ?? [];

echo "member_area_config.pwa:\n";
echo json_encode($pwa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n";
echo "member_area_config.logos.favicon: ".($logos['favicon'] ?? '(vazio)')."\n";
echo "member_area_config.theme.background: ".($theme['background'] ?? '(vazio)')."\n\n";

$manifestUrl = rtrim(config('app.url'), '/').'/m/'.$slug.'/manifest.json';
echo "Manifest URL: {$manifestUrl}\n";

$json = @file_get_contents($manifestUrl);
if ($json) {
    echo "Manifest response:\n";
    echo json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
} else {
    echo "Não foi possível buscar manifest via file_get_contents.\n";
}

if (in_array('--save-test', $argv, true)) {
    echo "\n=== Simulando save PWA ===\n";
    $svc = app(\App\Services\MemberAreaPwaAdminService::class);
    $result = $svc->updatePwa((int) $hub->tenant_id, [
        'name' => 'Meu App Teste',
        'short_name' => 'MeuApp',
        'theme_color' => '#ff0000',
        'background_color' => '#000000',
        'favicon' => 'https://cdn.test/icon.png',
        'push_enabled' => false,
    ]);
    echo "Salvo: ".json_encode($result['pwa_settings'], JSON_UNESCAPED_UNICODE)."\n";

    $hub = $hub->fresh();
    echo "DB pwa.name: ".($hub->member_area_config['pwa']['name'] ?? '')."\n";

    $ch = curl_init($manifestUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: localhost:8081']);
    $manifestAfter = curl_exec($ch);
    curl_close($ch);
    echo "Manifest após save:\n";
    echo json_encode(json_decode($manifestAfter ?: '{}', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
}
