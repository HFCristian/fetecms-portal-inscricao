<?php

namespace App\Services;

use App\Enums\StatusRecomendacao;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\RecomendacaoPremiacao;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * O **painel da premiação** (Sprint 196): a mesma base de Credenciais e
 * Prêmios, lida de longe — quanto ainda falta dar e quem já levou o quê, área
 * por área.
 *
 * Existe porque a deliberação do comitê acontece em volta de uma mesa, com a
 * tela projetada: os cartões de cada credencial servem para **anexar**, mas
 * para responder "ainda sobra vaga na MOSTRATEC?" ou "Agrárias já levou
 * três?" era preciso rolar a página inteira somando de cabeça. Abre para o
 * admin (Premiação) e para a comissão especial (aba do avaliador), só leitura.
 *
 * Só as credenciais **ativas** entram — as mesmas da lista de premiação em
 * TXT. "Faltam" só existe para quem tem número de vagas; sem limite, a
 * credencial conta o que já deu e não entra na soma do que falta.
 */
class PainelPremiacaoService
{
    /** @return array<string, mixed> */
    public function painel(): array
    {
        $credenciais = Credencial::where('edicao_id', Edicao::atual()?->id ?? 0)
            ->where('ativa', true)
            ->with([
                'projetos:id,titulo,categoria,area_id,instituicao_id',
                'projetos.area:id,nome',
                'projetos.instituicao:id,nome',
            ])
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get();

        $aguardando = $this->aprovadasAguardando($credenciais);

        $linhas = $credenciais->map(function (Credencial $c) use ($aguardando) {
            $usadas = $c->projetos->count();
            $faltam = $c->vagas === null ? null : max(0, $c->vagas - $usadas);

            return [
                'id' => $c->id,
                'tipo' => $c->tipo->value,
                'tipo_label' => $c->tipo->label(),
                'nome' => $c->nome,
                'orgao' => $c->orgao,
                'vagas' => $c->vagas,
                'usadas' => $usadas,
                'faltam' => $faltam,
                'esgotada' => $faltam === 0,
                'aprovadas_aguardando' => $aguardando[$c->id] ?? 0,
                'premiados' => $c->projetos
                    ->sortBy(fn (Projeto $p) => $this->chave($p->titulo))
                    ->map(fn (Projeto $p) => ['id' => $p->id, 'titulo' => $p->titulo, 'area' => $p->area?->nome])
                    ->values()
                    ->all(),
            ];
        });

        $comLimite = $linhas->whereNotNull('vagas');

        return [
            'resumo' => [
                'credenciais' => $linhas->count(),
                'vagas_total' => (int) $comLimite->sum('vagas'),
                'vagas_preenchidas' => (int) $comLimite->sum(fn (array $l) => min($l['usadas'], $l['vagas'])),
                'faltam' => (int) $comLimite->sum('faltam'),
                'credenciais_com_vaga' => $comLimite->where('faltam', '>', 0)->count(),
                'esgotadas' => $comLimite->where('esgotada', true)->count(),
                'sem_limite' => $linhas->whereNull('vagas')->count(),
                'premiacoes' => (int) $linhas->sum('usadas'),
                'projetos_premiados' => $credenciais->flatMap(fn (Credencial $c) => $c->projetos->pluck('id'))->unique()->count(),
                'aprovadas_aguardando' => array_sum($aguardando),
            ],
            'credenciais' => $linhas->values()->all(),
            'ranking_por_area' => $this->rankingPorArea($credenciais),
            'atualizado_em' => now()->toIso8601String(),
        ];
    }

    /**
     * Quantas recomendações aprovadas cada credencial tem esperando o anexo —
     * um projeto conta uma vez, por mais avaliadores que o tenham recomendado.
     *
     * @param  Collection<int, Credencial>  $credenciais
     * @return array<int, int>
     */
    private function aprovadasAguardando(Collection $credenciais): array
    {
        $premiados = $credenciais->mapWithKeys(fn (Credencial $c) => [$c->id => $c->projetos->pluck('id')->flip()]);

        return RecomendacaoPremiacao::whereIn('credencial_id', $credenciais->pluck('id'))
            ->where('status', StatusRecomendacao::Aprovada->value)
            ->get(['credencial_id', 'projeto_id'])
            ->unique(fn (RecomendacaoPremiacao $r) => $r->credencial_id.'|'.$r->projeto_id)
            ->reject(fn (RecomendacaoPremiacao $r) => $premiados[$r->credencial_id]->has($r->projeto_id))
            ->countBy('credencial_id')
            ->all();
    }

    /**
     * Área por área, os projetos premiados do que mais recebeu para o que
     * menos. Mesma quantidade, mesma posição (dois em 1º, o seguinte em 3º);
     * empate desempata pelo título só na ordem da lista.
     *
     * As áreas saem da que mais premiou para a que menos — é a pergunta que a
     * mesa faz ("quem ainda não levou nada?") lida de baixo para cima.
     *
     * @param  Collection<int, Credencial>  $credenciais
     * @return list<array<string, mixed>>
     */
    private function rankingPorArea(Collection $credenciais): array
    {
        $porProjeto = [];

        foreach ($credenciais as $credencial) {
            foreach ($credencial->projetos as $projeto) {
                $porProjeto[$projeto->id] ??= [
                    'id' => $projeto->id,
                    'titulo' => $projeto->titulo,
                    'categoria' => $projeto->categoria?->label(),
                    'escola' => $projeto->instituicao?->nome,
                    'area' => $projeto->area?->nome ?? 'Sem área',
                    'premiacoes' => [],
                ];
                $porProjeto[$projeto->id]['premiacoes'][] = [
                    'nome' => $credencial->nome,
                    'tipo' => $credencial->tipo->value,
                    'tipo_label' => $credencial->tipo->label(),
                ];
            }
        }

        return collect($porProjeto)
            ->map(fn (array $p) => $p + ['total' => count($p['premiacoes'])])
            ->groupBy('area')
            ->map(function (Collection $projetos, string $area) {
                $ordenados = $projetos
                    ->sortBy([
                        fn (array $a, array $b) => $b['total'] <=> $a['total'],
                        fn (array $a, array $b) => strcmp($this->chave($a['titulo']), $this->chave($b['titulo'])),
                    ])
                    ->values();

                $posicao = 0;
                $anterior = null;

                return [
                    'area' => $area,
                    'premiacoes' => (int) $projetos->sum('total'),
                    'projetos' => $ordenados->map(function (array $p, int $i) use (&$posicao, &$anterior) {
                        if ($p['total'] !== $anterior) {
                            $posicao = $i + 1;
                            $anterior = $p['total'];
                        }

                        return ['posicao' => $posicao] + Arr::except($p, ['area']);
                    })->all(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $b['premiacoes'] <=> $a['premiacoes'],
                fn (array $a, array $b) => strcmp($this->chave($a['area']), $this->chave($b['area'])),
            ])
            ->values()
            ->all();
    }

    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
