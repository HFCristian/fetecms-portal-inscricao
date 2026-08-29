<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correção manual de um projeto submetido pelo admin (Projetos submetidos →
 * Editar): categoria, área, subárea e link do vídeo.
 *
 * É um escape do edital — o orientador não pode mexer depois de submeter —,
 * então **toda** mudança exige justificativa e vira registro na trilha
 * (Registros → Projetos), um por campo alterado, com o "de → para".
 *
 * Trocar a área sem informar a subárea limpa a subárea: manter a antiga
 * deixaria o projeto classificado numa subárea de outra área.
 */
class AdminProjetoEdicaoService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /**
     * @param  array{categoria?: string|null, area_id?: int|null, subarea_id?: int|null, link_video?: string|null, justificativa: string}  $dados
     * @return array{projeto: Projeto, alteracoes: list<string>}
     */
    public function atualizar(Projeto $projeto, array $dados, User $admin): array
    {
        $justificativa = trim((string) $dados['justificativa']);
        $alteracoes = [];

        DB::transaction(function () use ($projeto, $dados, $admin, $justificativa, &$alteracoes) {
            $mudancas = [];

            if (array_key_exists('categoria', $dados)) {
                $de = $projeto->categoria;
                $para = $dados['categoria'] === null ? null : Categoria::from($dados['categoria']);

                if ($de?->value !== $para?->value) {
                    $mudancas['categoria'] = $para?->value;
                    $alteracoes[] = 'categoria';
                    $this->registros->correcaoProjeto(
                        TipoRegistro::ProjetoCategoria, $projeto, $admin,
                        $de?->label(), $para?->label(), $justificativa,
                    );
                }
            }

            if (array_key_exists('area_id', $dados)) {
                $de = $projeto->area_id;
                $para = $dados['area_id'];

                if ((int) $de !== (int) $para) {
                    $mudancas['area_id'] = $para;
                    $alteracoes[] = 'área';
                    $this->registros->correcaoProjeto(
                        TipoRegistro::ProjetoArea, $projeto, $admin,
                        $this->nomeArea($de), $this->nomeArea($para), $justificativa,
                    );

                    // Área nova sem subárea informada: a antiga não vale mais.
                    if (! array_key_exists('subarea_id', $dados)) {
                        $dados['subarea_id'] = null;
                    }
                }
            }

            if (array_key_exists('subarea_id', $dados)) {
                $de = $projeto->subarea_id;
                $para = $dados['subarea_id'];

                if ((int) $de !== (int) $para) {
                    $mudancas['subarea_id'] = $para;
                    $alteracoes[] = 'subárea';
                    $this->registros->correcaoProjeto(
                        TipoRegistro::ProjetoSubarea, $projeto, $admin,
                        $this->nomeSubarea($de), $this->nomeSubarea($para), $justificativa,
                    );
                }
            }

            if (array_key_exists('link_video', $dados)) {
                $de = $projeto->link_video;
                $para = $dados['link_video'];

                if ((string) $de !== (string) $para) {
                    $mudancas['link_video'] = $para;
                    $alteracoes[] = 'vídeo';
                    $this->registros->correcaoProjeto(
                        TipoRegistro::ProjetoVideo, $projeto, $admin,
                        $de, $para, $justificativa,
                    );
                }
            }

            if ($mudancas !== []) {
                $projeto->forceFill($mudancas)->save();
            }
        });

        return [
            'projeto' => $projeto->fresh(['area', 'subarea', 'user']),
            'alteracoes' => $alteracoes,
        ];
    }

    private function nomeArea(?int $id): ?string
    {
        return $id === null ? null : Area::find($id)?->nome;
    }

    private function nomeSubarea(?int $id): ?string
    {
        return $id === null ? null : Subarea::find($id)?->nome;
    }
}
