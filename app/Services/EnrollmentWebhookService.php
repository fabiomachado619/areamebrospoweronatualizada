<?php

namespace App\Services;

use App\Models\EnrollmentWebhookCredential;
use App\Models\EnrollmentExternalProductMapping;
use App\Models\EnrollmentWebhookLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EnrollmentWebhookService
{
    public const DEFAULT_STUDENT_PASSWORD = CheckoutStudentProvisioningService::DEFAULT_PASSWORD;

    public function __construct(
        protected AccessEmailService $accessEmailService,
        protected MemberHubService $memberHubService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function process(int $tenantId, array $payload, ?EnrollmentWebhookCredential $credential = null): array
    {
        $normalized = $this->normalizePayload($payload);
        $platform = $normalized['platform'];
        $event = $normalized['event'];
        $transactionId = $normalized['transaction_id'];

        if ($duplicate = EnrollmentWebhookLog::findProcessedDuplicate($tenantId, $platform, $transactionId, $event)) {
            return [
                'success' => true,
                'duplicate' => true,
                'email_sent' => false,
                'message' => 'Evento já processado',
                'action' => $duplicate->action,
            ];
        }

        try {
            if ($this->isIgnoredEvent($event)) {
                $this->writeLog($tenantId, $normalized, EnrollmentWebhookLog::ACTION_IGNORED, false, null, null, null, $credential);

                return [
                    'success' => true,
                    'action' => EnrollmentWebhookLog::ACTION_IGNORED,
                    'duplicate' => false,
                    'email_sent' => false,
                    'message' => 'Evento ignorado',
                ];
            }

            $course = $this->resolveCourse($tenantId, $normalized, $credential);
            $this->validateOptionalHub($tenantId, $normalized['hub_id']);

            if ($this->isRevokeEvent($event)) {
                return $this->revokeAccess($tenantId, $normalized, $course, $credential);
            }

            if ($this->isGrantEvent($event)) {
                return $this->grantAccess($tenantId, $normalized, $course, $credential);
            }

            throw new \InvalidArgumentException('Evento não suportado: '.$event);
        } catch (\Throwable $e) {
            $this->writeLog(
                $tenantId,
                $normalized,
                EnrollmentWebhookLog::ACTION_ERROR,
                false,
                $e->getMessage(),
                null,
                null,
                $credential
            );

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Registra webhook recebido sem executar matrícula (ex.: e-mail ausente, evento ignorado).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function logInboundOnly(
        int $tenantId,
        array $payload,
        ?EnrollmentWebhookCredential $credential,
        string $logAction,
        ?string $message = null,
        bool $success = true,
    ): array {
        $normalized = $this->normalizePayload($payload);
        $this->writeLog($tenantId, $normalized, $logAction, false, $message, null, null, $credential);

        $responseAction = $logAction === EnrollmentWebhookLog::ACTION_ERROR ? 'error' : EnrollmentWebhookLog::ACTION_IGNORED;

        return [
            'success' => $success,
            'action' => $responseAction,
            'duplicate' => false,
            'email_sent' => false,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        return [
            'name' => trim((string) ($payload['name'] ?? '')),
            'email' => strtolower(trim((string) ($payload['email'] ?? ''))),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'document' => $this->nullableString($payload['document'] ?? null),
            'course_id' => $this->nullableString($payload['course_id'] ?? null),
            'hub_id' => $this->nullableString($payload['hub_id'] ?? null),
            'external_product_id' => $this->nullableString($payload['external_product_id'] ?? null),
            'platform' => strtolower(trim((string) ($payload['platform'] ?? ''))),
            'event' => strtolower(trim((string) ($payload['event'] ?? ''))),
            'transaction_id' => $this->nullableString($payload['transaction_id'] ?? null),
            'status' => strtolower(trim((string) ($payload['status'] ?? ''))),
            'send_access_email' => array_key_exists('send_access_email', $payload)
                ? (bool) $payload['send_access_email']
                : true,
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function grantAccess(int $tenantId, array $data, Product $course, ?EnrollmentWebhookCredential $credential = null): array
    {
        if ($data['email'] === '' || ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('E-mail inválido.');
        }

        return DB::transaction(function () use ($tenantId, $data, $course, $credential) {
            [$user, $isNewUser, $plainPassword] = $this->findOrCreateStudent($tenantId, $data);
            $hadAccess = $course->users()->where('users.id', $user->id)->exists();

            $course->users()->syncWithoutDetaching([$user->id]);

            $emailSent = false;
            $shouldSendEmail = (bool) $data['send_access_email'] && ! $hadAccess;

            if ($shouldSendEmail) {
                $emailSent = $this->accessEmailService->sendForEnrollmentAccess(
                    $user,
                    $course,
                    $isNewUser ? $plainPassword : null,
                );
            }

            $this->writeLog(
                $tenantId,
                $data,
                $hadAccess ? EnrollmentWebhookLog::ACTION_DUPLICATE : EnrollmentWebhookLog::ACTION_ENROLLED,
                $emailSent,
                null,
                $course->id,
                $user->id,
                $credential
            );

            return [
                'success' => true,
                'action' => $hadAccess ? EnrollmentWebhookLog::ACTION_DUPLICATE : EnrollmentWebhookLog::ACTION_ENROLLED,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'email_sent' => $emailSent,
                'duplicate' => $hadAccess,
                'message' => $hadAccess ? 'Aluno já possuía acesso ao curso' : null,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function revokeAccess(int $tenantId, array $data, Product $course, ?EnrollmentWebhookCredential $credential = null): array
    {
        if ($data['email'] === '' || ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('E-mail inválido.');
        }

        return DB::transaction(function () use ($tenantId, $data, $course, $credential) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first();
            if (! $user) {
                throw new \InvalidArgumentException('Aluno não encontrado para revogação.');
            }
            if ($user->tenant_id !== $tenantId) {
                throw new \InvalidArgumentException('Aluno não pertence a este tenant.');
            }

            $hadAccess = $course->users()->where('users.id', $user->id)->exists();
            if ($hadAccess) {
                $course->users()->detach($user->id);
            }

            $this->writeLog(
                $tenantId,
                $data,
                $hadAccess ? EnrollmentWebhookLog::ACTION_REVOKED : EnrollmentWebhookLog::ACTION_DUPLICATE,
                false,
                null,
                $course->id,
                $user->id,
                $credential
            );

            return [
                'success' => true,
                'action' => $hadAccess ? EnrollmentWebhookLog::ACTION_REVOKED : EnrollmentWebhookLog::ACTION_DUPLICATE,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'email_sent' => false,
                'duplicate' => ! $hadAccess,
                'message' => $hadAccess ? null : 'Aluno não possuía acesso ao curso',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: User, 1: bool, 2: ?string}
     */
    private function findOrCreateStudent(int $tenantId, array $data): array
    {
        $existing = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first();

        if ($existing) {
            if ($existing->tenant_id !== $tenantId) {
                throw new \InvalidArgumentException('E-mail já cadastrado em outro tenant.');
            }
            if (! $existing->isAluno()) {
                throw new \InvalidArgumentException('Usuário existente não é aluno.');
            }

            $updates = [];
            if ($data['name'] !== '') {
                $updates['name'] = $data['name'];
            }
            if ($data['phone'] !== null) {
                $updates['phone'] = $data['phone'];
            }
            if ($data['document'] !== null) {
                $updates['document'] = $data['document'];
            }
            if ($updates !== []) {
                $existing->fill($updates)->save();
            }

            return [$existing->fresh(), false, null];
        }

        if ($data['name'] === '') {
            throw new \InvalidArgumentException('Nome é obrigatório para criar aluno.');
        }

        $plainPassword = self::DEFAULT_STUDENT_PASSWORD;

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'document' => $data['document'],
            'password' => Hash::make($plainPassword),
            'role' => User::ROLE_ALUNO,
            'tenant_id' => $tenantId,
        ]);

        return [$user, true, $plainPassword];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCourse(int $tenantId, array $data, ?EnrollmentWebhookCredential $credential = null): Product
    {
        $courseId = $data['course_id'];

        if (($courseId === null || $courseId === '') && $credential?->product_id) {
            $courseId = $credential->product_id;
        }

        if ($courseId === null || $courseId === '') {
            $platform = $data['platform'] !== '' ? $data['platform'] : ($credential?->platform ?? '');
            $externalId = $data['external_product_id'] ?? $credential?->external_product_id;

            if ($platform !== '' && $externalId !== null && $externalId !== '') {
                $courseId = EnrollmentExternalProductMapping::resolveProductId(
                    $tenantId,
                    $platform,
                    $externalId
                );
            }
        }

        if ($courseId === null || $courseId === '') {
            throw new \InvalidArgumentException('Informe course_id ou platform + external_product_id, ou configure o curso no webhook.');
        }

        $course = Product::query()->find($courseId);
        if (! $course) {
            throw new \InvalidArgumentException('Curso não encontrado.');
        }
        if ((int) $course->tenant_id !== $tenantId) {
            throw new \InvalidArgumentException('Curso não pertence ao tenant do token.');
        }
        if ($course->type !== Product::TYPE_AREA_MEMBROS) {
            throw new \InvalidArgumentException('Produto informado não é área de membros.');
        }
        if ($course->isMemberHub()) {
            throw new \InvalidArgumentException('Informe o curso (area_membros), não o HUB.');
        }

        return $course;
    }

    private function validateOptionalHub(int $tenantId, ?string $hubId): void
    {
        if ($hubId === null || $hubId === '') {
            return;
        }

        $hub = Product::query()->find($hubId);
        if (! $hub || (int) $hub->tenant_id !== $tenantId || ! $hub->isMemberHub()) {
            throw new \InvalidArgumentException('hub_id inválido para este tenant.');
        }
    }

    private function isGrantEvent(string $event): bool
    {
        return in_array($event, config('enrollment_webhook.grant_events', []), true);
    }

    private function isRevokeEvent(string $event): bool
    {
        return in_array($event, config('enrollment_webhook.revoke_events', []), true);
    }

    private function isIgnoredEvent(string $event): bool
    {
        return $event === '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeLog(
        int $tenantId,
        array $data,
        string $action,
        bool $emailSent,
        ?string $errorMessage,
        ?string $courseId,
        ?int $userId = null,
        ?EnrollmentWebhookCredential $credential = null
    ): void {
        EnrollmentWebhookLog::create([
            'tenant_id' => $tenantId,
            'enrollment_webhook_id' => $credential?->id,
            'platform' => $data['platform'] !== '' ? $data['platform'] : null,
            'event' => $data['event'] !== '' ? $data['event'] : null,
            'status' => $data['status'] !== '' ? $data['status'] : null,
            'transaction_id' => $data['transaction_id'],
            'course_id' => $courseId,
            'hub_id' => $data['hub_id'],
            'email' => $data['email'] !== '' ? $data['email'] : null,
            'payload' => $data['raw'],
            'action' => $action,
            'email_sent' => $emailSent,
            'error_message' => $errorMessage,
            'processed_at' => now(),
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
