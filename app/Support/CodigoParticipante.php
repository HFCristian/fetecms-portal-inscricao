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
 * coorientador; e, fora da equipe, `S` acompanhante, `I` intérprete, `V`
 * avaliador e `T` voluntário) porque os três moram em tabelas diferentes: sem ele, o aluno 45
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

    /**
     * Acompanhante aprovado de um estudante (Sprint 162). O id é o do pedido de
     * suporte, e os "três dígitos do CPF" saem do documento que ele informou.
     */
    public const PAPEL_ACOMPANHANTE = 'S';

    /**
     * Intérprete do evento (Sprint 214). Não pertence a projeto nenhum, então o
     * pedaço do projeto vai como `0`; o id é o do cadastro de intérprete.
     */
    public const PAPEL_INTERPRETE = 'I';

    /**
     * Avaliador (Sprint 218), para a etiqueta avulsa do credenciamento. Como o
     * intérprete, não pertence a projeto: projeto `0`, id da conta (`users`) e
     * os três dígitos do CPF do perfil de avaliador.
     */
    public const PAPEL_AVALIADOR = 'V';

    /**
     * Voluntário — a conta temporária de um balcão (Sprint 218). Projeto `0`,
     * id da conta (`users`) e o CPF do cadastro da conta temporária.
     */
    public const PAPEL_VOLUNTARIO = 'T';

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

        if (! preg_match('/^(\d{4})-(\d+)-(\d{3})-([AOCSIVT])(\d+)$/', $codigo, $m)) {
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

    /** O crachá é de um intérprete (e não de alguém da equipe de um projeto)? */
    public static function ehInterprete(array $lido): bool
    {
        return $lido['papel'] === self::PAPEL_INTERPRETE;
    }

    /**
     * Por que este crachá não é atendido num balcão de finalistas
     * (credenciamento e cerimônia): é de alguém que não está na equipe de um
     * projeto. Null para os papéis da equipe.
     */
    public static function recusaForaDaEquipe(array $lido): ?string
    {
        return match ($lido['papel']) {
            self::PAPEL_INTERPRETE => 'Este crachá é de intérprete. A chegada dele é registrada em Avaliação presencial → Intérpretes.',
            self::PAPEL_AVALIADOR => 'Este crachá é de avaliador(a), não de alguém da equipe de um projeto finalista.',
            self::PAPEL_VOLUNTARIO => 'Este crachá é de voluntário(a), não de alguém da equipe de um projeto finalista.',
            default => null,
        };
    }

    /** Rótulo do papel, para a etiqueta e para a tela. */
    public static function papelLabel(string $papel): string
    {
        return match (strtoupper($papel)) {
            self::PAPEL_ALUNO => 'Aluno(a)',
            self::PAPEL_ORIENTADOR => 'Orientador(a)',
            self::PAPEL_COORIENTADOR => 'Coorientador(a)',
            self::PAPEL_ACOMPANHANTE => 'Acompanhante',
            self::PAPEL_INTERPRETE => 'Intérprete',
            self::PAPEL_AVALIADOR => 'Avaliador(a)',
            self::PAPEL_VOLUNTARIO => 'Voluntário(a)',
            default => 'Participante',
        };
    }

    /**
     * Os três primeiros dígitos do CPF.
     *
     * Cadastro sem CPF (o cadastro manual aceita estudante só com o nome; o
     * acompanhante pode ter informado RG) recebe o que houver de dígitos, e
     * `000` sem nenhum — o código continua montável e o balcão identifica a
     * pessoa mesmo assim.
     */
    public static function cpf3(?string $cpf): string
    {
        $digitos = preg_replace('/\D/', '', (string) $cpf);

        return str_pad(substr((string) $digitos, 0, 3), 3, '0', STR_PAD_RIGHT);
    }
}
