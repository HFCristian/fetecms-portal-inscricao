<?php

namespace App\Support;

use App\Enums\Turno;
use App\Enums\VagaPresencial;
use App\Models\Edicao;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * A agenda da **avaliação presencial** (Sprints 169 e 224): em que dias e
 * horários cada turno acontece, e quando o avaliador pode avaliar nele.
 *
 * Os dias são os da janela do evento (`evento_de` → `evento_ate`), e cada dia
 * tem os seus turnos (`edicoes.agenda_presencial`, editada em Parametrização →
 * Datas e períodos). Cada par dia × turno é uma **ocorrência**, com dois pares
 * de horas: o **do turno** (quando a equipe apresenta no estande) e o **da
 * avaliação** (quando o avaliador avalia). Turno sem horário num dia não
 * acontece naquele dia.
 *
 * Começar uma avaliação vale do início da avaliação até o fim dela ou do turno,
 * o que vier antes — sem a equipe no estande não há o que avaliar. Escrever
 * vale até o **fim da avaliação**, que é o prazo: o que passa do fim do turno é
 * **margem**, para terminar o que se abriu (quem decide começar é o fluxo, que
 * pergunta {@see emAndamento()}). Avaliação em branco vai do início do turno até
 * 30 minutos depois do fim, a regra de antes da Sprint 224.
 */
final class JanelaTurnos
{
    /**
     * Minutos depois do fim do turno em que a avaliação ainda é aceita, quando
     * a organização não define o horário da avaliação.
     */
    public const MARGEM_MINUTOS = 30;

    /** Quantos projetos cada avaliador recebe por turno, sem configuração. */
    public const PADRAO_FILA = 5;

    /** Avaliações de avaliadores comuns por projeto, sem configuração. */
    public const PADRAO_COMUNS = 2;

    /** Avaliações da comissão especial por projeto, sem configuração. */
    public const PADRAO_COMITE = 1;

    /** Quanto a avaliação da comissão especial pesa na média, sem configuração. */
    public const PADRAO_PESO_COMITE = 2.0;

    /** Quantas avaliações presenciais cada projeto recebe, sem configuração. */
    public const PADRAO_POR_PROJETO = self::PADRAO_COMUNS + self::PADRAO_COMITE;

    /**
     * @param  array<string, array<string, array{inicio: string, fim: string, avaliacao_inicio: string, avaliacao_fim: string}>>  $agenda
     */
    private function __construct(
        private readonly ?CarbonImmutable $de,
        private readonly ?CarbonImmutable $ate,
        private readonly array $agenda,
        private readonly int $fila,
        private readonly int $comuns,
        private readonly int $comite,
        private readonly float $pesoComite,
    ) {}

    public static function daEdicao(?Edicao $edicao = null): self
    {
        $edicao ??= Edicao::atual();

        return new self(
            $edicao?->evento_de?->toImmutable(),
            $edicao?->evento_ate?->toImmutable(),
            self::normalizar($edicao?->agenda_presencial ?? []),
            max(1, (int) ($edicao?->presencial_fila_avaliador ?: self::PADRAO_FILA)),
            // Zero é resposta (só comuns, ou só a comissão): em branco é que vale o padrão.
            max(0, (int) ($edicao?->presencial_comuns_por_projeto ?? self::PADRAO_COMUNS)),
            max(0, (int) ($edicao?->presencial_comite_por_projeto ?? self::PADRAO_COMITE)),
            max(1.0, (float) ($edicao?->presencial_peso_comite ?? self::PADRAO_PESO_COMITE)),
        );
    }

