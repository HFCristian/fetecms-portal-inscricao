<?php

namespace App\Http\Middleware;

use App\Services\InscricoesService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Segura o cadastro público de orientador enquanto as inscrições não abrem.
 * Uso: ->middleware('inscricoes.iniciadas').
 *
 * Diferente do `inscricoes.abertas`, este olha só a ponta da abertura: passado
 * o prazo a pessoa ainda pode criar a conta (só não cadastra projeto).
 */
class InscricoesIniciadas
{
    public function __construct(private readonly InscricoesService $inscricoes) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->inscricoes->cadastroBloqueado()) {
            return $next($request);
        }

        return response()->json($this->inscricoes->motivo(), 422);
    }
}
