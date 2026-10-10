<?php

namespace App\Enums;

/**
 * Quanto da equipe de um projeto finalista já tem o termo de uso de imagem
 * (Sprint 174).
 *
 * O termo vale **por estudante** — um arquivo pode cobrir a equipe toda ou só
 * uma pessoa —, então o projeto pode estar com parte dela coberta. A falta não
 * é irregularidade: quem não enviou online apresenta a autorização no
 * credenciamento, e os rótulos falam em "pendente", nunca em "irregular".
 */
enum SituacaoTermoImagem: string
{
    /** Todos os estudantes da equipe estão cobertos. */
    case Completo = 'completo';

    /** Parte da equipe está coberta. */
    case Parcial = 'parcial';

    /** Nenhum estudante coberto ainda. */
    case Nenhum = 'nenhum';

    /** Projeto sem estudante cadastrado (cadastro manual): não há o que cobrir. */
    case SemEstudantes = 'sem_estudantes';

    public function label(): string
    {
        return match ($this) {
            self::Completo => 'Completo',
            self::Parcial => 'Parcial',
            self::Nenhum => 'Pendente',
            self::SemEstudantes => 'Sem estudantes',
        };
    }

    /** A situação a partir da contagem: quantos estão cobertos de quantos. */
    public static function de(int $cobertos, int $total): self
    {
        return match (true) {
            $total === 0 => self::SemEstudantes,
            $cobertos >= $total => self::Completo,
            $cobertos > 0 => self::Parcial,
            default => self::Nenhum,
        };
    }

    /** @return array<int, array{value:string, label:string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
