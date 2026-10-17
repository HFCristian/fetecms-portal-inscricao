<?php

namespace App\Services;

use App\Enums\TipoRegistro;
use App\Enums\Turno;
use App\Models\Edicao;
use App\Models\Interprete;
use App\Models\InterpreteCheckin;
use App\Models\User;
use App\Support\CodigoParticipante;
use App\Support\JanelaTurnos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A **chegada** dos intérpretes no evento (Sprint 215), ocorrência a ocorrência
 * — cada dia × turno da agenda da avaliação presencial ({@see JanelaTurnos}).
 *
 * A porta escolhe a ocorrência (vem escolhida a que está acontecendo, senão a
 * próxima) e registra pelo crachá — leitor USB ou câmera — ou pela busca por
 * nome ou CPF. O **acompanhamento** responde a pergunta da organização: dos
 * intérpretes que deviam estar neste turno (os que atendem projetos que
 * apresentam nele), quem já chegou e quem falta.
 *
 * Sem o horário dos turnos configurado, as ocorrências são os dias do evento
 * com os dois turnos; sem as datas do evento, não há onde registrar.
 */
class InterpreteCheckinService
{
    public function __construct(
        private readonly RegistroAtividadeService $registros,
        private readonly InterpretesService $interpretes,
    ) {}

    /**
     * As ocorrências em que se pode registrar chegada, e a que a tela abre.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $ocorrencias = $this->ocorrencias();
        $janela = JanelaTurnos::daEdicao();
        $foco = $janela->configurada() ? $janela->emFoco() : null;

        return [
            'ocorrencias' => $ocorrencias,
            'em_foco' => $foco['chave'] ?? ($ocorrencias[0]['chave'] ?? null),
            'motivo' => $ocorrencias === []
                ? 'Defina o período do evento em Parametrização → Datas e períodos: é dele que saem os dias e turnos da chegada.'
                : null,
        ];
    }

    /**
     * Crachá lido: registra a chegada do intérprete na ocorrência escolhida.
     *
     * @return array<string, mixed> o que o card da porta mostra
     */
    public function porCodigo(string $codigo, string $dia, Turno $turno, User $user): array
    {
        $lido = CodigoParticipante::ler($codigo);

        if ($lido === null) {
            throw ValidationException::withMessages([
                'codigo' => 'Código não reconhecido. Leia de novo ou digite o que está escrito na etiqueta.',
            ]);
        }

        if (! CodigoParticipante::ehInterprete($lido)) {
            throw ValidationException::withMessages([
                'codigo' => CodigoParticipante::recusaForaDaEquipe($lido) === null
                    ? 'Este crachá é de um participante, não de intérprete.'
                    : 'Este crachá é de '.mb_strtolower(CodigoParticipante::papelLabel($lido['papel'])).', não de intérprete.',
            ]);
        }

        $edicao = $this->edicaoOuFalha();
        $interprete = Interprete::where('edicao_id', $edicao->id)->find($lido['participante_id']);

        if ($interprete === null || (int) $edicao->ano !== $lido['ano']) {
            throw ValidationException::withMessages([
                'codigo' => 'Este crachá não é de um intérprete cadastrado nesta edição.',
            ]);
        }

        if (CodigoParticipante::cpf3($interprete->cpf) !== $lido['cpf3']) {
            throw ValidationException::withMessages([
                'codigo' => 'A etiqueta não confere com o cadastro deste intérprete. Confira se o crachá é dele mesmo.',
            ]);
        }

        return $this->registrar($interprete, $dia, $turno, $user);
    }

    /**
     * Registra a chegada — se já havia, devolve a hora dela sem gravar de novo.
     *
     * @return array<string, mixed>
     */
    public function registrar(Interprete $interprete, string $dia, Turno $turno, User $user): array
    {
        $this->daEdicaoOuFalha($interprete);
        $this->validarOcorrencia($dia, $turno);

        $existente = $this->checkinDe($interprete, $dia, $turno);
        $checkin = $existente ?? InterpreteCheckin::create([
            'interprete_id' => $interprete->id,
            'dia' => $dia,
            'turno' => $turno->value,
            'registrado_por' => $user->id,
        ]);

        $linha = $this->interpretes->linha($interprete, $this->interpretes->contexto());
        $doTurno = collect($linha['locais'])->where('turno', $turno->value)->pluck('numero')->filter()
            ->map(fn ($n) => str_pad((string) $n, 3, '0', STR_PAD_LEFT));

        return [
            'ja_presente' => $existente !== null,
            'checkin_em' => $checkin->created_at?->format('H:i'),
            'interprete' => [
                'id' => $interprete->id,
                'nome' => $interprete->nome,
                'tipos_labels' => $linha['tipos_labels'],
            ],
            'participante' => ['nome' => $interprete->nome, 'papel_label' => 'Intérprete'],
            // O card da porta mostra "papel · projeto": aqui vai o que ele
            // interpreta e onde atende neste turno.
            'projeto_titulo' => implode(' · ', array_filter([
                implode(', ', $linha['tipos_labels']),
                $doTurno->isEmpty() ? null : 'Estande '.$doTurno->implode(', '),
            ])) ?: null,
        ];
    }

    /** Desfaz a chegada, com justificativa — o número decide quem falta. */
    public function desfazer(Interprete $interprete, string $dia, Turno $turno, string $justificativa, User $user): void
    {
        $this->daEdicaoOuFalha($interprete);
        $checkin = $this->checkinDe($interprete, $dia, $turno);

        if ($checkin === null) {
            throw ValidationException::withMessages([
                'checkin' => 'Este intérprete não tem chegada registrada nesta ocorrência.',
            ]);
        }

        $checkin->delete();

        $this->registros->interprete(
            TipoRegistro::InterpreteCheckinDesfeito,
            $user,
            sprintf('chegada de %s em %s · %s desfeita', $interprete->nome, CarbonImmutable::parse($dia)->format('d/m'), $turno->label()),
            $justificativa,
        );
    }

