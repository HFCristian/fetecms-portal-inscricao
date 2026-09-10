<?php

namespace App\Services;

use App\Enums\ModoDistribuicao;
use App\Enums\Role;
use App\Models\Edicao;
use App\Models\User;

/**
 * A fila do avaliador amarrada à **sessão** dele — o coração do modo
 * {@see ModoDistribuicao::Atividade}.
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
 * O que **não** entra nesta conta é a designação manual do admin: ela é escape
 * do edital e não é de sessão nenhuma.
 */
class SessaoAvaliadorService
{
    public function __construct(private readonly FilaAvaliadorService $fila) {}

    /**
     * Chamado no login. No modo por atividade, monta a fila de trabalho do
     * avaliador na hora; nos demais casos não faz nada.
     *
     * Devolve quantas designações novas foram criadas — 0 quando o modo não é
     * este, quando o usuário não é avaliador ou quando a avaliação ainda não
     * está aberta (designar antes da liberação só encheria a tela do que ele
     * ainda não pode abrir).
     */
    public function aoEntrar(User $user): int
    {
        if (! $this->seAplica($user)) {
            return 0;
        }

        return $this->fila->repor($user);
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
