<?php

namespace App\Support;

use App\Enums\Turno;
use App\Enums\VagaPresencial;
use App\Models\Edicao;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * A agenda da **avaliação presencial** (Sprint 169): em que dias e horários
 * cada turno acontece, e portanto quando um avaliador pode avaliar.
 *
 * O horário de cada turno é um só e vale **em todos os dias** do evento
 * (`edicoes.horarios_turnos`); os dias são os da janela do evento
 * (`evento_de` → `evento_ate`). Cada par dia × turno é uma **ocorrência**, e é
 * nela que a organização ativa avaliadores, distribui projetos e conta o prazo.
 *
 * O prazo é o fim do turno **mais 30 minutos**: a equipe ainda está no estande
 * quando o turno acaba, e o avaliador que começou às 11h50 precisa terminar o
 * que abriu. Nada novo se abre na margem — ela existe para fechar, não para
 * começar (quem decide isso é o fluxo, que pergunta {@see emAndamento()}).
 */
final class JanelaTurnos
{
    /** Minutos depois do fim do turno em que a avaliação ainda é aceita. */
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
     * @param  array<string, array{inicio: string, fim: string}>  $horarios
     */
    private function __construct(
        private readonly ?CarbonImmutable $de,
        private readonly ?CarbonImmutable $ate,
        private readonly array $horarios,
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
            self::normalizar($edicao?->horarios_turnos ?? []),
            max(1, (int) ($edicao?->presencial_fila_avaliador ?: self::PADRAO_FILA)),
            // Zero é resposta (só comuns, ou só a comissão): em branco é que vale o padrão.
            max(0, (int) ($edicao?->presencial_comuns_por_projeto ?? self::PADRAO_COMUNS)),
            max(0, (int) ($edicao?->presencial_comite_por_projeto ?? self::PADRAO_COMITE)),
            max(1.0, (float) ($edicao?->presencial_peso_comite ?? self::PADRAO_PESO_COMITE)),
        );
    }

    /**
     * Valida e normaliza os horários vindos da tela: "08:00"–"12:00" por
     * turno, início antes do fim. Turno sem os dois campos fica de fora — a
     * feira pode ter um turno só.
     *
     * @param  array<string, mixed>  $horarios
     * @return array<string, array{inicio: string, fim: string}>
     */
    public static function validar(array $horarios): array
    {
        $limpos = [];

        foreach (Turno::cases() as $turno) {
            $par = $horarios[$turno->value] ?? null;
            $inicio = is_array($par) ? trim((string) ($par['inicio'] ?? '')) : '';
            $fim = is_array($par) ? trim((string) ($par['fim'] ?? '')) : '';

            if ($inicio === '' && $fim === '') {
                continue;
            }

            foreach (['inicio' => $inicio, 'fim' => $fim] as $campo => $valor) {
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor)) {
                    throw ValidationException::withMessages([
                        "horarios.{$turno->value}.{$campo}" => "Informe o horário do {$turno->label()} no formato 08:00.",
                    ]);
                }
            }

            if ($inicio >= $fim) {
                throw ValidationException::withMessages([
                    "horarios.{$turno->value}.fim" => "O {$turno->label()} precisa terminar depois de começar.",
                ]);
            }

            $limpos[$turno->value] = ['inicio' => $inicio, 'fim' => $fim];
        }

        return $limpos;
    }

    /** @return array<string, array{inicio: string, fim: string}> */
    public function horarios(): array
    {
        return $this->horarios;
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

    /** Há o que agendar: data do evento e o horário de ao menos um turno. */
    public function configurada(): bool
    {
        return $this->de !== null && $this->horarios !== [];
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

    public function inicio(string $dia, Turno $turno): ?CarbonImmutable
    {
        return $this->hora($dia, $turno, 'inicio');
    }

    public function fim(string $dia, Turno $turno): ?CarbonImmutable
    {
        return $this->hora($dia, $turno, 'fim');
    }

    /** O último instante aceito: o fim do turno mais a margem. */
    public function prazo(string $dia, Turno $turno): ?CarbonImmutable
    {
        return $this->fim($dia, $turno)?->addMinutes(self::MARGEM_MINUTOS);
    }

    /** A ocorrência aceita escrita agora (do início ao fim + margem)? */
    public function aberta(string $dia, Turno $turno, ?CarbonInterface $agora = null): bool
    {
        $agora ??= now();
        $inicio = $this->inicio($dia, $turno);
        $prazo = $this->prazo($dia, $turno);

        return $inicio !== null && $agora->greaterThanOrEqualTo($inicio) && $agora->lessThanOrEqualTo($prazo);
    }

    /** O turno está acontecendo agora (sem a margem)? É o que deixa começar algo novo. */
    public function emAndamento(string $dia, Turno $turno, ?CarbonInterface $agora = null): bool
    {
        $agora ??= now();
        $inicio = $this->inicio($dia, $turno);

        return $inicio !== null && $agora->greaterThanOrEqualTo($inicio) && $agora->lessThanOrEqualTo($this->fim($dia, $turno));
    }

    /** A ocorrência faz parte da agenda (dia do evento e turno com horário)? */
    public function existe(string $dia, Turno $turno): bool
    {
        return in_array($dia, $this->dias(), true) && isset($this->horarios[$turno->value]);
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
                if (isset($this->horarios[$turno->value])) {
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
        $prazo = $this->prazo($dia, $turno);
        $diaLabel = CarbonImmutable::parse($dia)->format('d/m');

        $situacao = match (true) {
            $inicio === null => 'sem_horario',
            $agora->lessThan($inicio) => 'futuro',
            $agora->lessThanOrEqualTo($fim) => 'em_andamento',
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
            'prazo_label' => $prazo?->format('H:i'),
            'situacao' => $situacao,
            'aberta' => in_array($situacao, ['em_andamento', 'margem'], true),
            'rotulo' => $inicio === null
                ? "{$diaLabel} · {$turno->label()}"
                : "{$diaLabel} · {$turno->label()} · {$inicio->format('H:i')}–{$fim->format('H:i')}",
        ];
    }

    private function hora(string $dia, Turno $turno, string $ponta): ?CarbonImmutable
    {
        $hora = $this->horarios[$turno->value][$ponta] ?? null;

        return $hora === null ? null : CarbonImmutable::parse("{$dia} {$hora}");
    }

    /**
     * O que está gravado, sem confiar no formato: descarta turno incompleto.
     *
     * @param  array<string, mixed>  $horarios
     * @return array<string, array{inicio: string, fim: string}>
     */
    private static function normalizar(array $horarios): array
    {
        $limpos = [];

        foreach (Turno::cases() as $turno) {
            $par = $horarios[$turno->value] ?? null;

            if (is_array($par) && ! empty($par['inicio']) && ! empty($par['fim'])) {
                $limpos[$turno->value] = ['inicio' => (string) $par['inicio'], 'fim' => (string) $par['fim']];
            }
        }

        return $limpos;
    }
}
