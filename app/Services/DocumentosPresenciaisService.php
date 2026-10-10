<?php

namespace App\Services;

use App\Enums\TipoDocumento;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use App\Support\AssinaturaPdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Aba "Documentos" do orientador: o que a organização precisa receber **antes
 * do evento** — o **termo de responsabilidade** e o **termo de uso de imagem**.
 *
 * Só **projeto finalista** anexa. Finalista é quem está na lista final vigente
 * da edição — a mesma definição do credenciamento e do almoxarifado. Antes da
 * lista publicada não há o que pedir, e depois do evento não há a quem
 * entregar: a janela vai da **publicação da lista** ao **fim do evento**.
 *
 * O termo é **um por projeto**: ele é assinado pelo responsável e cobre a
 * equipe, então guardar um por pessoa multiplicaria o mesmo documento.
 * Reenviar substitui o anterior — quem corrige o arquivo não deve precisar
 * pedir a exclusão do errado antes.
 *
 * Todo PDF anexado passa pela conferência de assinatura (`AssinaturaPdf`), cujo
 * laudo fica guardado junto do arquivo. O documento **não é recusado** por
 * falhar nela: o laudo é o que o balcão lê na checagem do estande, e um termo
 * assinado à caneta e escaneado continua sendo um termo.
 *
 * O **termo de uso de imagem** (Sprint 174) é diferente em dois pontos. Pode
 * vir em **vários arquivos** — a escola às vezes tem um por estudante, às vezes
 * um da turma —, e cada arquivo diz **para quais estudantes vale**: é isso que
 * o credenciamento lê para já marcar quem está coberto. E aceita **foto**
 * (JPG/PNG) além do PDF, porque a autorização costuma ser assinada à mão pelos
 * responsáveis. A conferência da assinatura gov.br roda só no PDF, e só informa.
 *
 * Nenhum dos dois é obrigatório online: quem não enviar apresenta o termo de
 * responsabilidade na checagem do estande e a autorização de imagem no
 * credenciamento. O envio antecipado só agiliza o dia da feira.
 */
class DocumentosPresenciaisService
{
    /** Tamanho máximo do arquivo — o mesmo dos anexos da inscrição. */
    public const MAX_KB = 10240;

