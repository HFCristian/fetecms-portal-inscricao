<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Models\VerificacaoDisparidade;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Verificação de **disparidade** entre as notas de um projeto
 * (Avaliação online → Ranking dos projetos → Verificar disparidade).
 *
 * O ranking ordena pela média, e a média esconde o desacordo: 9,50 com 4,50 dá
 * o mesmo 7,00 que 7,00 com 7,00 — só que no primeiro caso os dois avaliadores
 * leram projetos diferentes, e o número que decide a lista final não representa
 * nenhum dos dois. A tela responde "onde isso aconteceu?": o admin informa a
 * **diferença** que considera demais e recebe os projetos em que a distância
 * entre a maior e a menor nota chegou lá.
 *
 * O critério é a **amplitude** (maior − menor) e não o desvio em relação à
 * média: ela é o que se explica numa contestação sem precisar de estatística, e
 * já pega o caso de duas avaliações, que é a maioria enquanto a cobertura não
 * fecha.
 *
 * Toda lista gerada fica **registrada** com os projetos que tinha: a ação que
 * vem depois é designar mais um avaliador, e é preciso poder dizer depois por
 * que aquele projeto recebeu um quarto parecer.
 */
class VerificacaoDisparidadeService
{
    /** Menos de duas notas não tem distância nenhuma para medir. */
    private const MIN_AVALIACOES = 2;

    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /**
     * Os projetos cuja amplitude de notas alcança `$diferenca`, do mais díspar
     * para o menos. Só consulta — não grava nada.
     *
     * @return list<array<string, mixed>>
     */
    public function calcular(float $diferenca): array
    {
        // O recorte é o mesmo do ranking: a verificação existe para corrigir a
        // classificação, e projeto de orientador demo não entra nela.
        $projetos = Projeto::semDemo()
            ->whereHas('avaliacoes', fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value))
            ->with([
                'area:id,nome',
                'avaliacoes' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->get();

        $linhas = [];

        foreach ($projetos as $projeto) {
            $notas = $projeto->avaliacoes
                ->pluck('nota')
                ->filter(fn ($n) => $n !== null)
                ->map(fn ($n) => (float) $n)
                ->values();

            if ($notas->count() < self::MIN_AVALIACOES) {
                continue;
            }

            $amplitude = round($notas->max() - $notas->min(), 2);

            if ($amplitude < $diferenca) {
                continue;
            }

            $linhas[] = [
                'projeto_id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'area' => $projeto->area?->nome,
                'categoria' => $projeto->categoria?->label(),
                'avaliacoes' => $notas->count(),
                'nota_min' => round($notas->min(), 2),
                'nota_max' => round($notas->max(), 2),
                'amplitude' => $amplitude,
                'media' => round($notas->avg(), 2),
            ];
        }

        // O mais díspar primeiro — é onde o admin precisa olhar antes.
        usort($linhas, fn (array $a, array $b) => [$b['amplitude'], $a['titulo']] <=> [$a['amplitude'], $b['titulo']]);

        return $linhas;
    }

    /**
     * Calcula e **registra** a verificação, devolvendo-a já detalhada.
     */
    public function gerar(float $diferenca, User $admin): VerificacaoDisparidade
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'diferenca' => 'Nenhuma edição em curso para registrar a verificação.',
            ]);
        }

        $linhas = $this->calcular($diferenca);

        return DB::transaction(function () use ($diferenca, $admin, $edicao, $linhas) {
            $verificacao = VerificacaoDisparidade::create([
                'edicao_id' => $edicao->id,
                'diferenca' => $diferenca,
                'total' => count($linhas),
                'gerada_por' => $admin->id,
            ]);

            foreach ($linhas as $linha) {
                $verificacao->itens()->create($linha);
            }

            $this->registros->disparidadeVerificada($admin, $diferenca, count($linhas));

            return $verificacao->fresh();
        });
    }

    /**
     * As verificações já feitas na edição em curso, da mais nova para a mais
     * antiga.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        return VerificacaoDisparidade::where('edicao_id', $edicao->id)
            ->with('autor:id,name')
            ->latest('id')
            ->get()
            ->map(fn (VerificacaoDisparidade $v) => [
                'id' => $v->id,
                'diferenca' => round((float) $v->diferenca, 2),
                'total' => $v->total,
                'autor' => $v->autor?->name,
                'criada_em' => $v->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Uma verificação com a lista que ela devolveu, como estava no momento da
     * geração.
     *
     * @return array<string, mixed>
     */
    public function detalhar(VerificacaoDisparidade $verificacao): array
    {
        $verificacao->loadMissing('autor:id,name', 'itens');

        return [
            'id' => $verificacao->id,
            'diferenca' => round((float) $verificacao->diferenca, 2),
            'total' => $verificacao->total,
            'autor' => $verificacao->autor?->name,
            'criada_em' => $verificacao->created_at?->toIso8601String(),
            'nota_maxima' => Avaliacao::notaMaxima(),
            'itens' => $verificacao->itens
                ->sortByDesc('amplitude')
                ->values()
                ->map(fn ($i) => [
                    'projeto_id' => $i->projeto_id,
                    'titulo' => $i->titulo,
                    'area' => $i->area,
                    'categoria' => $i->categoria,
                    'avaliacoes' => $i->avaliacoes,
                    'nota_min' => $i->nota_min,
                    'nota_max' => $i->nota_max,
                    'amplitude' => $i->amplitude,
                    'media' => $i->media,
                ])
                ->all(),
        ];
    }
}
