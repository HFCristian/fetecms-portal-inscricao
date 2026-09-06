XVI FETECMS

@php
    // Mesma blindagem da versão HTML: sem `$textoSimples` (worker com a classe
    // antiga em memória) o corpo cru sairia com as tags à mostra.
    $simples = $textoSimples
        ?? ((($formato ?? 'texto') === 'html') ? \App\Support\HtmlEmail::paraTexto((string) $corpo) : (string) $corpo);
@endphp
{{ $simples }}

--
Equipe FETECMS
fetecms@gmail.com

Mensagem automática do portal de inscrições da FETECMS.
