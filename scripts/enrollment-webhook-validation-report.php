<?php

/**
 * Validação local Fase 2 — webhook de matrícula n8n.
 * Uso: php scripts/enrollment-webhook-validation-report.php
 */

use App\Models\EnrollmentExternalProductMapping;
use App\Models\EnrollmentWebhookCredential;
use App\Models\EnrollmentWebhookLog;
use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberHubService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$baseUrl = rtrim(env('WEBHOOK_VALIDATION_URL', env('APP_URL', 'http://127.0.0.1')), '/');
$endpoint = $baseUrl.'/api/webhooks/enrollment';
$tenantId = 1;
$runId = substr(uniqid('val', true), -8);

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  VALIDAÇÃO FASE 2 — Webhook de Matrícula (run: {$runId})\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "Endpoint: {$endpoint}\n\n";

function section(string $title): void
{
    echo "\n── {$title} ".str_repeat('─', max(0, 58 - strlen($title)))."\n";
}

function postEnrollment(string $endpoint, string $token, array $payload): array
{
    $response = Http::withToken($token)
        ->acceptJson()
        ->asJson()
        ->post($endpoint, $payload);

    return [
        'status' => $response->status(),
        'body' => $response->json() ?? ['raw' => $response->body()],
    ];
}

function ok(bool $cond, string $label): void
{
    echo ($cond ? '  ✓ ' : '  ✗ ').$label.($cond ? '' : ' [FALHOU]')."\n";
}

// ── Setup ────────────────────────────────────────────────────────
section('Setup');

$hub = Product::query()->where('checkout_slug', 'hub-validacao')->first();
$courseAlpha = Product::query()->where('checkout_slug', 'curso-alpha')->first();
$courseBeta = Product::query()->where('checkout_slug', 'curso-beta')->first();
$courseGamma = Product::query()->where('checkout_slug', 'curso-gamma')->first();

if (! $hub || ! $courseAlpha || ! $courseBeta || ! $courseGamma) {
    echo "  Executando HubValidationSeeder...\n";
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\HubValidationSeeder', '--force' => true]);
    $hub = Product::query()->where('checkout_slug', 'hub-validacao')->fresh();
    $courseAlpha = Product::query()->where('checkout_slug', 'curso-alpha')->first();
    $courseBeta = Product::query()->where('checkout_slug', 'curso-beta')->first();
    $courseGamma = Product::query()->where('checkout_slug', 'curso-gamma')->first();
}

if (! $hub->isMemberHub()) {
    app(MemberHubService::class)->designateHub($hub->fresh());
    $hub = $hub->fresh();
}

echo "  HUB: {$hub->name} (id={$hub->id}, slug=hub-validacao)\n";
echo "  Curso Alpha: id={$courseAlpha->id}\n";
echo "  Curso Beta:  id={$courseBeta->id}\n";
echo "  Curso Gamma: id={$courseGamma->id}\n";

$otherTenantCourse = Product::query()->firstOrCreate(
    ['checkout_slug' => 'curso-tenant2'],
    [
        'tenant_id' => 2,
        'name' => 'Curso Tenant 2',
        'slug' => 'curso-tenant2',
        'type' => Product::TYPE_AREA_MEMBROS,
        'billing_type' => Product::BILLING_ONE_TIME,
        'price' => 50,
        'currency' => 'BRL',
        'is_active' => true,
    ]
);

$externalId = 'kiwify-prod-gamma-'.$runId;
EnrollmentExternalProductMapping::query()->updateOrCreate(
    [
        'tenant_id' => $tenantId,
        'platform' => 'kiwify',
        'external_product_id' => $externalId,
    ],
    ['product_id' => $courseGamma->id]
);
echo "  Mapping: kiwify/{$externalId} → Curso Gamma\n";

['plain_token' => $token] = EnrollmentWebhookCredential::issueForTenant($tenantId, 'n8n-validation-'.$runId);
echo "  Token gerado (prefixo): ".substr($token, 0, 12)."...\n";

$emailNew = "webhook-novo-{$runId}@test.local";
$emailExisting = "webhook-existente-{$runId}@test.local";
$emailDup = "webhook-dup-{$runId}@test.local";
$emailRefund = "webhook-refund-{$runId}@test.local";
$emailMapped = "webhook-mapped-{$runId}@test.local";
$emailNoMail = "webhook-nomail-{$runId}@test.local";

$results = [];

// ── 1. Compra aprovada — aluno novo ────────────────────────────
section('1. Compra aprovada — aluno novo');

