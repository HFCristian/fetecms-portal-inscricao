<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Limpeza do HTML que o editor da mala direta produz.
 *
 * O corpo escrito no painel vira e-mail, então só passa daqui uma lista curta
 * de tags e atributos: formatação (negrito, itálico, sublinhado, traçado),
 * parágrafos, listas, links e as imagens do próprio portal. Tudo o mais —
 * script, style, iframe, on*, javascript: — some, mesmo vindo de um admin.
 */
class HtmlEmail
{
    /** @var array<string, list<string>> tag => atributos permitidos */
    private const PERMITIDAS = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'strike' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height', 'data-arquivo-id'],
    ];

    /** Tags cujo conteúdo também some (não é texto, é código). */
    private const DESCARTAVEIS = ['script', 'style', 'iframe', 'object', 'embed', 'noscript'];

    public static function sanitizar(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        // O wrapper força UTF-8 e evita o <html><body> implícito do parser.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="fetec-raiz">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $raiz = $doc->getElementById('fetec-raiz');

        if ($raiz === null) {
            return '';
        }

        self::limpar($raiz);

        $saida = '';
        foreach ($raiz->childNodes as $filho) {
            $saida .= $doc->saveHTML($filho);
        }

        return trim($saida);
    }

    /** Texto puro do corpo, para a versão text/plain do e-mail. */
    public static function paraTexto(string $html): string
    {
        $com = preg_replace(
            [
                '/<\s*(script|style)[^>]*>.*?<\/\s*\1\s*>/is',
                '/<\s*br\s*\/?>/i',
                '/<\/\s*li\s*>/i',
                '/<\/\s*(p|div|h[1-6]|ul|ol)\s*>/i',
                '/<\s*li[^>]*>/i',
            ],
            ['', "\n", "\n", "\n\n", '- '],
            $html,
        );

        $texto = html_entity_decode(strip_tags((string) $com), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $texto) ?? $texto);
    }

    /** Percorre a árvore removendo tag e atributo fora da lista. */
    private static function limpar(DOMNode $no): void
    {
        foreach (iterator_to_array($no->childNodes) as $filho) {
            if (! $filho instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($filho->nodeName);

            if (in_array($tag, self::DESCARTAVEIS, true)) {
                $no->removeChild($filho);

                continue;
            }

            if (! array_key_exists($tag, self::PERMITIDAS)) {
                // Tag proibida some, mas o texto dentro dela fica.
                self::limpar($filho);
                while ($filho->firstChild) {
                    $no->insertBefore($filho->firstChild, $filho);
                }
                $no->removeChild($filho);

                continue;
            }

            foreach (iterator_to_array($filho->attributes) as $atributo) {
                $nome = strtolower($atributo->nodeName);

                if (! in_array($nome, self::PERMITIDAS[$tag], true) || ! self::valorSeguro($nome, $atributo->nodeValue)) {
                    $filho->removeAttribute($atributo->nodeName);
                }
            }

            self::limpar($filho);
        }
    }

    /** URL de href/src: só http(s), mailto e as rotas do próprio portal. */
    private static function valorSeguro(string $atributo, ?string $valor): bool
    {
        if (! in_array($atributo, ['href', 'src'], true)) {
            return true;
        }

        $valor = trim((string) $valor);

        return $valor !== '' && preg_match('#^(https?://|mailto:|/)#i', $valor) === 1;
    }
}
