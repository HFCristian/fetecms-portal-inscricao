<?php

namespace App\Services;

use App\Enums\SituacaoDocumento;
use App\Enums\TipoRegistro;
use App\Models\Credenciamento;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grava e consulta a trilha de auditoria (submissões, cancelamentos, exclusões,
 * trocas de e-mail, correções do admin e o que ele mexe num rascunho alheio).
 * Escrever é sempre "fire and forget" a partir dos serviços de negócio; ler é
 * exclusividade do painel do admin.
 */
class RegistroAtividadeService
{
    /** Colunas do CSV exportado, na ordem em que aparecem. */
    private const CABECALHO_CSV = [
        'Data', 'Hora', 'Tipo', 'E-mail do autor', 'Nome do autor', 'Papel',
        'Projeto', 'Categoria', 'Dono da inscrição', 'Detalhes',
    ];

    public function submissao(Projeto $projeto, User $autor): RegistroAtividade
    {
        return $this->registrarProjeto(TipoRegistro::Submissao, $projeto, $autor);
    }

    public function cancelamento(Projeto $projeto, User $autor): RegistroAtividade
    {
        return $this->registrarProjeto(TipoRegistro::Cancelamento, $projeto, $autor);
    }

    public function exclusao(Projeto $projeto, User $autor): RegistroAtividade
    {
        return $this->registrarProjeto(TipoRegistro::Exclusao, $projeto, $autor);
    }

    /**
     * Troca de e-mail. `$anterior` precisa ser lido ANTES de salvar o usuário;
     * `$autor` é quem executou (o próprio dono ou um admin).
     */
    public function trocaEmail(User $dono, string $anterior, string $novo, User $autor): RegistroAtividade
    {
        return RegistroAtividade::create([
            'tipo' => TipoRegistro::TrocaEmail,
            'user_id' => $autor->id,
            // O autor é identificado pelo e-mail que ele tinha ao agir: se trocou
            // o próprio, o registro guarda o antigo — é assim que ele era conhecido.
            'autor_email' => $autor->is($dono) ? $anterior : $autor->email,
            'autor_nome' => $autor->name,
            'autor_role' => $autor->role?->value,
            'dono_email' => $anterior,
            'dono_nome' => $dono->name,
            'detalhes' => ['de' => $anterior, 'para' => $novo],
        ]);
    }

    /**
     * Mudança de um parâmetro da avaliação online (datas e mínimos). Guarda o
     * valor anterior e o novo já formatados para leitura.
     */
    public function parametroAvaliacao(TipoRegistro $tipo, User $admin, ?string $de, ?string $para): RegistroAtividade
    {
        return RegistroAtividade::create([
            'tipo' => $tipo,
            'user_id' => $admin->id,
            'autor_email' => $admin->email,
            'autor_nome' => $admin->name,
            'autor_role' => $admin->role?->value,
            'detalhes' => ['de' => $de, 'para' => $para],
        ]);
    }

    /**
     * Correção de um projeto pelo admin (categoria, área, subárea ou vídeo).
     * A justificativa é obrigatória na tela e vem junto para o registro poder
     * responder "por que isso mudou".
     */
    public function correcaoProjeto(
        TipoRegistro $tipo,
        Projeto $projeto,
        User $admin,
        ?string $de,
        ?string $para,
        string $justificativa,
        ?string $campo = null,
    ): RegistroAtividade {
        // `campo` abre a frase quando um mesmo tipo cobre vários campos — é o
        // caso do coorientador (nome, e-mail, CPF, telefone). Só ele sai quando
        // é nulo: `de` e `para` precisam CONTINUAR na lista mesmo nulos, que é
        // como a trilha mostra "(sem valor)".
        return $this->registrarNoProjeto($tipo, $projeto, $admin, ($campo === null ? [] : ['campo' => $campo]) + [
            'de' => $de,
            'para' => $para,
            'justificativa' => $justificativa,
        ]);
    }

