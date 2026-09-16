<?php

namespace App\Services;

use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Aba "Presencial" do avaliador: ele diz se pretende avaliar **no dia da
 * feira**, e quem aceita passa a ver as orientações da organização.
 *
 * A resposta tem **três estados**: sim, não e **ainda não respondeu**. O nulo
 * não é um detalhe técnico — a organização precisa saber a quem cobrar, e quem
 * nunca abriu a aba não é a mesma coisa que quem recusou.
 *
 * Mudar de ideia é permitido **até o evento começar**: depois disso a escala do
 * dia já foi montada em cima de quem respondeu, e uma desistência tardia é
 * assunto de telefone, não de formulário. Quem respondeu "não" continua vendo a
 * pergunta (e não as orientações), justamente para poder voltar atrás.
 */
class AvaliacaoPresencialService
{
    /**
     * O que a tela do avaliador precisa saber.
     *
     * @return array<string, mixed>
     */
    public function situacao(User $avaliador, bool $teste = false): array
    {
        $perfil = $this->perfil($avaliador);
        $edicao = Edicao::atual();
        $modoTeste = $teste && (bool) $avaliador->is_demo;

        $comecou = (bool) $edicao?->eventoIniciado();
        $aceitou = $perfil?->presencial === true;

        return [
            'respondido' => $perfil?->presencial !== null,
            'presencial' => $perfil?->presencial,
            'respondido_em' => $perfil?->presencial_em?->toIso8601String(),
            // Depois que o evento começa, a escala do dia já está montada.
            'pode_alterar' => $modoTeste || ! $comecou,
            'evento_iniciado' => $comecou,
            'evento_de_label' => $edicao?->evento_de?->format('d/m/Y H:i'),
            'evento_ate_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            // As orientações só existem para quem aceitou: é o que a aba
            // entrega em troca do "sim".
            'informacoes' => $aceitou ? ($edicao?->info_avaliacao_presencial ?: null) : null,
            'modo_teste' => $modoTeste,
            'is_demo' => (bool) $avaliador->is_demo,
        ];
    }

    /**
     * Grava a intenção do avaliador.
     *
     * @return array<string, mixed> a situação recarregada
     */
    public function responder(User $avaliador, bool $presencial, bool $teste = false): array
    {
        if (! $this->situacao($avaliador, $teste)['pode_alterar']) {
            throw ValidationException::withMessages([
                'presencial' => 'O evento já começou — fale com a organização para alterar a sua participação.',
            ]);
        }

        $perfil = $this->perfil($avaliador);

        if ($perfil === null) {
            throw ValidationException::withMessages([
                'presencial' => 'Complete o seu cadastro de avaliador antes de responder.',
            ]);
        }

        $perfil->forceFill([
            'presencial' => $presencial,
            'presencial_em' => now(),
        ])->save();

        return $this->situacao($avaliador->refresh(), $teste);
    }

    /** Este avaliador se dispôs a avaliar presencialmente? */
    public function aceitou(User $avaliador): bool
    {
        return $this->perfil($avaliador)?->presencial === true;
    }

    private function perfil(User $avaliador): ?AvaliadorProfile
    {
        return $avaliador->avaliadorProfile()->first();
    }
}
