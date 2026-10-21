{{-- Lista do ranking presencial (Sprint 251): os premiados por nota e, desde a
     Sprint 253, as credenciais e os prêmios — cada grupo numa tabela. --}}
@include('pdf.base')

<div class="cabecalho">
    <h1>{{ $lista->nome }}</h1>
    <div class="sub">
        {{ $lista->ehFinal() ? 'Lista final presencial' : 'Lista preliminar presencial' }}{{ $lista->vigente ? ' (ativa)' : '' }}{{ $lista->rascunho ? ' · rascunho' : '' }}
        · {{ $edicao }} · versão {{ $lista->versao }} · gerado em {{ $gerado_em }}
    </div>
</div>

@if (empty($grupos))
    <p class="vazio">A lista está vazia.</p>
@else
    @foreach ($grupos as $grupo)
        <h2>{{ $grupo['secao'] === 'nota' ? 'Por nota · ' : '' }}{{ $grupo['titulo'] }}</h2>
        <table>
            <thead>
                <tr>
                    @if ($grupo['secao'] === 'nota')<th class="num">Lugar</th>@endif
                    <th>Projeto</th>
                    <th>Equipe</th>
                    @if ($grupo['secao'] === 'nota')<th style="width: 12%">Média</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($grupo['premiados'] as $p)
                    @php($i = $p['item'])
                    <tr>
                        @if ($grupo['secao'] === 'nota')
                            <td class="num">{{ $i['colocacao'] !== null ? $i['colocacao'].'º' : '—' }}</td>
                        @endif
                        <td>
                            @if ($i['codigo'])<strong>{{ $i['codigo'] }}</strong> · @endif{{ $i['titulo'] }}
                            <div class="menor">{{ $i['escola'] }}</div>
                            @if ($grupo['secao'] !== 'nota')
                                <div class="menor">{{ implode(' · ', array_filter([$i['categoria'], $i['area']])) }}</div>
                            @endif
                        </td>
                        <td>
                            @foreach ($i['alunos'] as $aluno)<div>{{ $aluno }}</div>@endforeach
                            @if ($i['orientador'])<div class="menor">{{ $i['orientador'] }} - Orientador(a)</div>@endif
                            @if ($i['coorientador'])<div class="menor">{{ $i['coorientador'] }} - Coorientador(a)</div>@endif
                        </td>
                        @if ($grupo['secao'] === 'nota')
                            <td>{{ \App\Services\RankingPresencialService::nota($i['media']) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
@endif
