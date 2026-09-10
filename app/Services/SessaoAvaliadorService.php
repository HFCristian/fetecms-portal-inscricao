<?php

namespace App\Services;

use App\Enums\ModoDistribuicao;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A fila do avaliador amarrada à **sessão** dele — o coração do modo
 * {@see ModoDistribuicao::Atividade} — e o prazo da avaliação que
 * ficou aberta, que vale nos dois modos.
 *
 * No modo total o admin distribui em massa e a fila fica guardada esperando o
 * avaliador aparecer. Numa feira em que boa parte dos cadastrados nunca entra,
 * isso manda projeto para o limbo: três designados, nenhuma avaliação, e quem
 * está de fato trabalhando fica sem o que avaliar. No modo por atividade a
 * ordem se inverte — a fila é montada **quando ele entra** e devolvida ao bolo
 * quando a sessão acaba, então só ocupa projeto quem está ali.
 *
 * Quantos projetos ele recebe é o mesmo **mínimo por avaliador** de sempre
 * (Parametrização → Avaliação Online): o campo já significa "quantos projetos o
 * avaliador enxerga de uma vez", e um número novo com o mesmo sentido só criaria
 * dúvida sobre qual vale.
 *
 * **A sessão acaba de três jeitos**, e os três são cobertos:
 *
 * 1. o avaliador **sai** pelo menu ({@see self::aoSair()});
 * 2. ele **entra de novo** — o login limpa o que sobrou antes de montar a fila
 *    nova ({@see self::aoEntrar()});
 * 3. ele **fecha o navegador** e nunca mais volta. Aí é a varredura por
 *    inatividade que devolve ({@see self::varrer()}), depois das horas que o
 *    admin definiu. Sem ela, quem nunca clica em "sair" prenderia os projetos
 *    para sempre — que é justamente o problema que o modo veio resolver.
 *
 * **O que a sessão nunca leva embora**: a designação manual do admin (escape do
 * edital) e a avaliação que ele **começou**. Quem abriu o projeto continua com
 * ele até concluir — ou até estourar o prazo dos {@see Edicao::diasAvaliacaoAberta()}
 * dias, e mesmo aí **o rascunho fica guardado** para ele retomar.
 */
