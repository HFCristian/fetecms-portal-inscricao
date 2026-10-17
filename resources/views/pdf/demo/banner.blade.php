{{--
    Banner do ensaio (5.1 a 5.4), em A3: duas colunas com as seções do
    trabalho. `banner_sem` tira seções de propósito, para o ensaio passar pelo
    5.1 = Não.
--}}
@include('pdf.demo._cabecalho', ['documento' => 'Banner'])
<style>
    /* Pôster em A3: lido a um metro de distância, não na mesa. */
    .cabecalho h1 { font-size: 26pt; }
    .cabecalho .sub { font-size: 12pt; }
    .banner td { width: 50%; border: 0; padding: 0 12pt 0 0; }
    .banner h2 { font-size: 18pt; margin-top: 16pt; }
    .banner p { font-size: 13.5pt; }
    .banner .referencia { font-size: 11pt; }
    .ia { margin-top: 16pt; border: 1.5pt solid #43157A; padding: 8pt; font-size: 12pt; }
</style>

@php
    $secoes = array_diff_key($projeto['secoes'], array_flip([...$projeto['banner_sem'], 'Declaração de uso de IA']));
    $metade = (int) ceil(count($secoes) / 2);
    $colunas = [array_slice($secoes, 0, $metade, true), array_slice($secoes, $metade, null, true)];
@endphp

<table class="banner">
    <tr>
        @foreach ($colunas as $i => $coluna)
            <td class="texto">
                @foreach ($coluna as $titulo => $texto)
                    <h2>{{ $titulo }}</h2>
                    @foreach (explode("\n", $texto) as $paragrafo)
                        <p>{{ $paragrafo }}</p>
                    @endforeach
                @endforeach

                @if ($i === 1)
                    <h2>Referências</h2>
                    @foreach (array_slice($projeto['referencias']['principais'], 0, 3) as $referencia)
                        <p class="referencia">{{ $referencia }}</p>
                    @endforeach
                @endif
            </td>
        @endforeach
    </tr>
</table>

@if ($projeto['ia'])
    <div class="ia"><strong>Uso de Inteligência Artificial:</strong> {{ $projeto['ia'] }}</div>
@endif
