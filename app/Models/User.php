<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AbaAdmin;
use App\Enums\Role;
use App\Notifications\RedefinirSenhaNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'chat_dica_dispensada', 'is_demo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'chat_dica_dispensada' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    /** A edição que este usuário escolheu ver (nulo = segue a padrão do portal). */
    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    /** Escopos de admin deste usuário, um por edição. */
    public function escopos(): BelongsToMany
    {
        return $this->belongsToMany(EscopoAdmin::class, 'admin_escopos', 'user_id', 'escopo_admin_id')
            ->withPivot('edicao_id')
            ->withTimestamps();
    }

    /**
     * O escopo deste admin na edição em curso. `null` significa **acesso
     * total**: sem atribuição, o admin continua vendo tudo (comportamento
     * anterior aos escopos, e a rede que impede uma edição nova de trancar a
     * equipe para fora).
     */
    public function escopoAdmin(): ?EscopoAdmin
    {
        if (! $this->isAdmin()) {
            return null;
        }

        $edicao = Edicao::escopoDe($this);

        if ($edicao === null) {
            return null;
        }

        return $this->escopos()->wherePivot('edicao_id', $edicao->id)->first();
    }

    /** Este admin abre esta aba do menu? Quem não é admin nunca abre. */
    public function podeAbrirAba(AbaAdmin $aba): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        $escopo = $this->escopoAdmin();

        return $escopo === null || $escopo->permite($aba);
    }

    /**
     * As abas que este admin enxerga agora — é o que monta o menu.
     *
     * @return list<string>
     */
    public function abasPermitidas(): array
    {
        if (! $this->isAdmin()) {
            return [];
        }

        $escopo = $this->escopoAdmin();

        return $escopo === null
            ? AbaAdmin::valores()
            : array_values(array_intersect(AbaAdmin::valores(), $escopo->abas ?? []));
    }

    public function orientadorProfile(): HasOne
    {
        return $this->hasOne(OrientadorProfile::class);
    }

    public function avaliadorProfile(): HasOne
    {
        return $this->hasOne(AvaliadorProfile::class);
    }

    public function projetos(): HasMany
    {
        return $this->hasMany(Projeto::class);
    }

    /** Avaliações em que este usuário é o avaliador (E7). */
    public function avaliacoes(): HasMany
    {
        return $this->hasMany(Avaliacao::class, 'avaliador_id');
    }

    public function isOrientador(): bool
    {
        return $this->role === Role::Orientador;
    }

    public function isAvaliador(): bool
    {
        return $this->role === Role::Avaliador;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Envia a notificação de redefinição de senha em pt_BR, com link para o SPA
     * (sobrescreve o padrão do Laravel, que aponta para uma rota nomeada Blade).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new RedefinirSenhaNotification($token));
    }
}
