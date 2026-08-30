<?php

namespace App\Services;

use App\Enums\MeioTransporte;
use App\Models\Edicao;
use App\Models\LocalizacaoComite;
use App\Models\LocalizacaoComitePonto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Comitê especial → Transporte de comitê.
 *
 * Quem está conduzindo um grupo liga o localizador do próprio aparelho e o
 * portal passa a saber onde o grupo está, para onde vai e quanto falta. O
 * assistente coleta, nesta ordem: **quantas pessoas** estão junto, **os nomes**
 * (opcionais, com a área de cada uma), o **meio de transporte**, o **ponto de
 * partida**, o **destino + por quanto tempo** o localizador fica ligado e, por
 * fim, a **permissão de localização** do dispositivo.
 *
 * **Privacidade**: o portal guarda a **última posição** de cada sessão e o
 * **trajeto vivo** (um ponto a cada 5 segundos). Encerrar a sessão — à mão ou
 * pelo vencimento do prazo — **apaga o trajeto**; sobra apenas o registro de que
 * a sessão existiu, sem por onde o grupo passou.
 */
class ComiteTransporteService
{
    /** De quanto em quanto tempo o dispositivo manda a posição. */
    public const INTERVALO_SEGUNDOS = 5;

    /** Limites do "por quanto tempo o localizador fica ligado". */
    public const MIN_MINUTOS = 5;

    public const MAX_MINUTOS = 720;

    /**
     * A sessão ativa deste usuário, se houver. Uma por pessoa: ligar de novo
     * substitui a anterior.
     */
    public function sessaoDe(User $user): ?LocalizacaoComite
    {
        return LocalizacaoComite::ativas()->where('user_id', $user->id)->latest('id')->first();
    }

    /**
     * Liga o localizador com o que o assistente coletou.
     *
     * @param  array<string, mixed>  $dados
     */
    public function iniciar(User $user, array $dados): LocalizacaoComite
    {
        return DB::transaction(function () use ($user, $dados) {
            // Uma sessão ativa por pessoa: a nova encerra a anterior (e leva o
            // trajeto dela junto).
            foreach (LocalizacaoComite::ativas()->where('user_id', $user->id)->get() as $anterior) {
                $this->encerrar($anterior);
            }

            return LocalizacaoComite::create([
                'user_id' => $user->id,
                'edicao_id' => Edicao::atual()?->id,
                'pessoas' => max(1, (int) ($dados['pessoas'] ?? 1)),
                'acompanhantes' => $this->acompanhantes($dados['acompanhantes'] ?? []),
                'transporte' => $dados['transporte'] ?? MeioTransporte::Carro->value,
                'origem_nome' => $dados['origem_nome'] ?? null,
                'origem_lat' => $dados['origem_lat'] ?? null,
                'origem_lng' => $dados['origem_lng'] ?? null,
                'destino_nome' => $dados['destino_nome'],
                'destino_lat' => $dados['destino_lat'] ?? null,
                'destino_lng' => $dados['destino_lng'] ?? null,
                'expira_em' => now()->addMinutes((int) $dados['minutos']),
            ]);
        });
    }

    /**
     * Ajusta por quanto tempo o localizador ainda fica ligado — o prazo pode ser
     * esticado ou encurtado depois de iniciado, como o assistente promete.
     */
    public function prorrogar(LocalizacaoComite $sessao, int $minutos): LocalizacaoComite
    {
        $sessao->update(['expira_em' => now()->addMinutes($minutos)]);

        return $sessao->fresh();
    }

    /** Desliga o localizador e apaga o trajeto vivo. */
    public function encerrar(LocalizacaoComite $sessao): void
    {
        DB::transaction(function () use ($sessao) {
            $sessao->pontos()->delete();
            $sessao->update(['encerrado_em' => now()]);
        });
    }

    /**
     * Recebe uma posição do dispositivo (a cada 5 segundos) e guarda a
     * estimativa que veio junto (distância e tempo até o destino, calculados
     * pelo provedor de rotas no navegador).
     *
     * @param  array<string, mixed>  $dados
     */
    public function registrarPonto(LocalizacaoComite $sessao, array $dados): LocalizacaoComite
    {
        $agora = now();

        DB::transaction(function () use ($sessao, $dados, $agora) {
            $sessao->update([
                'latitude' => $dados['latitude'],
                'longitude' => $dados['longitude'],
                'precisao_m' => $dados['precisao_m'] ?? null,
                'distancia_m' => $dados['distancia_m'] ?? $sessao->distancia_m,
                'duracao_s' => $dados['duracao_s'] ?? $sessao->duracao_s,
                'posicao_em' => $agora,
            ]);

            LocalizacaoComitePonto::create([
                'localizacao_comite_id' => $sessao->id,
                'latitude' => $dados['latitude'],
                'longitude' => $dados['longitude'],
                'registrado_em' => $agora,
            ]);
        });

        return $sessao->fresh();
    }

