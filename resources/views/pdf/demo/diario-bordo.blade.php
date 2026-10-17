{{--
    Diário de bordo do ensaio (3.1 e 3.2). Na feira ele é manuscrito; aqui é a
    cópia digitalizada que o avaliador abre no portal.
--}}
@include('pdf.demo._cabecalho', ['documento' => 'Diário de Bordo'])

<p class="menor">Cópia digitalizada do caderno manuscrito da equipe.</p>

<table>
    <thead><tr><th style="width: 18%">Data</th><th>Registro</th></tr></thead>
    <tbody>
        @foreach ($projeto['diario'] as [$data, $registro])
            <tr>
                <td>{{ \Carbon\CarbonImmutable::parse($data)->format('d/m/Y') }}</td>
                <td>{{ $registro }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
