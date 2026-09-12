{{--
    Folha de estilo comum dos PDFs do portal (Mapa do Evento e identificação).

    A identidade visual é a mesma da SPA — roxo #43157A no cabeçalho e nos
    títulos —, mas em papel: fundo branco, texto preto e tabelas com linha fina,
    porque o documento vai ser impresso em preto e branco no dia do evento e
    precisa continuar legível.

    O Dompdf entende um subconjunto de CSS: nada de flex/grid aqui, só tabela e
    bloco. Fonte DejaVu Sans, que é a embarcada com acento.
--}}
<style>
    @page { margin: 1.6cm 1.2cm; }

    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1c1b1f; margin: 0; }

    .cabecalho { border-bottom: 2pt solid #43157A; padding-bottom: 6pt; margin-bottom: 12pt; }
    .cabecalho h1 { color: #43157A; font-size: 15pt; margin: 0 0 2pt 0; }
    .cabecalho .sub { color: #49454f; font-size: 8.5pt; }

    h2 { color: #43157A; font-size: 11.5pt; margin: 14pt 0 5pt 0; padding-bottom: 3pt;
         border-bottom: 1pt solid #d9d2e0; }

    table { width: 100%; border-collapse: collapse; }
    th { background: #f3edf7; color: #43157A; font-size: 8pt; text-align: left;
         text-transform: uppercase; padding: 4pt 5pt; border-bottom: 1pt solid #43157A; }
    td { padding: 4pt 5pt; border-bottom: 0.5pt solid #e4dfe9; vertical-align: top; }
    tr { page-break-inside: avoid; }

    .num { color: #49454f; width: 8%; }
    .menor { font-size: 8pt; color: #49454f; }
    .rodape { margin-top: 10pt; font-size: 7.5pt; color: #79747e; text-align: center; }
    .vazio { color: #79747e; font-style: italic; padding: 8pt 0; }
</style>
