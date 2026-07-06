<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Autentica pelo guard de sessão (Sanctum SPA). Lança ValidationException
     * (422) em credenciais inválidas ou conta inativa.
     */
    public function attempt(string $email, string $password, bool $remember = false): User
    {
        if (! Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => __('auth.inactive'),
            ]);
        }

        return $user;
    }

    /**
     * Altera a senha do próprio usuário. Exige a senha atual correta; a nova é
     * gravada com hash (cast 'hashed' do model). Verificação manual (independe
     * do guard) para funcionar tanto no SPA (sessão) quanto via token.
     */
    public function alterarSenha(User $user, string $senhaAtual, string $novaSenha): void
    {
        if (! Hash::check($senhaAtual, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'A senha atual está incorreta.',
            ]);
        }

        $user->update(['password' => $novaSenha]);
    }
}
