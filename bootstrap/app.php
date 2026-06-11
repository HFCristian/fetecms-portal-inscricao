<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
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

        // Toda a API responde erros em pt_BR. Validação (422) já vem traduzida.
        $exceptions->render(function (Throwable $e, Request $request) use ($mensagemHttp) {
            if (! $request->is('api/*') || $e instanceof ValidationException) {
                return null;
            }

            $json = fn (int $status, string $message) => response()->json(['message' => $message], $status);

            return match (true) {
                $e instanceof AuthenticationException => $json(401, 'Não autenticado. Faça login para continuar.'),
                $e instanceof ThrottleRequestsException => $json(429, 'Muitas requisições em pouco tempo. Aguarde um instante e tente novamente.'),
                $e instanceof TokenMismatchException => $json(419, 'Sua sessão expirou. Atualize a página e tente novamente.'),
                $e instanceof ModelNotFoundException => $json(404, 'Recurso não encontrado.'),
                $e instanceof AuthorizationException => $json(403, 'Você não tem permissão para esta ação.'),
                $e instanceof HttpExceptionInterface => $json($e->getStatusCode(), $mensagemHttp($e)),
                // 500 e afins: em produção mostra mensagem genérica pt_BR; em debug, deixa o handler padrão.
                default => config('app.debug') ? null : $json(500, 'Erro interno do servidor. Tente novamente mais tarde.'),
            };
        });
    })->create();
