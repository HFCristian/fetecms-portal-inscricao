<?php

namespace App\Services;

use App\Enums\StatusSuporte;
use App\Enums\TipoRegistro;
use App\Enums\TipoSuporte;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\SuporteProjeto;
use App\Models\User;
use App\Support\CodigoParticipante;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * **Requisições de suporte** dos projetos finalistas (Sprint 162):
 * acompanhante (estudante neurodivergente ou com deficiência), intérprete de
 * Libras e intérprete de outra língua.
 *
 * O caminho tem dois lados. O **orientador** pede, na aba *Suporte* da área
 * dele, para cada projeto seu que está na lista final — e, no acompanhante,
 * cadastra a pessoa (nome completo, documento e vínculo com o estudante), que
 * precisa de crachá e de controle de acesso. A **organização** aprova ou recusa
 * (recusar exige motivo, que o orientador lê). Só o **aprovado** chega às
 * equipes do evento ({@see SinalizacaoProjetoService}) e, no acompanhante, vira
 * participante com código próprio (papel `S`) nas etiquetas e na lista nominal.
 *
 * Mexer num pedido já decidido o devolve para "aguardando": a organização
 * aprovou o que leu, não o que veio depois.
 *
 * A janela é a dos documentos do finalista: da lista publicada ao fim do evento.
 * O admin passa por cima dela, e o orientador demo tem o modo de teste.
 */
