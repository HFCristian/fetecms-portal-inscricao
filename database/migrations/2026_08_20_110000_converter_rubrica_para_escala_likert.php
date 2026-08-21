<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Quesitos da rubrica que mudam de escala (a continuidade já nasce Likert). */
    private const QUESITOS = ['nota_video', 'nota_resumo', 'nota_pesquisa'];

    /**
     * A rubrica deixa a nota de 0 a 10 por quesito e passa a uma escala Likert
     * de 5 pontos (1 = muito insatisfeito … 5 = muito satisfeito). A nota final
     * continua sendo a soma dos três quesitos, agora de 3 a 15.
     *
     * `nota` vira decimal porque o quesito de pesquisa pode valer meio ponto:
     * quando o projeto tem documento de continuação, a nota do quesito é a
     * média entre os dois documentos (ex.: 4 e 5 → 4,5).
     *
     * As avaliações já concluídas são reescaladas proporcionalmente para a
     * escala nova, de modo que o ranking não misture as duas.
     */
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->decimal('nota', 4, 1)->nullable()->change();
        });

        $this->converter(fn (int $valor) => 1 + (int) round($valor * 4 / 10), fn (float $nota) => round($nota / 2, 1));
    }

    public function down(): void
    {
        $this->converter(fn (int $valor) => (int) round(($valor - 1) * 10 / 4), fn (float $nota) => round($nota * 2));

        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('nota')->nullable()->change();
        });
    }

    /**
     * Reescala os quesitos preenchidos e recalcula a nota final somando-os.
     * A avaliação que só tem a nota final (rascunho antigo, anterior à rubrica)
     * não tem quesito para somar: aí a própria nota é reescalada pelo teto.
     *
     * @param  callable(int): int  $quesito  converte a nota de um quesito
     * @param  callable(float): float  $avulsa  converte uma nota final sem quesitos
     */
    private function converter(callable $quesito, callable $avulsa): void
    {
        DB::table('avaliacoes')->chunkById(200, function ($avaliacoes) use ($quesito, $avulsa) {
            foreach ($avaliacoes as $avaliacao) {
                $notas = [];

                foreach (self::QUESITOS as $coluna) {
                    if ($avaliacao->{$coluna} !== null) {
                        $notas[$coluna] = max(0, $quesito((int) $avaliacao->{$coluna}));
                    }
                }

                if ($notas === [] && $avaliacao->nota === null) {
                    continue;
                }

                // Só recalcula a nota final de quem já a tinha (avaliação concluída):
                // no rascunho ela continua nula até o envio.
                if ($avaliacao->nota !== null) {
                    $notas['nota'] = count($notas) === count(self::QUESITOS)
                        ? array_sum($notas)
                        : $avulsa((float) $avaliacao->nota);
                }

                DB::table('avaliacoes')->where('id', $avaliacao->id)->update($notas);
            }
        });
    }
};
