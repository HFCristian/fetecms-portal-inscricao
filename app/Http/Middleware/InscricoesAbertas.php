<?php

namespace App\Http\Middleware;

use App\Services\InscricoesService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Congela a área de projetos do orientador depois do prazo de submissão.
 * Uso: ->middleware('inscricoes.abertas') nas rotas que escrevem.
 *
 * Leitura passa sempre (a inscrição continua visível) e o admin também — ele
 * mantém o escape do edital para casos excepcionais.
 */
class InscricoesAbertas
{
    public function __construct(private readonly InscricoesService $inscricoes) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $this->inscricoes->bloqueadoPara($request->user())) {
            return $next($request);
        }

        return response()->json($this->inscricoes->motivo(), 422);
    }
}
