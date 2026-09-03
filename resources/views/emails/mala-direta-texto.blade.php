XVI FETECMS

{{-- Mesma blindagem da versão HTML: `$textoSimples` é uma chave do `with` do
     Mailable e pode faltar quando o worker da fila está com a classe antiga em
     memória. `$corpo` é propriedade pública, então chega sempre. --}}
{{ $textoSimples ?? $corpo ?? '' }}

--
Equipe FETECMS
fetecms@gmail.com

Você recebeu esta mensagem porque tem cadastro no portal de inscrições da FETECMS.
