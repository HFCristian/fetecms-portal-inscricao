<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Coorientador;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correção manual de um projeto submetido pelo admin (Projetos submetidos →
 * Editar): categoria, área, subárea, link do vídeo, **orientador** e
 * **coorientador**.
 *
 * Trocar o orientador troca o **dono** do projeto (`projetos.user_id`): ele sai
 * da área do antigo e passa a aparecer em "Meus projetos" do novo, que é quem a
 * Policy autoriza dali em diante. Por isso a escolha é entre contas de
 * orientador que já existem — o portal não inventa dono.
 *
 * O coorientador não tem conta: é uma linha de dados do projeto, então o admin
 * pode **editar, incluir e remover**.
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

            if (array_key_exists('user_id', $dados) && $dados['user_id'] !== null) {
                $de = $projeto->user;
                $para = User::find($dados['user_id']);

                if ($para !== null && $para->id !== $projeto->user_id) {
                    $mudancas['user_id'] = $para->id;
                    $alteracoes[] = 'orientador';
                    $this->registros->correcaoProjeto(
                        TipoRegistro::ProjetoOrientador, $projeto, $admin,
                        $this->pessoa($de?->name, $de?->email),
                        $this->pessoa($para->name, $para->email),
                        $justificativa,
                    );
                }
            }

            if (array_key_exists('coorientador', $dados)) {
                $this->salvarCoorientador($projeto, $dados['coorientador'], $admin, $justificativa, $alteracoes);
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
            'projeto' => $projeto->fresh(['area', 'subarea', 'user', 'coorientador']),
            'alteracoes' => $alteracoes,
        ];
    }

    /**
     * Inclui, edita ou remove o coorientador do projeto. `null` remove; um array
     * inclui (quando não há) ou atualiza os campos que mudaram — um registro por
     * campo, para a trilha dizer exatamente o que foi corrigido.
     *
     * @param  array<string, mixed>|null  $dados
     * @param  list<string>  $alteracoes
     */
    private function salvarCoorientador(
        Projeto $projeto,
        ?array $dados,
        User $admin,
        string $justificativa,
        array &$alteracoes,
    ): void {
        $atual = $projeto->coorientador;

        if ($dados === null) {
            if ($atual === null) {
                return;
            }

            $this->registros->correcaoProjeto(
                TipoRegistro::ProjetoCoorientador, $projeto, $admin,
                $this->pessoa($atual->nome, $atual->email), '(sem coorientador)', $justificativa,
            );
            $atual->delete();
            $alteracoes[] = 'coorientador';

            return;
        }

        if ($atual === null) {
            Coorientador::create(['projeto_id' => $projeto->id] + $dados);
            $this->registros->correcaoProjeto(
                TipoRegistro::ProjetoCoorientador, $projeto, $admin,
                '(sem coorientador)', $this->pessoa($dados['nome'] ?? null, $dados['email'] ?? null), $justificativa,
            );
            $alteracoes[] = 'coorientador';

            return;
        }

        $rotulos = ['nome' => 'nome', 'email' => 'e-mail', 'cpf' => 'CPF', 'telefone' => 'telefone'];
        $mudou = [];

        foreach ($rotulos as $campo => $rotulo) {
            if (! array_key_exists($campo, $dados) || (string) $atual->{$campo} === (string) $dados[$campo]) {
                continue;
            }

            $this->registros->correcaoProjeto(
                TipoRegistro::ProjetoCoorientador, $projeto, $admin,
                $atual->{$campo}, $dados[$campo], $justificativa, 'coorientador: '.$rotulo,
            );
            $mudou[$campo] = $dados[$campo];
        }

        if ($mudou !== []) {
            $atual->update($mudou);
            $alteracoes[] = 'coorientador';
        }
    }

    /** "Nome (e-mail)" — como a trilha identifica uma pessoa trocada. */
    private function pessoa(?string $nome, ?string $email): ?string
    {
        if ($nome === null && $email === null) {
            return null;
        }

        return trim($nome.($email === null ? '' : " ({$email})"));
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
