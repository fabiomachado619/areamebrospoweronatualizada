<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['mail.default' => 'log']);

use App\Models\EnrollmentWebhookCredential;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

['plain_token' => $plain] = EnrollmentWebhookCredential::issueForTenant(1, 'email-log-test');
$course = Product::query()->where('checkout_slug', 'curso-alpha')->first();
$ts = time();
$email = "email-log-{$ts}@test.local";

$response = Http::withToken($plain)->post('http://127.0.0.1/api/webhooks/enrollment', [
    'name' => 'Email Log Test',
    'email' => $email,
    'phone' => '55900001111',
    'document' => '11122233344',
    'course_id' => (string) $course->id,
    'platform' => 'kiwify',
    'event' => 'purchase_approved',
    'transaction_id' => "tx-email-log-{$ts}",
    'send_access_email' => true,
]);

echo "MAIL_MAILER=log\n";
echo "HTTP {$response->status()}\n";
echo $response->body()."\n";

$logPath = storage_path('logs/laravel.log');
if (is_file($logPath)) {
    $lines = array_slice(file($logPath), -30);
    echo "\n--- Trecho laravel.log (e-mail) ---\n";
    foreach ($lines as $line) {
        if (stripos($line, 'mail') !== false || stripos($line, 'AccessEmail') !== false || stripos($line, 'Message-ID') !== false) {
            echo $line;
        }
    }
}
