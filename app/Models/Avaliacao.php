<?php

namespace App\Models;

use App\Enums\StatusAvaliacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avaliação de um projeto submetido por um avaliador (E7). A nota sai de uma
 * rubrica de três quesitos (vídeo de apresentação, resumo e projeto de
 * pesquisa), cada um numa escala Likert de 5 pontos e com comentários
 * opcionais; `nota` guarda a soma dos três (3 a 15).
 *
 * Projetos com documento de continuação ganham um quarto quesito: ele não
 * soma à parte — a nota do quesito de pesquisa vira a MÉDIA entre o projeto de
 * pesquisa e o de continuação, então o teto continua 15 para todos os projetos
 * e o ranking segue comparável.
 */
class Avaliacao extends Model
{
    /** Extremos da escala Likert de cada quesito. */
    public const NOTA_MINIMA_QUESITO = 1;

    public const NOTA_MAXIMA_QUESITO = 5;

    /**
     * Escala Likert (valor => rótulo), do mais insatisfeito ao mais satisfeito.
     * Fonte única: o front monta os botões a partir do que vem na API.
     */
    public const ESCALA = [
        1 => 'Muito insatisfeito',
        2 => 'Insatisfeito',
        3 => 'Neutro',
        4 => 'Satisfeito',
        5 => 'Muito satisfeito',
    ];

    /** Quesitos que toda avaliação tem, na ordem em que aparecem. */
    public const QUESITOS = ['video', 'resumo', 'pesquisa'];

    /** Quesito extra: só existe quando o projeto tem documento de continuação. */
    public const QUESITO_CONTINUIDADE = 'continuidade';

    protected $table = 'avaliacoes';

    /** Campos da conferência de classificação (área obrigatória, subárea opcional). */
    public const CAMPOS_CLASSIFICACAO = [
        'area_correta', 'area_sugerida_id',
        'subarea_correta', 'subarea_sugerida_id',
    ];

    protected $fillable = [
        'projeto_id', 'avaliador_id', 'status', 'nota',
        'nota_video', 'comentario_video',
        'nota_resumo', 'comentario_resumo',
        'nota_pesquisa', 'comentario_pesquisa',
        'nota_continuidade', 'comentario_continuidade',
        'area_correta', 'area_sugerida_id',
        'subarea_correta', 'subarea_sugerida_id',
        'rascunho_em', 'concluida_em',
    ];

    /** Nota máxima da avaliação: a soma dos quesitos (3 × 5 = 15). */
    public static function notaMaxima(): int
    {
        return count(self::QUESITOS) * self::NOTA_MAXIMA_QUESITO;
    }

    /**
     * Soma dos quesitos preenchidos — o valor gravado em `nota` ao concluir.
     * Com projeto de continuação, o quesito de pesquisa entra pela média entre
     * os dois documentos, o que pode render meio ponto (ex.: 4 e 5 → 4,5).
     */
    public function somaDosQuesitos(): float
    {
        $soma = array_sum(array_map(
            fn (string $q) => (int) $this->{"nota_{$q}"},
            self::QUESITOS,
        ));

        if ($this->nota_continuidade === null) {
            return (float) $soma;
        }

        return $soma - (int) $this->nota_pesquisa + $this->notaDaPesquisa();
    }

    /**
     * Nota do quesito de pesquisa como ela vale na soma: sozinha, ou na média
     * com a do projeto de continuação quando o projeto tem esse documento.
     */
    public function notaDaPesquisa(): float
    {
        if ($this->nota_continuidade === null) {
            return (float) $this->nota_pesquisa;
        }

        return ((int) $this->nota_pesquisa + (int) $this->nota_continuidade) / 2;
    }

    protected function casts(): array
    {
        return [
            'status' => StatusAvaliacao::class,
            // A nota final aceita meio ponto (média com o projeto de continuação).
            'nota' => 'float',
            'nota_video' => 'integer',
            'nota_resumo' => 'integer',
            'nota_pesquisa' => 'integer',
            'nota_continuidade' => 'integer',
            'area_correta' => 'boolean',
            'subarea_correta' => 'boolean',
            'rascunho_em' => 'datetime',
            'concluida_em' => 'datetime',
        ];
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
