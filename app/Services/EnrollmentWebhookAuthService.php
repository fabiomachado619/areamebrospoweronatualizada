<?php

namespace App\Services;

use App\Models\EnrollmentWebhookCredential;
use Illuminate\Http\Request;

class EnrollmentWebhookAuthService
{
    public function resolveBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (is_string($header) && str_starts_with(strtolower($header), 'bearer ')) {
            return trim(substr($header, 7));
        }

        return null;
    }

    public function verifySignatureIfConfigured(Request $request, EnrollmentWebhookCredential $credential): bool
    {
        $secret = $credential->getSigningSecretPlain();
        if ($secret === null || $secret === '') {
            return true;
        }

        $signatureHeader = (string) $request->header('X-Signature', '');
        if ($signatureHeader === '') {
            return false;
        }

        $provided = $signatureHeader;
        if (str_starts_with(strtolower($signatureHeader), 'sha256=')) {
            $provided = substr($signatureHeader, 7);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
