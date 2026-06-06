<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class CaseInsensitiveUserAuth
{
    /**
     * Autentica por e-mail (case-insensitive) e senha.
     */
    public static function attempt(array $credentials, bool $remember = false): bool
    {
        $email = strtolower(trim((string) ($credentials['email'] ?? '')));
        $password = $credentials['password'] ?? '';

        if ($email === '' || ! is_string($password) || $password === '') {
            return false;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user instanceof User || ! Hash::check($password, $user->password)) {
            return false;
        }

        Auth::login($user, $remember);

        return true;
    }
}
