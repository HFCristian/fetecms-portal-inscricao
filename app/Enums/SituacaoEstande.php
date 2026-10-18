<?php

namespace App\Enums;

/**
 * Por onde o projeto de um estande já passou no dia do evento.
 *
 * É a régua que pinta o mapa: o admin olha a planta de longe e vê o ginásio
 * mudar de cor conforme a feira acontece, sem abrir estande por estande.
 *
 * Os estágios são cumulativos e saem todos de fatos que o portal já registrava
 * com hora — credenciamento, checagem do estande e avaliação presencial —, e é
 * por isso que o mapa nunca discorda das outras abas. A exceção é a **saída
 * antes do horário** (Sprint 243): não há balcão na porta de saída, então a
 * organização a marca à mão, e ela vale só no dia em que aconteceu.
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

    /** A equipe chegou e foi embora antes do fim do turno, neste dia. */
    case Saiu = 'saiu';

    public function label(): string
    {
        return match ($this) {
            self::Livre => 'Sem projeto',
            self::Aguardando => 'Aguardando credenciamento',
            self::Credenciado => 'Credenciado',
            self::Checado => 'Pronto para avaliação',
            self::Avaliado => 'Em avaliação',
            self::Saiu => 'Saiu antes do horário',
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
            // Cinza-escuro: o estande ficou vazio, e nenhuma cor do evento
            // ("bom" ou "falta") pode ser confundida com ele.
            self::Saiu => '#5c5866',
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
