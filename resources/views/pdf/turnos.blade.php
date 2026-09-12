{{-- Turnos de apresentação: um bloco por turno, na ordem da lista final. --}}
@include('pdf.base')

<div class="cabecalho">
    <h1>Turnos de apresentação</h1>
    <div class="sub">{{ $edicao }} · gerado em {{ $gerado_em }} · {{ $lista['total'] }} projeto(s)</div>
</div>

@foreach ($lista['turnos'] as $turno)
    <h2>{{ $turno['label'] }} — {{ $turno['total'] }} projeto(s)</h2>

    @if (empty($turno['projetos']))
        <p class="vazio">Nenhum projeto neste turno.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>Projeto</th>
                    <th>Categoria / Área</th>
                    <th>Escola</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($turno['projetos'] as $i => $projeto)
                    <tr>
                        <td class="num">{{ str_pad($i + 1, 3, '0', STR_PAD_LEFT) }}</td>
                        <td>
                            {{ $projeto['titulo'] }}
                            <div class="menor">{{ $projeto['orientador'] }}</div>
                        </td>
                        <td>
                            {{ $projeto['categoria'] ?? '—' }}
                            <div class="menor">{{ $projeto['area'] ?? 'sem área' }}</div>
                        </td>
                        <td>
                            {{ $projeto['escola'] }}
                            <div class="menor">{{ trim(($projeto['cidade'] ?? '').' - '.($projeto['uf'] ?? ''), ' -') }}</div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach

<div class="rodape">XVI FETECMS · documento gerado pelo portal de inscrição</div>
