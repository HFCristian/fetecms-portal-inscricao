<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\User;
use App\Support\Tempo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AvaliadorService
{
    private const PROFILE_FIELDS = ['cpf', 'titulacao', 'area_id', 'subarea_id'];

    public function __construct(private readonly SubareaService $subareas) {}

    /**
     * Números do perfil do avaliador: quantas avaliações concluiu, quanto isso
     * rende de certificado e onde ele está no ranking de quem mais avaliou.
     *
     * @return array<string, mixed>
     */
    public function estatisticas(User $user): array
    {
        $concluidas = $this->concluidasPorAvaliador();
        $minhas = (int) $concluidas->get($user->id, 0);
        $minutos = $minhas * AvaliadorProfile::MINUTOS_POR_AVALIACAO;

        // Só entra no ranking quem já concluiu alguma: uma lista de zeros não
        // classifica ninguém.
        $acima = $concluidas->filter(fn (int $total) => $total > $minhas)->count();

        return [
            'avaliacoes_concluidas' => $minhas,
            'certificado_minutos' => $minutos,
            'certificado_label' => Tempo::cargaHoraria($minutos),
            'por_avaliacao_label' => Tempo::cargaHoraria(AvaliadorProfile::MINUTOS_POR_AVALIACAO),
            'posicao' => $minhas > 0 ? $acima + 1 : null,
            'total_no_ranking' => $concluidas->count(),
            // Posição dividida com outros avaliadores de mesmo número.
            'empate' => $minhas > 0 && $concluidas->filter(fn (int $t) => $t === $minhas)->count() > 1,
        ];
    }

    /**
     * Avaliações concluídas por avaliador (só quem tem ao menos uma).
     *
     * @return Collection<int, int>
     */
    private function concluidasPorAvaliador(): Collection
    {
        return Avaliacao::query()
            ->where('status', StatusAvaliacao::Concluida->value)
            ->selectRaw('avaliador_id, COUNT(*) as total')
            ->groupBy('avaliador_id')
            ->pluck('total', 'avaliador_id')
            ->map(fn ($total) => (int) $total);
    }

    /**
     * O avaliador pode trocar de área/subárea? Só fora do período de avaliação:
     * depois da liberação a distribuição já foi feita em cima da classificação
     * dele, e mexer nela bagunçaria as designações.
     */
    public function podeTrocarClassificacao(): bool
    {
        return ! Edicao::atual()?->avaliacaoLiberada();
    }

    /**
     * Troca a área (e a subárea opcional) do avaliador. A subárea chega sempre
     * por id: uma subárea nova já foi criada pelo combobox do front.
     *
     * @param  array<string, mixed>  $dados  Já validado pelo FormRequest.
     */
    public function atualizarClassificacao(User $user, array $dados): AvaliadorProfile
    {
        if (! $this->podeTrocarClassificacao()) {
            throw ValidationException::withMessages([
                'area_id' => 'O período de avaliação já começou: a área não pode mais ser alterada. Fale com a organização.',
            ]);
        }

        return DB::transaction(function () use ($user, $dados) {
            $perfil = $user->avaliadorProfile;

            $perfil->update([
                'area_id' => $dados['area_id'],
                // Trocar de área sem escolher subárea deixa o campo vazio (é opcional).
                'subarea_id' => $dados['subarea_id'] ?? null,
            ]);

            return $perfil->fresh(['area', 'subarea']);
        });
    }

    /** Cria o usuário (role avaliador) e o perfil de avaliador numa transação. */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $data = $this->resolverSubarea($data);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::Avaliador,
                'is_active' => true,
            ]);

            $user->avaliadorProfile()->create(Arr::only($data, self::PROFILE_FIELDS));

            return $user->load('avaliadorProfile.area', 'avaliadorProfile.subarea');
        });
    }

    /**
     * Subárea NOVA por texto (subarea_nome) sem id: cria/reaproveita a subárea
     * global na área escolhida e injeta o subarea_id resultante.
     */
    private function resolverSubarea(array $data): array
    {
        $temNome = ! empty($data['subarea_nome'] ?? null);
        $temArea = ! empty($data['area_id'] ?? null);

        if (empty($data['subarea_id'] ?? null) && $temNome && $temArea) {
            $data['subarea_id'] = $this->subareas
                ->firstOrCreateNaArea((int) $data['area_id'], (string) $data['subarea_nome'])
                ->id;
        }

        return $data;
    }
}
