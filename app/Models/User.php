<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Notifications\RedefinirSenhaNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'chat_dica_dispensada'])]
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
        ];
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
