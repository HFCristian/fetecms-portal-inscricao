<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\StoreSubareaRequest;
use App\Models\Area;
use App\Models\Cidade;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\PalavraChave;
use App\Models\Subarea;
use App\Services\SubareaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CatalogoController extends Controller
{
    public function edicoes(): JsonResponse
    {
        $data = Edicao::where('inscricoes_abertas', true)
            ->orderByDesc('ano')
            ->get(['id', 'nome', 'ano']);

        return response()->json(['data' => $data]);
    }

    public function categorias(): JsonResponse
    {
        $data = array_map(fn (Categoria $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'max_alunos' => $c->maxAlunos(),
        ], Categoria::cases());

        return response()->json(['data' => $data]);
    }

    public function areas(): JsonResponse
    {
        // Cacheia ARRAY puro (não a Collection do Eloquent): cachear objetos no
        // driver database gera __PHP_Incomplete_Class na releitura.
        $data = Cache::remember('catalogo.areas', now()->addHour(),
            fn () => Area::orderBy('nome')->get(['id', 'nome'])->toArray());

        return response()->json(['data' => $data]);
    }

    public function subareas(Request $request): JsonResponse
    {
        $data = Subarea::when($request->filled('area_id'),
            fn ($q) => $q->where('area_id', $request->integer('area_id')))
            ->orderBy('nome')
            ->get(['id', 'nome', 'area_id']);

        return response()->json(['data' => $data]);
    }

    /**
     * Cria (ou reaproveita) uma subárea global dentro da área. Usado pelo combobox
     * "digite/crie" nos formulários autenticados (projeto/perfil). Devolve a subárea
     * para o front selecioná-la na hora.
     */
    public function criarSubarea(StoreSubareaRequest $request, SubareaService $subareas): JsonResponse
    {
        $subarea = $subareas->firstOrCreateNaArea(
            $request->integer('area_id'),
            (string) $request->input('nome'),
        );

        return response()->json([
            'data' => ['id' => $subarea->id, 'nome' => $subarea->nome, 'area_id' => $subarea->area_id],
        ], 201);
    }

    public function estados(): JsonResponse
    {
        $data = Cache::remember('catalogo.estados', now()->addHour(),
            fn () => Estado::orderBy('nome')->get(['id', 'nome', 'uf'])->toArray());

        return response()->json(['data' => $data]);
    }

    public function cidades(Request $request): JsonResponse
    {
        $data = Cidade::when($request->filled('estado_id'),
            fn ($q) => $q->where('estado_id', $request->integer('estado_id')))
            ->orderBy('nome')
            ->get(['id', 'nome', 'estado_id']);

        return response()->json(['data' => $data]);
    }

    public function palavrasChave(Request $request): JsonResponse
    {
        $data = PalavraChave::when($request->filled('search'),
            fn ($q) => $q->where('texto', 'like', '%'.$request->string('search').'%'))
            ->orderBy('texto')
            ->limit(20)
            ->pluck('texto');

        return response()->json(['data' => $data]);
    }

    public function instituicoes(Request $request): JsonResponse
    {
        $data = Instituicao::when($request->filled('search'),
            fn ($q) => $q->where('nome', 'like', '%'.$request->string('search').'%'))
            ->orderBy('nome')
            ->limit(50)
            ->get(['id', 'nome', 'cidade_id']);

        return response()->json(['data' => $data]);
    }
}
