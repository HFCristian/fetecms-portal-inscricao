{{--
    Relatório nominal de um avaliador (Sprint 163): os títulos dos projetos que
    ele avaliou em cada fase. Serve de base à declaração que a organização emite
    quando alguém pede a lista — por isso não leva nota nenhuma: a nota é da
    organização, o que se declara é o trabalho feito.
--}}
@include('pdf.base')

<div class="cabecalho">
    <h1>Projetos avaliados</h1>
    <div class="sub">
        {{ $edicao ?? 'XVI FETECMS' }} · gerado em {{ $gerado_em }}
    </div>
</div>

<p>
    <strong>{{ $avaliador['nome'] }}</strong>
    @if ($avaliador['cpf']) · CPF {{ $avaliador['cpf'] }} @endif
    @if ($avaliador['area']) · área de avaliação: {{ $avaliador['area'] }} @endif
</p>

@foreach (['online' => 'Fase online', 'presencial' => 'Fase presencial'] as $chave => $rotulo)
    <h2>{{ $rotulo }} — {{ count($$chave) }} projeto(s)</h2>
    @if (count($$chave) === 0)
        <p class="vazio">Nenhuma avaliação concluída nesta fase.</p>
    @else
        <table>
            <thead>
                <tr><th class="num">#</th><th>Projeto</th><th>Área</th><th>Categoria</th><th>Concluída em</th></tr>
            </thead>
            <tbody>
                @foreach ($$chave as $i => $p)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>{{ $p['titulo'] }}</td>
                        <td class="menor">{{ $p['area'] ?? '—' }}</td>
                        <td class="menor">{{ $p['categoria'] ?? '—' }}</td>
                        <td class="menor">{{ $p['concluida_em'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach

<div class="rodape">XVI FETECMS · relatório gerado pelo portal de inscrição</div>