$tx1 = "tx-new-{$runId}";
$r1 = postEnrollment($endpoint, $token, [
    'name' => 'Aluno Webhook Novo',
    'email' => $emailNew,
    'phone' => '5598888777666',
    'document' => '12345678901',
    'course_id' => (string) $courseAlpha->id,
    'hub_id' => (string) $hub->id,
    'platform' => 'kiwify',
    'event' => 'purchase_approved',
    'transaction_id' => $tx1,
    'status' => 'approved',
    'send_access_email' => true,
]);
echo "  HTTP {$r1['status']}: ".json_encode($r1['body'], JSON_UNESCAPED_UNICODE)."\n";
$results['1'] = $r1;

$userNew = User::query()->where('email', $emailNew)->first();
ok($r1['status'] === 200 && ($r1['body']['action'] ?? '') === 'enrolled', 'Matrícula criada');
ok($userNew !== null, 'Aluno criado no banco');
ok($userNew && $userNew->role === User::ROLE_ALUNO, 'Role = aluno');
ok($userNew && $userNew->phone === '5598888777666', 'phone salvo');
ok($userNew && $userNew->document === '12345678901', 'document salvo');
ok($userNew && $courseAlpha->users()->where('users.id', $userNew->id)->exists(), 'product_user matriculado');

// ── 2. Compra aprovada — aluno existente, novo curso ───────────
section('2. Compra aprovada — aluno existente, novo curso');

User::query()->where('email', $emailExisting)->delete();
$existingUser = User::create([
    'name' => 'Aluno Existente',
    'email' => $emailExisting,
    'password' => bcrypt('senha-antiga-fixa'),
    'role' => User::ROLE_ALUNO,
    'tenant_id' => $tenantId,
]);
$oldPasswordHash = $existingUser->password;
$courseAlpha->users()->syncWithoutDetaching([$existingUser->id]);

$tx2 = "tx-existing-{$runId}";
$r2 = postEnrollment($endpoint, $token, [
    'name' => 'Aluno Existente Atualizado',
    'email' => $emailExisting,
    'phone' => '5591111222333',
    'document' => '98765432100',
    'course_id' => (string) $courseBeta->id,
    'platform' => 'kiwify',
    'event' => 'purchase_approved',
    'transaction_id' => $tx2,
    'send_access_email' => true,
]);
echo "  HTTP {$r2['status']}: ".json_encode($r2['body'], JSON_UNESCAPED_UNICODE)."\n";
$results['2'] = $r2;

$existingUser->refresh();
ok($r2['status'] === 200 && ($r2['body']['action'] ?? '') === 'enrolled', 'Novo curso liberado');
ok($existingUser->name === 'Aluno Existente Atualizado', 'Nome atualizado');
ok($existingUser->phone === '5591111222333', 'Telefone atualizado');
ok($existingUser->password === $oldPasswordHash, 'Senha NÃO alterada');
ok($courseBeta->users()->where('users.id', $existingUser->id)->exists(), 'Matrícula no Curso Beta');

// ── 3. E-mail enviado (send_access_email=true) ──────────────────
section('3. E-mail de acesso enviado');

$logEmailSent = EnrollmentWebhookLog::query()
    ->where('transaction_id', $tx1)
    ->where('action', 'enrolled')
    ->first();
ok($logEmailSent && $logEmailSent->email_sent === true, "Log tx1 email_sent=true (valor: ".($logEmailSent->email_sent ? 'true' : 'false').')');
ok(($r1['body']['email_sent'] ?? false) === true, 'Resposta API email_sent=true (cenário 1)');

$txNoMail = "tx-nomail-{$runId}";
$rNoMail = postEnrollment($endpoint, $token, [
    'name' => 'Sem Email',
    'email' => $emailNoMail,
    'course_id' => (string) $courseAlpha->id,
    'platform' => 'kiwify',
    'event' => 'purchase_approved',
    'transaction_id' => $txNoMail,
    'send_access_email' => false,
]);
echo "  send_access_email=false → HTTP {$rNoMail['status']}: ".json_encode($rNoMail['body'], JSON_UNESCAPED_UNICODE)."\n";
$logNoMail = EnrollmentWebhookLog::query()->where('transaction_id', $txNoMail)->first();
ok($logNoMail && $logNoMail->email_sent === false, 'Log send_access_email=false → email_sent=false');
ok(($rNoMail['body']['email_sent'] ?? true) === false, 'Resposta API email_sent=false');

// ── 4. Link do e-mail aponta para HUB ───────────────────────────
section('4. Link do e-mail → HUB');

