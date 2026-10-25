<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CertificadosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Aba **Certificados**: os projetos que cada avaliador avaliou e a declaração
 * nominal em PDF — o que "Ver projetos avaliados" abre na conferência de nomes.
 * A lista de certificados está no {@see CertificadoListaController}.
 */
class CertificadosController extends Controller
{
    public function __construct(private readonly CertificadosService $service) {}

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
}
