<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
    })->create();
