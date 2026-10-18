<?php

namespace App\Services;

use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\SaidaAntecipada;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mapa do Evento → **Marcar saída** (Sprint 243): a equipe chegou e foi embora
 * antes do fim do turno.
 *
 * O mapa só sabia acumular — credenciado, checado, avaliado —, e o estande que
 * ficou vazio no meio da tarde continuava pintado como se a equipe estivesse
 * lá. A saída é o único estágio **marcado à mão**: não há balcão na porta de
 * saída, quem percebe é a organização andando pelo ginásio.
 *
 * Só sai quem **chegou**: o projeto precisa ter estande no mapa e o
 * credenciamento concluído. A marcação vale para o **dia** da saída — no dia
 * seguinte a equipe pode voltar —, e marcar de novo no mesmo dia corrige a
 * hora em vez de duplicar.
 */
class SaidaAntecipadaService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /**
     * @param  string|null  $saiuEm  "Y-m-d\TH:i", hora de Campo Grande; em branco, agora
     */
    public function marcar(int $projetoId, ?string $saiuEm, ?string $observacao, User $admin): SaidaAntecipada
    {
        $estande = $this->estande($projetoId);
        $agora = CarbonImmutable::now();
        $quando = $saiuEm ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $saiuEm) : $agora;

        // Um minuto de folga: o relógio do aparelho nunca bate com o do servidor.
        if ($quando->greaterThan($agora->addMinute())) {
            throw ValidationException::withMessages(['saiu_em' => 'A saída não pode ficar no futuro.']);
        }

        $credenciado = Credenciamento::where('projeto_id', $projetoId)
            ->whereNotNull('finalizado_em')
            ->value('finalizado_em');

        if ($credenciado === null) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto ainda não foi credenciado: só sai antes da hora quem chegou.',
            ]);
        }

        $credenciado = CarbonImmutable::parse($credenciado);

        if ($quando->lessThan($credenciado)) {
            throw ValidationException::withMessages([
                'saiu_em' => 'A saída não pode ser antes do credenciamento ('.$credenciado->format('d/m H:i').').',
            ]);
        }

        $observacao = trim((string) $observacao) ?: null;

        return DB::transaction(function () use ($estande, $quando, $observacao, $admin) {
            $saida = SaidaAntecipada::updateOrCreate(
                ['projeto_id' => $estande->projeto_id, 'dia' => $quando->toDateString()],
                ['saiu_em' => $quando, 'observacao' => $observacao, 'registrado_por' => $admin->id],
            );

            $this->registros->mapaSaida(
                $estande->projeto,
                $admin,
                marcada: true,
                lugar: $this->lugar($estande),
                saida: $this->rotulo($quando),
                observacao: $observacao,
            );

            return $saida;
        });
    }

    /** Desfaz a marcação: a equipe voltou, ou foi marcado o projeto errado. */
    public function desfazer(SaidaAntecipada $saida, User $admin): void
    {
        $estande = $this->estande($saida->projeto_id);

        DB::transaction(function () use ($saida, $estande, $admin) {
            $this->registros->mapaSaida(
                $estande->projeto,
                $admin,
                marcada: false,
                lugar: $this->lugar($estande),
                saida: $this->rotulo(CarbonImmutable::parse($saida->saiu_em)),
                observacao: null,
            );

            $saida->delete();
        });
    }

    /** "Saiu às 14:20 de 18/10". */
    public function rotulo(CarbonImmutable $quando): string
    {
        return 'Saiu às '.$quando->format('H:i').' de '.$quando->format('d/m');
    }

    /** O estande do projeto na edição em escopo — sem ele, o projeto não está no mapa. */
    private function estande(int $projetoId): EstandeProjeto
    {
        $edicao = Edicao::atual();

        $estande = $edicao === null ? null : EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('projeto_id', $projetoId)
            ->with('projeto')
            ->first();

        if ($estande?->projeto === null) {
            throw ValidationException::withMessages(['projeto_id' => 'Este projeto não tem estande no mapa do evento.']);
        }

        return $estande;
    }

    private function lugar(EstandeProjeto $estande): string
    {
        return 'Estande '.str_pad((string) $estande->numero, 3, '0', STR_PAD_LEFT).' · '.$estande->turno->label();
    }
}
