<?php

namespace App\Http\Middleware;

use App\Enums\AbaAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe a rota a quem tem a aba no escopo de admin da edição em curso.
 * Uso: ->middleware('aba:comunicacao'). Com mais de uma, basta ter QUALQUER
 * uma delas — é o caso das telas que moram em duas abas (a parametrização do
 * período de avaliação, por exemplo).
 *
 * Vem sempre DEPOIS de `role:admin` — quem não é admin já parou lá. Admin sem
 * escopo atribuído na edição tem acesso total, então este middleware é
 * transparente até alguém configurar os escopos.
 */
class EnsureAbaAdmin
{
    public function handle(Request $request, Closure $next, string ...$abas): Response
    {
        $user = $request->user();

        $liberado = collect($abas)
            ->map(fn (string $aba) => AbaAdmin::tryFrom($aba))
            ->filter()
            ->contains(fn (AbaAdmin $aba) => (bool) $user?->podeAbrirAba($aba));

        if (! $liberado) {
            abort(403, 'Seu escopo de administrador não inclui esta área do portal.');
        }

        return $next($request);
    }
}
