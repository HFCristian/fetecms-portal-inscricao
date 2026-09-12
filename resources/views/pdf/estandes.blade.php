{{-- Estandes dos projetos: um bloco por turno, em ordem de número. --}}
@include('pdf.base')

<div class="cabecalho">
    <h1>Estandes dos projetos</h1>
    <div class="sub">{{ $edicao }} · gerado em {{ $gerado_em }} · {{ $lista['total'] }} estande(s) ocupado(s)</div>
</div>

@foreach ($lista['turnos'] as $turno)
    <h2>{{ $turno['label'] }} — {{ $turno['total'] }} estande(s)</h2>

    @if (empty($turno['estandes']))
        <p class="vazio">Nenhum projeto neste turno.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th class="num">Estande</th>
                    <th>Projeto</th>
                    <th>Categoria / Área</th>
                    <th>Escola</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($turno['estandes'] as $estande)
                    <tr>
                        <td class="num">{{ $estande['estande'] }}</td>
                        <td>
                            {{ $estande['titulo'] }}
                            <div class="menor">{{ $estande['orientador'] }}</div>
                        </td>
                        <td>
                            {{ $estande['categoria'] ?? '—' }}
                            <div class="menor">{{ $estande['area'] ?? 'sem área' }}</div>
                        </td>
                        <td>
                            {{ $estande['escola'] }}
                            <div class="menor">{{ trim(($estande['cidade'] ?? '').' - '.($estande['uf'] ?? ''), ' -') }}</div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach

<div class="rodape">XVI FETECMS · documento gerado pelo portal de inscrição</div>
