<?php

namespace App\Enums;

/**
 * Por onde o projeto de um estande já passou no dia do evento.
 *
 * É a régua que pinta o mapa: o admin olha a planta de longe e vê o ginásio
 * mudar de cor conforme a feira acontece, sem abrir estande por estande.
 *
 * Os estágios são cumulativos e saem todos de fatos que o portal já registrava
 * com hora — credenciamento, checagem do estande e avaliação presencial. Nada
 * aqui é marcado à mão, e é por isso que o mapa nunca discorda das outras abas.
 */
enum SituacaoEstande: string
{
    /** Nenhum projeto alocado neste estande, neste turno. */
    case Livre = 'livre';

    /** Tem projeto, mas a equipe ainda não passou pelo balcão. */
    case Aguardando = 'aguardando';

    /** Passou pelo credenciamento. */
    case Credenciado = 'credenciado';

    /** O estande foi conferido — o projeto está **pronto para ser avaliado**. */
    case Checado = 'checado';

    /** Já recebeu ao menos uma avaliação presencial. */
    case Avaliado = 'avaliado';

    public function label(): string
    {
        return match ($this) {
            self::Livre => 'Sem projeto',
            self::Aguardando => 'Aguardando credenciamento',
            self::Credenciado => 'Credenciado',
            self::Checado => 'Pronto para avaliação',
            self::Avaliado => 'Em avaliação',
        };
    }

    /**
     * A cor do estande no mapa. Sai daqui, e não do front, porque a legenda da
     * tela, o PDF da lista e o desenho precisam concordar — três paletas
     * separadas divergiriam na primeira mudança.
     */
    public function cor(): string
    {
        return match ($this) {
            self::Livre => '#ffffff',
            self::Aguardando => '#efe7fa',
            self::Credenciado => '#c9b6e8',
            self::Checado => '#9fd8ae',
            self::Avaliado => '#2f8f4e',
        };
    }

    /** @return array<int, array{value:string, label:string, cor:string}> */
    public static function legenda(): array
    {
        return array_map(fn (self $s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'cor' => $s->cor(),
        ], self::cases());
    }
}
