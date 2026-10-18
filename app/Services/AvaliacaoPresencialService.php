<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aba "Presencial" do avaliador: ele diz se pretende avaliar **no dia da
 * feira**, e quem aceita passa a ver as orientações da organização.
 *
 * A resposta tem **três estados**: sim, não e **ainda não respondeu**. O nulo
 * não é um detalhe técnico — a organização precisa saber a quem cobrar, e quem
 * nunca abriu a aba não é a mesma coisa que quem recusou.
 *
 * A resposta muda **a qualquer momento**, inclusive durante o evento (Sprint
 * 225): o avaliador online que decide ir no dia marca "sim" e procura a equipe
 * — avaliar continua dependendo da liberação da organização (Validação de
 * avaliadores). Quem desiste ("não") devolve ao bolo o que estava só designado
 * para ele no estande. Quem respondeu "não" continua vendo a pergunta (e não
 * as orientações), justamente para poder voltar atrás.
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
            // Sem trava de data (Sprint 225). Só a conta criada pela organização
            // para o presencial (Sprint 191) não tem o que responder: ela existe
            // para isso.
            'pode_alterar' => ! $perfil?->apenas_presencial,
            'apenas_presencial' => (bool) $perfil?->apenas_presencial,
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
        $perfil = $this->perfil($avaliador);

        if ($perfil === null) {
            throw ValidationException::withMessages([
                'presencial' => 'Complete o seu cadastro de avaliador antes de responder.',
            ]);
        }

        if ($perfil->apenas_presencial) {
            throw ValidationException::withMessages([
                'presencial' => 'Sua conta foi criada pela organização para a avaliação presencial — fale com ela para mudar a sua participação.',
            ]);
        }

        DB::transaction(function () use ($perfil, $presencial, $avaliador) {
            $perfil->forceFill([
                'presencial' => $presencial,
                'presencial_em' => now(),
            ])->save();

            // Desistiu: o que estava só designado para ele no estande volta ao
            // bolo. O que ele já abriu fica — se voltar atrás, retoma.
            if (! $presencial) {
                AvaliacaoPresencial::where('edicao_id', Edicao::atual()?->id)
                    ->where('avaliador_id', $avaliador->id)
                    ->where('status', StatusAvaliacao::Designada->value)
                    ->delete();
            }
        });

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
