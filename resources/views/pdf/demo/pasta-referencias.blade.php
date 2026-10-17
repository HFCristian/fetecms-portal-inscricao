{{--
    Pasta de referências do ensaio (4.1 a 4.4): as 5 principais impressas e
    destacadas, e as demais impressas ou por QR Code, conforme o projeto.
--}}
@include('pdf.demo._cabecalho', ['documento' => 'Pasta de Referências'])

<h2>As 5 principais referências (impressas)</h2>
<div class="texto">
    @foreach ($projeto['referencias']['principais'] as $referencia)
        <p class="referencia">
            {{-- Com as demais impressas, as principais precisam estar destacadas (4.3). --}}
            @if ($projeto['referencias']['demais_qr'])
                {{ $referencia }}
            @else
                <span class="destaque">{{ $referencia }}</span>
            @endif
        </p>
    @endforeach
</div>

<h2>Demais referências</h2>
@if ($projeto['referencias']['demais_qr'])
    <p>
        As demais referências estão disponíveis por <strong>QR Code</strong>, colado na contracapa da
        pasta física ({{ count($projeto['referencias']['demais']) }} referência(s)).
    </p>
@else
    <div class="texto">
        @foreach ($projeto['referencias']['demais'] as $referencia)
            <p class="referencia">{{ $referencia }}</p>
        @endforeach
    </div>
@endif