$hubSlug = $hub->checkout_slug;
$expectedHubPath = '/m/'.$hubSlug;
$linkProduct = app(MemberHubService::class)->hubForTenant($tenantId);
ok($linkProduct !== null && $linkProduct->id === $hub->id, 'HUB detectado para tenant');

if ($userNew) {
    $accessEmail = app(\App\Services\AccessEmailService::class);
    $ref = new ReflectionClass($accessEmail);
    $method = $ref->getMethod('resolveMemberAreaMagicLink');
    $method->setAccessible(true);
    $magicLink = $method->invoke($accessEmail, $linkProduct ?? $courseAlpha, $userNew);
    echo "  Magic link gerado: {$magicLink}\n";
    ok(str_contains($magicLink, $hubSlug) || str_contains($magicLink, 'hub-validacao'), 'Link contém slug do HUB');
} else {
    ok(false, 'Usuário novo não encontrado para teste de link');
}

// ── 5. Payload duplicado ────────────────────────────────────────
section('5. Payload duplicado — sem reenvio de e-mail');

$txDup = "tx-dup-{$runId}";
$dupPayload = [
    'name' => 'Dup Test',
    'email' => $emailDup,
    'course_id' => (string) $courseAlpha->id,
    'platform' => 'kiwify',
    'event' => 'purchase_approved',
    'transaction_id' => $txDup,
    'send_access_email' => true,
];
$rDup1 = postEnrollment($endpoint, $token, $dupPayload);
$rDup2 = postEnrollment($endpoint, $token, $dupPayload);
echo "  1ª chamada: HTTP {$rDup1['status']} → ".json_encode($rDup1['body'], JSON_UNESCAPED_UNICODE)."\n";
echo "  2ª chamada: HTTP {$rDup2['status']} → ".json_encode($rDup2['body'], JSON_UNESCAPED_UNICODE)."\n";
ok(($rDup1['body']['action'] ?? '') === 'enrolled', 'Primeira chamada enrolled');
ok(($rDup2['body']['duplicate'] ?? false) === true, 'Segunda chamada duplicate=true');
ok(($rDup2['body']['email_sent'] ?? true) === false, 'Segunda chamada email_sent=false');
$dupLogs = EnrollmentWebhookLog::query()->where('transaction_id', $txDup)->count();
ok($dupLogs === 1, "Apenas 1 log para transaction_id duplicado (count={$dupLogs})");
$results['5'] = $rDup2;

// ── 6. Reembolso — remove acesso, mantém progresso ──────────────
section('6. Reembolso — remove acesso, mantém progresso');

User::query()->where('email', $emailRefund)->delete();
$refundUser = User::create([
    'name' => 'Aluno Refund',
    'email' => $emailRefund,
    'password' => bcrypt('refund-pass'),
    'role' => User::ROLE_ALUNO,
    'tenant_id' => $tenantId,
]);
$courseAlpha->users()->syncWithoutDetaching([$refundUser->id]);

$section = MemberSection::query()->firstOrCreate(
    ['product_id' => $courseAlpha->id, 'title' => 'Progresso Test'],
    ['position' => 99, 'section_type' => 'content']
);
$module = MemberModule::query()->firstOrCreate(
    ['member_section_id' => $section->id, 'title' => 'Mod Progresso'],
    ['product_id' => $courseAlpha->id, 'position' => 1]
);
$lesson = MemberLesson::query()->firstOrCreate(
    ['member_module_id' => $module->id, 'title' => 'Lição Progresso'],
    ['product_id' => $courseAlpha->id, 'position' => 1, 'type' => MemberLesson::TYPE_TEXT]
);
$progress = MemberLessonProgress::query()->create([
    'user_id' => $refundUser->id,
    'member_lesson_id' => $lesson->id,
    'product_id' => $courseAlpha->id,
    'completed_at' => now(),
    'progress_percent' => 100,
]);
$progressId = $progress->id;

$txRefund = "tx-refund-{$runId}";
$rRefund = postEnrollment($endpoint, $token, [
    'email' => $emailRefund,
    'course_id' => (string) $courseAlpha->id,
    'platform' => 'kiwify',
    'event' => 'refund',
    'transaction_id' => $txRefund,
]);
echo "  HTTP {$rRefund['status']}: ".json_encode($rRefund['body'], JSON_UNESCAPED_UNICODE)."\n";
ok(($rRefund['body']['action'] ?? '') === 'revoked', 'Ação revoked');
ok(! $courseAlpha->users()->where('users.id', $refundUser->id)->exists(), 'product_user removido');
ok(User::query()->find($refundUser->id) !== null, 'Usuário mantido');
ok(MemberLessonProgress::query()->find($progressId) !== null, 'Progresso mantido (id='.$progressId.')');
$results['6'] = $rRefund;

