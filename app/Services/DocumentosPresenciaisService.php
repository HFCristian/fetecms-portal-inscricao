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
 * do evento**, hoje o **termo de responsabilidade**.
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
 */
class DocumentosPresenciaisService
{
    /** Tamanho máximo do arquivo — o mesmo dos anexos da inscrição. */
    public const MAX_KB = 10240;

    public function __construct(private readonly DocumentoService $documentos) {}

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

        return Projeto::query()
            ->whereIn('id', $ids)
            ->with(['area:id,nome', 'documentos'])
            ->orderBy('titulo')
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'categoria' => $p->categoria?->label(),
                'termo' => $this->termoDe($p),
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

    private function garantirFinalista(Projeto $projeto, User $autor, bool $teste = false): void
    {
        if (! $this->finalistas($autor, $teste)->contains($projeto->id)) {
            throw ValidationException::withMessages([
                'projeto' => 'Só projetos finalistas enviam o termo de responsabilidade.',
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
