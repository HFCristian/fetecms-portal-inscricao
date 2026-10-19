<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Models\FotografiaEstande;
use App\Models\Projeto;
use App\Services\FotografiaEstandeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cerimonial → **Fotografias de estandes** (Sprint 247). Aberta também à conta
 * temporária do setor: é o voluntário que percorre o ginásio fotografando.
 */
class CerimonialFotografiaController extends Controller
{
    public function __construct(private readonly FotografiaEstandeService $fotos) {}

    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'turno' => ['nullable', Rule::in(Turno::valores())],
        ], ['turno.in' => 'Turno inválido.']);

        return response()->json([
            'data' => $this->fotos->mapa(Turno::tryFrom((string) ($dados['turno'] ?? '')) ?? Turno::A, $request->user()),
        ]);
    }

    /** "Registrar fotografia do estande?" — confirmado. */
    public function reservar(Request $request, Projeto $projeto): JsonResponse
    {
        return response()->json([
            'data' => $this->fotos->reservar($projeto, $request->user()),
            'meta' => ['message' => 'Estande reservado para o registro da fotografia.'],
        ]);
    }

    public function cancelar(Request $request, Projeto $projeto): Response
    {
        $this->fotos->cancelar($projeto, $request->user());

        return response()->noContent();
    }

    public function registrar(Request $request, Projeto $projeto): JsonResponse
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:'.FotografiaEstande::MAX_KB, 'mimes:jpg,jpeg,png,webp,heic,heif'],
        ], [
            'arquivo.required' => 'Tire a fotografia ou escolha um arquivo.',
            'arquivo.max' => 'A fotografia pode ter até 100 MB.',
            'arquivo.mimes' => 'Envie uma imagem JPG, PNG, WEBP ou HEIC.',
            'arquivo.uploaded' => 'O envio da fotografia falhou — o arquivo pode ser maior do que o servidor aceita.',
        ]);

        return response()->json([
            'data' => $this->fotos->registrar($projeto, $request->file('arquivo'), $request->user()),
            'meta' => ['message' => 'Fotografia do estande registrada.'],
        ]);
    }

    public function excluir(Request $request, FotografiaEstande $fotografia): Response
    {
        $this->fotos->excluir($fotografia, $request->user());

        return response()->noContent();
    }

    public function arquivo(FotografiaEstande $fotografia): StreamedResponse
    {
        return $this->fotos->arquivo($fotografia);
    }
}
