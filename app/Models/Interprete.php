<?php

namespace App\Models;

use App\Enums\TipoInterprete;
use App\Support\CodigoParticipante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um intérprete do evento (Sprint 214). Ver a migration das tabelas. */
class Interprete extends Model
{
    protected $fillable = [
        'edicao_id', 'nome', 'cpf', 'data_nascimento', 'tipos', 'outra_lingua', 'outro_pcd', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'tipos' => 'array',
        ];
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(InterpreteVinculo::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(InterpreteCheckin::class);
    }

    /**
     * O código do crachá, no padrão dos participantes, com o papel `I` e o
     * projeto `0` — o intérprete não pertence a um projeto.
     */
    public function codigo(): string
    {
        $ano = (int) ($this->edicao?->ano ?: now()->year);

        return CodigoParticipante::montar($ano, 0, $this->cpf, CodigoParticipante::PAPEL_INTERPRETE, $this->id);
    }

    /**
     * Os tipos em palavras; "Outros" vira o texto que a pessoa escreveu.
     *
     * @return list<string>
     */
    public function tiposLabels(): array
    {
        $rotulos = [];

        foreach ($this->tipos ?? [] as $valor) {
            $tipo = TipoInterprete::tryFrom((string) $valor);

            if ($tipo === null) {
                continue;
            }

            $texto = match ($tipo) {
                TipoInterprete::LinguaOutra => $this->outra_lingua,
                TipoInterprete::PcdOutro => $this->outro_pcd,
                default => null,
            };

            $rotulos[] = $texto ? $texto : $tipo->label();
        }

        return $rotulos;
    }
}
