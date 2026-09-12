{{--
    Etiquetas de identificação: uma por participante da lista final, com o QR
    Code e o código de barras.

    Duas por linha, em blocos que não quebram no meio (`page-break-inside`): a
    folha é recortada no dia do evento, e etiqueta partida entre duas páginas é
    etiqueta perdida.
--}}
@include('pdf.base')

<style>
    .etiqueta { width: 48%; border: 0.8pt dashed #cfc6db; padding: 8pt; margin: 0 0 8pt 0;
                page-break-inside: avoid; display: inline-block; vertical-align: top; }
    .etiqueta .nome { font-size: 10.5pt; font-weight: bold; color: #2a0058; }
    .etiqueta .papel { font-size: 8pt; color: #43157A; text-transform: uppercase; }
    .etiqueta .projeto { font-size: 7.5pt; color: #49454f; margin: 2pt 0 4pt 0; }
    .etiqueta .codigo { font-family: monospace; font-size: 8pt; color: #1c1b1f; margin-top: 2pt; }
    .codigos { margin-top: 4pt; }
    .codigos .qr { display: inline-block; width: 60pt; vertical-align: middle; }
    .codigos .barras { display: inline-block; width: 150pt; vertical-align: middle; margin-left: 6pt; }
</style>

<div class="cabecalho">
    <h1>Identificação dos participantes</h1>
    <div class="sub">
        {{ $lista->nome }} (v{{ $lista->versao }}) · gerado em {{ $gerado_em }} ·
        {{ count($participantes) }} participante(s)
    </div>
</div>

@forelse ($participantes as $p)
    <div class="etiqueta">
        <div class="papel">{{ $p['papel_label'] }}</div>
        <div class="nome">{{ $p['nome'] }}</div>
        <div class="projeto">
            {{ $p['projeto'] }}
            @if (! empty($p['escola'])) <br>{{ $p['escola'] }} @endif
        </div>
        <div class="codigos">
            <span class="qr">{!! $p['qr'] !!}</span>
            <span class="barras">{!! $p['barras'] !!}</span>
        </div>
        <div class="codigo">{{ $p['codigo'] }}</div>
    </div>
@empty
    <p class="vazio">A lista final não tem participantes.</p>
@endforelse

<div class="rodape">XVI FETECMS · identificação gerada pelo portal de inscrição</div>
