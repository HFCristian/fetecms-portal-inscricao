<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\ProjetoAjuste;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aba "Ajustes" do orientador: terminada a avaliação online, ele entra para
 * responder ao que os avaliadores sugeriram nos projetos dele.
 *
 * O que ele decide é a **reclassificação** — área e subárea sugeridas na
 * rubrica. Aceitar troca a classificação do projeto na hora; desmarcar devolve
 * o valor anterior. A sugestão **nunca some da tela**: até o fim do prazo ele
 * pode mudar de ideia quantas vezes quiser.
 *
 * As recomendações escritas (vídeo e projeto) aparecem junto, só para leitura —
 * não há o que aceitar nelas.
 *
 * A janela é a `edicoes.ajustes_de`/`ajustes_ate`; fora dela a aba continua
 * visível no menu, mas não abre. O orientador demo tem o mesmo "modo teste" do
 * avaliador demo: ele ignora as datas para poder mostrar o fluxo.
 */
class AjustesOrientadorService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /**
     * Estado da janela para esta pessoa. `aberta` é o que a tela usa para
     * liberar ou bloquear.
     *
     * @return array<string, mixed>
     */
    public function janela(User $user, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $modoTeste = $teste && (bool) $user->is_demo;

        $iniciados = (bool) $edicao?->ajustesIniciados();
        $encerrados = (bool) $edicao?->ajustesEncerrados();

        return [
            'aberta' => $modoTeste || ($iniciados && ! $encerrados),
            'iniciada' => $iniciados,
            'encerrada' => $encerrados,
            'de' => $edicao?->ajustes_de?->toIso8601String(),
            'ate' => $edicao?->ajustes_ate?->toIso8601String(),
            'de_label' => $edicao?->ajustes_de?->format('d/m/Y H:i'),
            'ate_label' => $edicao?->ajustes_ate?->format('d/m/Y H:i'),
            'modo_teste' => $modoTeste,
            'is_demo' => (bool) $user->is_demo,
        ];
    }

    /** Barra quem tenta decidir fora da janela (a tela já esconde o botão). */
    public function garantirJanelaAberta(User $user, bool $teste = false): void
    {
        if (! $this->janela($user, $teste)['aberta']) {
            throw ValidationException::withMessages([
                'periodo' => 'O período de ajustes não está aberto.',
            ]);
        }
    }

    /**
     * Os projetos submetidos do orientador, com quantas sugestões cada um
     * recebeu e quantas ainda não foram respondidas.
     *
     * @return list<array<string, mixed>>
     */
    public function projetos(User $orientador): array
    {
        return Projeto::query()
            ->where('user_id', $orientador->id)
            ->whereIn('status', [
                ProjetoStatus::Submetido->value,
                ProjetoStatus::Aprovado->value,
                ProjetoStatus::Rejeitado->value,
            ])
            ->with(['area:id,nome', 'subarea:id,nome'])
            ->orderBy('titulo')
            ->get()
            ->map(function (Projeto $projeto) {
                $sugestoes = $this->sugestoes($projeto);

                return [
                    'id' => $projeto->id,
                    'titulo' => $projeto->titulo,
                    'area' => $projeto->area?->nome,
                    'subarea' => $projeto->subarea?->nome,
                    'sugestoes' => count($sugestoes),
                    'pendentes' => count(array_filter($sugestoes, fn (array $s) => $s['decidido_em'] === null)),
                    'aceitas' => count(array_filter($sugestoes, fn (array $s) => $s['aceito'])),
                    'recomendacoes' => count($this->recomendacoes($projeto)),
                ];
            })
            ->all();
    }

    /**
     * Um projeto com tudo o que os avaliadores disseram: as sugestões de
     * classificação (decidíveis) e as recomendações escritas (leitura).
     *
     * @return array<string, mixed>
     */
    public function detalhe(Projeto $projeto): array
    {
        $projeto->loadMissing(['area:id,nome', 'subarea:id,nome']);

        return [
            'id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'area' => $projeto->area?->nome,
            'subarea' => $projeto->subarea?->nome,
            'sugestoes' => $this->sugestoes($projeto),
            'recomendacoes' => $this->recomendacoes($projeto),
        ];
    }

    /**
     * Aceita ou desfaz uma sugestão. Aceitar aplica a troca no projeto na hora;
     * desmarcar devolve o valor que estava lá antes. Como só uma sugestão do
     * mesmo tipo pode estar valendo, aceitar uma desmarca a anterior.
     *
     * @return array<string, mixed> o projeto recarregado, no formato do detalhe
     */
    public function decidir(Projeto $projeto, int $avaliacaoId, string $tipo, bool $aceito, User $autor): array
    {
        $avaliacao = Avaliacao::where('projeto_id', $projeto->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->findOrFail($avaliacaoId);

        $sugerido = $tipo === ProjetoAjuste::TIPO_AREA
            ? $avaliacao->area_sugerida_id
            : $avaliacao->subarea_sugerida_id;

        if ($sugerido === null) {
            throw ValidationException::withMessages([
                'avaliacao_id' => 'Esta avaliação não sugeriu uma troca deste tipo.',
            ]);
        }

        DB::transaction(function () use ($projeto, $avaliacao, $tipo, $aceito, $autor, $sugerido) {
            $coluna = $tipo === ProjetoAjuste::TIPO_AREA ? 'area_id' : 'subarea_id';
            $registro = $tipo === ProjetoAjuste::TIPO_AREA
                ? TipoRegistro::ProjetoArea
                : TipoRegistro::ProjetoSubarea;

            if ($aceito) {
                // Uma sugestão do mesmo tipo por vez: a anterior deixa de valer
                // (o projeto passa a apontar para a nova).
                ProjetoAjuste::where('projeto_id', $projeto->id)
                    ->where('tipo', $tipo)
                    ->where('avaliacao_id', '!=', $avaliacao->id)
                    ->update(['aceito' => false]);

                $anterior = $projeto->{$coluna};

                $this->aplicar($projeto, $tipo, $sugerido);

                ProjetoAjuste::updateOrCreate(
                    ['avaliacao_id' => $avaliacao->id, 'tipo' => $tipo],
                    [
                        'projeto_id' => $projeto->id,
                        'aceito' => true,
                        'de_id' => $anterior,
                        'para_id' => $sugerido,
                        'user_id' => $autor->id,
                        'decidido_em' => now(),
                    ],
                );

                $this->registrar($registro, $projeto, $autor, $anterior, $sugerido, 'Sugestão do avaliador aceita pelo orientador.');

                return;
            }

            $ajuste = ProjetoAjuste::where('avaliacao_id', $avaliacao->id)->where('tipo', $tipo)->first();

            // Só desfaz de verdade se a sugestão for a que está valendo agora —
            // outra pode ter sido aceita por cima nesse meio-tempo.
            if ($ajuste?->aceito && (int) $projeto->{$coluna} === (int) $ajuste->para_id) {
                $this->aplicar($projeto, $tipo, $ajuste->de_id);
                $this->registrar($registro, $projeto, $autor, $ajuste->para_id, $ajuste->de_id, 'Sugestão do avaliador recusada pelo orientador.');
            }

            ProjetoAjuste::updateOrCreate(
                ['avaliacao_id' => $avaliacao->id, 'tipo' => $tipo],
                [
                    'projeto_id' => $projeto->id,
                    'aceito' => false,
                    'de_id' => $ajuste?->de_id,
                    'para_id' => $sugerido,
                    'user_id' => $autor->id,
                    'decidido_em' => now(),
                ],
            );
        });

        return $this->detalhe($projeto->refresh());
    }

    /**
     * As sugestões de reclassificação do projeto, uma por avaliação+tipo. O
     * avaliador não é identificado: para o orientador, o parecer é anônimo.
     *
     * @return list<array<string, mixed>>
     */
    private function sugestoes(Projeto $projeto): array
    {
        $decisoes = ProjetoAjuste::where('projeto_id', $projeto->id)
            ->get()
            ->keyBy(fn (ProjetoAjuste $a) => $a->avaliacao_id.'|'.$a->tipo);

        $itens = [];

        foreach ($this->avaliacoesConcluidas($projeto) as $i => $avaliacao) {
            foreach ([ProjetoAjuste::TIPO_AREA, ProjetoAjuste::TIPO_SUBAREA] as $tipo) {
                $ehArea = $tipo === ProjetoAjuste::TIPO_AREA;
                $correta = $ehArea ? $avaliacao->area_correta : $avaliacao->subarea_correta;
                $sugerido = $ehArea ? $avaliacao->areaSugerida : $avaliacao->subareaSugerida;

                if ($correta !== false || $sugerido === null) {
                    continue;
                }

                $decisao = $decisoes->get($avaliacao->id.'|'.$tipo);
                $atual = $ehArea ? $projeto->area_id : $projeto->subarea_id;

                $itens[] = [
                    'avaliacao_id' => $avaliacao->id,
                    'avaliador' => 'Avaliador '.($i + 1),
                    'tipo' => $tipo,
                    'tipo_label' => $ehArea ? 'Área do conhecimento' : 'Subárea',
                    'atual' => $ehArea ? $projeto->area?->nome : $projeto->subarea?->nome,
                    'sugerido' => $sugerido->nome,
                    'sugerido_id' => $sugerido->id,
                    // "Em vigor" é o que o projeto tem agora, não o que ele marcou:
                    // aceitar outra sugestão por cima desliga esta.
                    'aceito' => (bool) $decisao?->aceito && (int) $atual === (int) $sugerido->id,
                    'decidido_em' => $decisao?->decidido_em?->toIso8601String(),
                ];
            }
        }

        return $itens;
    }

    /**
     * As recomendações escritas pelos avaliadores (vídeo e projeto), só leitura.
     *
     * @return list<array<string, mixed>>
     */
    private function recomendacoes(Projeto $projeto): array
    {
        $itens = [];

        foreach ($this->avaliacoesConcluidas($projeto) as $i => $avaliacao) {
            foreach ([
                'video' => ['Sobre o vídeo', $avaliacao->comentario_video],
                'projeto' => ['Sobre o projeto', $avaliacao->comentario_projeto],
            ] as $chave => [$titulo, $texto]) {
                if (blank($texto)) {
                    continue;
                }

                $itens[] = [
                    'avaliacao_id' => $avaliacao->id,
                    'avaliador' => 'Avaliador '.($i + 1),
                    'tipo' => $chave,
                    'titulo' => $titulo,
                    'texto' => $texto,
                ];
            }
        }

        return $itens;
    }

    /**
     * Avaliações concluídas do projeto, sempre na mesma ordem — é ela que dá o
     * "Avaliador 1", "Avaliador 2" da tela.
     *
     * @return Collection<int, Avaliacao>
     */
    private function avaliacoesConcluidas(Projeto $projeto)
    {
        return Avaliacao::where('projeto_id', $projeto->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->with(['areaSugerida:id,nome', 'subareaSugerida:id,nome'])
            ->orderBy('concluida_em')
            ->orderBy('id')
            ->get();
    }

    /** Grava a troca no projeto (área nova zera a subárea, que é de outra árvore). */
    private function aplicar(Projeto $projeto, string $tipo, ?int $valor): void
    {
        if ($tipo === ProjetoAjuste::TIPO_AREA) {
            $projeto->forceFill(['area_id' => $valor, 'subarea_id' => null])->save();

            return;
        }

        $projeto->forceFill(['subarea_id' => $valor])->save();
    }

    private function registrar(
        TipoRegistro $tipo,
        Projeto $projeto,
        User $autor,
        ?int $de,
        ?int $para,
        string $justificativa,
    ): void {
        $nome = fn (?int $id) => $id === null ? null : ($tipo === TipoRegistro::ProjetoArea
            ? Area::find($id)?->nome
            : Subarea::find($id)?->nome);

        $this->registros->correcaoProjeto($tipo, $projeto, $autor, $nome($de), $nome($para), $justificativa);
    }
}
