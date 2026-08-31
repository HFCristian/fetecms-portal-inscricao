<?php

namespace App\Services;

use App\Enums\AbaAdmin;
use App\Models\Edicao;
use Illuminate\Validation\ValidationException;

/**
 * Ordem das abas do menu do admin (Parametrização → Ordem do menu).
 *
 * A ordem nasceu sendo a do enum `App\Enums\AbaAdmin`, isto é, a ordem em que
 * cada aba foi aparecendo no código — que não acompanha o calendário da feira:
 * no mês do evento o Credenciamento deveria abrir o menu; no período de
 * inscrição, Projetos. Aqui a organização escolhe.
 *
 * A ordem é **da edição** (`edicoes.ordem_abas`), como os prazos e os limites:
 * trocar de edição no seletor do topo troca também o menu. É uma decisão de
 * equipe, não de gosto pessoal — quem dá suporte precisa poder dizer "o terceiro
 * item do menu" e acertar.
 *
 * O que se grava é **sempre a lista inteira**, completada com as abas que a tela
 * não mandou. Assim ler de volta nunca depende de preencher lacuna, e o menu
 * não muda de forma quando o código ganha uma aba nova (ela entra no fim).
 */
class OrdemAbasService
{
    /**
     * A ordem em vigor e o catálogo de abas para a tela desenhar.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $edicao = Edicao::atual();
        $ordem = AbaAdmin::ordenar(AbaAdmin::valores(), $edicao?->ordem_abas);

        $porValor = collect(AbaAdmin::opcoes())->keyBy('value');

        return [
            'personalizada' => ! empty($edicao?->ordem_abas),
            'edicao' => $edicao === null ? null : ['id' => $edicao->id, 'nome' => $edicao->nome],
            // Já na ordem em vigor: a tela é uma lista arrastável, não um mapa.
            'abas' => array_values(array_map(
                fn (string $aba) => $porValor[$aba],
                $ordem,
            )),
        ];
    }

    /**
     * Grava a ordem escolhida. A lista chega da tela como está na tela — todas
     * as abas, na ordem que a pessoa arrumou.
     *
     * @param  list<string>  $ordem
     * @return array<string, mixed>
     */
    public function definir(array $ordem): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'ordem' => 'Nenhuma edição em curso para guardar a ordem do menu.',
            ]);
        }

        $edicao->update(['ordem_abas' => AbaAdmin::sanitizarOrdem($ordem)]);

        return $this->config();
    }

    /** Volta à ordem do enum — o menu como o portal nasceu. */
    public function restaurar(): array
    {
        Edicao::atual()?->update(['ordem_abas' => null]);

        return $this->config();
    }
}