// ── 7. Produto externo via mapping ─────────────────────────────
section('7. Produto externo via mapping');

$txMap = "tx-map-{$runId}";
$rMap = postEnrollment($endpoint, $token, [
    'name' => 'Aluno Mapping',
    'email' => $emailMapped,
    'platform' => 'kiwify',
    'external_product_id' => $externalId,
    'event' => 'purchase_approved',
    'transaction_id' => $txMap,
    'send_access_email' => true,
]);
echo "  HTTP {$rMap['status']}: ".json_encode($rMap['body'], JSON_UNESCAPED_UNICODE)."\n";
ok($rMap['status'] === 200, 'Mapping resolveu curso');
ok((string) ($rMap['body']['course_id'] ?? '') === (string) $courseGamma->id, 'Curso Gamma via external_product_id');
$mappedUser = User::query()->where('email', $emailMapped)->first();
ok($mappedUser && $courseGamma->users()->where('users.id', $mappedUser->id)->exists(), 'Matriculado no Curso Gamma');
$results['7'] = $rMap;

// ── 8. Curso de outro tenant bloqueado ───────────────────────────
section('8. Curso de outro tenant — bloqueado');

$txBlock = "tx-block-{$runId}";
$rBlock = postEnrollment($endpoint, $token, [
    'name' => 'Blocked',
    'email' => "blocked-{$runId}@test.local",
    'course_id' => (string) $otherTenantCourse->id,
    'platform' => 'kiwify',
    'event' => 'purchase_approved',
    'transaction_id' => $txBlock,
]);
echo "  HTTP {$rBlock['status']}: ".json_encode($rBlock['body'], JSON_UNESCAPED_UNICODE)."\n";
ok($rBlock['status'] === 422, 'HTTP 422 para tenant errado');
ok(($rBlock['body']['success'] ?? true) === false, 'success=false');
$results['8'] = $rBlock;

// ── Resumo banco ────────────────────────────────────────────────
section('Resumo — enrollment_webhook_logs');

$logs = EnrollmentWebhookLog::query()
    ->where('tenant_id', $tenantId)
    ->where(function ($q) use ($runId) {
        $q->where('transaction_id', 'like', "%{$runId}%")
            ->orWhere('email', 'like', "%{$runId}%");
    })
    ->orderBy('id')
    ->get(['id', 'email', 'event', 'transaction_id', 'action', 'email_sent', 'course_id', 'error_message']);

echo "  ".str_pad('ID', 5).' | '.str_pad('action', 10).' | '.str_pad('email_sent', 10).' | transaction_id | email'."\n";
echo '  '.str_repeat('-', 90)."\n";
foreach ($logs as $log) {
    echo '  '.str_pad((string) $log->id, 5).' | '
        .str_pad($log->action, 10).' | '
        .str_pad($log->email_sent ? 'true' : 'false', 10).' | '
        .$log->transaction_id.' | '
        .$log->email."\n";
}

section('Resumo — product_user (alunos deste run)');

$emails = [$emailNew, $emailExisting, $emailDup, $emailRefund, $emailMapped, $emailNoMail];
foreach ($emails as $em) {
    $u = User::query()->where('email', $em)->first();
    if (! $u) {
        echo "  {$em}: (não encontrado)\n";
        continue;
    }
    $courses = DB::table('product_user')
        ->join('products', 'products.id', '=', 'product_user.product_id')
        ->where('product_user.user_id', $u->id)
        ->pluck('products.checkout_slug')
        ->implode(', ');
    echo "  {$em}: phone={$u->phone}, doc={$u->document}, cursos=[{$courses}]\n";
}

section('Logs Laravel (e-mail)');
$logFile = storage_path('logs/laravel.log');
if (is_file($logFile)) {
    $tail = shell_exec('tail -n 80 '.escapeshellarg($logFile).' 2>/dev/null | grep -i "AccessEmailService\\|AccessGrantedMail\\|webhook" || true');
    echo $tail ?: "  (nenhuma linha recente de e-mail encontrada)\n";
} else {
    echo "  laravel.log não encontrado\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  Validação concluída — run: {$runId}\n";
echo "  Token (guarde para testes manuais n8n):\n";
echo "  {$token}\n";
echo "═══════════════════════════════════════════════════════════════\n\n";
