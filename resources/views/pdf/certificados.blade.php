{{-- Lista de certificados gerada (Sprint 259): uma tabela por categoria, para conferir em papel. --}}
@include('pdf.base')

@php($cpf = fn ($c) => $c ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $c) : '—')

<div class="cabecalho">
    <h1>{{ $lista->nome }}</h1>
    <div class="sub">
        {{ $edicao }} · {{ array_sum(array_map(fn ($g) => count($g['linhas']), $grupos)) }} certificado(s)
        · gerada em {{ $lista->gerada_em?->format('d/m/Y H:i') }}{{ $lista->gerador ? ' por '.$lista->gerador->name : '' }}
        · arquivo de {{ $gerado_em }}
    </div>
</div>

@if (empty($grupos))
    <p class="vazio">Nenhum certificado neste recorte.</p>
@else
    @foreach ($grupos as $grupo)
        <h2>{{ $grupo['rotulo'] }} — {{ count($grupo['linhas']) }}@if ($grupo['carga_texto']) <span class="menor">· {{ $grupo['carga_texto'] }}</span>@endif</h2>
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th style="width: 13%">CPF</th>
                    <th>Função · Atividade</th>
                    <th style="width: 8%">Carga</th>
                    <th style="width: 15%">Período</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($grupo['linhas'] as $l)
                    <tr>
                        <td>
                            {{ $l['nome'] }}
                            @if ($l['corrigido'])<div class="menor">cadastro: {{ $l['nome_original'] }}</div>@endif
                            @if ($l['email'])<div class="menor">{{ $l['email'] }}</div>@endif
                        </td>
                        <td>{{ $cpf($l['cpf']) }}</td>
                        <td>
                            {{ $l['funcao'] }} · {{ $l['tipo_atividade'] }}
                            <div class="menor">{{ $l['titulo'] }}{{ $l['campo_generico'] ? ' · '.$l['campo_generico'] : '' }}</div>
                        </td>
                        <td><strong>{{ $l['horas'] }}h</strong>@if ($l['teto_aplicado'])<div class="menor">no limite</div>@endif</td>
                        <td>{{ $l['data_inicio'] ?? '—' }}{{ $l['data_fim'] ? ' a '.$l['data_fim'] : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <div class="rodape">
        Carga horária em horas inteiras, arredondadas para cima. "No limite" marca quem chegou ao teto de horas da categoria.
    </div>
@endif
