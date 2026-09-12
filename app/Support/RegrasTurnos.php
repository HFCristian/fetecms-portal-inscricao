<?php

namespace App\Support;

use App\Enums\Turno;

/**
 * As regras que dividem os finalistas entre os turnos A e B (aba Mapa do Evento
 * → Turnos de Apresentação).
 *
 * Cinco regras, **em ordem de prioridade** — a primeira que alcança um projeto
 * decide o turno dele, e as seguintes não o tocam mais:
 *
 * 1. `vestibular` — listas por vestibular. O aluno presta a prova num turno e
 *    não pode estar no estande; o admin escreve **qual** vestibular, marca o
 *    turno em que aquela gente APRESENTA e escolhe os projetos um a um. Várias
 *    listas convivem (um vestibular cada).
 * 2. `justificativa` — o mesmo, para os outros motivos. Aqui o admin registra a
 *    justificativa, **por onde ela chegou** e o turno em que a pessoa **não
 *    pode** estar; o projeto vai para o turno oposto. Guardar a indisponibilidade
 *    (e não o destino) é o que faz o registro continuar verdadeiro se um dia os
 *    turnos trocarem de horário.
 * 3. `fora_ms` — projetos de escola de fora de Mato Grosso do Sul.
 * 4. `fora_capital` — projetos do interior do estado.
 * 5. `capital` — projetos de Campo Grande.
 *
 * As três últimas são recortes de localidade e, por isso, se sobrepõem: um
 * projeto de fora do MS também está "fora da capital". A ordem resolve —
 * `fora_ms` responde primeiro.
 *
 * Toda regra é opcional (o toggle da tela): sem nenhuma ligada, a divisão é só
 * o equilíbrio 50/50 entre os dois turnos.
 */
final class RegrasTurnos
{
    /** As regras com lista própria (vestibular e justificativa). */
    public const COM_LISTAS = ['vestibular', 'justificativa'];

    /** As regras de localidade, que só escolhem um turno. */
    public const DE_LOCALIDADE = ['fora_ms', 'fora_capital', 'capital'];

    /** A ordem de prioridade — é ela que resolve o projeto alcançado por duas regras. */
    public const ORDEM = ['vestibular', 'justificativa', 'fora_ms', 'fora_capital', 'capital'];

    /** De onde a justificativa chegou à organização. */
    public const ORIGENS = ['email', 'whatsapp', 'oficio', 'presencial', 'outro'];

    /** @var array<string, mixed> */
    private array $regras;

    /** @var array{A:int, B:int} */
    private array $capacidade;

    /** @param  array<string, mixed>  $config */
    public function __construct(array $config = [])
    {
        $this->capacidade = [
            Turno::A->value => max(0, (int) ($config['capacidade'][Turno::A->value] ?? 0)),
            Turno::B->value => max(0, (int) ($config['capacidade'][Turno::B->value] ?? 0)),
        ];

        $bruto = is_array($config['regras'] ?? null) ? $config['regras'] : [];
        $this->regras = [];

        foreach (self::ORDEM as $chave) {
            $this->regras[$chave] = in_array($chave, self::COM_LISTAS, true)
                ? self::normalizarComListas($bruto[$chave] ?? [], $chave)
                : self::normalizarLocalidade($bruto[$chave] ?? []);
        }
    }

    public static function deArray(?array $config): self
    {
        return new self($config ?? []);
    }

    /** @return array{A:int, B:int} */
    public function capacidade(): array
    {
        return $this->capacidade;
    }

    public function capacidadeDe(Turno $turno): int
    {
        return $this->capacidade[$turno->value];
    }

    public function capacidadeTotal(): int
    {
        return array_sum($this->capacidade);
    }

    public function ativa(string $regra): bool
    {
        return (bool) ($this->regras[$regra]['ativa'] ?? false);
    }

    /**
     * As listas de uma regra com listas (vestibular/justificativa).
     *
     * @return list<array<string, mixed>>
     */
    public function listas(string $regra): array
    {
        return $this->regras[$regra]['listas'] ?? [];
    }

    /** O turno escolhido para uma regra de localidade. */
    public function turnoDe(string $regra): Turno
    {
        return Turno::tryFrom($this->regras[$regra]['turno'] ?? '') ?? Turno::A;
    }

