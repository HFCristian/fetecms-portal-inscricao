{{-- Termo de ética do ensaio (1.1 e 1.2): o TCLE/TALE do projeto com estudantes. --}}
@include('pdf.demo._cabecalho', ['documento' => 'Termos de Consentimento e Assentimento'])

<div class="texto">
    <p>{{ $projeto['etica']['texto'] }}</p>

    <h2>Termo de Consentimento Livre e Esclarecido (TCLE)</h2>
    <p>
        Eu, responsável legal pelo(a) estudante, autorizo a participação dele(a) na pesquisa
        "{{ $projeto['titulo'] }}", ciente de que as respostas não serão identificadas e de que a
        participação pode ser interrompida a qualquer momento, sem prejuízo.
    </p>
    <p>Assinatura do responsável: ______________________ &nbsp; Data: ___/___/2026</p>

    <h2>Termo de Assentimento Livre e Esclarecido (TALE)</h2>
    <p>
        Eu, estudante, entendi o que vai ser feito na pesquisa e aceito participar. Sei que posso
        desistir quando quiser.
    </p>
    <p>Assinatura do(a) estudante: ______________________ &nbsp; Data: ___/___/2026</p>
</div>
