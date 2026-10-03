<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CertificadosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Aba **Certificados** (Sprint 163): planilhas para a emissão de certificados
 * e o relatório nominal de cada avaliador.
 */
class CertificadosController extends Controller
{
    public function __construct(private readonly CertificadosService $service) {}

    public function opcoes(): JsonResponse
    {
        return response()->json(['data' => $this->service->opcoes()]);
    }

    /** Avaliadores com as duas fases contadas — a tabela da tela. */
    public function avaliadores(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'fase' => ['nullable', Rule::in($this->fases())],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->service->avaliadores($dados['fase'] ?? CertificadosService::FASE_TODAS, $dados['q'] ?? '')->all(),
        ]);
    }

    public function exportarAvaliadores(Request $request): Response
    {
        $dados = $request->validate([
            'fase' => ['required', Rule::in($this->fases())],
            'formato' => ['required', Rule::in(['csv', 'xlsx'])],
        ]);

        return $this->baixar($this->service->exportarAvaliadores($dados['fase'], $dados['formato']));
    }

    public function exportarParticipantes(Request $request): Response
    {
        $dados = $request->validate([
            'grupos' => ['required', 'array', 'min:1'],
            'grupos.*' => [Rule::in(array_keys(CertificadosService::GRUPOS))],
            'escopo' => ['required', Rule::in(['finalistas', 'submetidos'])],
            'formato' => ['required', Rule::in(['csv', 'xlsx'])],
        ]);

        return $this->baixar($this->service->exportarParticipantes($dados['grupos'], $dados['escopo'], $dados['formato']));
    }

    public function exportarNominais(Request $request): Response
    {
        $dados = $request->validate([
            'fase' => ['required', Rule::in($this->fases())],
            'formato' => ['required', Rule::in(['csv', 'xlsx'])],
        ]);

        return $this->baixar($this->service->exportarAvaliacoesNominais($dados['fase'], $dados['formato']));
    }

    public function projetosDoAvaliador(User $avaliador): JsonResponse
    {
        return response()->json(['data' => $this->service->projetosDoAvaliador($avaliador)]);
    }

    public function declaracao(User $avaliador): Response
    {
        $nome = 'projetos-avaliados-'.str($avaliador->name)->slug().'.pdf';

        return response($this->service->declaracaoPdf($avaliador), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nome.'"',
        ]);
    }

    /** @return list<string> */
    private function fases(): array
    {
        return [CertificadosService::FASE_ONLINE, CertificadosService::FASE_PRESENCIAL, CertificadosService::FASE_TODAS];
    }

    /** @param  array{conteudo: string, nome: string, tipo: string}  $arquivo */
    private function baixar(array $arquivo): Response
    {
        return response($arquivo['conteudo'], 200, [
            'Content-Type' => $arquivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$arquivo['nome'].'"',
        ]);
    }
}