    /**
     * O que vai para o banco (e volta dele) — sempre a estrutura inteira, com
     * os padrões preenchidos, para ler de volta não depender de preenchimento.
     *
     * @return array<string, mixed>
     */
    public function paraArray(): array
    {
        return ['capacidade' => $this->capacidade, 'regras' => $this->regras];
    }

    /**
     * Uma regra de lista: liga/desliga + as listas do admin.
     *
     * Cada lista guarda os **ids dos projetos**, e não das pessoas: buscar por
     * participante é conveniência de tela — quem apresenta, e ocupa o estande, é
     * o projeto inteiro.
     *
     * @param  array<string, mixed>  $regra
     * @return array{ativa:bool, listas:list<array<string, mixed>>}
     */
    private static function normalizarComListas(mixed $regra, string $chave): array
    {
        $regra = is_array($regra) ? $regra : [];
        $listas = is_array($regra['listas'] ?? null) ? $regra['listas'] : [];

        $limpas = [];

        foreach ($listas as $lista) {
            if (! is_array($lista)) {
                continue;
            }

            $nome = trim((string) ($lista['nome'] ?? ''));
            $projetos = array_values(array_unique(array_map(
                'intval',
                array_filter((array) ($lista['projetos'] ?? []), 'is_numeric'),
            )));

            if ($nome === '') {
                continue;
            }

            $base = ['nome' => $nome, 'projetos' => $projetos];

            $limpas[] = $chave === 'vestibular'
                ? $base + ['turno' => self::turnoValido($lista['turno'] ?? null)]
                : $base + [
                    // Aqui o admin marca o turno IMPOSSÍVEL; o destino é o oposto.
                    'turno_indisponivel' => self::turnoValido($lista['turno_indisponivel'] ?? null),
                    'origem' => in_array($lista['origem'] ?? null, self::ORIGENS, true)
                        ? $lista['origem']
                        : 'outro',
                    'observacao' => trim((string) ($lista['observacao'] ?? '')),
                ];
        }

        return ['ativa' => (bool) ($regra['ativa'] ?? false), 'listas' => $limpas];
    }

    /**
     * @param  array<string, mixed>  $regra
     * @return array{ativa:bool, turno:string}
     */
    private static function normalizarLocalidade(mixed $regra): array
    {
        $regra = is_array($regra) ? $regra : [];

        return [
            'ativa' => (bool) ($regra['ativa'] ?? false),
            'turno' => self::turnoValido($regra['turno'] ?? null),
        ];
    }

    private static function turnoValido(mixed $valor): string
    {
        return (Turno::tryFrom((string) $valor) ?? Turno::A)->value;
    }

    /** Rótulos das regras, na ordem de prioridade, para a tela desenhar. */
    public static function catalogo(): array
    {
        return [
            ['value' => 'vestibular', 'label' => 'Justificativa por Vestibular', 'tipo' => 'listas',
                'descricao' => 'O aluno presta vestibular num dos turnos. Informe o vestibular, o turno em que esses projetos apresentam e escolha-os um a um.'],
            ['value' => 'justificativa', 'label' => 'Justificativa (Outras)', 'tipo' => 'listas',
                'descricao' => 'Outros impedimentos. Informe a justificativa, por onde ela chegou e o turno em que a pessoa NÃO pode estar — o projeto vai para o outro.'],
            ['value' => 'fora_ms', 'label' => 'Projetos de fora do MS', 'tipo' => 'localidade',
                'descricao' => 'Escola de outro estado. Quem vem de longe costuma chegar no mesmo dia.'],
            ['value' => 'fora_capital', 'label' => 'Projetos de fora da capital', 'tipo' => 'localidade',
                'descricao' => 'Escola do interior de Mato Grosso do Sul.'],
            ['value' => 'capital', 'label' => 'Projetos de Campo Grande MS', 'tipo' => 'localidade',
                'descricao' => 'Escola da capital — a que tem mais facilidade de escolher horário.'],
        ];
    }

    /** @return array<int, array{value:string, label:string}> */
    public static function origens(): array
    {
        return [
            ['value' => 'email', 'label' => 'E-mail'],
            ['value' => 'whatsapp', 'label' => 'WhatsApp'],
            ['value' => 'oficio', 'label' => 'Ofício'],
            ['value' => 'presencial', 'label' => 'Presencial'],
            ['value' => 'outro', 'label' => 'Outro'],
        ];
    }
}
