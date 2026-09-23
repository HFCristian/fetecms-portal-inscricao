<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra a **conta temporária** numa rota que a aba dela abre.
 *
 * O RBAC do portal decide por aba: a conta temporária de um setor abre a aba
 * daquele setor e mais nada (`User::abasPermitidas()`). Isso basta enquanto a
 * aba inteira é o trabalho dela — o que valia para o credenciamento e para o
 * almoxarifado.
 *
 * O **cerimonial** quebra essa simetria: o voluntário atende a porta (o
 * check-in) e não é da organização, então não tem por que ver o painel com
 * quantas medalhas e quantas credenciais separar, nem gerir as contas dos
 * colegas. Este middleware é o corte fino dentro da aba, e é ele — não o menu
 * escondido — que faz a restrição valer: trocar a URL à mão responde 403.
 */
class EnsureAdminPermanente
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->ehContaTemporaria()) {
            abort(403, 'Esta seção é da organização — a sua conta atende apenas o balcão.');
        }

        return $next($request);
    }
}