class SuporteProjetoService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /** @return array<string, mixed> */
    public function janela(User $user, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $modoTeste = $teste && (bool) $user->is_demo;
        $lista = ListaFinal::vigente($edicao, $modoTeste);
        $encerrado = (bool) $edicao?->eventoEncerrado();

        return [
            'aberta' => $lista !== null && ($modoTeste || ! $encerrado),
            'tem_lista' => $lista !== null,
            'encerrada' => $encerrado,
            'evento_ate_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            'modo_teste' => $modoTeste,
            'is_demo' => (bool) $user->is_demo,
            'tipos' => TipoSuporte::opcoes(),
        ];
    }

    /**
     * Os projetos finalistas do orientador, cada um com os seus pedidos.
     *
     * @return list<array<string, mixed>>
     */
    public function projetosDoOrientador(User $orientador, bool $teste = false): array
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $teste && (bool) $orientador->is_demo);

        if ($lista === null) {
            return [];
        }

        return Projeto::query()
            ->where('user_id', $orientador->id)
            ->whereIn('id', $lista->projetos()->select('projetos.id'))
            ->with(['alunos:id,projeto_id,nome', 'suportes.aluno:id,nome', 'area:id,nome'])
            ->orderBy('titulo')
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'categoria' => $p->categoria?->label(),
                'alunos' => $p->alunos->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome])->all(),
                'suportes' => $p->suportes->sortBy('id')->map(fn (SuporteProjeto $s) => $this->linha($s))->values()->all(),
            ])
            ->all();
    }

    /**
     * Cria ou altera um pedido. Alterar o que já foi decidido devolve o pedido
     * para "aguardando".
     *
     * @param  array<string, mixed>  $dados
     */
    public function salvar(Projeto $projeto, array $dados, User $autor, ?SuporteProjeto $suporte = null, bool $teste = false): SuporteProjeto
    {
        $this->garantirPodeMexer($projeto, $autor, $teste);

        $tipo = TipoSuporte::from($dados['tipo']);
        $alunoId = $dados['aluno_id'] ?? null;

        if ($alunoId !== null && ! $projeto->alunos()->whereKey($alunoId)->exists()) {
            throw ValidationException::withMessages(['aluno_id' => 'Escolha um estudante deste projeto.']);
        }

        if ($tipo === TipoSuporte::Acompanhante) {
            foreach (['acompanhante_nome' => 'o nome completo', 'acompanhante_documento' => 'o documento', 'acompanhante_vinculo' => 'o vínculo com o estudante'] as $campo => $rotulo) {
                if (trim((string) ($dados[$campo] ?? '')) === '') {
                    throw ValidationException::withMessages([$campo => "Informe {$rotulo} do acompanhante."]);
                }
            }
            if ($alunoId === null) {
                throw ValidationException::withMessages(['aluno_id' => 'Diga qual estudante o acompanhante acompanha.']);
            }
        }

        if ($tipo === TipoSuporte::InterpreteLingua && trim((string) ($dados['idioma'] ?? '')) === '') {
            throw ValidationException::withMessages(['idioma' => 'Informe a língua.']);
        }

        $acompanhante = $tipo === TipoSuporte::Acompanhante;
        $campos = [
            'tipo' => $tipo,
            'idioma' => $tipo === TipoSuporte::InterpreteLingua ? trim((string) $dados['idioma']) : null,
            'aluno_id' => $alunoId,
            'acompanhante_nome' => $acompanhante ? trim((string) $dados['acompanhante_nome']) : null,
            'acompanhante_documento' => $acompanhante ? trim((string) $dados['acompanhante_documento']) : null,
            'acompanhante_vinculo' => $acompanhante ? trim((string) $dados['acompanhante_vinculo']) : null,
            'observacao' => trim((string) ($dados['observacao'] ?? '')) ?: null,
        ];

        if ($suporte === null) {
            $suporte = $projeto->suportes()->create($campos + [
                'status' => $autor->isAdmin() && ! empty($dados['aprovar']) ? StatusSuporte::Aprovado : StatusSuporte::Pendente,
                'solicitado_por' => $autor->id,
            ] + ($autor->isAdmin() && ! empty($dados['aprovar']) ? ['decidido_por' => $autor->id, 'decidido_em' => now()] : []));

            $this->registros->atoNoProjeto(TipoRegistro::SuportePedido, $projeto, $autor, 'pedido: '.$suporte->fresh('aluno')->resumo());

            return $suporte->fresh(['aluno']);
        }

        $suporte->fill($campos);

        // Quem aprovou leu outra coisa: mudou, volta para a fila.
        if ($suporte->isDirty() && $suporte->status !== StatusSuporte::Pendente && ! $autor->isAdmin()) {
            $suporte->fill(['status' => StatusSuporte::Pendente, 'motivo' => null, 'decidido_por' => null, 'decidido_em' => null]);
        }

        if ($suporte->isDirty()) {
            $suporte->save();
            $this->registros->atoNoProjeto(TipoRegistro::SuportePedido, $projeto, $autor, 'pedido alterado: '.$suporte->fresh('aluno')->resumo());
        }

        return $suporte->fresh(['aluno']);
    }

    public function excluir(SuporteProjeto $suporte, User $autor, bool $teste = false): void
    {
        $projeto = $suporte->projeto;
        $this->garantirPodeMexer($projeto, $autor, $teste);

        $resumo = $suporte->resumo();
        $suporte->delete();

        $this->registros->atoNoProjeto(TipoRegistro::SuporteRemovido, $projeto, $autor, 'pedido excluído: '.$resumo);
    }

    /** A organização aprova ou recusa (recusar exige motivo, que o orientador lê). */
    public function decidir(SuporteProjeto $suporte, bool $aprovar, ?string $motivo, User $admin): SuporteProjeto
    {
        if (! $aprovar && trim((string) $motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Explique ao orientador por que o pedido foi recusado.']);
        }

        $suporte->update([
            'status' => $aprovar ? StatusSuporte::Aprovado : StatusSuporte::Recusado,
            'motivo' => $aprovar ? (trim((string) $motivo) ?: null) : trim((string) $motivo),
            'decidido_por' => $admin->id,
            'decidido_em' => now(),
        ]);

        $this->registros->atoNoProjeto(
            TipoRegistro::SuporteDecidido, $suporte->projeto, $admin,
            ($aprovar ? 'aprovado: ' : 'recusado: ').$suporte->fresh('aluno')->resumo(),
            $aprovar ? null : trim((string) $motivo),
        );

        return $suporte->fresh(['aluno']);
    }

    /**
     * Os pedidos da edição para a organização, com filtro de situação, tipo e
     * busca por projeto, orientador ou acompanhante.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function listarAdmin(array $filtros = []): array
    {
        $busca = mb_strtolower(trim((string) ($filtros['q'] ?? '')));
        $todos = SuporteProjeto::query()
            ->whereHas('projeto')
            ->with(['projeto:id,titulo,user_id', 'projeto.user:id,name,email', 'aluno:id,nome', 'decisor:id,name', 'solicitante:id,name'])
            ->orderByRaw("CASE status WHEN 'pendente' THEN 0 WHEN 'aprovado' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get();

        $filtrados = $todos
            ->when(! empty($filtros['status']), fn (Collection $c) => $c->filter(fn (SuporteProjeto $s) => $s->status->value === $filtros['status']))
            ->when(! empty($filtros['tipo']), fn (Collection $c) => $c->filter(fn (SuporteProjeto $s) => $s->tipo->value === $filtros['tipo']))
            ->when($busca !== '', fn (Collection $c) => $c->filter(fn (SuporteProjeto $s) => str_contains(mb_strtolower(implode(' ', [
                $s->projeto?->titulo, $s->projeto?->user?->name, $s->acompanhante_nome, $s->aluno?->nome,
            ])), $busca)));

        return [
            'pedidos' => $filtrados->map(fn (SuporteProjeto $s) => $this->linha($s) + [
                'projeto_id' => $s->projeto_id,
                'projeto' => $s->projeto?->titulo,
                'orientador' => $s->projeto?->user?->name,
                'orientador_email' => $s->projeto?->user?->email,
            ])->values()->all(),
            'totais' => [
                'pendente' => $todos->where('status', StatusSuporte::Pendente)->count(),
                'aprovado' => $todos->where('status', StatusSuporte::Aprovado)->count(),
                'recusado' => $todos->where('status', StatusSuporte::Recusado)->count(),
            ],
            'tipos' => TipoSuporte::opcoes(),
        ];
    }

    /**
     * Os acompanhantes aprovados dos projetos — viram participantes nas
     * etiquetas e na lista nominal.
     *
     * @param  list<int>  $projetoIds
     * @return Collection<int, SuporteProjeto>
     */
    public function acompanhantesAprovados(array $projetoIds): Collection
    {
        return SuporteProjeto::whereIn('projeto_id', $projetoIds)
            ->where('tipo', TipoSuporte::Acompanhante->value)
            ->where('status', StatusSuporte::Aprovado->value)
            ->with('aluno:id,nome')
            ->orderBy('id')
            ->get();
    }

    /** O código do crachá de um acompanhante. */
    public static function codigo(int $ano, SuporteProjeto $s): string
    {
        return CodigoParticipante::montar($ano, $s->projeto_id, $s->acompanhante_documento, CodigoParticipante::PAPEL_ACOMPANHANTE, $s->id);
    }

    /**
     * O orientador mexe só nos próprios projetos finalistas, dentro da janela;
     * o admin, em qualquer um.
     */
    private function garantirPodeMexer(Projeto $projeto, User $autor, bool $teste): void
    {
        if ($autor->isAdmin()) {
            return;
        }

        abort_unless($projeto->user_id === $autor->id, 403, 'Este projeto não é seu.');

        $janela = $this->janela($autor, $teste);

        if (! $janela['aberta']) {
            throw ValidationException::withMessages([
                'periodo' => $janela['tem_lista']
                    ? 'O evento já terminou: os pedidos de suporte estão encerrados.'
                    : 'A lista final ainda não foi publicada.',
            ]);
        }

        $lista = ListaFinal::vigente(Edicao::atual(), $teste && (bool) $autor->is_demo);

        if (! $lista?->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages(['projeto' => 'Só projetos finalistas pedem suporte para o evento.']);
        }
    }

    /** @return array<string, mixed> */
    private function linha(SuporteProjeto $s): array
    {
        return [
            'id' => $s->id,
            'tipo' => $s->tipo->value,
            'tipo_label' => $s->tipo->label(),
            'idioma' => $s->idioma,
            'aluno_id' => $s->aluno_id,
            'aluno' => $s->aluno?->nome,
            'acompanhante_nome' => $s->acompanhante_nome,
            'acompanhante_documento' => $s->acompanhante_documento,
            'acompanhante_vinculo' => $s->acompanhante_vinculo,
            'observacao' => $s->observacao,
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'motivo' => $s->motivo,
            'resumo' => $s->resumo(),
            'decidido_por' => $s->decisor?->name,
            'decidido_em' => $s->decidido_em?->format('d/m/Y H:i'),
            'criado_em' => $s->created_at?->format('d/m/Y H:i'),
        ];
    }
}
