XVI FETECMS

@php
    // Mesma blindagem da versão HTML: as chaves do `with` do Mailable podem
    // faltar quando o worker da fila está com a classe antiga em memória, e aí
    // o corpo cru sairia com as tags à mostra. `$mala` e `$corpo` são
    // propriedades públicas do Mailable, então chegam sempre.
    $ehHtml = ($mala->formato ?? 'texto') === 'html';
    $simples = $textoSimples
        ?? ($ehHtml ? \App\Support\HtmlEmail::paraTexto((string) $corpo) : (string) $corpo);
@endphp
{{ $simples }}

--
Equipe FETECMS
fetecms@gmail.com

Você recebeu esta mensagem porque tem cadastro no portal de inscrições da FETECMS.
