<?php

namespace App\Enums;

/**
 * Situação de cada destinatário dentro de uma mala direta.
 *
 * - pendente: ainda na fila;
 * - enviado: entregue ao servidor de e-mail sem erro;
 * - falha: o envio foi tentado e o servidor recusou (motivo em `erro`);
 * - invalido: e-mail malformado — nem chega a ser enfileirado, mas aparece
 *   no relatório para o admin corrigir a lista.
 */
enum StatusDestinatario: string
{
    case Pendente = 'pendente';
    case Enviado = 'enviado';
    case Falha = 'falha';
    case Invalido = 'invalido';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Na fila',
            self::Enviado => 'Enviado',
            self::Falha => 'Falha no envio',
            self::Invalido => 'E-mail inválido',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
