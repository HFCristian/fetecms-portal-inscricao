{{-- Ranking dos projetos: a classificação por média, no recorte da tela. --}}
@include('pdf.base')

<div class="cabecalho">
    <h1>Ranking dos projetos</h1>
    <div class="sub">
        {{ $edicao }} · {{ $recorte }} · {{ count($lista) }} projeto(s) · gerado em {{ $gerado_em }}
    </div>
</div>

@if (empty($lista))
    <p class="vazio">Nenhum projeto avaliado neste recorte.</p>
@else
    <table>
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Projeto</th>
                <th>Área / Categoria</th>
                <th style="width: 14%">Média</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lista as $projeto)
                <tr>
                    <td class="num">{{ $projeto['posicao'] }}º</td>
                    <td>{{ $projeto['titulo'] }}</td>
                    <td>
                        {{ $projeto['area'] ?? 'Sem área' }}
                        <div class="menor">{{ $projeto['categoria'] ?? '—' }}</div>
                    </td>
                    <td>
                        <strong>{{ number_format((float) $projeto['media'], 2, ',', '') }}</strong>
                        <span class="menor">/{{ rtrim(rtrim(number_format($nota_maxima, 2, ',', ''), '0'), ',') }}</span>
                        <div class="menor">
                            {{ $projeto['avaliacoes'] }} avaliação(ões){{ $projeto['completo'] ? '' : ' · parcial' }}
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- O parcial precisa estar escrito no papel: fora da tela, ninguém lembra
         que aquela posição ainda pode mudar. --}}
    <div class="rodape">
        "Parcial" marca o projeto que ainda não recebeu o mínimo de avaliações da categoria —
        a média dele pode mudar, e com ela a posição.
    </div>
@endif
