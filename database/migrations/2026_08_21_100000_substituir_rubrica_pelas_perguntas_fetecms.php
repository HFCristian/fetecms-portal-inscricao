<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Teto da rubrica antiga (3 quesitos × 5 pontos da escala Likert). */
    private const TETO_ANTIGO = 15.0;

    /** Teto da rubrica nova (soma dos pesos do documento). */
    private const TETO_NOVO = 10.0;

    /** Colunas da rubrica antiga, que deixam de existir. */
    private const COLUNAS_ANTIGAS = [
        'nota_resumo', 'comentario_resumo',
        'nota_pesquisa', 'comentario_pesquisa',
        'nota_continuidade', 'comentario_continuidade',
        'nota_video',
    ];

    /**
     * A rubrica de 3 quesitos em escala Likert (vídeo, resumo, projeto de
     * pesquisa — mais o projeto de continuação) dá lugar às perguntas do
     * documento "Perguntas de Avaliação FETECMS": 17 perguntas pontuadas em 10
     * seções, cada uma com seu peso. As respostas passam a morar em `respostas`
     * (JSON chave => valor), porque o conjunto de perguntas é do catálogo em
     * App\Support\Rubrica e não da estrutura da tabela.
     *
     * `comentario_video` sobrevive: vira o campo de recomendações sobre o
     * vídeo, que é exatamente o que ele já guardava. `comentario_projeto`
     * nasce para as recomendações finais.
     *
     * Não há como traduzir as respostas antigas para as perguntas novas — as
     * avaliações já concluídas ficam só com a nota final, reescalada de 0–15
     * para 0–10 para o ranking não misturar as duas rubricas.
     */
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->json('respostas')->nullable()->after('status');
            $table->text('comentario_projeto')->nullable()->after('comentario_video');
        });

        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->decimal('nota', 5, 2)->nullable()->change();
        });

        $this->reescalar(self::TETO_ANTIGO, self::TETO_NOVO);

        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn(self::COLUNAS_ANTIGAS);
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('nota_video')->nullable()->after('status');
            $table->text('comentario_resumo')->nullable()->after('comentario_video');
            $table->unsignedTinyInteger('nota_resumo')->nullable()->after('comentario_video');
            $table->unsignedTinyInteger('nota_pesquisa')->nullable()->after('comentario_resumo');
            $table->text('comentario_pesquisa')->nullable()->after('nota_pesquisa');
            $table->unsignedTinyInteger('nota_continuidade')->nullable()->after('comentario_pesquisa');
            $table->text('comentario_continuidade')->nullable()->after('nota_continuidade');
        });

        $this->reescalar(self::TETO_NOVO, self::TETO_ANTIGO);

        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->decimal('nota', 4, 1)->nullable()->change();
            $table->dropColumn(['respostas', 'comentario_projeto']);
        });
    }

    /** Converte a nota final de quem já concluiu, mantendo a proporção do teto. */
    private function reescalar(float $de, float $para): void
    {
        DB::table('avaliacoes')->whereNotNull('nota')->chunkById(200, function ($avaliacoes) use ($de, $para) {
            foreach ($avaliacoes as $avaliacao) {
                DB::table('avaliacoes')
                    ->where('id', $avaliacao->id)
                    ->update(['nota' => round((float) $avaliacao->nota * $para / $de, 2)]);
            }
        });
    }
};
