<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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

    /**
     * Dispara o e-mail com o link de redefinição (token de uso único, válido por
     * 30 min — ver config/auth.php). Usa o broker nativo, que já cuida do hash do
     * token, expiração e throttle. Nunca revela se o e-mail existe: o controller
     * responde de forma neutra em qualquer status (previne enumeração de usuários).
     */
    public function enviarLinkRecuperacao(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    /**
     * Redefine a senha a partir do token recebido por e-mail. A nova senha é
     * gravada com hash (cast 'hashed') e o remember_token é rotacionado para
     * invalidar sessões "lembrar de mim" antigas. Lança ValidationException (422)
     * se o token for inválido/expirado ou o e-mail não casar.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation?: string}  $credentials
     */
    public function redefinirSenha(array $credentials): void
    {
        $status = Password::reset($credentials, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Este link de redefinição é inválido ou expirou. Solicite um novo.',
            ]);
        }
    }
}
