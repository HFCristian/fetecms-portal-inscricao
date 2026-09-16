<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conta de acesso temporário ao balcão de credenciamento.
 *
 * O usuário por trás dela é um `role = admin` comum; o que a distingue é esta
 * linha, que carrega CPF, curso, o **setor** e a **janela de validade**.
 * Enquanto ela existe, a pessoa **só** abre a aba do setor dela —
 * Credenciamento ou Almoxarifado (ver `User::abasPermitidas()`).
 *
 * A janela tem duas pontas: `valido_de` (nulo = vale desde já) e `expira_em`.
 * Entre elas a conta está **em vigor**; antes, **agendada**; depois, **vencida**.
 */
class ContaTemporaria extends Model
{
    protected $table = 'contas_temporarias';

    protected $fillable = ['user_id', 'cpf', 'curso', 'setor', 'valido_de', 'expira_em', 'criado_por'];

    /** As abas que uma conta temporária pode atender. */
    public const SETOR_CREDENCIAMENTO = 'credenciamento';

    public const SETOR_ALMOXARIFADO = 'almoxarifado';

    /** Voluntário da avaliação presencial (Sprint 133). */
    public const SETOR_PRESENCIAL = 'avaliacao_presencial';

    /** @return list<string> */
    public static function setores(): array
    {
        return [self::SETOR_CREDENCIAMENTO, self::SETOR_ALMOXARIFADO, self::SETOR_PRESENCIAL];
    }

    protected function casts(): array
    {
        return ['valido_de' => 'datetime', 'expira_em' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /**
     * Os turnos de trabalho desta conta (Sprint 133). Vazio = a janela inteira
     * vale, que é o comportamento das contas de balcão.
     */
    public function turnos(): HasMany
    {
        return $this->hasMany(ContaTemporariaTurno::class)->orderBy('inicio');
    }

    /**
     * A conta está dentro de um turno agora?
     *
     * Sem turnos cadastrados, a resposta é "sim" — o que manda é a janela. Com
     * turnos, só vale enquanto um deles está acontecendo.
     */
    public function dentroDeTurno(): bool
    {
        $turnos = $this->relationLoaded('turnos') ? $this->turnos : $this->turnos()->get();

        return $turnos->isEmpty() || $turnos->contains(fn (ContaTemporariaTurno $t) => $t->agora());
    }

    /** O próximo turno que ainda vai começar (para explicar a recusa no login). */
    public function proximoTurno(): ?ContaTemporariaTurno
    {
        $turnos = $this->relationLoaded('turnos') ? $this->turnos : $this->turnos()->get();

        return $turnos->first(fn (ContaTemporariaTurno $t) => $t->inicio->isFuture());
    }

    /** O prazo já passou? */
    public function vencida(): bool
    {
        return $this->expira_em->isPast();
    }

    /** A janela ainda não começou — a conta existe, mas não abre. */
    public function agendada(): bool
    {
        return $this->valido_de !== null && $this->valido_de->isFuture();
    }

    /** Dentro da janela: nem agendada, nem vencida. */
    public function emVigor(): bool
    {
        return ! $this->agendada() && ! $this->vencida();
    }

    /** CPF com a máscara de exibição — o banco guarda só os dígitos. */
    public function cpfFormatado(): string
    {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $this->cpf) ?? $this->cpf;
    }
}
