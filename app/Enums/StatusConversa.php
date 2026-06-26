<?php

namespace App\Enums;

/**
 * Status de uma conversa do chat de suporte.
 *
 * Fluxo: nao_visualizada → visualizada (admin abre) → em_tratamento → respondida
 * (admin responde no painel) → arquivada. Uma nova mensagem do usuário em uma
 * conversa já respondida/arquivada a reabre como `nao_visualizada`.
 *
 * Agrupamento no painel do admin:
 * - "Não respondida": nao_visualizada, visualizada, em_tratamento (lista aberta);
 * - "Respondida": respondida;
 * - "Arquivada": arquivada.
 *
 * O alerta diário (07:00) conta apenas nao_visualizada + visualizada (em_tratamento
 * já está sendo cuidada por um admin).
 */
enum StatusConversa: string
{
    case NaoVisualizada = 'nao_visualizada';
    case Visualizada = 'visualizada';
    case EmTratamento = 'em_tratamento';
    case Respondida = 'respondida';
    case Arquivada = 'arquivada';

    public function label(): string
    {
        return match ($this) {
            self::NaoVisualizada => 'Não visualizada',
            self::Visualizada => 'Visualizada',
            self::EmTratamento => 'Em tratamento',
            self::Respondida => 'Respondida',
            self::Arquivada => 'Arquivada',
        };
    }

    /** Conversa ainda sem resposta (qualquer status do grupo "não respondida"). */
    public function naoRespondida(): bool
    {
        return in_array($this, [self::NaoVisualizada, self::Visualizada, self::EmTratamento], true);
    }

    /** Conta para o alerta diário enviado ao suporte. */
    public function pendenteAlerta(): bool
    {
        return in_array($this, [self::NaoVisualizada, self::Visualizada], true);
    }

    /** @return array<int, self> Status que compõem o grupo "não respondida". */
    public static function naoRespondidas(): array
    {
        return [self::NaoVisualizada, self::Visualizada, self::EmTratamento];
    }

    /** @return array<int, self> Status considerados no alerta diário ao suporte. */
    public static function pendentesAlerta(): array
    {
        return [self::NaoVisualizada, self::Visualizada];
    }
}
