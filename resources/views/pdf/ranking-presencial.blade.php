{{-- Ranking presencial: a média ponderada do estande, no recorte da tela (Sprint 250). --}}
@include('pdf.base')

@php($rotulo = fn ($n) => str_pad((string) $n, 3, '0', STR_PAD_LEFT))

<div class="cabecalho">
    <h1>Ranking presencial</h1>
    <div class="sub">
        {{ $edicao }} · {{ $recorte }} · {{ count($linhas) }} projeto(s) · gerado em {{ $gerado_em }}
    </div>
</div>

@if (empty($linhas))
    <p class="vazio">Nenhum projeto com avaliação presencial enviada neste recorte.</p>
@else
    <table>
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Projeto</th>
                <th>Área / Categoria</th>
                <th style="width: 16%">Média</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($linhas as $l)
                <tr>
                    <td class="num">{{ $l['posicao'] }}º</td>
                    <td>
                        @if ($l['codigo'])<strong>{{ $l['codigo'] }}</strong> · @endif{{ $l['titulo'] }}
                        @if ($l['estande'] !== null)
                            <div class="menor">Estande {{ $rotulo($l['estande']) }}{{ $l['turno_label'] ? ' · '.$l['turno_label'] : '' }}</div>
                        @endif
                    </td>
                    <td>
                        {{ $l['area'] ?? 'Sem área' }}
                        <div class="menor">{{ $l['categoria'] ?? '—' }}</div>
                    </td>
                    <td>
                        <strong>{{ \App\Services\RankingPresencialService::nota($l['media']) }}</strong>
                        <span class="menor">/10</span>
                        <div class="menor">
                            {{ $l['comuns'] }} comum · {{ $l['comite'] }} comissão{{ $l['parcial'] ? ' · parcial' : '' }}
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="rodape">
        A nota da comissão especial pesa {{ str_replace('.', ',', (string) $peso_comite) }} na média.
        "Parcial" marca o projeto que ainda não teve todas as vagas preenchidas — a média dele pode mudar, e com ela a posição.
    </div>
@endif
