<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Cidade;
use App\Models\Coorientador;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Projetos em rascunho" (Projetos por área → botão que aparece depois do fim
 * das inscrições): o admin abre a inscrição que o orientador deixou pela
 * metade, termina de preencher e **submete**, mesmo com o prazo vencido.
 *
 * É um escape do edital, então:
 *
 * - o admin **não conclui deixando em rascunho** — a única saída que fecha o
 *   trabalho é submeter, e ela exige justificativa (validada no
 *   ProjetoSubmissaoController);
 * - **cada campo que ele mexe** vira uma linha em Registros → Rascunhos, com o
 *   "de → para". Quem grava é este serviço, chamado pelos controllers de
 *   escrita do orientador — que o admin reaproveita, já que a Policy o deixa
 *   passar e o middleware `inscricoes.abertas` não o barra.
 *
 * Nada disso vale para o dono da inscrição: `ehEdicaoDeAdmin()` só é verdadeiro
 * quando um admin mexe no rascunho de OUTRA pessoa. O orientador editando o
 * próprio rascunho não gera registro nenhum, como sempre.
 */
class AdminRascunhoService
{
    /**
     * Colunas do projeto que valem registro, com o nome que aparece na trilha.
     * O que não está aqui (timestamps, status, user_id, edicao_id) é ruído de
     * infraestrutura, não decisão de quem preencheu a inscrição.
     *
     * @var array<string, string>
     */
    private const CAMPOS = [
        'titulo' => 'Título',
        'categoria' => 'Categoria',
        'pictec_ms' => 'PICTEC MS',
        'instituicao_id' => 'Instituição',
        'area_id' => 'Área',
        'subarea_id' => 'Subárea',
        'resumo' => 'Resumo',
        'link_video' => 'Link do vídeo',
        'palavras_chave' => 'Palavras-chave',
        'pais' => 'País',
        'estado_id' => 'Estado',
        'cidade_id' => 'Cidade',
        'estado_nome' => 'Estado',
        'cidade_nome' => 'Cidade',
        'continuacao' => 'Projeto de continuação',
        'tempo_pesquisa_meses' => 'Tempo de pesquisa (meses)',
        'feira_afiliada' => 'Feira afiliada',
        'feira_afiliada_nome' => 'Nome da feira afiliada',
        'necessita_termo_etica' => 'Necessita termo de ética',
        'numero_credencial' => 'Número da credencial',
        'agenda_2030' => 'Agenda 2030',
        'categoria_agenda_2030' => 'Categoria da Agenda 2030',
        'email_comunicacao' => 'E-mail de comunicação',
        'declaracao_email' => 'Declaração de e-mail',
    ];

    /** Campos do aluno/coorientador cujo nome aparece na trilha. */
    private const CAMPOS_PESSOA = [
        'nome' => 'nome',
        'email' => 'e-mail',
        'cpf' => 'CPF',
        'telefone' => 'telefone',
        'data_nascimento' => 'data de nascimento',
        'genero' => 'gênero',
        'etnia' => 'etnia',
        'camiseta' => 'camiseta',
        'instituicao_id' => 'instituição',
        'modalidade' => 'modalidade',
        'ano_escolar' => 'ano/série',
        'periodo' => 'período',
        'graduacao_pretendida' => 'graduação pretendida',
        'bolsista' => 'bolsista',
        'clube_ciencias' => 'clube de ciências',
        'autorizacao_menor' => 'autorização do menor',
    ];

    public function __construct(
        private readonly RegistroAtividadeService $registros,
        private readonly ProjetoChecklistService $checklist,
    ) {}

    /**
     * Este usuário está mexendo, como admin, no rascunho de outra pessoa? É a
     * condição única para exigir justificativa e gravar a trilha.
     */
    public function ehEdicaoDeAdmin(Projeto $projeto, ?User $user): bool
    {
        return $user !== null
            && $user->isAdmin()
            && $projeto->user_id !== $user->id
            && $projeto->status === ProjetoStatus::Rascunho;
    }

