<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Utilitários dos e-mails transacionais do portal (confirmação de cadastro,
 * aviso de submissão): troca das variáveis `{{ nome }}` e quebra do corpo em
 * parágrafos para o layout HTML.
 *
 * As variáveis aceitam espaço dentro das chaves — quem escreve o texto no
 * painel copia e cola de tudo quanto é lugar.
 */
class MensagemEmail
{
    /**
     * Substitui `{{ chave }}` pelos valores informados. Variável desconhecida
     * fica como está, para o admin enxergar o erro de digitação no teste.
     *
     * @param  array<string, string>  $valores
     */
    public static function personalizar(string $texto, array $valores): string
    {
        foreach ($valores as $chave => $valor) {
            $texto = preg_replace(
                '/\{\{\s*'.preg_quote($chave, '/').'\s*\}\}/u',
                str_replace('$', '\\$', $valor),
                $texto,
            );
        }

        return $texto;
    }

    /**
     * Corpo em parágrafos (linha em branco separa). Cada parágrafo mantém as
     * quebras simples de linha, que o layout converte em <br>.
     *
     * @return list<string>
     */
    public static function paragrafos(string $texto): array
    {
        $blocos = preg_split('/\R{2,}/u', trim(Str::of($texto)->replace("\r\n", "\n")->toString())) ?: [];

        return array_values(array_filter(array_map('trim', $blocos), fn (string $p) => $p !== ''));
    }
}
