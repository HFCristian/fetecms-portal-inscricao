<?php

namespace App\Support;

/**
 * O código de identificação de um participante da lista final — o que vai no QR
 * Code e no código de barras usados no evento.
 *
 * Formato: `ano-projeto-cpf3-papelId`, por exemplo **`2026-31-123-A45`**:
 *
 * | pedaço | de onde vem | por quê |
 * |--------|-------------|---------|
 * | `2026` | ano da edição | separa quem é da feira deste ano de quem é de outra |
 * | `31`   | id do projeto | leva direto ao trabalho, sem consultar nome |
 * | `123`  | 3 primeiros dígitos do CPF | confere a pessoa sem expor o documento |
 * | `A45`  | papel + id do participante | identifica a pessoa sem ambiguidade |
 *
 * O **papel é um prefixo obrigatório** (`A` aluno, `O` orientador, `C`
 * coorientador) porque os três moram em tabelas diferentes: sem ele, o aluno 45
 * e o coorientador 45 teriam o mesmo código.
 *
 * O que **não** entra no código, de propósito: o número do projeto na lista
 * final. Ele é refeito toda vez que a composição da lista muda de versão, e um
 * crachá impresso antes passaria a apontar para outro lugar. Tudo que está aqui
 * é estável pela vida inteira do cadastro.
 *
 * O CPF entra com **três dígitos**: é conferência, não identificação — três
 * algarismos não localizam ninguém, e bastam para o balcão perceber que pegou o
 * crachá da pessoa errada.
 */
final class CodigoParticipante
{
    public const PAPEL_ALUNO = 'A';

    public const PAPEL_ORIENTADOR = 'O';

    public const PAPEL_COORIENTADOR = 'C';

    /** Monta o código de um participante. */
    public static function montar(int $ano, int $projetoId, ?string $cpf, string $papel, int $participanteId): string
    {
        return implode('-', [
            $ano,
            $projetoId,
            self::cpf3($cpf),
            $papel.$participanteId,
        ]);
    }

    /**
     * Lê um código de volta. Devolve null para qualquer coisa fora do formato —
     * o leitor do balcão vai encontrar etiqueta amassada e QR de outro evento.
     *
     * @return array{ano:int, projeto_id:int, cpf3:string, papel:string, participante_id:int}|null
     */
    public static function ler(string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));

        if (! preg_match('/^(\d{4})-(\d+)-(\d{3})-([AOC])(\d+)$/', $codigo, $m)) {
            return null;
        }

        return [
            'ano' => (int) $m[1],
            'projeto_id' => (int) $m[2],
            'cpf3' => $m[3],
            'papel' => $m[4],
            'participante_id' => (int) $m[5],
        ];
    }

    /** Rótulo do papel, para a etiqueta e para a tela. */
    public static function papelLabel(string $papel): string
    {
        return match (strtoupper($papel)) {
            self::PAPEL_ALUNO => 'Aluno(a)',
            self::PAPEL_ORIENTADOR => 'Orientador(a)',
            self::PAPEL_COORIENTADOR => 'Coorientador(a)',
            default => 'Participante',
        };
    }

    /**
     * Os três primeiros dígitos do CPF.
     *
     * Cadastro sem CPF (não deveria existir — a coluna é obrigatória nos três
     * papéis) recebe `000`, para o código continuar montável e o balcão
     * conseguir identificar a pessoa mesmo assim.
     */
    private static function cpf3(?string $cpf): string
    {
        $digitos = preg_replace('/\D/', '', (string) $cpf);

        return str_pad(substr((string) $digitos, 0, 3), 3, '0', STR_PAD_RIGHT);
    }
}
