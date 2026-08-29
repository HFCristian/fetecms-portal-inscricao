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
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->mala->anexos->map(function (MalaDiretaArquivo $arquivo) {
            return Attachment::fromStorageDisk($arquivo->disk, $arquivo->path)
                ->as($arquivo->nome_original)
                ->withMime($arquivo->mime ?? 'application/octet-stream');
        })->all();
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
