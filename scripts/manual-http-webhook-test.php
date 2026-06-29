<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\EnrollmentWebhookCredential;
use App\Models\Product;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('api', fn (Request $request) => Limit::none());

$nextId = (int) (Product::query()->max('id') ?? 0) + 1;
$course = new Product;
$course->forceFill([
    'id' => (string) $nextId,
    'tenant_id' => 1,
    'name' => 'Curso HTTP Manual',
    'slug' => 'http-manual-'.uniqid('', true),
    'type' => Product::TYPE_AREA_MEMBROS,
    'billing_type' => Product::BILLING_ONE_TIME,
    'price' => 97,
    'currency' => 'BRL',
    'is_active' => true,
    'checkout_slug' => 'slug-'.uniqid('', true),
    'checkout_config' => ['email_template' => Product::defaultEmailTemplate()],
]);
$course->save();

$issued = EnrollmentWebhookCredential::createWebhook(
    tenantId: 1,
    name: 'HTTP GG Test',
    productId: $course->id,
    platform: 'gg_checkout',
    externalProductId: null,
    isActive: true,
);

$key = $issued['model']->webhook_key;
$baseUrl = getenv('APP_URL') ?: 'http://127.0.0.1:8000';
$url = rtrim($baseUrl, '/').'/api/webhooks/enrollment/'.$key;
$email = 'http-gg-'.uniqid('', true).'@example.com';

$payload = [
    'event' => 'pix.generated',
    'customer' => [
        'name' => 'Teste HTTP',
        'email' => $email,
        'phone' => '5511999887766',
    ],
    'payment' => ['id' => 'pay-http-1', 'status' => 'waiting_payment'],
    'product' => ['id' => 'EXT-999', 'title' => 'Produto Externo'],
];

$response = Http::timeout(30)->acceptJson()->post($url, $payload);
$user = User::query()->where('email', $email)->first();

echo "=== Teste HTTP manual (GG Checkout) ===\n";
echo "URL: {$url}\n";
echo "HTTP: {$response->status()}\n";
echo "Resposta: {$response->body()}\n";
echo 'Aluno criado: '.($user ? "sim (id {$user->id})" : 'não')."\n";
echo 'Curso liberado: '.($user && $course->users()->where('users.id', $user->id)->exists() ? 'sim' : 'não')."\n";
echo 'Curso esperado (webhook): '.$course->id."\n";
