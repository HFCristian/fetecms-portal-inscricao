<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AbaAdmin;
use App\Enums\Role;
use App\Notifications\RedefinirSenhaNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Escopos de admin deste usuário — os *roles* do RBAC. Um admin pode ter
     * **vários** na mesma edição, e o acesso dele é a união das abas de todos.
     */
    public function escopos(): BelongsToMany
    {
        return $this->belongsToMany(EscopoAdmin::class, 'admin_escopos', 'user_id', 'escopo_admin_id')
            ->withPivot('edicao_id')
            ->withTimestamps();
    }

    /**
     * Os escopos deste admin na edição em curso. Coleção **vazia** significa
     * **acesso total**: sem atribuição, o admin continua vendo tudo
     * (comportamento anterior aos escopos, e a rede que impede uma edição nova
     * de trancar a equipe para fora).
     *
     * @return Collection<int, EscopoAdmin>
     */
    public function escoposAdmin(): Collection
    {
        if (! $this->isAdmin()) {
            return new Collection;
        }

        $edicao = Edicao::escopoDe($this);

        if ($edicao === null) {
            return new Collection;
        }

        return $this->escopos()->wherePivot('edicao_id', $edicao->id)->get();
    }

    /** A conta de acesso temporário ao balcão, quando esta é uma. */
    public function contaTemporaria(): HasOne
    {
        return $this->hasOne(ContaTemporaria::class);
    }

    /**
     * Esta é uma conta temporária de credenciamento?
     *
     * Vale como trava de acesso: ela abre **só** a aba Credenciamento, por cima
     * de qualquer escopo — inclusive do "acesso total" de quem não tem nenhum.
     */
    public function ehContaTemporaria(): bool
    {
        return $this->isAdmin() && $this->contaTemporaria()->exists();
    }

    /**
     * Este admin abre esta aba do menu? Quem não é admin nunca abre.
     *
     * Basta **um** dos escopos dele liberar a aba — os roles somam, não se
     * restringem entre si.
     */
    public function podeAbrirAba(AbaAdmin $aba): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        if ($this->ehContaTemporaria()) {
            return $aba === AbaAdmin::Credenciamento;
        }

        $escopos = $this->escoposAdmin();

        return $escopos->isEmpty() || $escopos->contains(fn (EscopoAdmin $e) => $e->permite($aba));
    }

    /**
     * As abas que este admin enxerga agora — é o que monta o menu. É a **união**
     * das abas de todos os escopos dele, na ordem canônica do enum.
     *
     * @return list<string>
     */
    public function abasPermitidas(): array
    {
        if (! $this->isAdmin()) {
            return [];
        }

        // Conta temporária é balcão e nada mais: não depende de alguém lembrar
        // de atribuir o escopo certo.
        if ($this->ehContaTemporaria()) {
            return [AbaAdmin::Credenciamento->value];
        }

        $escopos = $this->escoposAdmin();

        if ($escopos->isEmpty()) {
            return AbaAdmin::valores();
        }

        $liberadas = $escopos->flatMap(fn (EscopoAdmin $e) => $e->abas ?? [])->unique()->all();

        return array_values(array_intersect(AbaAdmin::valores(), $liberadas));
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