    /**
     * Valida e normaliza a agenda vinda da tela: para cada dia do evento, cada
     * turno com o horário dele ("08:00"–"12:00") e o da avaliação. Turno sem
     * nenhum campo não acontece naquele dia; com o horário do turno e a
     * avaliação em branco, a avaliação vai do início do turno até 30 minutos
     * depois do fim. Dia fora do período do evento é recusado.
     *
     * @param  array<string, mixed>  $agenda  "Y-m-d" => turno => campos
     * @param  list<string>  $dias  os dias do evento
     * @return array<string, array<string, array{inicio: string, fim: string, avaliacao_inicio: string, avaliacao_fim: string}>>
     */
    public static function validar(array $agenda, array $dias): array
    {
        $limpa = [];

        foreach ($agenda as $dia => $turnos) {
            $dia = (string) $dia;

            if (! is_array($turnos)) {
                continue;
            }

            foreach (Turno::cases() as $turno) {
                $campos = $turnos[$turno->value] ?? null;
                $valores = array_map(
                    fn (string $c) => is_array($campos) ? trim((string) ($campos[$c] ?? '')) : '',
                    array_combine(self::CAMPOS, self::CAMPOS),
                );

                if (implode('', $valores) === '') {
                    continue;
                }

                $rotulo = self::rotuloDia($dia).", {$turno->label()}";
                $chave = "agenda.{$dia}.{$turno->value}";

                if (! in_array($dia, $dias, true)) {
                    throw ValidationException::withMessages([
                        $chave => "{$rotulo}: o dia está fora do período do evento.",
                    ]);
                }

                foreach (['inicio' => 'o início do turno', 'fim' => 'o fim do turno'] as $campo => $nome) {
                    if ($valores[$campo] === '') {
                        throw ValidationException::withMessages(["{$chave}.{$campo}" => "{$rotulo}: informe {$nome}."]);
                    }
                }

                if (($valores['avaliacao_inicio'] === '') !== ($valores['avaliacao_fim'] === '')) {
                    throw ValidationException::withMessages([
                        "{$chave}.avaliacao_inicio" => "{$rotulo}: informe o início e o fim da avaliação, ou deixe os dois em branco.",
                    ]);
                }

                foreach ($valores as $campo => $valor) {
                    if ($valor !== '' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor)) {
                        throw ValidationException::withMessages(["{$chave}.{$campo}" => "{$rotulo}: use o formato 08:00."]);
                    }
                }

                if ($valores['inicio'] >= $valores['fim']) {
                    throw ValidationException::withMessages(["{$chave}.fim" => "{$rotulo}: o turno precisa terminar depois de começar."]);
                }

                if ($valores['avaliacao_inicio'] !== '' && $valores['avaliacao_inicio'] >= $valores['avaliacao_fim']) {
                    throw ValidationException::withMessages([
                        "{$chave}.avaliacao_fim" => "{$rotulo}: a avaliação precisa terminar depois de começar.",
                    ]);
                }

                if ($valores['avaliacao_inicio'] !== '' && ($valores['avaliacao_inicio'] >= $valores['fim'] || $valores['avaliacao_fim'] <= $valores['inicio'])) {
                    throw ValidationException::withMessages([
                        "{$chave}.avaliacao_inicio" => "{$rotulo}: a avaliação precisa acontecer durante o turno (pode passar do fim, para terminar o que se abriu).",
                    ]);
                }

                $limpa[$dia][$turno->value] = self::comAvaliacao($dia, $valores);
            }
        }

        ksort($limpa);