    /**
     * Diferença entre o projeto antes e depois de um update. `$antes` precisa
     * ser lido ANTES de salvar (`$projeto->getAttributes()`); `$projeto` é o
     * modelo já atualizado.
     *
     * @param  array<string, mixed>  $antes
     */
    public function registrarAlteracoesProjeto(Projeto $projeto, ?User $user, array $antes): void
    {
        if (! $this->ehEdicaoDeAdmin($projeto, $user)) {
            return;
        }

        $depois = $projeto->getAttributes();

        foreach (self::CAMPOS as $coluna => $rotulo) {
            $de = $antes[$coluna] ?? null;
            $para = $depois[$coluna] ?? null;

            if ($this->iguais($de, $para)) {
                continue;
            }

            $this->registros->alteracaoRascunho(
                $projeto, $user, $rotulo,
                $this->formatar($coluna, $de),
                $this->formatar($coluna, $para),
            );
        }
    }

    /** Aluno novo na equipe. */
    public function registrarAlunoAdicionado(Projeto $projeto, ?User $user, Aluno $aluno): void
    {
        if ($this->ehEdicaoDeAdmin($projeto, $user)) {
            $this->registros->alteracaoRascunho($projeto, $user, 'Aluno', null, $aluno->nome);
        }
    }

    /**
     * Aluno editado: uma linha só, listando os campos que mudaram — o nome do
     * aluno identifica de quem se trata.
     *
     * @param  array<string, mixed>  $antes
     */
    public function registrarAlunoAlterado(Projeto $projeto, ?User $user, Aluno $aluno, array $antes): void
    {
        if (! $this->ehEdicaoDeAdmin($projeto, $user)) {
            return;
        }

        $mudados = $this->camposDePessoaAlterados($antes, $aluno->getAttributes());

        if ($mudados !== []) {
            $this->registros->alteracaoRascunho(
                $projeto, $user,
                'Aluno · '.($antes['nome'] ?? $aluno->nome),
                implode(', ', $mudados),
                $aluno->nome,
            );
        }
    }

    public function registrarAlunoRemovido(Projeto $projeto, ?User $user, Aluno $aluno): void
    {
        if ($this->ehEdicaoDeAdmin($projeto, $user)) {
            $this->registros->alteracaoRascunho($projeto, $user, 'Aluno', $aluno->nome, null);
        }
    }

    /**
     * Coorientador criado ou substituído. `$antes` é o nome que estava lá (null
     * quando não havia coorientador).
     */
    public function registrarCoorientador(Projeto $projeto, ?User $user, ?string $antes, Coorientador $atual): void
    {
        if ($this->ehEdicaoDeAdmin($projeto, $user) && $antes !== $atual->nome) {
            $this->registros->alteracaoRascunho($projeto, $user, 'Coorientador', $antes, $atual->nome);
        }
    }

    public function registrarCoorientadorRemovido(Projeto $projeto, ?User $user, ?string $nome): void
    {
        if ($this->ehEdicaoDeAdmin($projeto, $user)) {
            $this->registros->alteracaoRascunho($projeto, $user, 'Coorientador', $nome, null);
        }
    }

    public function registrarDocumentoAnexado(Projeto $projeto, ?User $user, ProjetoDocumento $documento): void
    {
        if ($this->ehEdicaoDeAdmin($projeto, $user)) {
            $this->registros->alteracaoRascunho(
                $projeto, $user,
                'Anexo · '.$documento->tipo->label(),
                null, $documento->nome_original,
            );
        }
    }

    public function registrarDocumentoRemovido(Projeto $projeto, ?User $user, ProjetoDocumento $documento): void
    {
        if ($this->ehEdicaoDeAdmin($projeto, $user)) {
            $this->registros->alteracaoRascunho(
                $projeto, $user,
                'Anexo · '.$documento->tipo->label(),
                $documento->nome_original, null,
            );
        }
    }

    /** A submissão que fecha o trabalho do admin, com a justificativa do escape. */
    public function registrarSubmissao(Projeto $projeto, User $admin, string $justificativa): void
    {
        $this->registros->submissaoRascunho($projeto, $admin, trim($justificativa));
    }

