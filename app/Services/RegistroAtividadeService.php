<?php

namespace App\Services;

use App\Enums\TipoRegistro;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grava e consulta a trilha de auditoria (submissões, cancelamentos, exclusões
 * e trocas de e-mail). Escrever é sempre "fire and forget" a partir dos serviços
 * de negócio; ler é exclusividade do painel do admin.
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

        return RegistroAtividade::query()
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
        foreach (TipoRegistro::cases() as $tipo) {
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

        if ($registro->tipo === TipoRegistro::TrocaEmail && isset($detalhes['de'], $detalhes['para'])) {
            $partes[] = $detalhes['de'].' → '.$detalhes['para'];
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
