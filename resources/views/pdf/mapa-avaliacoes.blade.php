{{-- Mapa do evento: as avaliações que faltam em cada projeto do turno, em ordem de estande. --}}
@include('pdf.base')

<style>
    .cor { display: inline-block; width: 8pt; height: 8pt; border: 0.6pt solid #8f889b; vertical-align: middle; margin-right: 3pt; }
    .c-estande { width: 6%; } .c-codigo { width: 10%; } .c-projeto { width: 40%; } .c-turno { width: 5%; }
    .c-vaga { width: 11%; } .c-situacao { width: 17%; }
    .resumo td { border-bottom: none; padding: 2pt 6pt 2pt 0; font-size: 8.5pt; }
</style>

<div class="cabecalho">
    <h1>Mapa do evento — avaliações por projeto</h1>
    <div class="sub">
        {{ $painel['edicao'] }} · {{ $painel['turno_label'] }} ·
        {{-- O corte é o que dá sentido ao número: "às 14h de quinta" não é "agora". --}}
        situação em {{ $painel['corte_label'] }} ·
        {{ $painel['vagas']['comuns'] }} comum(ns) + {{ $painel['vagas']['comite'] }} do comitê por projeto
    </div>
    <div class="sub">Filtro: {{ $filtro }} · {{ count($linhas) }} de {{ $painel['total'] }} projeto(s)</div>
</div>

<table class="resumo">
    <tr>
        @foreach ($painel['categorias'] as $c)
            <td><span class="cor" style="background: {{ $c['cor'] }}; border-color: {{ $c['borda'] }};"></span>{{ $c['label'] }}: <strong>{{ $c['total'] }}</strong></td>
        @endforeach
    </tr>
</table>

@php($cores = collect($painel['categorias'])->keyBy('chave'))

@if (empty($linhas))
    <p class="vazio">Nenhum projeto neste recorte.</p>
@else
    <table>
        <thead>
            <tr>
                <th class="c-estande">Estande</th>
                <th class="c-codigo">Código</th>
                <th class="c-projeto">Projeto</th>
                <th class="c-turno">Turno</th>
                <th class="c-vaga">Comum (enviadas)</th>
                <th class="c-vaga">Comitê (enviadas)</th>
                <th class="c-situacao">Situação</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($linhas as $l)
                <tr>
                    <td class="menor">{{ $l['estande'] }}</td>
                    <td>{{ $l['codigo'] ?? '—' }}</td>
                    <td>
                        {{ $l['titulo'] }}
                        <div class="menor">
                            {{ $l['categoria'] ?? '—' }} · {{ $l['area'] ?? 'sem área' }}
                            @unless ($l['pronto']) · {{ $l['credenciado'] ? 'aguardando checagem' : 'sem credenciamento' }} @endunless
                            @if ($l['saida']) · saiu às {{ $l['saida'] }} @endif
                        </div>
                    </td>
                    <td>{{ $painel['turno'] }}</td>
                    @foreach (['comum', 'comite'] as $vaga)
                        <td>
                            {{ $l[$vaga]['enviadas'] }} de {{ $l[$vaga]['vagas'] }}
                            @if ($l[$vaga]['em_andamento'] > 0)
                                <div class="menor">{{ $l[$vaga]['em_andamento'] }} em andamento</div>
                            @endif
                        </td>
                    @endforeach
                    @php($cat = $cores[$l['situacao']])
                    <td><span class="cor" style="background: {{ $cat['cor'] }}; border-color: {{ $cat['borda'] }};"></span>{{ $l['situacao_label'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="rodape">Gerado em {{ now()->format('d/m/Y H:i') }} · a situação conta só as avaliações enviadas</div>
