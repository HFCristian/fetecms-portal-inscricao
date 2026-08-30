<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MeioTransporte;
use App\Http\Controllers\Controller;
use App\Models\LocalizacaoComite;
use App\Services\ComiteTransporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comitê especial → Transporte de comitê: a sessão do localizador de quem está
 * conduzindo um grupo, e o mapa com todas as sessões ligadas.
 *
 * A posição chega de 5 em 5 segundos por `POST .../ponto`; o mapa lê o estado
 * atual por `GET .../mapa`. Não há WebSocket no projeto — as duas pontas são
 * polling, no mesmo intervalo.
 */
class ComiteTransporteController extends Controller
{
    public function __construct(private readonly ComiteTransporteService $comite) {}

    /** A sessão do próprio usuário (ou null) + as opções do assistente. */
    public function minha(Request $request): JsonResponse
    {
        $sessao = $this->comite->sessaoDe($request->user());

        return response()->json([
            'data' => [
                'sessao' => $sessao === null ? null : $this->comite->resumo($sessao, comTrajeto: true),
                'transportes' => MeioTransporte::opcoes(),
                'intervalo_segundos' => ComiteTransporteService::INTERVALO_SEGUNDOS,
                'min_minutos' => ComiteTransporteService::MIN_MINUTOS,
                'max_minutos' => ComiteTransporteService::MAX_MINUTOS,
            ],
        ]);
    }

    /** Liga o localizador com o que o assistente coletou. */
    public function iniciar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pessoas' => ['required', 'integer', 'min:1', 'max:99'],
            'acompanhantes' => ['nullable', 'array', 'max:99'],
            'acompanhantes.*.nome' => ['nullable', 'string', 'max:120'],
            'acompanhantes.*.area' => ['nullable', 'string', 'max:120'],
            'transporte' => ['required', Rule::in(MeioTransporte::valores())],
            'origem_nome' => ['nullable', 'string', 'max:255'],
            'origem_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origem_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'destino_nome' => ['required', 'string', 'max:255'],
            'destino_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'destino_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'minutos' => [
                'required', 'integer',
                'min:'.ComiteTransporteService::MIN_MINUTOS,
                'max:'.ComiteTransporteService::MAX_MINUTOS,
            ],
        ]);

        $sessao = $this->comite->iniciar($request->user(), $dados);

        return response()->json([
            'data' => $this->comite->resumo($sessao, comTrajeto: true),
        ], 201);
    }

    /** Ajusta o tempo restante do localizador. */
    public function prorrogar(Request $request): JsonResponse
    {
        $sessao = $this->minhaSessao($request);

        $dados = $request->validate([
            'minutos' => [
                'required', 'integer',
                'min:'.ComiteTransporteService::MIN_MINUTOS,
                'max:'.ComiteTransporteService::MAX_MINUTOS,
            ],
        ]);

        return response()->json([
            'data' => $this->comite->resumo($this->comite->prorrogar($sessao, $dados['minutos']), comTrajeto: true),
        ]);
    }

    /** Desliga o localizador (e apaga o trajeto). */
    public function encerrar(Request $request): JsonResponse
    {
        $this->comite->encerrar($this->minhaSessao($request));

        return response()->json(['data' => ['message' => 'Localizador desligado.']]);
    }

    /**
     * Uma posição do dispositivo. `distancia_m`/`duracao_s` vêm da rota
     * calculada no navegador — é o que alimenta a previsão de chegada.
     */
    public function ponto(Request $request): JsonResponse
    {
        $sessao = $this->minhaSessao($request);

        $dados = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'precisao_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'distancia_m' => ['nullable', 'integer', 'min:0'],
            'duracao_s' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json([
            'data' => $this->comite->resumo($this->comite->registrarPonto($sessao, $dados)),
        ]);
    }

    /** Todas as sessões ligadas agora — alimenta o Mapa do comitê. */
    public function mapa(): JsonResponse
    {
        return response()->json([
            'data' => $this->comite->ativas(),
            'meta' => ['intervalo_segundos' => ComiteTransporteService::INTERVALO_SEGUNDOS],
        ]);
    }

    /** O detalhe de um ponto do mapa, com o trajeto percorrido. */
    public function detalhe(LocalizacaoComite $localizacao): JsonResponse
    {
        abort_unless($localizacao->ativa(), 404, 'Este localizador não está mais ligado.');

        return response()->json(['data' => $this->comite->resumo($localizacao, comTrajeto: true)]);
    }

    /** A sessão ativa de quem chamou — 404 quando o localizador está desligado. */
    private function minhaSessao(Request $request): LocalizacaoComite
    {
        $sessao = $this->comite->sessaoDe($request->user());

        abort_if($sessao === null, 404, 'Nenhum localizador ligado nesta conta.');

        return $sessao;
    }
}
