<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conta de acesso temporário ao balcão de credenciamento.
 *
 * O usuário por trás dela é um `role = admin` comum; o que a distingue é esta
 * linha, que carrega CPF, curso e o prazo de validade. Enquanto ela existe, a
 * pessoa **só** abre a aba Credenciamento (ver `User::abasPermitidas()`).
 */
class ContaTemporaria extends Model
{
    protected $table = 'contas_temporarias';

    protected $fillable = ['user_id', 'cpf', 'curso', 'expira_em', 'criado_por'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /** O prazo já passou? */
    public function vencida(): bool
    {
        return $this->expira_em->isPast();
    }

    /** CPF com a máscara de exibição — o banco guarda só os dígitos. */
    public function cpfFormatado(): string
    {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $this->cpf) ?? $this->cpf;
    }
}
