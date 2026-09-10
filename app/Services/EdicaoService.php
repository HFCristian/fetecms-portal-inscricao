<?php

namespace App\Services;

use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Scopes\EdicaoScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edições da feira (Parametrização → Edições).
 *
 * O sistema roda várias edições ao mesmo tempo: cada projeto pertence a uma, e
 * o `EdicaoScope` faz cada consulta enxergar só a que está em escopo. Aqui
 * moram as três decisões que sustentam isso:
 *
 * - **criar** uma edição nova (podendo copiar a parametrização da atual, que é
 *   o caminho de "reaproveitar o sistema no ano que vem");
 * - **escolher a padrão**, que vale para quem não trocou de edição — inclusive
 *   o cadastro público, os e-mails e os jobs da fila, onde não há usuário;
 * - **trocar a edição de um usuário**, o que muda de uma vez os prazos, os
 *   limites e os projetos que ele vê.
 *
 * Uma edição com projeto **não é excluída** — apagá-la levaria junto a inscrição
 * de outras pessoas. Para tirá-la de circulação, marque outra como padrão.
 */
class EdicaoService
{
    /** Parâmetros copiados de uma edição para outra (tudo menos as datas). */
    private const PARAMETROS_COPIAVEIS = [
        'avaliacoes_min_por_avaliador', 'avaliacoes_min_por_projeto',
        'avaliacoes_max_por_avaliador', 'avaliacoes_max_por_projeto',
        'avaliacoes_por_categoria', 'designacoes_por_projeto',
        'distribuicao_regras', 'distribuicao_ao_cadastrar', 'modo_distribuicao',
    ];

    /**
     * Todas as edições, da mais nova para a mais antiga, com quantos projetos
     * cada uma tem e se pode ser excluída.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $projetosPorEdicao = Projeto::withoutGlobalScope(EdicaoScope::class)
            ->selectRaw('edicao_id, count(*) as total')
            ->groupBy('edicao_id')
            ->pluck('total', 'edicao_id');

        return Edicao::query()
            ->orderByDesc('ano')
            ->orderByDesc('id')
            ->get()
            ->map(function (Edicao $e) use ($projetosPorEdicao) {
                $projetos = (int) ($projetosPorEdicao[$e->id] ?? 0);

                return [
                    'id' => $e->id,
                    'nome' => $e->nome,
                    'ano' => $e->ano,
                    'padrao' => (bool) $e->padrao,
                    'inscricoes_abertas' => (bool) $e->inscricoes_abertas,
                    'projetos' => $projetos,
                    // Padrão nunca sai de circulação, e edição com inscrição
                    // dentro levaria os projetos junto.
                    'pode_excluir' => $projetos === 0 && ! $e->padrao,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * As edições que aparecem no seletor de qualquer usuário, mais a que ele
     * está vendo agora.
     *
     * @return array<string, mixed>
     */
    public function opcoesPara(?User $user): array
    {
        $emEscopo = Edicao::escopoDe($user);

        return [
            'atual_id' => $emEscopo?->id,
            'atual' => $emEscopo?->nome,
            'padrao_id' => Edicao::padrao()?->id,
            'edicoes' => Edicao::query()
                ->orderByDesc('ano')
                ->orderByDesc('id')
                ->get(['id', 'nome', 'ano', 'padrao'])
                ->map(fn (Edicao $e) => [
                    'id' => $e->id,
                    'nome' => $e->nome,
                    'ano' => $e->ano,
                    'padrao' => (bool) $e->padrao,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Cria a edição. Com `copiar_de`, herda a parametrização daquela edição
     * (limites, regras de distribuição, designação ao cadastrar) — as datas
     * NÃO são copiadas: cada ano tem o seu calendário.
     *
     * @param  array<string, mixed>  $dados
     */
    public function criar(array $dados): Edicao
    {
        $atributos = [
            'nome' => trim($dados['nome']),
            'ano' => (int) $dados['ano'],
            'inscricoes_abertas' => (bool) ($dados['inscricoes_abertas'] ?? true),
            'padrao' => false,
        ];

        if (! empty($dados['copiar_de'])) {
            $origem = Edicao::find($dados['copiar_de']);

            if ($origem !== null) {
                foreach (self::PARAMETROS_COPIAVEIS as $campo) {
                    $atributos[$campo] = $origem->{$campo};
                }
            }
        }

        $edicao = Edicao::create($atributos);

        // Primeira edição do banco assume a padrão sozinha — senão ninguém teria
        // escopo e o sistema ficaria sem edição em vigor.
        if (Edicao::where('padrao', true)->doesntExist()) {
            $this->definirPadrao($edicao);
        }

        return $edicao->fresh();
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(Edicao $edicao, array $dados): Edicao
    {
        $edicao->update(array_filter([
            'nome' => isset($dados['nome']) ? trim($dados['nome']) : null,
            'ano' => isset($dados['ano']) ? (int) $dados['ano'] : null,
        ], fn ($v) => $v !== null) + (
            array_key_exists('inscricoes_abertas', $dados)
                ? ['inscricoes_abertas' => (bool) $dados['inscricoes_abertas']]
                : []
        ));

        return $edicao->fresh();
    }

    /** Marca a edição como padrão — e desmarca a anterior, porque só há uma. */
    public function definirPadrao(Edicao $edicao): Edicao
    {
        DB::transaction(function () use ($edicao) {
            Edicao::where('padrao', true)->update(['padrao' => false]);
            $edicao->update(['padrao' => true]);
        });

        return $edicao->fresh();
    }

    /**
     * Troca a edição que o usuário está vendo. `null` volta a seguir a padrão —
     * inclusive quando ela mudar depois.
     */
    public function trocarDoUsuario(User $user, ?int $edicaoId): ?Edicao
    {
        if ($edicaoId !== null && Edicao::whereKey($edicaoId)->doesntExist()) {
            throw ValidationException::withMessages([
                'edicao_id' => 'Esta edição não existe.',
            ]);
        }

        $user->edicao_id = $edicaoId;
        $user->save();

        return Edicao::escopoDe($user->fresh());
    }

    public function excluir(Edicao $edicao): void
    {
        if ($edicao->padrao) {
            throw ValidationException::withMessages([
                'edicao' => 'A edição padrão não pode ser excluída. Marque outra como padrão antes.',
            ]);
        }

        $projetos = Projeto::withoutGlobalScope(EdicaoScope::class)
            ->where('edicao_id', $edicao->id)
            ->count();

        if ($projetos > 0) {
            throw ValidationException::withMessages([
                'edicao' => "Esta edição tem {$projetos} projeto(s) e não pode ser excluída.",
            ]);
        }

        // Quem estava olhando esta edição volta a seguir a padrão.
        User::where('edicao_id', $edicao->id)->update(['edicao_id' => null]);

        $edicao->delete();
    }
}
