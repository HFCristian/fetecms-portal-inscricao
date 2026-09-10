<?php

namespace App\Models;

use App\Enums\StatusAvaliacao;
use App\Support\Rubrica;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avaliação de um projeto submetido por um avaliador (E7). As perguntas, a
 * escala e os pesos vêm da {@see Rubrica} (documento "Perguntas de Avaliação
 * FETECMS"): as respostas ficam em `respostas` (chave da pergunta => valor) e
 * `nota` guarda a soma ponderada, de 0 a 10, calculada no servidor.
 *
 * Fora da pontuação, o avaliador ainda confere a classificação do projeto
 * (área e subárea) e deixa recomendações sobre o vídeo e sobre o projeto.
 */
class Avaliacao extends Model
{
    protected $table = 'avaliacoes';

    /**
     * Teto de avaliadores por projeto: um projeto nunca fica visível para mais
     * gente do que isso — salvo se o próprio mínimo do edital for maior.
     */
    public const TETO_POR_PROJETO = 5;

    /** Campos da conferência de classificação (área obrigatória, subárea opcional). */
    public const CAMPOS_CLASSIFICACAO = [
        'area_correta', 'area_sugerida_id',
        'subarea_correta', 'subarea_sugerida_id',
    ];

    protected $fillable = [
        'projeto_id', 'avaliador_id', 'status', 'nota',
        'respostas',
        'comentario_video', 'comentario_projeto',
        'area_correta', 'area_sugerida_id',
        'subarea_correta', 'subarea_sugerida_id',
        'rascunho_em', 'concluida_em',
        'designacao_manual',
    ];

    /** Nota máxima da avaliação: a soma dos pesos da rubrica (10,00). */
    public static function notaMaxima(): float
    {
        return Rubrica::NOTA_MAXIMA;
    }

    /**
     * Nota ponderada das respostas gravadas — o valor de `nota` ao concluir e,
     * num rascunho, o quanto o preenchimento já rendeu.
     */
    public function notaCalculada(): float
    {
        return Rubrica::nota($this->respostas ?? []);
    }

    protected function casts(): array
    {
        return [
            'status' => StatusAvaliacao::class,
            // Soma ponderada com duas casas (pesos como 0,5375 e 1/3).
            'nota' => 'float',
            'respostas' => 'array',
            'area_correta' => 'boolean',
            'subarea_correta' => 'boolean',
            'rascunho_em' => 'datetime',
            'concluida_em' => 'datetime',
            'designacao_manual' => 'boolean',
        ];
    }

    /**
     * As designações que uma devolução **automática** pode desfazer — o rodízio
     * do avaliador, a redistribuição em massa e a devolução no fim da sessão.
     *
     * Duas coisas nunca entram aqui, e é o que esta consulta existe para
     * garantir num lugar só:
     *
     * - **Designação manual do admin**: é o escape do edital. Quem a fez sabe
     *   o que está pondo na fila daquela pessoa, e nenhuma rotina automática
     *   tem autoridade para desfazer isso. Só a retirada explícita do admin,
     *   na tela de Designações, tira uma dessas.
     * - **Avaliação já assumida** (em andamento ou concluída): o avaliador
     *   abriu o projeto e começou a trabalhar. Puxá-lo de volta jogaria fora o
     *   que já foi preenchido.
     *
     * Sobra o que é de fato reversível: o projeto que o algoritmo pôs na fila e
     * que ninguém abriu.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDevolvivel(Builder $query): void
    {
        $query->where('status', StatusAvaliacao::Designada->value)
            ->where('designacao_manual', false);
    }

    /**
     * O contrário do {@see self::scopeDevolvivel()}: o que uma devolução
     * automática precisa preservar. Serve para o relatório dizer ao admin
     * quantas designações a rodada respeitou.
     *
     * @param  Builder<self>  $query
     */
    public function scopeProtegida(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('status', '!=', StatusAvaliacao::Designada->value)
            ->orWhere('designacao_manual', true));
    }

    public function areaSugerida(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_sugerida_id');
    }

    public function subareaSugerida(): BelongsTo
    {
        return $this->belongsTo(Subarea::class, 'subarea_sugerida_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliador_id');
    }
}
