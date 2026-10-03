<?php

namespace App\Services;

use App\Support\EtiquetasPdf;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Geração de PDF a partir de uma view Blade (Dompdf).
 *
 * Um lugar só para a configuração do motor: as listas do Mapa do Evento e os
 * demais relatórios saem todos por aqui, e ajustar margem ou fonte em cada
 * chamada seria o caminho para PDFs com caras diferentes. As etiquetas de
 * identificação são a exceção: centenas de itens repetidos numa grade fixa
 * custam caro demais no motor de layout, e são desenhadas direto na página
 * ({@see EtiquetasPdf}).
 *
 * Duas opções que importam:
 *
 * - `isRemoteEnabled` fica **desligado**. As views são nossas, mas um PDF que
 *   busca recurso remoto é um SSRF esperando acontecer — o que precisar de
 *   imagem embute em `data:`.
 * - `defaultFont` é DejaVu Sans: é a fonte embarcada do Dompdf que tem acento
 *   e cedilha. Com Helvetica, "Ciências Agrárias" sai quebrado.
 */
class PdfService
{
    /**
     * Renderiza a view e devolve os bytes do PDF.
     *
     * @param  array<string, mixed>  $dados
     */
    public function render(string $view, array $dados = [], string $tamanho = 'a4', string $orientacao = 'portrait'): string
    {
        $opcoes = new Options;
        $opcoes->set('isRemoteEnabled', false);
        $opcoes->set('isHtml5ParserEnabled', true);
        $opcoes->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($opcoes);
        $dompdf->loadHtml(view($view, $dados)->render(), 'UTF-8');
        $dompdf->setPaper($tamanho, $orientacao);
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