    /** Arquivos aceitos no termo de uso de imagem: o PDF e a foto do papel assinado. */
    public const MIMES_IMAGEM = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly DocumentoService $documentos,
        private readonly TermosImagemService $termosImagem,
    ) {}

    /**
     * Estado da janela para esta pessoa.
     *
     * @return array<string, mixed>
     */
    public function janela(User $user, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $modoTeste = $teste && (bool) $user->is_demo;
        $lista = ListaFinal::vigente($edicao, $modoTeste);

        $encerrado = (bool) $edicao?->eventoEncerrado();

        return [
            // Sem lista final publicada não há finalista — logo, não há termo.
            'aberta' => $lista !== null && ($modoTeste || ! $encerrado),
            'tem_lista' => $lista !== null,
            'encerrada' => $encerrado,
            'evento_de_label' => $edicao?->evento_de?->format('d/m/Y H:i'),
            'evento_ate_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            'modo_teste' => $modoTeste,
            'is_demo' => (bool) $user->is_demo,
            'max_kb' => self::MAX_KB,
        ];
    }

    /** Barra quem tenta anexar fora da janela (a tela já esconde o botão). */
    public function garantirJanelaAberta(User $user, bool $teste = false): void
    {
        $janela = $this->janela($user, $teste);

        if (! $janela['aberta']) {
            throw ValidationException::withMessages([
                'periodo' => $janela['tem_lista']
                    ? 'O período de envio de documentos está encerrado.'
                    : 'A lista final ainda não foi publicada.',
            ]);
        }
    }

    /**
     * Os projetos **finalistas** deste orientador, com o termo já anexado (se
     * houver).
     *
     * @return list<array<string, mixed>>
     */
    public function projetos(User $orientador, bool $teste = false): array
    {
        $ids = $this->finalistas($orientador, $teste);

        if ($ids->isEmpty()) {
            return [];
        }

        $cobertura = $this->termosImagem->cobertura($ids);

        return Projeto::query()
            ->whereIn('id', $ids)
            ->with(['area:id,nome', 'documentos.alunos:id,nome', 'alunos:id,projeto_id,nome'])
            ->orderBy('titulo')
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'categoria' => $p->categoria?->label(),
                'termo' => $this->termoDe($p),
                // Os estudantes, para o orientador marcar quem cada arquivo cobre.
                'alunos' => $p->alunos
                    ->sortBy(fn ($a) => mb_strtolower((string) $a->nome))
                    ->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome])
                    ->values()
                    ->all(),
                'termo_imagem' => $this->resumoImagem($p, $cobertura[$p->id]),
            ])
            ->all();
    }

    /**
     * Anexa (ou substitui) o termo de responsabilidade do projeto.
     *
     * @return array<string, mixed> o termo no formato da tela
     */
    public function anexarTermo(Projeto $projeto, UploadedFile $arquivo, User $autor, bool $teste = false): array
    {
        $this->garantirJanelaAberta($autor, $teste);
        $this->garantirFinalista($projeto, $autor, $teste);

        // Reenviar substitui: o termo é um por projeto.
        foreach ($this->termos($projeto) as $anterior) {
            $this->documentos->remover($anterior);
        }

        $laudo = AssinaturaPdf::conferir((string) file_get_contents($arquivo->getRealPath()));

        $documento = $this->documentos->armazenar(
            $projeto,
            $arquivo,
            TipoDocumento::TermoResponsabilidade,
        );

        $documento->forceFill([
            'assinatura_valida' => $laudo['valida'],
            'assinatura' => $laudo,
        ])->save();

        return $this->formatar($documento);
    }

    /** Remove o termo do projeto (o orientador reenvia depois). */
    public function removerTermo(Projeto $projeto, User $autor, bool $teste = false): void
    {
        $this->garantirJanelaAberta($autor, $teste);
        $this->garantirFinalista($projeto, $autor, $teste);

        foreach ($this->termos($projeto) as $termo) {
            $this->documentos->remover($termo);
        }
    }

    /**
     * O termo de um projeto, no formato da tela (null se não houver) — usado
     * também pelas telas do admin.
     *
     * @return array<string, mixed>|null
     */
    public function termoDe(Projeto $projeto): ?array
    {
        $termo = $this->termos($projeto)->first();

        return $termo === null ? null : $this->formatar($termo);
    }

    /**
     * Anexa um arquivo do **termo de uso de imagem**, valendo para os
     * estudantes marcados (Sprint 174). Não substitui os anteriores: o projeto
     * pode ter um arquivo por estudante.
     *
     * @param  list<int>  $alunoIds
     * @return array<string, mixed> a cobertura do projeto depois do envio
     */
    public function anexarTermoImagem(Projeto $projeto, UploadedFile $arquivo, array $alunoIds, User $autor, bool $teste = false): array
    {
        $this->garantirJanelaAberta($autor, $teste);
        $this->garantirFinalista($projeto, $autor, $teste, 'o termo de uso de imagem');
        $alunoIds = $this->alunosDoProjeto($projeto, $alunoIds);

        $documento = $this->documentos->armazenar($projeto, $arquivo, TipoDocumento::TermoUsoImagem);

        // A conferência da assinatura digital só faz sentido no PDF — a foto do
        // papel assinado à mão não tem o que conferir, e nem por isso vale menos.
        if ($this->ehPdf($documento)) {
            $laudo = AssinaturaPdf::conferir((string) file_get_contents($arquivo->getRealPath()));
            $documento->forceFill(['assinatura_valida' => $laudo['valida'], 'assinatura' => $laudo])->save();
        }

        $documento->alunos()->sync($alunoIds);

        return $this->resumoImagem($projeto->fresh(['documentos.alunos:id,nome']));
    }

    /**
     * Troca para quais estudantes um arquivo do termo de imagem vale — o
     * orientador marcou errado e não precisa reenviar o arquivo por isso.
     *
     * @param  list<int>  $alunoIds
     * @return array<string, mixed> a cobertura do projeto depois da troca
     */
    public function definirAlunosTermoImagem(ProjetoDocumento $documento, array $alunoIds, User $autor, bool $teste = false): array
    {
        $projeto = $this->termoImagemDoOrientador($documento, $autor, $teste);

        $documento->alunos()->sync($this->alunosDoProjeto($projeto, $alunoIds));

        return $this->resumoImagem($projeto->fresh(['documentos.alunos:id,nome']));
    }

    /**
     * Remove um arquivo do termo de imagem; os estudantes que só ele cobria
     * voltam a faltar.
     *
     * @return array<string, mixed> a cobertura do projeto depois da remoção
     */
    public function removerTermoImagem(ProjetoDocumento $documento, User $autor, bool $teste = false): array
    {
        $projeto = $this->termoImagemDoOrientador($documento, $autor, $teste);

        $this->documentos->remover($documento);

        return $this->resumoImagem($projeto->fresh(['documentos.alunos:id,nome']));
    }

    /**
     * A situação do termo de imagem de um projeto, no formato da tela: a
     * cobertura (quem está e quem falta) e cada arquivo, com os estudantes
     * dele e o laudo da assinatura quando é PDF.
     *
     * @param  array<string, mixed>|null  $cobertura  já calculada em lote, quando houver
     * @return array<string, mixed>
     */
    private function resumoImagem(Projeto $projeto, ?array $cobertura = null): array
    {
        $cobertura ??= $this->termosImagem->doProjeto($projeto->id);

        $arquivos = $projeto->documentos
            ->filter(fn (ProjetoDocumento $d) => $d->tipo === TipoDocumento::TermoUsoImagem)
            ->sortBy('id')
            ->map(fn (ProjetoDocumento $d) => $this->formatar($d) + [
                'pdf' => $this->ehPdf($d),
                'alunos' => $d->alunos
                    ->sortBy(fn ($a) => mb_strtolower((string) $a->nome))
                    ->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return ['arquivos' => $arquivos] + $cobertura;
    }

    /**
     * O projeto do arquivo, se ele é um termo de imagem de um finalista deste
     * orientador e a janela está aberta. Arquivo de outro tipo responde como
     * inexistente — esta rota não mexe em anexo de inscrição.
     */
    private function termoImagemDoOrientador(ProjetoDocumento $documento, User $autor, bool $teste): Projeto
    {
        if ($documento->tipo !== TipoDocumento::TermoUsoImagem) {
            abort(404);
        }

        $projeto = $documento->projeto;

        $this->garantirJanelaAberta($autor, $teste);
        $this->garantirFinalista($projeto, $autor, $teste, 'o termo de uso de imagem');

        return $projeto;
    }

    /**
     * Os estudantes marcados, conferidos contra a equipe do projeto: ao menos
     * um, e nenhum de fora.
     *
     * @param  array<int, mixed>  $alunoIds
     * @return list<int>
     */
    private function alunosDoProjeto(Projeto $projeto, array $alunoIds): array
    {
        $alunoIds = array_values(array_unique(array_map('intval', $alunoIds)));
        $daEquipe = $projeto->alunos()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($alunoIds === []) {
            throw ValidationException::withMessages([
                'alunos' => 'Marque ao menos um estudante para quem o termo vale.',
            ]);
        }

        if (array_diff($alunoIds, $daEquipe) !== []) {
            throw ValidationException::withMessages([
                'alunos' => 'Marque só estudantes deste projeto.',
            ]);
        }

        return $alunoIds;
    }

    private function ehPdf(ProjetoDocumento $documento): bool
    {
        return $documento->mime === 'application/pdf'
            || str_ends_with(mb_strtolower((string) $documento->nome_original), '.pdf');
    }

    /**
     * Os ids dos projetos deste orientador que estão na lista final vigente.
     *
     * @return Collection<int, int>
     */
    private function finalistas(User $orientador, bool $teste = false): Collection
    {
        $modoTeste = $teste && (bool) $orientador->is_demo;
        $lista = ListaFinal::vigente(Edicao::atual(), $modoTeste);

        if ($lista === null) {
            return collect();
        }

        return $lista->projetos()
            ->where('projetos.user_id', $orientador->id)
            ->pluck('projetos.id');
    }

    private function garantirFinalista(Projeto $projeto, User $autor, bool $teste = false, string $documento = 'o termo de responsabilidade'): void
    {
        if (! $this->finalistas($autor, $teste)->contains($projeto->id)) {
            throw ValidationException::withMessages([
                'projeto' => "Só projetos finalistas enviam {$documento}.",
            ]);
        }
    }

    /** @return Collection<int, ProjetoDocumento> */
    private function termos(Projeto $projeto): Collection
    {
        return ProjetoDocumento::where('projeto_id', $projeto->id)
            ->where('tipo', TipoDocumento::TermoResponsabilidade->value)
            ->orderByDesc('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function formatar(ProjetoDocumento $documento): array
    {
        $laudo = $documento->assinatura ?? [];

        return [
            'id' => $documento->id,
            'nome_original' => $documento->nome_original,
            'tamanho_bytes' => $documento->tamanho_bytes,
            'enviado_em' => $documento->created_at?->toIso8601String(),
            'assinatura' => [
                'valida' => $documento->assinatura_valida,
                'assinado' => (bool) ($laudo['assinado'] ?? false),
                'icp_brasil' => (bool) ($laudo['icp_brasil'] ?? false),
                'integro' => $laudo['integro'] ?? null,
                'motivo' => $laudo['motivo'] ?? 'Assinatura não conferida.',
                'signatarios' => array_map(
                    fn (array $s) => [
                        'nome' => $s['nome'] ?? null,
                        // O CPF do certificado aparece mascarado: identifica
                        // sem expor o documento inteiro na tela.
                        'cpf' => isset($s['cpf']) && $s['cpf'] !== null
                            ? '***.'.substr((string) $s['cpf'], 3, 3).'.'.substr((string) $s['cpf'], 6, 3).'-**'
                            : null,
                        'emissor' => $s['emissor'] ?? null,
                    ],
                    (array) ($laudo['signatarios'] ?? []),
                ),
            ],
        ];
    }
}
