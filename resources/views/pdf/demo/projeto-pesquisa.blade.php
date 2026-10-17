{{-- Projeto de pesquisa do ensaio: o que a equipe levaria impresso (2.1 e 2.2). --}}
@include('pdf.demo._cabecalho', ['documento' => 'Projeto de Pesquisa'])

<div class="texto">
    <h2>Resumo</h2>
    <p>{{ $projeto['resumo'] }}</p>
    <p><strong>Palavras-chave:</strong> {{ implode('; ', $projeto['palavras_chave']) }}.</p>

    @foreach ($projeto['secoes'] as $titulo => $texto)
        <h2>{{ $titulo }}</h2>
        @foreach (explode("\n", $texto) as $paragrafo)
            <p>{{ $paragrafo }}</p>
        @endforeach
    @endforeach

    <h2>Referências</h2>
    @foreach ([...$projeto['referencias']['principais'], ...$projeto['referencias']['demais']] as $referencia)
        <p class="referencia">{{ $referencia }}</p>
    @endforeach
</div>