    /**
     * A lista da tela: todos os projetos em rascunho, com o orientador dono, o
     * tamanho da equipe e quantas pendências ainda faltam para submeter.
     *
     * Filtros: `busca` (título ou orientador), `area_id` e `categoria`.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function listar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        $pagina = $this->query($filtros)->paginate($porPagina)->withQueryString();

        $pagina->getCollection()->transform(fn (Projeto $p) => $this->item($p));

        return $pagina;
    }

    /** @param array<string, mixed> $filtros */
    private function query(array $filtros): Builder
    {
        $busca = trim((string) ($filtros['busca'] ?? ''));

        return Projeto::query()
            ->where('status', ProjetoStatus::Rascunho)
            ->with(['user:id,name,email', 'area:id,nome', 'instituicao:id,nome', 'alunos', 'documentos'])
            ->when(! empty($filtros['area_id']), fn ($q) => $q->where('area_id', $filtros['area_id']))
            ->when(! empty($filtros['categoria']), fn ($q) => $q->where('categoria', $filtros['categoria']))
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(function ($sub) use ($termo) {
                    $sub->whereRaw('LOWER(titulo) LIKE ?', [$termo])
                        ->orWhereHas('user', fn ($u) => $u
                            ->whereRaw('LOWER(name) LIKE ?', [$termo])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$termo]));
                });
            })
            ->orderByDesc('updated_at');
    }

    /** @return array<string, mixed> */
    private function item(Projeto $projeto): array
    {
        $pendencias = $this->checklist->pendencias($projeto);

        return [
            'id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'categoria' => $projeto->categoria?->value,
            'categoria_label' => $projeto->categoria?->label(),
            'area_id' => $projeto->area_id,
            'area' => $projeto->area?->nome,
            'instituicao' => $projeto->instituicao?->nome,
            'orientador' => $projeto->user?->name,
            'orientador_email' => $projeto->user?->email,
            'alunos' => $projeto->alunos->count(),
            'pendencias' => count($pendencias),
            'pronto' => $pendencias === [],
            'atualizado_em' => $projeto->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Campos de pessoa que mudaram, já com o nome legível.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     * @return list<string>
     */
    private function camposDePessoaAlterados(array $antes, array $depois): array
    {
        $mudados = [];

        foreach (self::CAMPOS_PESSOA as $coluna => $rotulo) {
            if (! $this->iguais($antes[$coluna] ?? null, $depois[$coluna] ?? null)) {
                $mudados[] = $rotulo;
            }
        }

        return $mudados;
    }

    /**
     * Comparação tolerante: null e string vazia são a mesma "ausência", e
     * número/booleano vindos do banco não devem divergir do que o PHP escreveu
     * só pelo tipo.
     */
    private function iguais(mixed $de, mixed $para): bool
    {
        $normal = fn ($v) => match (true) {
            $v === null, $v === '' => null,
            is_bool($v) => $v ? '1' : '0',
            is_array($v) => json_encode($v),
            default => (string) $v,
        };

        return $normal($de) === $normal($para);
    }

    /** Valor legível de um campo: FK vira nome, booleano vira sim/não, lista vira texto. */
    private function formatar(string $coluna, mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($coluna) {
            'area_id' => Area::find($valor)?->nome,
            'subarea_id' => Subarea::find($valor)?->nome,
            'instituicao_id' => Instituicao::find($valor)?->nome,
            'estado_id' => Estado::find($valor)?->nome,
            'cidade_id' => Cidade::find($valor)?->nome,
            'categoria' => Categoria::tryFrom((string) $valor)?->label() ?? (string) $valor,
            'palavras_chave' => $this->listaDeTexto($valor),
            'pictec_ms', 'continuacao', 'feira_afiliada', 'necessita_termo_etica',
            'agenda_2030', 'declaracao_email' => $valor ? 'sim' : 'não',
            default => (string) $valor,
        };
    }

    /** Palavras-chave chegam como JSON do banco ou array do modelo. */
    private function listaDeTexto(mixed $valor): string
    {
        $lista = is_array($valor) ? $valor : (json_decode((string) $valor, true) ?: []);

        return is_array($lista) ? implode(', ', $lista) : (string) $valor;
    }
}