    /**
     * Busca por nome ou CPF, com a situação na ocorrência escolhida — o
     * caminho de quem chega sem o crachá.
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(string $termo, string $dia, Turno $turno, int $limite = 20): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < 2) {
            return [];
        }

        $edicao = $this->edicaoOuFalha();
        $chave = $this->chave($termo);
        $digitos = preg_replace('/\D/', '', $termo);
        $presentes = $this->checkinsDa($edicao, $dia, $turno);

        return Interprete::where('edicao_id', $edicao->id)
            ->get()
            ->filter(fn (Interprete $i) => str_contains($this->chave($i->nome), $chave)
                || (strlen((string) $digitos) >= 3 && str_contains($i->cpf, (string) $digitos)))
            ->sortBy(fn (Interprete $i) => $this->chave($i->nome))
            ->take($limite)
            ->map(fn (Interprete $i) => [
                'id' => $i->id,
                'nome' => $i->nome,
                'tipos_labels' => $i->tiposLabels(),
                'presente' => $presentes->has($i->id),
                'checkin_em' => $presentes->get($i->id)?->created_at?->format('H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * Quem devia estar nesta ocorrência e chegou, quem falta e quem chegou sem
     * estar designado para este turno.
     *
     * @return array<string, mixed>
     */
    public function acompanhamento(string $dia, Turno $turno): array
    {
        $edicao = $this->edicaoOuFalha();
        $this->validarOcorrencia($dia, $turno);

        $contexto = $this->interpretes->contexto();
        $presentes = $this->checkinsDa($edicao, $dia, $turno);

        $linhas = Interprete::where('edicao_id', $edicao->id)
            ->with(['vinculos', 'edicao'])
            ->get()
            ->map(function (Interprete $i) use ($contexto, $presentes, $turno) {
                $linha = $this->interpretes->linha($i, $contexto);
                $doTurno = collect($linha['vinculos'])->where('turno', $turno->value);
                $checkin = $presentes->get($i->id);

                return [
                    'id' => $i->id,
                    'nome' => $i->nome,
                    'tipos_labels' => $linha['tipos_labels'],
                    'esperado' => $doTurno->isNotEmpty(),
                    'estandes' => $doTurno->pluck('numero')->filter()->unique()->sort()->values()->all(),
                    'projetos' => $doTurno->map(fn (array $v) => trim(($v['codigo'] ? $v['codigo'].' - ' : '').$v['projeto_titulo']))
                        ->unique()->values()->all(),
                    'presente' => $checkin !== null,
                    'checkin_em' => $checkin?->created_at?->format('H:i'),
                    'registrado_por' => $checkin?->registrador?->name,
                ];
            })
            ->filter(fn (array $l) => $l['esperado'] || $l['presente'])
            ->sortBy(fn (array $l) => [$l['presente'] ? 1 : 0, $this->chave($l['nome'])])
            ->values();

        $esperados = $linhas->where('esperado', true);

        return [
            'ocorrencia' => JanelaTurnos::daEdicao($edicao)->ocorrencia($dia, $turno),
            'totais' => [
                'esperados' => $esperados->count(),
                'presentes' => $esperados->where('presente', true)->count(),
                'faltando' => $esperados->where('presente', false)->count(),
                'extras' => $linhas->where('esperado', false)->count(),
            ],
            'interpretes' => $linhas->all(),
        ];
    }

    // --- Apoio -------------------------------------------------------------

    /**
     * As ocorrências: as da agenda quando o horário dos turnos existe; senão,
     * os dias do evento com os dois turnos.
     *
     * @return list<array<string, mixed>>
     */
    private function ocorrencias(): array
    {
        $janela = JanelaTurnos::daEdicao();

        if ($janela->configurada()) {
            return $janela->ocorrencias();
        }

        $lista = [];

        foreach ($janela->dias() as $dia) {
            foreach (Turno::cases() as $turno) {
                $lista[] = $janela->ocorrencia($dia, $turno);
            }
        }

        return $lista;
    }

    private function validarOcorrencia(string $dia, Turno $turno): void
    {
        $chaves = array_column($this->ocorrencias(), 'chave');

        if (! in_array($dia.'|'.$turno->value, $chaves, true)) {
            throw ValidationException::withMessages([
                'dia' => 'Escolha um dia e um turno do evento.',
            ]);
        }
    }

    private function checkinDe(Interprete $interprete, string $dia, Turno $turno): ?InterpreteCheckin
    {
        return InterpreteCheckin::where('interprete_id', $interprete->id)
            ->whereDate('dia', $dia)
            ->where('turno', $turno->value)
            ->first();
    }

    /** @return Collection<int, InterpreteCheckin> por id do intérprete */
    private function checkinsDa(Edicao $edicao, string $dia, Turno $turno): Collection
    {
        return InterpreteCheckin::whereHas('interprete', fn ($q) => $q->where('edicao_id', $edicao->id))
            ->whereDate('dia', $dia)
            ->where('turno', $turno->value)
            ->with('registrador:id,name')
            ->get()
            ->keyBy('interprete_id');
    }

    private function daEdicaoOuFalha(Interprete $interprete): void
    {
        if ($interprete->edicao_id !== $this->edicaoOuFalha()->id) {
            abort(404);
        }
    }

    private function edicaoOuFalha(): Edicao
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'edicao' => 'Nenhuma edição em escopo. Crie a edição da feira antes.',
            ]);
        }

        return $edicao;
    }

    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
