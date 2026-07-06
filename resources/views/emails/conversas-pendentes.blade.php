<x-mail::message>
# Mensagens de suporte aguardando resposta

Bom dia! Há **{{ $quantidade }}** {{ $quantidade === 1 ? 'mensagem' : 'mensagens' }} de orientadores/avaliadores no chat de suporte ainda **sem resposta**.

<x-mail::button :url="url('/admin/suporte')">
Abrir o painel de suporte
</x-mail::button>

Acesse o painel do administrador para visualizar e responder.

Equipe FETECMS<br>
fetecms@gmail.com
</x-mail::message>
