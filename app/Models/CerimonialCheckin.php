<?php

namespace App\Models;

use App\Support\CodigoParticipante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um check-in da cerimônia: **uma pessoa** que entrou na sala.
 *
 * A identidade é o trio do crachá — projeto + papel (A/O/C) + id do
 * participante —, o mesmo que o QR Code e o código de barras carregam. O
 * `nome` vem desnormalizado porque as listas nominais e a trilha de auditoria
 * precisam continuar legíveis depois de a equipe mudar.
 */
class CerimonialCheckin extends Model
{
    protected $table = 'cerimonial_checkins';

    protected $fillable = [
        'edicao_id', 'projeto_id', 'papel', 'participante_id',
        'nome', 'checkin_em', 'registrado_por', 'demo',
    ];

    protected function casts(): array
    {
        return [
            'checkin_em' => 'datetime',
            'demo' => 'boolean',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /** "Aluno(a)", "Orientador(a)", "Coorientador(a)". */
    public function papelLabel(): string
    {
        return CodigoParticipante::papelLabel($this->papel);
    }

    /** A chave que identifica a pessoa dentro do projeto dela. */
    public function chave(): string
    {
        return $this->papel.$this->participante_id;
    }
}
