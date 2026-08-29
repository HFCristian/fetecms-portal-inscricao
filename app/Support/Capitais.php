<?php

namespace App\Support;

/**
 * As 27 capitais brasileiras, por UF. Serve para marcar `cidades.capital` — e
 * é essa marca que define o "interior" na lista final: interior é a cidade que
 * não é a capital do próprio estado.
 */
class Capitais
{
    /** @return array<string, string> */
    public static function porUf(): array
    {
        return [
            'AC' => 'Rio Branco',
            'AL' => 'Maceió',
            'AM' => 'Manaus',
            'AP' => 'Macapá',
            'BA' => 'Salvador',
            'CE' => 'Fortaleza',
            'DF' => 'Brasília',
            'ES' => 'Vitória',
            'GO' => 'Goiânia',
            'MA' => 'São Luís',
            'MG' => 'Belo Horizonte',
            'MS' => 'Campo Grande',
            'MT' => 'Cuiabá',
            'PA' => 'Belém',
            'PB' => 'João Pessoa',
            'PE' => 'Recife',
            'PI' => 'Teresina',
            'PR' => 'Curitiba',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Natal',
            'RO' => 'Porto Velho',
            'RR' => 'Boa Vista',
            'RS' => 'Porto Alegre',
            'SC' => 'Florianópolis',
            'SE' => 'Aracaju',
            'SP' => 'São Paulo',
            'TO' => 'Palmas',
        ];
    }
}
