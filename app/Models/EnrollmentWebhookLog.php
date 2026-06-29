<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentWebhookLog extends Model
{
    public const ACTION_ENROLLED = 'enrolled';

    public const ACTION_REVOKED = 'revoked';

    public const ACTION_DUPLICATE = 'duplicate';

    public const ACTION_IGNORED = 'ignored';

    public const ACTION_ERROR = 'error';

    protected $fillable = [
        'tenant_id',
        'enrollment_webhook_id',
        'platform',
        'event',
        'status',
        'transaction_id',
        'course_id',
        'external_product_id',
        'hub_id',
        'email',
        'payload',
        'action',
        'email_sent',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'email_sent' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public static function findProcessedDuplicate(
        int $tenantId,
        ?string $platform,
        ?string $transactionId,
        ?string $event,
        ?string $courseId = null,
        ?string $externalProductId = null,
    ): ?self {
        if ($platform === null || $platform === '' || $transactionId === null || $transactionId === '' || $event === null || $event === '') {
            return null;
        }

        $query = static::query()
            ->where('tenant_id', $tenantId)
            ->where('platform', $platform)
            ->where('transaction_id', $transactionId)
            ->where('event', $event)
            ->whereIn('action', [self::ACTION_ENROLLED, self::ACTION_REVOKED, self::ACTION_DUPLICATE]);

        if ($courseId !== null && $courseId !== '') {
            $query->where('course_id', $courseId);
        } elseif ($externalProductId !== null && $externalProductId !== '') {
            $query->where('external_product_id', $externalProductId);
        }

        return $query->first();
    }

    public function credential(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EnrollmentWebhookCredential::class, 'enrollment_webhook_id');
    }

    public function courseProduct(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'course_id');
    }
}
