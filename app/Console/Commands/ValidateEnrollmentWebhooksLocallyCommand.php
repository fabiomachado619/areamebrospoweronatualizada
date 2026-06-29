<?php

namespace App\Console\Commands;

use App\Events\AccessDeliveryReady;
use App\Models\EnrollmentExternalProductMapping;
use App\Models\EnrollmentWebhookCredential;
use App\Models\EnrollmentWebhookLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Services\TenantMailConfigService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ValidateEnrollmentWebhooksLocallyCommand extends Command
{
    protected $signature = 'enrollment:validate-local
                            {--base-url= : URL base (ex.: http://127.0.0.1:8000). Vazio = kernel HTTP interno}
                            {--fresh : Recria banco SQLite local antes de validar}';

    protected $description = 'Validação local completa do fluxo de Webhooks de Matrícula (todas as plataformas)';

    /** @var list<array<string, mixed>> */
    private array $results = [];

    private int $passed = 0;

    private int $failed = 0;

    private string $outboundUrl = 'http://127.0.0.1:19876/capture';

    public function handle(): int
    {
        $this->info('=== Validação local — Webhooks de Matrícula ===');
        $this->configureLocalMailForValidation();
        $this->disableApiRateLimiting();
        $this->newLine();

        if ($this->option('fresh')) {
            $this->prepareLocalDatabase();
        }

        if (! Schema::hasTable('products')) {
            $this->error('Banco não migrado. Execute: php artisan migrate --force');

            return self::FAILURE;
        }

        $captureStarted = false;
        $outboundCaptures = [];

        foreach ($this->platformDefinitions() as $platform => $definition) {
            $this->validatePlatform($platform, $definition, $outboundCaptures);
        }

        $this->validateOutboundPhoneCases($outboundCaptures);

        if ($captureStarted) {
            $this->stopOutboundCaptureServer();
        }

        $this->newLine();
        $this->info("Resumo: {$this->passed} OK | {$this->failed} FALHA");
        $this->line('Relatório: storage/logs/manual-enrollment-validation.json');

        file_put_contents(
            storage_path('logs/manual-enrollment-validation.json'),
            json_encode([
                'executed_at' => now()->toIso8601String(),
                'passed' => $this->passed,
                'failed' => $this->failed,
                'results' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return $this->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function disableApiRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::none());
    }

    private function configureLocalMailForValidation(): void
    {
        app()->instance(TenantMailConfigService::class, new class extends TenantMailConfigService
        {
            public function applyMailerConfigForTenant(?int $tenantId, array $overrides = [], ?string $provider = null): void
            {
                config([
                    'mail.default' => 'log',
                    'mail.mailers.smtp.transport' => 'log',
                    'mail.from.address' => 'validacao-local@example.com',
                    'mail.from.name' => 'Validação Local',
                ]);
            }
        });
    }

    private function prepareLocalDatabase(): void
    {
        $dbPath = database_path('local_manual_validation.sqlite');
        if (is_file($dbPath)) {
            unlink($dbPath);
        }
        touch($dbPath);
        Artisan::call('migrate', ['--force' => true]);
        $this->line('Banco SQLite recriado: '.$dbPath);
    }

    /**
     * @return array<string, array{build: callable(string, array<string, mixed>): array<string, mixed>, revoke: callable(string): array<string, mixed>, external_product_id?: string}>
     */
    private function platformDefinitions(): array
    {
        return [
            'poweron' => [
                'external_product_id' => 'prod-manual-po',
                'build' => fn (string $email, array $extra) => [
                    'body' => [
                        'event' => $extra['event'] ?? 'pedido_pago',
                        'payload' => [
                            'order' => ['id' => $extra['order_id'] ?? random_int(97000, 97999), 'status' => $extra['order_status'] ?? 'completed'],
                            'customer' => [
                                'name' => $extra['name'] ?? 'Aluno Power On',
                                'email' => $email,
                                'phone' => $extra['phone'] ?? '5511999887766',
                            ],
                            'status' => $extra['status'] ?? 'paid',
                            'payment' => ['gateway_transaction_id' => $extra['transaction_id'] ?? 'tx-po-'.uniqid()],
                            'product' => ['id' => 'prod-manual-po', 'name' => 'Produto PO'],
                        ],
                    ],
                ],
                'revoke' => fn (string $email) => [
                    'body' => [
                        'event' => 'refund',
                        'payload' => [
                            'customer' => ['name' => 'Aluno Power On', 'email' => $email],
                            'status' => 'refunded',
                            'product' => ['id' => 'prod-manual-po'],
                        ],
                    ],
                ],
            ],
            'kiwify' => [
                'external_product_id' => 'ext-manual-kw',
                'build' => fn (string $email, array $extra) => [
                    'body' => [
                        'order_id' => $extra['transaction_id'] ?? 'order-'.uniqid(),
                        'order_status' => $extra['status'] ?? 'paid',
                        'webhook_event_type' => $extra['event'] ?? 'order_approved',
                        'Product' => ['product_id' => 'ext-manual-kw', 'product_name' => 'Curso Kiwify'],
                        'Customer' => [
                            'full_name' => $extra['name'] ?? 'Aluno Kiwify',
                            'email' => $email,
                            'mobile' => $extra['phone'] ?? '5511888777666',
                        ],
                    ],
                ],
                'revoke' => fn (string $email) => [
                    'body' => [
                        'order_id' => 'order-ref-'.uniqid(),
                        'order_status' => 'refunded',
                        'webhook_event_type' => 'order_refunded',
                        'Customer' => ['full_name' => 'Aluno Kiwify', 'email' => $email],
                    ],
                ],
            ],
            'hotmart' => [
                'external_product_id' => 'hotmart-manual-ucode',
                'build' => fn (string $email, array $extra) => [
                    'body' => [
                        'event' => $extra['event'] ?? 'PURCHASE_COMPLETE',
                        'data' => [
                            'product' => ['id' => 0, 'ucode' => 'hotmart-manual-ucode', 'name' => 'Hotmart'],
                            'purchase' => [
                                'transaction' => $extra['transaction_id'] ?? 'HP'.uniqid(),
                                'status' => $extra['purchase_status'] ?? 'COMPLETED',
                            ],
                            'buyer' => [
                                'name' => $extra['name'] ?? 'Aluno Hotmart',
                                'email' => $email,
                                'checkout_phone' => $extra['phone'] ?? '5511777666555',
                            ],
                        ],
                    ],
                ],
                'revoke' => fn (string $email) => [
                    'body' => [
                        'event' => 'PURCHASE_REFUNDED',
                        'data' => [
                            'purchase' => ['transaction' => 'HP-ref-'.uniqid(), 'status' => 'REFUNDED'],
                            'buyer' => ['name' => 'Aluno Hotmart', 'email' => $email],
                        ],
                    ],
                ],
            ],
            'wiapy' => [
                'external_product_id' => 'prod-manual-wy',
                'build' => fn (string $email, array $extra) => [
                    'data' => [
                        'payment' => [
                            'id' => $extra['transaction_id'] ?? 'pay-'.uniqid(),
                            'status' => $extra['status'] ?? 'paid',
                        ],
                        'customer' => [
                            'name' => $extra['name'] ?? 'Aluno Wiapy',
                            'email' => $email,
                            'mobile_phone' => $extra['phone'] ?? '(11) 98888-7777',
                        ],
                        'products' => [['id' => 'prod-manual-wy', 'title' => 'Curso Wiapy']],
                    ],
                ],
                'revoke' => fn (string $email) => [
                    'data' => [
                        'payment' => ['id' => 'pay-ref-'.uniqid(), 'status' => 'refunded'],
                        'customer' => ['name' => 'Aluno Wiapy', 'email' => $email],
                    ],
                ],
            ],
            'notascast' => [
                'external_product_id' => 'notascast-none',
                'build' => fn (string $email, array $extra) => [
                    'body' => [
                        'name' => $extra['name'] ?? 'Aluno Notascast',
                        'email' => $email,
                        'whatsapp' => $extra['phone'] ?? '5511666555444',
                    ],
                ],
                'revoke' => fn (string $email) => [
                    'email' => $email,
                    'name' => 'Aluno Notascast',
                    'event' => 'refund',
                    'platform' => 'notascast',
                ],
            ],
            'gg_checkout' => [
                'external_product_id' => 'YbfsgK1Fgm0LzUsFglrn',
                'build' => fn (string $email, array $extra) => [
                    'event' => $extra['event'] ?? 'pix.generated',
                    'customer' => [
                        'name' => $extra['name'] ?? 'Aluno GG',
                        'email' => $email,
                        'phone' => $extra['phone'] ?? '5511555444333',
                        'document' => '12345678901',
                    ],
                    'payment' => [
                        'id' => $extra['transaction_id'] ?? 'pay-gg-'.uniqid(),
                        'status' => $extra['status'] ?? 'waiting_payment',
                    ],
                    'product' => ['id' => 'YbfsgK1Fgm0LzUsFglrn', 'title' => 'Produto GG'],
                    'products' => [
                        ['id' => 'YbfsgK1Fgm0LzUsFglrn', 'type' => 'main', 'title' => 'Produto GG'],
                    ],
                ],
                'revoke' => fn (string $email) => [
                    'event' => 'pix.refunded',
                    'customer' => ['name' => 'Aluno GG', 'email' => $email],
                    'payment' => ['id' => 'pay-ref-gg', 'status' => 'refunded'],
                    'product' => ['id' => 'YbfsgK1Fgm0LzUsFglrn', 'title' => 'Produto GG'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $outboundCaptures
     */
    private function validatePlatform(string $platform, array $definition, array &$outboundCaptures): void
    {
        $this->newLine();
        $this->info("--- Plataforma: {$platform} ---");

        DB::beginTransaction();

        try {
            $course = $this->createMemberCourse("Curso Manual {$platform}");
            $mappedCourse = $this->createMemberCourse("Curso Mapeado {$platform}");

            if (($definition['external_product_id'] ?? '') !== 'notascast-none') {
                EnrollmentExternalProductMapping::query()->create([
                    'tenant_id' => 1,
                    'platform' => $platform,
                    'external_product_id' => $definition['external_product_id'],
                    'product_id' => $mappedCourse->id,
                ]);
            }

            ['model' => $credential] = EnrollmentWebhookCredential::createWebhook(
                tenantId: 1,
                name: "Validação {$platform}",
                productId: $course->id,
                platform: $platform,
                externalProductId: null,
                isActive: true,
            );

            $outbound = Webhook::create([
                'tenant_id' => 1,
                'name' => "Outbound {$platform}",
                'url' => $this->outboundUrl,
                'events' => [AccessDeliveryReady::class],
                'is_active' => true,
            ]);
            $outbound->products()->sync([$course->id]);

            $url = '/api/webhooks/enrollment/'.$credential->webhook_key;
            $email = "{$platform}-local-".uniqid().'@example.com';
            $build = $definition['build'];
            $revoke = $definition['revoke'];

            $beforeUsers = User::query()->count();
            $beforeOutbound = WebhookLog::query()->count();
            $beforeEnrollLogs = EnrollmentWebhookLog::query()->count();

            // 1. Aluno novo
            $payload = $build($email, ['transaction_id' => 'tx-new-'.uniqid()]);
            $r1 = $this->postWebhook($url, $payload);
            $user = User::query()->where('email', $email)->first();
            $outboundAfter1 = WebhookLog::query()->count() - $beforeOutbound;

            $this->record("{$platform}/1_aluno_novo", [
                'http' => $r1['status'],
                'action' => $r1['json']['action'] ?? null,
                'course_id' => $r1['json']['course_id'] ?? null,
                'email_sent' => $r1['json']['email_sent'] ?? null,
            ], $r1['status'] === 200
                && ($r1['json']['action'] ?? '') === 'enrolled'
                && (string) ($r1['json']['course_id'] ?? '') === (string) $course->id
                && ($r1['json']['email_sent'] ?? false) === true
                && $user !== null
                && $course->users()->where('users.id', $user->id)->exists()
                && ! $mappedCourse->users()->where('users.id', $user->id)->exists()
                && $outboundAfter1 >= 1
                && User::query()->count() === $beforeUsers + 1);

            if ($platform === 'gg_checkout') {
                $lastLog = EnrollmentWebhookLog::query()->latest('id')->first();
                $this->record("{$platform}/gg_deteccao", [
                    'platform_log' => $lastLog?->platform,
                    'external_product_id' => $lastLog?->external_product_id,
                ], $lastLog?->platform === 'gg_checkout'
                    && $lastLog?->external_product_id === 'YbfsgK1Fgm0LzUsFglrn'
                    && (string) ($r1['json']['course_id'] ?? '') === (string) $course->id);
            }

            // 2. Aluno existente
            $beforeOutbound2 = WebhookLog::query()->count();
            $r2 = $this->postWebhook($url, $build($email, ['transaction_id' => 'tx-existing-'.uniqid()]));
            $outboundAfter2 = WebhookLog::query()->count() - $beforeOutbound2;

            $this->record("{$platform}/2_aluno_existente", [
                'http' => $r2['status'],
                'duplicate' => $r2['json']['duplicate'] ?? null,
                'email_sent' => $r2['json']['email_sent'] ?? null,
                'outbound_delta' => $outboundAfter2,
            ], $r2['status'] === 200
                && ($r2['json']['duplicate'] ?? false) === true
                && ($r2['json']['email_sent'] ?? false) === true
                && $outboundAfter2 >= 1
                && User::query()->where('email', $email)->count() === 1);

            // 3. Mesmo curso (replay)
            $replayPayload = $build($email, ['transaction_id' => 'tx-replay-fixed']);
            $this->postWebhook($url, $replayPayload);
            $beforeOutbound3 = WebhookLog::query()->count();
            $r3 = $this->postWebhook($url, $replayPayload);
            $outboundAfter3 = WebhookLog::query()->count() - $beforeOutbound3;
            $pivotCount = DB::table('product_user')->where('user_id', $user?->id)->where('product_id', $course->id)->count();

            $this->record("{$platform}/3_mesmo_curso", [
                'http' => $r3['status'],
                'duplicate' => $r3['json']['duplicate'] ?? null,
                'email_sent' => $r3['json']['email_sent'] ?? null,
                'pivot_count' => $pivotCount,
                'outbound_delta' => $outboundAfter3,
            ], $r3['status'] === 200
                && ($r3['json']['duplicate'] ?? false) === true
                && ($r3['json']['email_sent'] ?? false) === true
                && $pivotCount === 1
                && $outboundAfter3 >= 1);

            // 4. Revogação
            $beforeOutbound4 = WebhookLog::query()->count();
            $beforeEnroll4 = EnrollmentWebhookLog::query()->count();
            $r4 = $this->postWebhook($url, $revoke($email));
            $outboundAfter4 = WebhookLog::query()->count() - $beforeOutbound4;
            $enrollLogsAfterRevoke = EnrollmentWebhookLog::query()->count() - $beforeEnroll4;
            $hasAccessAfterRevoke = $user && $course->users()->where('users.id', $user->id)->exists();
            $lastRevokeLog = EnrollmentWebhookLog::query()->latest('id')->first();

            $this->record("{$platform}/4_revogacao", [
                'http' => $r4['status'],
                'action' => $r4['json']['action'] ?? null,
                'has_access' => $hasAccessAfterRevoke,
                'email_sent' => $r4['json']['email_sent'] ?? null,
                'outbound_delta' => $outboundAfter4,
                'log_action' => $lastRevokeLog?->action,
            ], $r4['status'] === 200
                && in_array($r4['json']['action'] ?? '', ['revoked', 'duplicate'], true)
                && $hasAccessAfterRevoke === false
                && ($r4['json']['email_sent'] ?? false) === false
                && $outboundAfter4 === 0);

            // 5. Probe
            foreach (['probe_empty' => [], 'probe_test' => ['event' => 'test'], 'probe_ping' => ['event' => 'ping']] as $probeName => $probePayload) {
                $usersBefore = User::query()->count();
                $outboundBefore = WebhookLog::query()->count();
                $rProbe = $this->postWebhook($url, $probePayload);
                $this->record("{$platform}/5_{$probeName}", [
                    'http' => $rProbe['status'],
                    'action' => $rProbe['json']['action'] ?? null,
                ], $rProbe['status'] === 200
                    && ($rProbe['json']['action'] ?? '') === 'ignored'
                    && User::query()->count() === $usersBefore
                    && WebhookLog::query()->count() === $outboundBefore);
            }

            // 6. Sem e-mail
            $usersBeforeInvalid = User::query()->count();
            $invalidPayload = match ($platform) {
                'wiapy' => ['data' => ['payment' => ['id' => 'x', 'status' => 'paid'], 'customer' => ['name' => 'Sem Email']]],
                'gg_checkout' => [
                    'event' => 'pix.paid',
                    'customer' => ['name' => 'Sem Email'],
                    'payment' => ['id' => 'x', 'status' => 'paid'],
                    'product' => ['id' => 'YbfsgK1Fgm0LzUsFglrn', 'title' => 'X'],
                ],
                default => ['body' => ['name' => 'Sem Email', 'whatsapp' => '+5511999999999']],
            };
            $rInvalid = $this->postWebhook($url, $invalidPayload);
            $this->record("{$platform}/6_sem_email", [
                'http' => $rInvalid['status'],
                'message' => $rInvalid['json']['message'] ?? null,
            ], $rInvalid['status'] === 422
                && User::query()->count() === $usersBeforeInvalid
                && str_contains(strtolower((string) ($rInvalid['json']['message'] ?? '')), 'e-mail'));

            // Outbound: student.phone quando disponível
            $this->postWebhook($url, $build("{$platform}-phone-".uniqid().'@example.com', [
                'phone' => '5511222333444',
                'transaction_id' => 'tx-phone-'.uniqid(),
            ]));
            $phoneLog = WebhookLog::query()->latest('id')->first();
            $phonePayload = is_array($phoneLog?->request_payload) ? $phoneLog->request_payload : [];
            $studentPhone = $phonePayload['payload']['student']['phone'] ?? '__missing__';
            $this->record("{$platform}/outbound_phone_presente", [
                'student_phone' => $studentPhone,
            ], $studentPhone === '5511222333444');

            $this->line("  Logs inbound gerados: ".(EnrollmentWebhookLog::query()->count() - $beforeEnrollLogs));
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  list<string>  $outboundCaptures
     */
    private function validateOutboundPhoneCases(array &$outboundCaptures): void
    {
        $this->newLine();
        $this->info('--- Outbound global ---');

        DB::beginTransaction();

        try {
            $course = $this->createMemberCourse('Curso Outbound Phone Null');
            ['model' => $credential] = EnrollmentWebhookCredential::createWebhook(
                tenantId: 1,
                name: 'Canonical phone null',
                productId: $course->id,
                platform: 'manual',
                externalProductId: null,
                isActive: true,
            );
            $outbound = Webhook::create([
                'tenant_id' => 1,
                'name' => 'Outbound phone null',
                'url' => $this->outboundUrl,
                'events' => [AccessDeliveryReady::class],
                'is_active' => true,
            ]);
            $outbound->products()->sync([$course->id]);

            $url = '/api/webhooks/enrollment/'.$credential->webhook_key;
            $email = 'sem-phone-'.uniqid().'@example.com';

            $this->postWebhook($url, [
                'name' => 'Sem Telefone',
                'email' => $email,
                'platform' => 'manual',
                'event' => 'purchase_approved',
                'transaction_id' => 'tx-nophone-'.uniqid(),
            ]);

            $log = WebhookLog::query()->latest('id')->first();
            $payload = is_array($log?->request_payload) ? $log->request_payload : [];
            $student = $payload['payload']['student'] ?? [];
            $emailsInPayload = array_filter([
                $student['email'] ?? null,
            ]);

            $this->record('outbound/phone_null', [
                'student_phone' => array_key_exists('phone', $student) ? $student['phone'] : '__missing_key__',
                'student_email' => $student['email'] ?? null,
            ], array_key_exists('phone', $student)
                && $student['phone'] === null
                && count($emailsInPayload) === 1);

            // Garantir que outbound é individual (1 aluno por payload)
            $this->record('outbound/aluno_individual', [
                'has_single_student' => isset($student['email']) && ! isset($payload['payload']['students']),
            ], isset($student['email']) && ! isset($payload['payload']['students']));
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array{status: int, json: array<string, mixed>, body: string}
     */
    private function postWebhook(string $path, array $payload): array
    {
        $this->disableApiRateLimiting();

        $baseUrl = $this->option('base-url');

        if (is_string($baseUrl) && $baseUrl !== '') {
            $response = Http::timeout(30)
                ->acceptJson()
                ->post(rtrim($baseUrl, '/').$path, $payload);

            return [
                'status' => $response->status(),
                'json' => $response->json() ?? [],
                'body' => $response->body(),
            ];
        }

        $kernel = app()->make(\Illuminate\Contracts\Http\Kernel::class);
        $request = Request::create($path, 'POST', $payload, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload));

        /** @var Response $response */
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $decoded = json_decode($response->getContent(), true);

        return [
            'status' => $response->getStatusCode(),
            'json' => is_array($decoded) ? $decoded : [],
            'body' => (string) $response->getContent(),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function record(string $name, array $details, bool $ok): void
    {
        $this->results[] = [
            'test' => $name,
            'status' => $ok ? 'PASS' : 'FAIL',
            'details' => $details,
        ];

        if ($ok) {
            $this->passed++;
            $this->line("  [OK] {$name}");
        } else {
            $this->failed++;
            $this->error("  [FALHA] {$name}");
            $this->line('        '.json_encode($details, JSON_UNESCAPED_UNICODE));
        }
    }

    private function createMemberCourse(string $name): Product
    {
        $nextId = (int) (Product::query()->max('id') ?? 0) + 1;

        $product = new Product;
        $product->forceFill([
            'id' => (string) $nextId,
            'tenant_id' => 1,
            'name' => $name,
            'slug' => 'manual-'.substr(uniqid('', true), -10),
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 97,
            'currency' => 'BRL',
            'is_active' => true,
            'checkout_slug' => 'slug-'.substr(uniqid('', true), -8),
            'checkout_config' => ['email_template' => Product::defaultEmailTemplate()],
        ]);
        $product->save();

        return $product->fresh();
    }

    private function startOutboundCaptureServer(): bool
    {
        $logFile = storage_path('logs/manual-outbound-captures.log');
        file_put_contents($logFile, '');

        if (PHP_OS_FAMILY === 'Windows') {
            $router = base_path('scripts/outbound-capture-router.php');
            if (! is_file($router)) {
                return false;
            }
            $cmd = 'php -S 127.0.0.1:19876 "'.$router.'"';
            pclose(popen('start /B '.$cmd, 'r'));

            return true;
        }

        $cmd = 'php -S 127.0.0.1:19876 '.escapeshellarg(base_path('scripts/outbound-capture-router.php')).' > /dev/null 2>&1 &';
        exec($cmd);

        return true;
    }

    private function stopOutboundCaptureServer(): void
    {
        // Servidor PHP embutido encerra com o processo; nada a fazer.
    }

    /**
     * @return list<string>
     */
    private function loadOutboundCaptureFile(): array
    {
        $logFile = storage_path('logs/manual-outbound-captures.log');
        if (! is_file($logFile)) {
            return [];
        }

        return array_values(array_filter(explode("\n---\n", trim((string) file_get_contents($logFile)))));
    }
}
