{{-- Comunicado da mala direta. HTML de e-mail: tabela + estilo inline, que é
     o que os clientes de e-mail entendem sem brigar. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mala->assunto }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f1f7;font-family:Inter,Helvetica,Arial,sans-serif;color:#1c1b1f;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1f7;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#43157A;padding:24px 32px;">
                            <p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;letter-spacing:0.5px;">XVI FETECMS</p>
                            <p style="margin:4px 0 0;font-size:13px;color:#e5d9f2;">Feira de Tecnologia, Engenharia e Ciências de Mato Grosso do Sul</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            @php
                                // Nada aqui pode depender de uma chave do `with` do Mailable: em
                                // produção um worker de fila com a classe ANTIGA em memória
                                // renderiza este arquivo NOVO (a view sai do disco a cada envio, a
                                // classe não). Isso já derrubou uma mala inteira e, depois de
                                // remendado, passou a entregá-la SEM formatação — com as tags
                                // aparecendo como texto na caixa de entrada.
                                //
                                // Por isso o formato vem da própria linha da mala e o corpo, da
                                // propriedade pública do Mailable: as duas chegam sempre, seja qual
                                // for a versão da classe. O corpo em HTML já foi sanitizado na
                                // gravação (HtmlEmail::sanitizar), então nunca é escapado aqui.
                                $ehHtml = ($mala->formato ?? 'texto') === 'html';
                                $texto = (string) ($corpo ?? '');

                                // O callback é quem troca cada imagem pelo CID (embutida nesta
                                // mensagem) e só existe na classe nova. Sem ele o corpo vai como
                                // está: perde-se a imagem, nunca a formatação.
                                $corpoFinal = $ehHtml
                                    ? (isset($corpoHtml) && is_callable($corpoHtml) ? $corpoHtml($message) : $texto)
                                    : null;

                                $blocos = $ehHtml ? [] : ($paragrafos ?? \App\Support\MensagemEmail::paragrafos($texto));
                            @endphp

                            @if ($ehHtml)
                                <div style="font-size:15px;line-height:1.6;color:#1c1b1f;">
                                    {!! $corpoFinal !!}
                                </div>
                            @else
                                @foreach ($blocos as $paragrafo)
                                    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#1c1b1f;">{!! nl2br(e($paragrafo)) !!}</p>
                                @endforeach
                            @endif

                            <p style="margin:28px 0 0;font-size:15px;line-height:1.6;color:#1c1b1f;">
                                Equipe FETECMS<br>
                                <a href="mailto:fetecms@gmail.com" style="color:#43157A;">fetecms@gmail.com</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#f4f1f7;padding:16px 32px;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#49454f;">
                                Você recebeu esta mensagem porque tem cadastro no portal de inscrições da FETECMS.
                                Dúvidas? Responda para <a href="mailto:fetecms@gmail.com" style="color:#43157A;">fetecms@gmail.com</a>.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
