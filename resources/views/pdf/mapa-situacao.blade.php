{{-- Mapa do evento: os projetos de um recorte da situação, em ordem de estande. --}}
@include('pdf.base')

<div class="cabecalho">
    <h1>Mapa do evento — {{ $lista['criterio_label'] }}</h1>
    <div class="sub">
        {{ $edicao }} · {{ $lista['turno_label'] }} ·
        {{-- O corte é o que dá sentido ao número: "às 14h de quinta" não é "agora". --}}
        situação em {{ $lista['corte_label'] }} ·
        {{ $lista['valor_label'] }} · {{ $lista['total'] }} projeto(s)
    </div>
</div>

@if (empty($lista['linhas']))
    <p class="vazio">Nenhum projeto neste recorte.</p>
@else
    <table>
        <thead>
            <tr>
                <th class="num">Estande</th>
                <th>Projeto</th>
                <th>Categoria / Área</th>
                <th>Escola</th>
                <th>Situação</th>
                <th class="num">Avaliações</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lista['linhas'] as $linha)
                <tr>
                    <td class="num">{{ $linha['estande'] }}</td>
                    <td>
                        {{ $linha['titulo'] }}
                        <div class="menor">{{ $linha['orientador'] }}</div>
                    </td>
                    <td>
                        {{ $linha['categoria'] ?? '—' }}
                        <div class="menor">{{ $linha['area'] ?? 'sem área' }}</div>
                    </td>
                    <td>{{ $linha['escola'] }}</td>
                    <td>
                        {{ $linha['situacao_label'] }}
                        <div class="menor">
                            {{ $linha['credenciado'] ? 'credenciado '.$linha['credenciado_em'] : 'sem credenciamento' }}
                            @if ($linha['checado']) · checado {{ $linha['checado_em'] }} @endif
                        </div>
                    </td>
                    <td class="num">
                        {{ $linha['avaliacoes'] }} de {{ $linha['avaliacoes_maximo'] }}
                        <div class="menor">faltam {{ $linha['avaliacoes_faltantes'] }}</div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
