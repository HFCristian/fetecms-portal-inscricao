<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Tempo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Atrás do ALB da AWS: confia nos cabeçalhos X-Forwarded-* (Proto/Host/For)
        // para o Laravel enxergar HTTPS e o IP real do cliente (cookies Secure,
        // URLs https, rate limit por IP correto). Seguro porque o security group
        // do EC2 só aceita tráfego vindo do load balancer. Ver docs/DEPLOY_AWS.md.
        $middleware->trustProxies(at: '*');

        // Sanctum SPA: autentica o front web (mesma origem) por cookie de sessão + CSRF.
        $middleware->statefulApi();

        // Cabeçalhos de segurança em todas as respostas.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Mensagem pt_BR para erros HTTP genéricos (preserva mensagens custom de abort(403)).
        $mensagemHttp = function (HttpExceptionInterface $e): string {
            $status = $e->getStatusCode();
            $custom = trim($e->getMessage());

            if ($status === 403 && $custom !== '' && ! in_array($custom, ['Forbidden', 'This action is unauthorized.'], true)) {
                return $custom;
            }

            return match ($status) {
                400 => 'Requisição inválida.',
                403 => 'Você não tem permissão para esta ação.',
                404 => 'Recurso não encontrado.',
                405 => 'Método não permitido.',
                413 => 'Arquivo grande demais.',
                419 => 'Sua sessão expirou. Atualize a página e tente novamente.',
                429 => 'Muitas requisições em pouco tempo. Aguarde um instante e tente novamente.',
                default => 'Não foi possível processar a requisição.',
            };
        };

        // 429: devolve `retry_after` (segundos) além da mensagem e preserva o
        // Retry-After original, para o front avisar quanto falta e rodar a
        // contagem regressiva até a liberação.
        $excedeuLimite = function (ThrottleRequestsException $e) {
            $headers = $e->getHeaders();
            $segundos = max(1, (int) ($headers['Retry-After'] ?? 60));
            $mensagem = trim($e->getMessage());

            // O middleware `throttle` lança a mensagem padrão em inglês; as nossas
            // (ex.: bloqueio de login) já vêm prontas em pt_BR e são preservadas.
            if ($mensagem === '' || $mensagem === 'Too Many Attempts.') {
                $mensagem = 'Muitas requisições em pouco tempo. Tente novamente em '.Tempo::humanizar($segundos).'.';
            }

            return response()->json(['message' => $mensagem, 'retry_after' => $segundos], 429, $headers);
        };

        // Toda a API responde erros em pt_BR. Validação (422) já vem traduzida.
        $exceptions->render(function (Throwable $e, Request $request) use ($mensagemHttp, $excedeuLimite) {
            if (! $request->is('api/*') || $e instanceof ValidationException) {
                return null;
            }

            $json = fn (int $status, string $message) => response()->json(['message' => $message], $status);

            return match (true) {
                $e instanceof AuthenticationException => $json(401, 'Não autenticado. Faça login para continuar.'),
                $e instanceof ThrottleRequestsException => $excedeuLimite($e),
                $e instanceof TokenMismatchException => $json(419, 'Sua sessão expirou. Atualize a página e tente novamente.'),
                $e instanceof ModelNotFoundException => $json(404, 'Recurso não encontrado.'),
                $e instanceof AuthorizationException => $json(403, 'Você não tem permissão para esta ação.'),
                $e instanceof HttpExceptionInterface => $json($e->getStatusCode(), $mensagemHttp($e)),
                // 500 e afins: em produção mostra mensagem genérica pt_BR; em debug, deixa o handler padrão.
                default => config('app.debug') ? null : $json(500, 'Erro interno do servidor. Tente novamente mais tarde.'),
            };
        });
    })->create();
