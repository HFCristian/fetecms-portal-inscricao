<?php

namespace App\Enums;

/**
 * Públicos-alvo de uma mala direta (painel do admin). O admin pode marcar
 * quantos quiser: a união é deduplicada por e-mail antes do disparo.
 *
 * Todos os públicos consideram apenas contas ATIVAS e NÃO demo — conta de
 * teste não recebe comunicado de edital. Admins ficam de fora de "todos os
 * usuários"; para falar com eles existe a lista personalizada.
 */
enum PublicoMala: string
{
    case Todos = 'todos';
    case Orientadores = 'orientadores';
    case Avaliadores = 'avaliadores';
    case OrientadoresRascunho = 'orientadores_rascunho';
    case OrientadoresSubmetidos = 'orientadores_submetidos';
    case AvaliadoresPendentes = 'avaliadores_pendentes';
    case AvaliadoresConcluidas = 'avaliadores_concluidas';

    public function label(): string
    {
        return match ($this) {
            self::Todos => 'Todos os usuários',
            self::Orientadores => 'Todos os orientadores',
            self::Avaliadores => 'Todos os avaliadores',
            self::OrientadoresRascunho => 'Orientadores com projeto em rascunho',
            self::OrientadoresSubmetidos => 'Orientadores com projetos submetidos',
            self::AvaliadoresPendentes => 'Avaliadores com avaliações pendentes',
            self::AvaliadoresConcluidas => 'Avaliadores com avaliações concluídas',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Todos => 'Orientadores e avaliadores com conta ativa.',
            self::Orientadores => 'Toda conta de orientador ativa, com ou sem projeto.',
            self::Avaliadores => 'Toda conta de avaliador ativa, designada ou não.',
            self::OrientadoresRascunho => 'Tem ao menos um projeto ainda em rascunho.',
            self::OrientadoresSubmetidos => 'Já submeteu ao menos um projeto.',
            self::AvaliadoresPendentes => 'Abriu uma avaliação e ainda não concluiu.',
            self::AvaliadoresConcluidas => 'Já concluiu ao menos uma avaliação.',
        };
    }

    /** Papel predominante do público — usado só para agrupar a tela. */
    public function role(): Role
    {
        return match ($this) {
            self::Orientadores, self::OrientadoresRascunho, self::OrientadoresSubmetidos => Role::Orientador,
            self::Avaliadores, self::AvaliadoresPendentes, self::AvaliadoresConcluidas => Role::Avaliador,
            self::Todos => Role::Orientador,
        };
    }

    /** @return array<int, array{value: string, label: string, descricao: string}> */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'descricao' => $p->descricao(),
            ],
            self::cases(),
        );
    }
}
