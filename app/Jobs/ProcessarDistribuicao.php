<?php

namespace App\Jobs;

use App\Enums\StatusDistribuicao;
use App\Models\Distribuicao;
use App\Services\DistribuicaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roda uma rodada de distribuição (ou redistribuição) fora da requisição,
 * atualizando o progresso para a tela poder desenhar a barra.
 *
 * Antes, "Distribuir" era uma requisição só que segurava a tela até o fim — uma
 * espera cega, e um bom candidato a estourar timeout com a base cheia.
 *
 * Requer `php artisan queue:work` rodando no deploy (QUEUE_CONNECTION=database),
 * a mesma exigência da mala direta.
 */
class ProcessarDistribuicao implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Uma tentativa só: a distribuição já é idempotente no resultado (ela
     * completa o que falta), mas repetir uma redistribuição interrompida
     * embaralharia designações que o admin não pediu para mexer.
     */
    public int $tries = 1;

    /** Rodada grande é normal aqui; o limite existe só para não travar a fila. */
    public int $timeout = 900;

    public function __construct(private readonly int $distribuicaoId) {}

    public function handle(DistribuicaoService $service): void
    {
        $rodada = Distribuicao::find($this->distribuicaoId);

        if ($rodada === null || $rodada->status->finalizada()) {
            return;
        }

        $rodada->update([
            'status' => StatusDistribuicao::Processando,
            'etapa' => 'Preparando a rodada',
        ]);

        // Grava no máximo uma vez por passo, e só quando o número muda: com
        // muitos projetos, escrever a cada iteração custaria mais que o próprio
        // algoritmo.
        $ultimo = -1;
        $relatar = function (int $feitos, int $total, ?string $etapa = null) use ($rodada, &$ultimo) {
            if ($feitos === $ultimo && $total === $rodada->total) {
                return;
            }

            $ultimo = $feitos;
            $rodada->update(array_filter([
                'processados' => $feitos,
                'total' => $total,
                'etapa' => $etapa,
            ], fn ($v) => $v !== null));
        };

        try {
            $relatorio = $rodada->tipo === Distribuicao::TIPO_REDISTRIBUIR
                ? $service->redistribuir($relatar)
                : $service->distribuir(fn (int $f, int $t) => $relatar($f, $t, 'Designando os projetos'));

            $rodada->update([
                'status' => StatusDistribuicao::Concluida,
                'processados' => $rodada->total,
                'etapa' => null,
                'relatorio' => $relatorio,
                'concluida_em' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Falha ao distribuir avaliações', [
                'distribuicao_id' => $rodada->id,
                'erro' => $e->getMessage(),
            ]);

            $rodada->update([
                'status' => StatusDistribuicao::Falha,
                'etapa' => null,
                'erro' => $e->getMessage(),
                'concluida_em' => now(),
            ]);
        }
    }
}