class SessaoAvaliadorService
{
    public function __construct(
        private readonly FilaAvaliadorService $fila,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /**
     * Chamado no login. No modo por atividade, devolve o que sobrou da sessão
     * anterior e monta a fila na hora; nos demais casos não faz nada além da
     * varredura, que vale sempre.
     *
     * Devolve quantas designações novas foram criadas — 0 quando o modo não é
     * este, quando o usuário não é avaliador ou quando a avaliação ainda não
     * está aberta (designar antes da liberação só encheria a tela do que ele
     * ainda não pode abrir).
     */
    public function aoEntrar(User $user): int
    {
        // A varredura roda na passagem, como a das contas temporárias: nenhum
        // prazo do portal pode depender de alguém ter configurado um agendador.
        $this->varrer();

        if (! $this->seAplica($user)) {
            return 0;
        }

        // A sessão anterior pode ter acabado sem um "sair" — devolve o que
        // sobrou antes de montar a fila nova, senão ela viria por cima da velha.
        $this->devolverFilaDeSessao($user);

        $criadas = $this->fila->repor($user);
        $this->marcarAtividade($user);

        return $criadas;
    }

    /**
     * Chamado no logout: a fila de sessão volta ao bolo na hora, para outro
     * avaliador poder pegar aqueles projetos ainda hoje.
     *
     * Devolve quantas saíram (0 fora do modo por atividade).
     */
    public function aoSair(User $user): int
    {
        return $this->seAplica($user) ? $this->devolverFilaDeSessao($user) : 0;
    }

    /**
     * O avaliador deu sinal de vida: adia o vencimento da sessão dele.
     *
     * Chamado quando ele abre o painel de avaliação — é o sinal honesto de que
     * a sessão está viva, diferente de um relógio que só conta a partir do login.
     */
    public function marcarAtividade(User $user): void
    {
        if (! $this->seAplica($user)) {
            return;
        }

        Avaliacao::where('avaliador_id', $user->id)
            ->whereIn('status', [StatusAvaliacao::Designada->value, StatusAvaliacao::EmAndamento->value])
            ->update(['atividade_em' => now()]);
    }

    /**
     * A faxina dos prazos, em duas partes independentes:
     *
     * - **sessões vencidas** (só no modo por atividade): a fila de quem sumiu
     *   há mais de {@see Edicao::horasSessaoAvaliador()} horas volta ao bolo;
     * - **avaliações abertas demais** (nos dois modos): passados os
     *   {@see Edicao::diasAvaliacaoAberta()} dias, o projeto volta para a pilha
     *   de distribuição — e o que já foi preenchido fica guardado.
     *
     * @return array{sessoes:int, abertas:int}
     */
    public function varrer(): array
    {
        return [
            'sessoes' => $this->devolverSessoesVencidas(),
            'abertas' => $this->devolverAvaliacoesParadas(),
        ];
    }

    /**
     * Devolve ao bolo a fila de sessão deste avaliador: as designações que o
     * algoritmo criou e ele não abriu.
     *
     * Aqui a devolução **apaga** a linha, e não a marca como devolvida: não há
     * nada dentro dela para guardar — ninguém abriu o projeto — e deixar rastro
     * de cada sessão encheria a tabela sem informar nada.
     */
    private function devolverFilaDeSessao(User $user): int
    {
        return Avaliacao::where('avaliador_id', $user->id)->devolvivel()->delete();
    }

    /**
     * As filas de quem sumiu: designações sem atividade há mais horas do que o
     * admin permitiu. Só faz sentido no modo por atividade — no modo total a
     * fila é para ficar guardada mesmo.
     *
     * A designação **sem** `atividade_em` é a que nasceu antes desta sprint (ou
     * numa distribuição em massa): o `created_at` faz as vezes dela, senão elas
     * nunca venceriam.
     */
    private function devolverSessoesVencidas(): int
    {
        if (! Edicao::modoDistribuicao()->designaNoLogin()) {
            return 0;
        }

        $limite = now()->subHours(Edicao::horasSessaoAvaliador());

        return Avaliacao::query()
            ->devolvivel()
            ->where(fn ($q) => $q
                ->where('atividade_em', '<', $limite)
                ->orWhere(fn ($sem) => $sem->whereNull('atividade_em')->where('created_at', '<', $limite)))
            ->delete();
    }

    /**
     * As avaliações abertas há tempo demais. O projeto volta para a pilha, mas
     * a linha **não é apagada**: as respostas que o avaliador já deu ficam
     * guardadas (`devolvida_em`), e ele retoma de onde parou se o projeto ainda
     * aceitar avaliação. Apagar seria mais simples e jogaria fora trabalho de
     * gente que só demorou.
     *
     * Vale nos dois modos, e a **designação manual não é exceção aqui**: o que
     * a protege é a devolução automática de fila, não o prazo de uma avaliação
     * que alguém abriu e largou. Cada devolução entra em Registros → Avaliação
     * Online, porque tirar um projeto das mãos de quem já começou é intervenção,
     * não mecânica de sessão.
     */
    private function devolverAvaliacoesParadas(): int
    {
        $dias = Edicao::diasAvaliacaoAberta();

        if ($dias === null) {
            return 0;
        }

        $limite = now()->subDays($dias);

        $paradas = Avaliacao::query()
            ->where('status', StatusAvaliacao::EmAndamento->value)
            ->where(fn ($q) => $q
                ->where('atividade_em', '<', $limite)
                ->orWhere(fn ($sem) => $sem->whereNull('atividade_em')->where('updated_at', '<', $limite)))
            ->with(['projeto:id,titulo', 'avaliador:id,name'])
            ->get();

        if ($paradas->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($paradas, $dias) {
            foreach ($paradas as $avaliacao) {
                $avaliacao->update(['devolvida_em' => now()]);

                if ($avaliacao->projeto !== null) {
                    $this->registros->avaliacaoDevolvidaPorPrazo(
                        $avaliacao->projeto,
                        $avaliacao->avaliador?->name ?? '(avaliador removido)',
                        $dias,
                    );
                }
            }
        });

        return $paradas->count();
    }

    /**
     * O ciclo de sessão vale para este usuário agora? Precisa ser avaliador, a
     * edição estar no modo por atividade e o período de avaliação estar aberto.
     *
     * Conta demo e conta inativa são recusadas pela própria fila, que já as
     * deixa de fora da reposição — aqui não é preciso repetir a regra.
     */
    private function seAplica(User $user): bool
    {
        if ($user->role !== Role::Avaliador || ! Edicao::modoDistribuicao()->designaNoLogin()) {
            return false;
        }

        $edicao = Edicao::atual();

        return $edicao !== null && $edicao->avaliacaoLiberada() && ! $edicao->avaliacaoEncerrada();
    }
}
