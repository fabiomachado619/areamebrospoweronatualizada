<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CheckoutStudentProvisioningService
{
    public const DEFAULT_PASSWORD = '123456';

    /**
     * Cria ou reutiliza o comprador no checkout interno.
     * Senha fixa 123456 apenas para aluno novo em produto área de membros.
     * Aluno existente mantém a senha atual.
     *
     * @return array{user: User, access_metadata: array<string, mixed>}
     */
    public function findOrCreateBuyer(string $email, string $name, Product $product, bool $syncNameWhenExisting = false): array
    {
        $email = trim($email);
        $name = trim($name);
        $tenantId = (int) $product->tenant_id;
        $isMemberArea = $product->type === Product::TYPE_AREA_MEMBROS;

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name !== '' ? $name : $email,
                'password' => $isMemberArea
                    ? Hash::make(self::DEFAULT_PASSWORD)
                    : bcrypt(Str::random(32)),
                'role' => User::ROLE_ALUNO,
                'tenant_id' => $tenantId,
            ]
        );

        if ($user->wasRecentlyCreated) {
            $user->update(['role' => User::ROLE_ALUNO]);
        } elseif ($syncNameWhenExisting && $name !== '' && trim((string) $user->name) !== $name) {
            $user->update(['name' => $name]);
        }

        $accessMetadata = [];
        if ($isMemberArea && $user->wasRecentlyCreated) {
            Cache::put(
                'access_password.'.$user->id.'.'.$product->id,
                self::DEFAULT_PASSWORD,
                now()->addHours(2)
            );
            $accessMetadata['access_password_temp'] = encrypt(self::DEFAULT_PASSWORD);
        }

        return [
            'user' => $user->fresh(),
            'access_metadata' => $accessMetadata,
        ];
    }
}
