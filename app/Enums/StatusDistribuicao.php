<?php

namespace App\Enums;

/** Em que pé está uma rodada de distribuição (a barra de progresso lê daqui). */
enum StatusDistribuicao: string
{
    case Pendente = 'pendente';
    case Processando = 'processando';
    case Concluida = 'concluida';
    case Falha = 'falha';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Na fila',
            self::Processando => 'Distribuindo',
            self::Concluida => 'Concluída',
            self::Falha => 'Falhou',
        };
    }

    /** Já terminou (com sucesso ou não)? A tela para de consultar aqui. */
    public function finalizada(): bool
    {
        return in_array($this, [self::Concluida, self::Falha], true);
    }
}
