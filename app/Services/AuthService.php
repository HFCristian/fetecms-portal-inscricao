<?php

namespace App\Services;

use App\Models\User;
use App\Support\Tempo;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /** Falhas de login seguidas toleradas (por e-mail + IP) antes do bloqueio. */
    public const MAX_TENTATIVAS = 5;

    /** Duração do bloqueio — e da janela de contagem das falhas — em segundos. */
    public const BLOQUEIO_SEGUNDOS = 60;

    /**
     * Autentica pelo guard de sessão (Sanctum SPA). Lança ValidationException
     * (422) em credenciais inválidas ou conta inativa e ThrottleRequestsException
     * (429, com Retry-After) quando a conta está bloqueada por excesso de tentativas.
     *
     * `$chaveTentativas` vem do LoginRequest (e-mail + IP); vazia desliga o
     * bloqueio (útil em chamadas internas que não vêm de uma requisição HTTP).
     */
    public function attempt(string $email, string $password, bool $remember = false, string $chaveTentativas = ''): User
    {
        $this->garantirSemBloqueio($chaveTentativas);

        if (! Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            $this->registrarFalha($chaveTentativas);

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

        // Login válido zera o contador: só falhas seguidas levam ao bloqueio.
        if ($chaveTentativas !== '') {
            RateLimiter::clear($chaveTentativas);
        }

        return $user;
    }

    /**
     * Barra a tentativa enquanto o bloqueio durar. O 429 carrega Retry-After
     * para o front informar a espera e mostrar a contagem regressiva.
     */
    private function garantirSemBloqueio(string $chave): void
    {
        if ($chave === '' || ! RateLimiter::tooManyAttempts($chave, self::MAX_TENTATIVAS)) {
            return;
        }

        $segundos = RateLimiter::availableIn($chave);

        throw new ThrottleRequestsException(
            __('auth.throttle', ['tempo' => Tempo::humanizar($segundos), 'seconds' => $segundos]),
            null,
            [
                'Retry-After' => $segundos,
                'X-RateLimit-Reset' => now()->addSeconds($segundos)->getTimestamp(),
            ],
        );
    }

    private function registrarFalha(string $chave): void
    {
        if ($chave !== '') {
            RateLimiter::hit($chave, self::BLOQUEIO_SEGUNDOS);
        }
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
     * Altera o e-mail de acesso do próprio usuário (qualquer papel). O e-mail é
     * a identidade da conta no portal, então toda troca vira registro na trilha
     * de auditoria — com o valor antigo e o novo.
     *
     * `$autor` é quem executou a ação (o próprio dono hoje; um admin no futuro).
     * Trocar para o mesmo e-mail é no-op: não gera registro nem escrita.
     */
    public function alterarEmail(User $user, string $novoEmail, ?User $autor = null): User
    {
        $anterior = $user->email;
        $novoEmail = trim($novoEmail);

        if (mb_strtolower($anterior) === mb_strtolower($novoEmail)) {
            return $user;
        }

        $user->update(['email' => $novoEmail]);
        $this->registros->trocaEmail($user, $anterior, $novoEmail, $autor ?? $user);

        return $user;
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
