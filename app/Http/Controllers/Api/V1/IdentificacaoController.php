<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ListaFinal;
use App\Services\IdentificacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A identificação dos participantes da lista final: o QR Code e o código de
 * barras que o evento usa para reconhecer cada pessoa.
 *
 * Os códigos são **derivados** dos dados do cadastro, não guardados: a lista
 * pode mudar de versão que o crachá impresso continua valendo.
 */
class IdentificacaoController extends Controller
{
    public function __construct(private readonly IdentificacaoService $identificacao) {}

    public function index(ListaFinal $lista): JsonResponse
    {
        return response()->json(['data' => $this->identificacao->painel($lista)]);
    }

    /**
     * O SVG de um código — é o que a tela põe no `<img>` e o que o admin baixa
     * para mandar à gráfica.
     */
    public function svg(string $tipo, string $codigo): Response
    {
        $svg = $this->identificacao->svg($codigo, $tipo === 'barras' ? 'barras' : 'qr');

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            // O código não muda: o navegador pode guardar à vontade.
            'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => 'inline; filename="'.$codigo.'-'.$tipo.'.svg"',
        ]);
    }

    /** Todas as etiquetas numa folha, prontas para imprimir e recortar. */
    public function pdf(ListaFinal $lista): Response
    {
        return response($this->identificacao->pdf($lista), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="identificacao-participantes.pdf"',
        ]);
    }

    /** Os SVGs de todo mundo, agrupados por projeto. */
    public function zip(ListaFinal $lista): BinaryFileResponse
    {
        $caminho = $this->identificacao->zip($lista);

        return response()
            ->download($caminho, 'identificacao-participantes.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
