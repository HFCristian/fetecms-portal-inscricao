<?php

namespace App\Mail;

use App\Models\MalaDireta;
use App\Models\MalaDiretaArquivo;
use App\Support\HtmlEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Message;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A mensagem de uma mala direta. O corpo chega já personalizado (variáveis
 * trocadas pelo MalaDiretaService); aqui só entra no layout da FETECMS.
 *
 * Duas formas de corpo convivem: **texto puro** (malas antigas e a API), que é
 * quebrado em parágrafos, e **HTML** do editor do painel, que vai como está —
 * já sanitizado na gravação. As imagens do corpo viajam **embutidas** (CID), e
 * não por link: e-mail com imagem remota costuma ser bloqueado.
 *
 * O "solicitante" da mala é metadado interno: não aparece para o destinatário.
 */
class MalaDiretaMensagem extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MalaDireta $mala,
        public string $corpo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mala->assunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mala-direta',
            text: 'emails.mala-direta-texto',
            with: [
                'html' => $this->mala->ehHtml(),
                'paragrafos' => $this->mala->ehHtml() ? [] : $this->paragrafos(),
                // O corpo HTML só ganha os CIDs na hora de montar a mensagem (é
                // o $message que sabe embutir), então a view chama este callback.
                'corpoHtml' => fn ($message) => $this->corpoComImagens($message),
                'textoSimples' => $this->mala->ehHtml() ? HtmlEmail::paraTexto($this->corpo) : $this->corpo,
            ],
        );
    }

    /**
     * Anexos do e-mail. As imagens do corpo NÃO entram aqui: elas são embutidas
     * no HTML pelo withSymfonyMessage, com o CID no lugar do src.
     *
     * O conteúdo é lido **aqui**, e não deixado a cargo do
     * `Attachment::fromStorageDisk()`. O disco privado do portal roda com
     * `throw => false`, então um arquivo que sumiu do storage devolve vazio em
     * silêncio: o e-mail sai com um anexo de 0 byte, o relatório diz "enviado" e
     * o destinatário recebe uma mensagem que promete o edital e não o entrega.
     * Foi assim que a mala direta chegou sem anexo em produção.
     *
     * Ler e conferir transforma esse silêncio em **falha**: o job estoura, o
     * destinatário aparece como falha no relatório com o motivo, e *Reenviar
     * falhas* resolve depois que o storage for consertado. Um e-mail que não sai
     * é melhor do que um que sai mentindo.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->mala->anexos->map(function (MalaDiretaArquivo $arquivo) {
            $conteudo = self::conteudoDoAnexo($arquivo);

            return Attachment::fromData(fn () => $conteudo, $arquivo->nome_original)
                ->withMime($arquivo->mime ?: 'application/octet-stream');
        })->all();
    }

    /**
     * O conteúdo de um anexo, ou uma exceção que diz exatamente o que faltou.
     *
     * Ausente é `null` — o disco com `throw => false` devolve isso para arquivo
     * que não existe. Arquivo genuinamente **vazio** devolve `''` e passa: se o
     * admin anexou um arquivo de 0 byte de propósito, o problema é dele, e
     * recusar por palpite barraria disparo legítimo.
     */
    public static function conteudoDoAnexo(MalaDiretaArquivo $arquivo): string
    {
        $conteudo = Storage::disk($arquivo->disk)->get($arquivo->path);

        if ($conteudo === null) {
            throw new RuntimeException(
                'O anexo "'.$arquivo->nome_original.'" não está no storage ('
                    .$arquivo->disk.':'.$arquivo->path.'). '
                    .'O e-mail não foi enviado para não sair sem ele. '
                    .'Confira se storage/app/private sobreviveu ao deploy e se a fila roda na mesma máquina do upload.'
            );
        }

        return $conteudo;
    }

    /**
     * Troca cada `<img data-arquivo-id="N">` do corpo pelo CID da imagem
     * embutida. Imagem que não pertence a esta mala (ou sumiu do disco) é
     * removida do corpo, para o e-mail não sair com um quadrado quebrado.
     */
    public function corpoComImagens(Message $message): string
    {
        $corpo = $this->corpo;

        foreach ($this->mala->imagens as $imagem) {
            $caminho = Storage::disk($imagem->disk)->path($imagem->path);
            $marcador = 'data-arquivo-id="'.$imagem->id.'"';

            if (! str_contains($corpo, $marcador) || ! is_file($caminho)) {
                continue;
            }

            $cid = $message->embed($caminho);
            // Substitui o src da tag que carrega este marcador.
            $corpo = preg_replace_callback(
                '/<img\b[^>]*'.preg_quote($marcador, '/').'[^>]*>/i',
                fn (array $m) => preg_replace('/\bsrc="[^"]*"/i', 'src="'.$cid.'"', $m[0]),
                $corpo,
            );
        }

        // O que sobrou apontando para o portal não abriria na caixa de entrada.
        return preg_replace('/<img\b[^>]*src="\/[^"]*"[^>]*>/i', '', $corpo) ?? $corpo;
    }

    /**
     * Quebra o texto em parágrafos (linha em branco separa) para o HTML não
     * sair como um bloco só. Só vale para o corpo em texto puro.
     *
     * @return array<int, string>
     */
    public function paragrafos(): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", trim($this->corpo));

        return array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}/', $texto) ?: []),
            fn (string $p) => $p !== '',
        ));
    }
}
