<?php

namespace App\Models;

use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Enums\VagaPresencial;
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
     * o número é da edição ({@see JanelaTurnos::porProjeto()}), e desde a 222
     * ele é a soma das vagas comuns com as da comissão especial.
     */
    public const MAX_POR_PROJETO = JanelaTurnos::PADRAO_POR_PROJETO;

    protected $fillable = [
        'edicao_id', 'projeto_id', 'avaliador_id', 'dia', 'turno', 'status', 'respostas', 'nota',
        'comentario', 'itens_conferidos', 'designacao_manual', 'escolha_livre', 'vaga', 'iniciada_em', 'concluida_em',
        'aberta_pelo_comite',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusAvaliacao::class,
            'dia' => 'date:Y-m-d',
            'turno' => Turno::class,
            'respostas' => 'array',
            // Só o que foi marcado até a Sprint 236: o checklist saiu do wizard.
            'itens_conferidos' => 'array',
            'nota' => 'float',
            'designacao_manual' => 'boolean',
            'escolha_livre' => 'boolean',
            'aberta_pelo_comite' => 'boolean',
            'vaga' => VagaPresencial::class,
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
     * entregue — o fim do horário de avaliação dele. Nulo na designação
     * antiga, sem turno gravado.
     */
    public function prazo(?JanelaTurnos $janela = null): ?CarbonImmutable
    {
        if ($this->dia === null || $this->turno === null) {
            return null;
        }

        return ($janela ?? JanelaTurnos::daEdicao())->prazo($this->dia->toDateString(), $this->turno);
    }

    /**
     * Rascunho do comitê especial (Sprint 238): aberto por quem é da comissão,
     * não vence com o turno — fica com ele até o envio.
     */
    public function semPrazo(): bool
    {
        return $this->aberta_pelo_comite && $this->status === StatusAvaliacao::EmAndamento;
    }

    /**
     * Passou do prazo sem ser enviada: não ocupa mais vaga no projeto, e o
     * rascunho fica guardado só para leitura. O rascunho do comitê não vence.
     */
    public function expirada(?JanelaTurnos $janela = null): bool
    {
        if ($this->semPrazo()) {
            return false;
        }

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