        return $limpa;
    }

    /**
     * A agenda dos dias do evento, como a tela a edita: todo dia aparece, e o
     * turno sem horário vem nulo.
     *
     * @return array<string, array<string, array{inicio: string, fim: string, avaliacao_inicio: string, avaliacao_fim: string}|null>>
     */
    public function agenda(): array
    {
        $agenda = [];

        foreach ($this->dias() as $dia) {
            foreach (Turno::cases() as $turno) {
                $agenda[$dia][$turno->value] = $this->agenda[$dia][$turno->value] ?? null;
            }
        }

        return $agenda;
    }

    public function fila(): int
    {
        return $this->fila;
    }

    /** O teto de avaliações do projeto: as vagas comuns mais as da comissão. */
    public function porProjeto(): int
    {
        return $this->comuns + $this->comite;
    }

    public function comunsPorProjeto(): int
    {
        return $this->comuns;
    }

    public function comitePorProjeto(): int
    {
        return $this->comite;
    }

    public function pesoComite(): float
    {
        return $this->pesoComite;
    }

    /** O peso que uma avaliação tem na média do projeto, pela vaga que ocupa. */
    public function peso(VagaPresencial $vaga): float
    {
        return $vaga === VagaPresencial::Comite ? $this->pesoComite : 1.0;
    }

    /**
     * A vaga que o avaliador ocupa no projeto, ou null se não sobrou nenhuma
     * para ele. O da comissão fica com a vaga dela e, tomada, com uma comum
     * (que pesa como as outras); o comum, só com uma comum.
     *
     * @param  array{comum?: int, comite?: int}  $ocupadas
     */
    public function vagaPara(bool $comissao, array $ocupadas): ?VagaPresencial
    {
        if ($comissao && ($ocupadas['comite'] ?? 0) < $this->comite) {
            return VagaPresencial::Comite;
        }

        return ($ocupadas['comum'] ?? 0) < $this->comuns ? VagaPresencial::Comum : null;
    }

    /**
     * A vaga da designação manual, que passa por cima do teto: a que caberia
     * e, sem nenhuma livre, a do tipo do avaliador.
     *
     * @param  array{comum?: int, comite?: int}  $ocupadas
     */
    public function vagaForcada(bool $comissao, array $ocupadas): VagaPresencial
    {
        return $this->vagaPara($comissao, $ocupadas)
            ?? ($comissao ? VagaPresencial::Comite : VagaPresencial::Comum);
    }

    /** Há o que agendar: data do evento e o horário de ao menos um turno num dos dias dela. */
    public function configurada(): bool
    {
        foreach ($this->dias() as $dia) {
            if (! empty($this->agenda[$dia])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Os dias do evento, como "Y-m-d".
     *
     * @return list<string>
     */
    public function dias(): array
    {
        if ($this->de === null) {
            return [];
        }

        $dias = [];
        $ultimo = ($this->ate ?? $this->de)->startOfDay();

        for ($dia = $this->de->startOfDay(); $dia->lessThanOrEqualTo($ultimo) && count($dias) < 31; $dia = $dia->addDay()) {
            $dias[] = $dia->toDateString();
        }

        return $dias;
    }

    /** O início do turno (a equipe no estande). */
    public function inicio(string $dia, Turno $turno): ?CarbonImmutable
    {
        return $this->hora($dia, $turno, 'inicio');
    }

    /** O fim do turno. */
    public function fim(string $dia, Turno $turno): ?CarbonImmutable
    {
        return $this->hora($dia, $turno, 'fim');
    }

    /** Quando o avaliador pode começar a avaliar. */
    public function inicioAvaliacao(string $dia, Turno $turno): ?CarbonImmutable
    {
        return $this->hora($dia, $turno, 'avaliacao_inicio');
    }

    /** O último instante aceito: o fim da avaliação. */
    public function prazo(string $dia, Turno $turno): ?CarbonImmutable
    {
        return $this->hora($dia, $turno, 'avaliacao_fim');
    }

    /** A ocorrência aceita escrita agora (do início ao fim da avaliação)? */
    public function aberta(string $dia, Turno $turno, ?CarbonInterface $agora = null): bool
    {
        $agora ??= now();
        $inicio = $this->inicioAvaliacao($dia, $turno);

        return $inicio !== null && $agora->greaterThanOrEqualTo($inicio) && $agora->lessThanOrEqualTo($this->prazo($dia, $turno));
    }

    /**
     * Dá para começar uma avaliação agora? Do início da avaliação até o fim
     * dela ou do turno, o que vier antes — depois disso é margem.
     */
    public function emAndamento(string $dia, Turno $turno, ?CarbonInterface $agora = null): bool
    {
        $agora ??= now();
        $inicio = $this->inicioAvaliacao($dia, $turno);

        return $inicio !== null && $agora->greaterThanOrEqualTo($inicio) && $agora->lessThanOrEqualTo($this->fimDoComeco($dia, $turno));
    }

    /** A ocorrência faz parte da agenda (dia do evento e turno com horário nele)? */
    public function existe(string $dia, Turno $turno): bool
    {
        return in_array($dia, $this->dias(), true) && isset($this->agenda[$dia][$turno->value]);
    }

    /**
     * Todas as ocorrências da agenda, em ordem cronológica.
     *
     * @return list<array<string, mixed>>
     */
    public function ocorrencias(?CarbonInterface $agora = null): array
    {
        $lista = [];

        foreach ($this->dias() as $dia) {
            foreach (Turno::cases() as $turno) {
                if (isset($this->agenda[$dia][$turno->value])) {
                    $lista[] = $this->ocorrencia($dia, $turno, $agora);
                }
            }
        }

        usort($lista, fn (array $a, array $b) => $a['inicio'] <=> $b['inicio']);

        return $lista;
    }

    /**
     * As ocorrências abertas agora — até duas, quando a margem de um turno
     * encosta no começo do seguinte.
     *
     * @return list<array<string, mixed>>
     */
    public function abertas(?CarbonInterface $agora = null): array
    {
        return array_values(array_filter(
            $this->ocorrencias($agora),
            fn (array $o) => $o['aberta'],
        ));
    }

    /**
     * A ocorrência que a tela mostra quando ninguém escolheu: a que está
     * acontecendo, senão a próxima, senão a última que houve.
     *
     * @return array<string, mixed>|null
     */
    public function emFoco(?CarbonInterface $agora = null): ?array
    {
        $agora ??= now();
        $todas = $this->ocorrencias($agora);

        if ($todas === []) {
            return null;
        }

        // A que começou por último entre as abertas: na margem de um turno com
        // o seguinte já começando, o foco é o novo.
        $abertas = array_values(array_filter($todas, fn (array $o) => $o['aberta']));
        if ($abertas !== []) {
            return end($abertas);
        }

        foreach ($todas as $o) {
            if ($o['situacao'] === 'futuro') {
                return $o;
            }
        }

        return end($todas);
    }

    /** @return array<string, mixed>|null a próxima ocorrência que ainda vai começar */
    public function proxima(?CarbonInterface $agora = null): ?array
    {
        foreach ($this->ocorrencias($agora) as $o) {
            if ($o['situacao'] === 'futuro') {
                return $o;
            }
        }

        return null;
    }

    /**
     * Uma ocorrência como a API a entrega.
     *
     * @return array<string, mixed>
     */
    public function ocorrencia(string $dia, Turno $turno, ?CarbonInterface $agora = null): array
    {
        $agora ??= now();
        $inicio = $this->inicio($dia, $turno);
        $fim = $this->fim($dia, $turno);
        $inicioAvaliacao = $this->inicioAvaliacao($dia, $turno);
        $prazo = $this->prazo($dia, $turno);
        $diaLabel = CarbonImmutable::parse($dia)->format('d/m');

        $situacao = match (true) {
            $inicioAvaliacao === null => 'sem_horario',
            $agora->lessThan($inicioAvaliacao) => 'futuro',
            $agora->lessThanOrEqualTo($this->fimDoComeco($dia, $turno)) => 'em_andamento',
            $agora->lessThanOrEqualTo($prazo) => 'margem',
            default => 'encerrado',
        };

        return [
            'chave' => $dia.'|'.$turno->value,
            'dia' => $dia,
            'dia_label' => $diaLabel,
            'turno' => $turno->value,
            'turno_label' => $turno->label(),
            'inicio' => $inicio?->toIso8601String(),
            'fim' => $fim?->toIso8601String(),
            'prazo' => $prazo?->toIso8601String(),
            'inicio_label' => $inicio?->format('H:i'),
            'fim_label' => $fim?->format('H:i'),
            'avaliacao_inicio_label' => $inicioAvaliacao?->format('H:i'),
            'avaliacao_fim_label' => $prazo?->format('H:i'),
            'prazo_label' => $prazo?->format('H:i'),
            'situacao' => $situacao,
            'aberta' => in_array($situacao, ['em_andamento', 'margem'], true),
            'rotulo' => $inicio === null
                ? "{$diaLabel} · {$turno->label()}"
                : "{$diaLabel} · {$turno->label()} · {$inicio->format('H:i')}–{$fim->format('H:i')}",
        ];
    }

    /** O fim de quando se pode começar: o da avaliação ou o do turno, o que vier antes. */
    private function fimDoComeco(string $dia, Turno $turno): ?CarbonImmutable
    {
        $fim = $this->fim($dia, $turno);
        $prazo = $this->prazo($dia, $turno);

        return $fim === null || $prazo === null ? $prazo ?? $fim : $fim->min($prazo);
    }

    private function hora(string $dia, Turno $turno, string $ponta): ?CarbonImmutable
    {
        $hora = $this->agenda[$dia][$turno->value][$ponta] ?? null;

        return $hora === null ? null : CarbonImmutable::parse("{$dia} {$hora}");
    }

    /** Os campos de cada turno num dia. */
    private const CAMPOS = ['inicio', 'fim', 'avaliacao_inicio', 'avaliacao_fim'];

    /**
     * Completa a avaliação em branco com a regra de antes: do início do turno
     * até 30 minutos depois do fim (sem passar da meia-noite).
     *
     * @param  array<string, string>  $valores
     * @return array{inicio: string, fim: string, avaliacao_inicio: string, avaliacao_fim: string}
     */
    private static function comAvaliacao(string $dia, array $valores): array
    {
        if (($valores['avaliacao_inicio'] ?? '') === '' || ($valores['avaliacao_fim'] ?? '') === '') {
            $fim = CarbonImmutable::parse("{$dia} {$valores['fim']}")->addMinutes(self::MARGEM_MINUTOS);
            $valores['avaliacao_inicio'] = $valores['inicio'];
            $valores['avaliacao_fim'] = $fim->toDateString() === $dia ? $fim->format('H:i') : '23:59';
        }

        return [
            'inicio' => $valores['inicio'],
            'fim' => $valores['fim'],
            'avaliacao_inicio' => $valores['avaliacao_inicio'],
            'avaliacao_fim' => $valores['avaliacao_fim'],
        ];
    }

    private static function rotuloDia(string $dia): string
    {
        try {
            return CarbonImmutable::parse($dia)->format('d/m');
        } catch (\Throwable) {
            return $dia;
        }
    }

    /**
     * O que está gravado, sem confiar no formato: descarta turno sem o horário
     * dele e completa a avaliação que faltar.
     *
     * @param  array<string, mixed>  $agenda
     * @return array<string, array<string, array{inicio: string, fim: string, avaliacao_inicio: string, avaliacao_fim: string}>>
     */
    private static function normalizar(array $agenda): array
    {
        $limpa = [];

        foreach ($agenda as $dia => $turnos) {
            if (! is_array($turnos) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dia)) {
                continue;
            }

            foreach (Turno::cases() as $turno) {
                $campos = $turnos[$turno->value] ?? null;

                if (is_array($campos) && ! empty($campos['inicio']) && ! empty($campos['fim'])) {
                    $limpa[(string) $dia][$turno->value] = self::comAvaliacao((string) $dia, array_map('strval', $campos));
                }
            }
        }

        return $limpa;
    }
}