    /**
     * Faxina das sessões vencidas: apaga o trajeto de quem passou do prazo e
     * marca a sessão como encerrada. Roda junto de cada leitura do mapa, para
     * não depender de agendador.
     */
    public function encerrarVencidas(): void
    {
        $vencidas = LocalizacaoComite::whereNull('encerrado_em')
            ->where('expira_em', '<=', now())
            ->get();

        foreach ($vencidas as $sessao) {
            $this->encerrar($sessao);
        }
    }

    /**
     * Todas as sessões ligadas agora, para o mapa do comitê.
     *
     * @return list<array<string, mixed>>
     */
    public function ativas(): array
    {
        $this->encerrarVencidas();

        return LocalizacaoComite::ativas()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (LocalizacaoComite $s) => $this->resumo($s))
            ->values()
            ->all();
    }

    /**
     * O que a tela precisa saber de uma sessão. `comTrajeto` inclui o caminho
     * percorrido desde que o localizador foi ligado.
     *
     * @return array<string, mixed>
     */
    public function resumo(LocalizacaoComite $sessao, bool $comTrajeto = false): array
    {
        $dados = [
            'id' => $sessao->id,
            'responsavel' => $sessao->user?->name,
            'pessoas' => $sessao->pessoas,
            'acompanhantes' => $sessao->acompanhantes ?? [],
            'transporte' => $sessao->transporte?->value,
            'transporte_label' => $sessao->transporte?->label(),
            'modo_rota' => $sessao->transporte?->modoRota() ?? 'DRIVING',
            'origem' => [
                'nome' => $sessao->origem_nome,
                'lat' => $sessao->origem_lat,
                'lng' => $sessao->origem_lng,
            ],
            'destino' => [
                'nome' => $sessao->destino_nome,
                'lat' => $sessao->destino_lat,
                'lng' => $sessao->destino_lng,
            ],
            'posicao' => $sessao->latitude === null ? null : [
                'lat' => $sessao->latitude,
                'lng' => $sessao->longitude,
                'precisao_m' => $sessao->precisao_m,
                'em' => $sessao->posicao_em?->toIso8601String(),
            ],
            'distancia_m' => $sessao->distancia_m,
            'duracao_s' => $sessao->duracao_s,
            // Previsão de chegada a partir da última estimativa recebida.
            'chegada_em' => $sessao->duracao_s === null || $sessao->posicao_em === null
                ? null
                : $sessao->posicao_em->copy()->addSeconds($sessao->duracao_s)->toIso8601String(),
            'expira_em' => $sessao->expira_em->toIso8601String(),
            'minutos_restantes' => max(0, (int) ceil(now()->diffInMinutes($sessao->expira_em, absolute: false))),
            'ativa' => $sessao->ativa(),
        ];

        if ($comTrajeto) {
            $dados['trajeto'] = $sessao->pontos()
                ->orderBy('registrado_em')
                ->get(['latitude', 'longitude'])
                ->map(fn (LocalizacaoComitePonto $p) => ['lat' => $p->latitude, 'lng' => $p->longitude])
                ->all();
        }

        return $dados;
    }

    /**
     * Normaliza os acompanhantes do passo 2: o nome é opcional, e quem não tem
     * nome nem área não vira linha nenhuma.
     *
     * @return list<array{nome: ?string, area: ?string}>
     */
    private function acompanhantes(mixed $lista): array
    {
        if (! is_array($lista)) {
            return [];
        }

        $pessoas = [];

        foreach ($lista as $pessoa) {
            $nome = trim((string) (is_array($pessoa) ? ($pessoa['nome'] ?? '') : $pessoa));
            $area = trim((string) (is_array($pessoa) ? ($pessoa['area'] ?? '') : ''));

            if ($nome === '' && $area === '') {
                continue;
            }

            $pessoas[] = ['nome' => $nome === '' ? null : $nome, 'area' => $area === '' ? null : $area];
        }

        return $pessoas;
    }
}
