<?php

namespace App\Models;

use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Support\JanelaTurnos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma avaliação presencial: o que um avaliador deu a um projeto no estande.
 *
 * A nota é **separada** da avaliação online — ela alimenta a premiação, e não
 * o ranking que definiu a lista final.
 */
class AvaliacaoPresencial extends Model
{
    protected $table = 'avaliacoes_presenciais';

    /**
     * Quantas avaliações presenciais um projeto recebe **sem configuração**. O
     * limite existe porque o tempo do evento é curto: acima disso, o avaliador
     * é mandado para um estande que ainda não foi visitado. Desde a Sprint 170
     * o número é da edição ({@see JanelaTurnos::porProjeto()}).
     */
    public const MAX_POR_PROJETO = JanelaTurnos::PADRAO_POR_PROJETO;

    protected $fillable = [
        'edicao_id', 'projeto_id', 'avaliador_id', 'dia', 'turno', 'status', 'respostas', 'nota',
        'comentario', 'itens_conferidos', 'designacao_manual', 'iniciada_em', 'concluida_em',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusAvaliacao::class,
            'dia' => 'date:Y-m-d',
            'turno' => Turno::class,
            'respostas' => 'array',
            'itens_conferidos' => 'array',
            'nota' => 'float',
            'designacao_manual' => 'boolean',
            'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliador_id');
    }

    public function concluida(): bool
    {
        return $this->status === StatusAvaliacao::Concluida;
    }

    /**
     * O último instante em que ela aceita escrita: o fim do turno em que foi
     * entregue, mais a margem. Nulo na designação antiga, sem turno gravado.
     */
    public function prazo(?JanelaTurnos $janela = null): ?CarbonImmutable
    {
        if ($this->dia === null || $this->turno === null) {
            return null;
        }

        return ($janela ?? JanelaTurnos::daEdicao())->prazo($this->dia->toDateString(), $this->turno);
    }

    /**
     * Passou do prazo sem ser enviada: não ocupa mais vaga no projeto, e o
     * rascunho fica guardado só para leitura.
     */
    public function expirada(?JanelaTurnos $janela = null): bool
    {
        $prazo = $this->prazo($janela);

        return ! $this->concluida() && $prazo !== null && now()->greaterThan($prazo);
    }

    /**
     * Ocupa vaga no projeto: enviada, ou ainda dentro do prazo. É a conta que o
     * teto de avaliações por projeto faz.
     */
    public function ocupaVaga(?JanelaTurnos $janela = null): bool
    {
        return $this->concluida() || ! $this->expirada($janela);
    }

    /** "2026-10-20|A" — a ocorrência da agenda em que ela foi entregue. */
    public function ocorrencia(): ?string
    {
        return $this->dia === null || $this->turno === null
            ? null
            : $this->dia->toDateString().'|'.$this->turno->value;
    }
}