    /**
     * O admin retirando a designação de um projeto do avaliador (Avaliação
     * online → Designações). Guarda de quem saiu e para quem foi — ou que ela
     * ficou sem dono, quando não havia ninguém elegível.
     */
    public function designacaoRetirada(
        Projeto $projeto,
        User $admin,
        string $de,
        ?string $para,
        string $situacao,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::AvaliacaoDesignacaoRetirada, $projeto, $admin, [
            'campo' => $situacao,
            'de' => $de,
            'para' => $para ?? '(sem avaliador)',
        ]);
    }

    /**
     * O admin mexendo no rascunho de outra pessoa (Projetos em rascunho): uma
     * linha por campo alterado, com o "de → para". Quem fecha a sequência é a
     * submissaoRascunho(), que carrega a justificativa obrigatória.
     */
    public function alteracaoRascunho(
        Projeto $projeto,
        User $admin,
        string $campo,
        ?string $de,
        ?string $para,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::RascunhoAlteracao, $projeto, $admin, [
            'campo' => $campo,
            'de' => $de,
            'para' => $para,
        ]);
    }

    /**
     * Lista final oficial: a publicação e cada projeto acrescentado ou retirado
     * depois. `$projeto` é nulo na oficialização (o registro é da lista toda).
     */
    public function listaFinal(
        TipoRegistro $tipo,
        User $admin,
        string $lista,
        ?Projeto $projeto,
        ?string $justificativa,
        ?string $detalhe = null,
    ): RegistroAtividade {
        $detalhes = array_filter([
            'campo' => $lista,
            'de' => $tipo === TipoRegistro::ListaFinalProjetoRemovido ? ($projeto?->titulo ?? $detalhe) : null,
            'para' => $tipo === TipoRegistro::ListaFinalProjetoRemovido ? null : ($projeto?->titulo ?? $detalhe),
            'justificativa' => $justificativa,
        ], fn ($v) => $v !== null);

        // Na remoção o "para" some de propósito (o projeto saiu), mas a chave
        // precisa existir para o texto sair como "Título → (sem valor)".
        $detalhes['para'] ??= null;

        if ($projeto === null) {
            return RegistroAtividade::create([
                'tipo' => $tipo,
                'user_id' => $admin->id,
                'autor_email' => $admin->email,
                'autor_nome' => $admin->name,
                'autor_role' => $admin->role?->value,
                'detalhes' => $detalhes,
            ]);
        }

        return $this->registrarNoProjeto($tipo, $projeto, $admin, $detalhes);
    }

    /**
     * Credenciamento de um projeto no evento: quem atendeu, quando e o que
     * ficou pendente. O horário fica no próprio registro (`created_at`) e no
     * `credenciamentos.finalizado_em`.
     */
    public function credenciamento(Credenciamento $credenciamento, Projeto $projeto, User $admin): RegistroAtividade
    {
        // Duas pendências diferentes: quem não veio ao balcão e, entre os que
        // vieram, o documento que faltou.
        $faltaram = $credenciamento->pessoas
            ->where('presente', false)
            ->map(fn ($p) => $p->pessoa_nome.': ausente no credenciamento')
            ->values()
            ->all();

        $documentos = $credenciamento->documentos
            ->filter(fn ($d) => $d->situacao === SituacaoDocumento::Ausente)
            ->map(fn ($d) => $d->pessoa_nome.': '.($d->documento?->nome ?? 'documento'))
            ->values()
            ->all();

        $ausentes = array_merge($faltaram, $documentos);

        return $this->registrarNoProjeto(TipoRegistro::CredenciamentoRealizado, $projeto, $admin, array_filter([
            'campo' => 'Credenciamento',
            'para' => $credenciamento->finalizado_em?->format('d/m/Y H:i'),
            'pendencias' => $ausentes === [] ? null : $ausentes,
            'justificativa' => $credenciamento->observacao,
        ], fn ($v) => $v !== null));
    }

    /**
     * O avaliador corrigindo o parecer final de uma avaliação já enviada. Um
     * registro por campo alterado, com o "de → para" e a justificativa; o autor
     * é o próprio avaliador (é ele quem edita).
     */
    public function parecerEditado(
        Projeto $projeto,
        User $avaliador,
        string $campo,
        ?string $de,
        ?string $para,
        string $justificativa,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::AvaliacaoParecerEditado, $projeto, $avaliador, [
            'campo' => $campo,
            'de' => $de,
            'para' => $para,
            'justificativa' => $justificativa,
        ]);
    }

    /**
     * Retirada de kit: quem levou, de quem, e quando.
     *
     * Vale tanto para a retirada feita no próprio credenciamento quanto para a
     * do colega que apareceu depois — é o mesmo fato, e ele precisa ficar
     * registrado das duas vezes, porque o kit sai da mão da organização.
     *
     * @param  list<string>  $pessoas  nomes de quem teve o kit retirado
     */
    public function kitRetirado(
        Projeto $projeto,
        User $admin,
        string $responsavel,
        array $pessoas,
        ?Carbon $quando = null,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::CredenciamentoKitRetirado, $projeto, $admin, [
            'responsavel' => $responsavel,
            'kits' => $pessoas,
            'quando' => ($quando ?? now())->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Credenciamento cancelado: o projeto volta para a fila do balcão. Guarda
     * quem tinha credenciado, quando, e a justificativa de quem desfez.
     */
    public function credenciamentoCancelado(
        Credenciamento $credenciamento,
        Projeto $projeto,
        User $admin,
        string $justificativa,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::CredenciamentoCancelado, $projeto, $admin, [
            'campo' => 'Credenciamento',
            'de' => trim(($credenciamento->autor?->name ?? 'desconhecido').' · '
                .($credenciamento->finalizado_em?->format('d/m/Y H:i') ?? '')),
            'para' => '(cancelado)',
            'justificativa' => $justificativa,
        ]);
    }

    /** O admin submetendo o rascunho de outra pessoa, com a justificativa do escape. */
    public function submissaoRascunho(Projeto $projeto, User $admin, string $justificativa): RegistroAtividade
    {
        return $this->registrarNoProjeto(TipoRegistro::RascunhoSubmissao, $projeto, $admin, [
            'justificativa' => $justificativa,
        ]);
    }

    /**
     * Linha da trilha presa a um projeto, executada por um admin. O projeto e o
     * dono são desnormalizados para o registro sobreviver ao delete.
     *
     * @param  array<string, mixed>  $detalhes
     */
    private function registrarNoProjeto(
        TipoRegistro $tipo,
        Projeto $projeto,
        User $admin,
        array $detalhes,
    ): RegistroAtividade {
        $dono = $projeto->relationLoaded('user') ? $projeto->user : $projeto->user()->first();

        return RegistroAtividade::create([
            'tipo' => $tipo,
            'user_id' => $admin->id,
            'autor_email' => $admin->email,
            'autor_nome' => $admin->name,
            'autor_role' => $admin->role?->value,
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'projeto_categoria' => $projeto->categoria?->value,
            'dono_email' => $dono?->email,
            'dono_nome' => $dono?->name,
            'detalhes' => $detalhes,
        ]);
    }

    private function registrarProjeto(TipoRegistro $tipo, Projeto $projeto, User $autor): RegistroAtividade
    {
        $dono = $projeto->relationLoaded('user') ? $projeto->user : $projeto->user()->first();

        return RegistroAtividade::create([
            'tipo' => $tipo,
            'user_id' => $autor->id,
            'autor_email' => $autor->email,
            'autor_nome' => $autor->name,
            'autor_role' => $autor->role?->value,
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'projeto_categoria' => $projeto->categoria?->value,
            'dono_email' => $dono?->email,
            'dono_nome' => $dono?->name,
            'detalhes' => $autor->isAdmin() && ! $autor->is($dono)
                ? ['por_admin' => true]
                : null,
        ]);
    }

    /**
     * Consulta filtrada do painel. Filtros aceitos: `tipos` (lista), `de`/`ate`
     * (datas, inclusivas) e `busca` (e-mail, nome ou título do projeto).
     *
     * @param  array{tipos?: array<int, string>|null, de?: string|null, ate?: string|null, busca?: string|null}  $filtros
     * @return Builder<RegistroAtividade>
     */
    public function query(array $filtros): Builder
    {
        $busca = trim((string) ($filtros['busca'] ?? ''));

        $daSecao = ! empty($filtros['secao'])
            ? array_map(fn (TipoRegistro $t) => $t->value, TipoRegistro::daSecao($filtros['secao']))
            : null;

        return RegistroAtividade::query()
            ->when($daSecao !== null, fn ($q) => $q->whereIn('tipo', $daSecao))
            ->when(! empty($filtros['tipos']), fn ($q) => $q->whereIn('tipo', $filtros['tipos']))
            ->when(! empty($filtros['de']), fn ($q) => $q->whereDate('created_at', '>=', $filtros['de']))
            ->when(! empty($filtros['ate']), fn ($q) => $q->whereDate('created_at', '<=', $filtros['ate']))
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(function ($sub) use ($termo) {
                    foreach (['autor_email', 'autor_nome', 'dono_email', 'dono_nome', 'projeto_titulo'] as $coluna) {
                        $sub->orWhereRaw('LOWER('.$coluna.') LIKE ?', [$termo]);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** @param array<string, mixed> $filtros */
    public function listar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        return $this->query($filtros)->paginate($porPagina)->withQueryString();
    }

    /**
     * Totais por tipo respeitando os filtros de período/busca (mas não o de
     * tipo, senão os cartões só mostrariam a aba selecionada).
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, int>
     */
    public function totaisPorTipo(array $filtros): array
    {
        $contagem = $this->query(array_merge($filtros, ['tipos' => null]))
            ->reorder()
            ->toBase()
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $totais = [];
        foreach (TipoRegistro::daSecao($filtros['secao'] ?? null) as $tipo) {
            $totais[$tipo->value] = (int) ($contagem[$tipo->value] ?? 0);
        }

        return $totais;
    }

    /**
     * Gera o CSV (UTF-8 com BOM, separador ";" — o que o Excel em pt_BR espera)
     * respeitando os mesmos filtros da tela. Percorre em chunks para não carregar
     * a trilha inteira na memória.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function exportarCsv(array $filtros): string
    {
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}"); // BOM: faz o Excel reconhecer os acentos
        fputcsv($saida, self::CABECALHO_CSV, ';');

        $this->query($filtros)->chunk(500, function ($registros) use ($saida) {
            foreach ($registros as $registro) {
                fputcsv($saida, $this->linhaCsv($registro), ';');
            }
        });

        rewind($saida);
        $csv = stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }

    /** @return array<int, string> */
    private function linhaCsv(RegistroAtividade $registro): array
    {
        return [
            $registro->created_at?->format('d/m/Y') ?? '',
            $registro->created_at?->format('H:i') ?? '',
            $registro->tipo->label(),
            $registro->autor_email,
            $registro->autor_nome ?? '',
            $registro->autor_role ?? '',
            $registro->projeto_titulo ?? '',
            $registro->projeto_categoria ?? '',
            $registro->dono_email ?? '',
            $this->descreverDetalhes($registro),
        ];
    }

    /** Texto legível do contexto do evento (o que vai para a última coluna do CSV). */
    public function descreverDetalhes(RegistroAtividade $registro): string
    {
        $detalhes = $registro->detalhes ?? [];
        $partes = [];

        // A retirada de kit não é um "de → para": é quem levou, de quem e quando.
        if ($registro->tipo === TipoRegistro::CredenciamentoKitRetirado) {
            return ($detalhes['responsavel'] ?? 'alguém')
                .' retirou o kit de: '.implode('; ', (array) ($detalhes['kits'] ?? []))
                .(empty($detalhes['quando']) ? '' : ' · em '.$detalhes['quando']);
        }

        if ($registro->tipo === TipoRegistro::TrocaEmail && isset($detalhes['de'], $detalhes['para'])) {
            $partes[] = $detalhes['de'].' → '.$detalhes['para'];
        }
        $comDeEPara = in_array($registro->tipo->secao(), [
            TipoRegistro::SECAO_AVALIACAO, TipoRegistro::SECAO_PROJETOS,
            TipoRegistro::SECAO_RASCUNHOS, TipoRegistro::SECAO_LISTA_FINAL,
            TipoRegistro::SECAO_CREDENCIAMENTO,
        ], true);
        if ($comDeEPara && array_key_exists('para', $detalhes)) {
            $valor = fn ($v) => ($v === null || $v === '') ? '(sem valor)' : (string) $v;
            // No rascunho, um registro por campo: o nome dele abre a frase.
            $prefixo = ! empty($detalhes['campo']) ? $detalhes['campo'].': ' : '';
            $partes[] = $prefixo.$valor($detalhes['de'] ?? null).' → '.$valor($detalhes['para']);
        }
        if (! empty($detalhes['pendencias'])) {
            $partes[] = 'ausentes: '.implode('; ', (array) $detalhes['pendencias']);
        }
        if (! empty($detalhes['justificativa'])) {
            $partes[] = 'justificativa: '.$detalhes['justificativa'];
        }
        if (! empty($detalhes['por_admin'])) {
            $partes[] = 'executado pelo admin';
        }
        if (($detalhes['origem'] ?? null) === 'historico') {
            $partes[] = 'registro histórico (anterior à trilha)';
        }

        return implode(' · ', $partes);
    }
}
