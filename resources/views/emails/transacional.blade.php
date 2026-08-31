{{-- E-mail transacional (confirmação de cadastro, projeto submetido). HTML de
     e-mail: tabela + estilo inline, que é o que os clientes entendem.

     O corpo chega de duas formas: texto puro (quebrado em parágrafos aqui) ou
     HTML do editor rico dos Modelos de e-mail — já sanitizado na gravação e,
     quando há código de 6 dígitos, com o parágrafo dele já destacado. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $assunto }}</title>
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
                            @if ($html ?? false)
                                <div style="font-size:15px;line-height:1.6;color:#1c1b1f;">
                                    {!! $corpoHtml !!}
                                </div>
                            @else
                                @foreach ($paragrafos as $paragrafo)
                                    @if ($destaque !== null && trim($paragrafo) === $destaque)
                                        {{-- O parágrafo que é só o código vira o bloco grande. --}}
                                        <p style="margin:0 0 16px;padding:16px;background-color:#f4f1f7;border-radius:12px;text-align:center;font-family:'Courier New',Courier,monospace;font-size:32px;font-weight:700;letter-spacing:8px;color:#43157A;">{{ $paragrafo }}</p>
                                    @else
                                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#1c1b1f;">{!! nl2br(e($paragrafo)) !!}</p>
                                    @endif
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
                                Mensagem automática do portal de inscrições da FETECMS.
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
