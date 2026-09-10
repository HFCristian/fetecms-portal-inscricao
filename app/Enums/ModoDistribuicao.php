<?php

namespace App\Enums;

/**
 * Como os projetos chegam à fila de trabalho do avaliador (Avaliação Online →
 * Algoritmo de distribuição). São duas maneiras, e a edição escolhe uma:
 *
 * - **Distribuição Total**: o admin roda a distribuição em massa e cada
 *   avaliador sai com a fila cheia, que fica lá esperando ele aparecer. É o
 *   comportamento histórico do portal, e continua sendo o padrão.
 *
 * - **Distribuição por Atividade**: ninguém recebe nada de antemão — a fila é
 *   montada **no login**, com os projetos que fazem sentido naquele momento, e
 *   devolvida ao bolo quando a sessão acaba. Serve para a feira em que boa
 *   parte dos cadastrados nunca entra: no modo total esses avaliadores levam
 *   projetos para o limbo, e quem está trabalhando fica sem o que avaliar.
 *
 * A **designação manual do admin** não pertence a nenhum dos dois: ela é o
 * escape do edital e sobrevive à troca de modo, à redistribuição e ao fim da
 * sessão.
 */
enum ModoDistribuicao: string
{
    case Total = 'total';
    case Atividade = 'atividade';

    public function label(): string
    {
        return match ($this) {
            self::Total => 'Distribuição Total',
            self::Atividade => 'Distribuição por Atividade',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Total => 'O admin distribui em massa e cada avaliador já encontra a fila '
                .'pronta ao entrar. As designações ficam guardadas até serem usadas.',
            self::Atividade => 'A fila é montada no momento do login e devolvida ao bolo quando '
                .'a sessão acaba. Só fica com projeto quem está de fato avaliando.',
        };
    }

    /** A distribuição em massa (Distribuir/Redistribuir) faz sentido neste modo? */
    public function distribuiEmMassa(): bool
    {
        return $this === self::Total;
    }

    /** O login do avaliador monta a fila dele neste modo? */
    public function designaNoLogin(): bool
    {
        return $this === self::Atividade;
    }

    /** O valor gravado, com o padrão histórico para edição sem configuração. */
    public static function deValor(?string $valor): self
    {
        return self::tryFrom((string) $valor) ?? self::Total;
    }

    /** @return list<array{value:string, label:string, descricao:string}> */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $m) => ['value' => $m->value, 'label' => $m->label(), 'descricao' => $m->descricao()],
            self::cases(),
        );
    }
}
