{{--
    Cabeçalho dos documentos do ensaio da avaliação presencial
    (`demo:avaliacao-presencial`). A faixa diz que o documento é fictício: o
    PDF pode ser baixado e impresso, e ninguém pode confundi-lo com o de uma
    equipe de verdade.
--}}
@include('pdf.base')
<style>
    .ficticio { background: #fff4e5; border: 1pt solid #e0a040; color: #7a4b00; font-size: 7.5pt;
                padding: 3pt 6pt; margin-bottom: 10pt; text-align: center; }
    .texto p { margin: 0 0 6pt 0; line-height: 1.45; text-align: justify; }
    .referencia { margin: 0 0 5pt 0; padding-left: 14pt; text-indent: -14pt; line-height: 1.4; }
    .destaque { background: #fff59d; font-weight: bold; }
</style>

<div class="ficticio">DOCUMENTO FICTÍCIO · ensaio da avaliação presencial · XVI FETECMS</div>

<div class="cabecalho">
    <h1>{{ $documento }}</h1>
    <div class="sub">{{ $projeto['titulo'] }} · {{ $projeto['categoria'] }} · Estudante: {{ $projeto['aluno'] }}</div>
</div>
