import { Button } from './ui.jsx';

/**
 * O que era a aba **Presencial** do avaliador (Sprint 131), dentro da
 * Avaliação presencial desde a Sprint 246.
 *
 * Ele responde se pretende avaliar **no dia da feira**. Quem aceita lê as
 * orientações da organização e fica sabendo que falta a **liberação** (Sprint
 * 223); quem recusa continua vendo só a pergunta, porque mudar de ideia é
 * permitido a qualquer momento (Sprint 225). Liberado, a tela troca este
 * conteúdo pela avaliação no estande.
 */
export default function IntencaoPresencial({ dados, salvando, onResponder }) {
    const aceitou = dados.presencial === true;
    const recusou = dados.presencial === false;

    return (
        <div className="max-w-3xl space-y-4">
            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                <h2 className="font-display text-base font-semibold text-on-surface mb-1">
                    Você quer avaliar presencialmente na feira?
                </h2>
                <p className="text-xs text-on-surface-variant mb-3">
                    {dados.evento_de_label
                        ? `O evento acontece de ${dados.evento_de_label} a ${dados.evento_ate_label ?? '—'}.`
                        : 'As datas do evento ainda não foram divulgadas.'}
                    {dados.apenas_presencial
                        ? ' Sua conta foi criada pela organização para a avaliação presencial: a participação já está confirmada.'
                        : ' Você pode mudar a resposta a qualquer momento, inclusive durante o evento.'}
                </p>

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant={aceitou ? 'primary' : 'outline'}
                        disabled={salvando || !dados.pode_alterar}
                        onClick={() => onResponder(true)}
                    >
                        <span className="material-symbols-outlined text-[20px]">
                            {aceitou ? 'check_circle' : 'radio_button_unchecked'}
                        </span>
                        Sim, quero participar
                    </Button>
                    <Button
                        type="button"
                        variant={recusou ? 'primary' : 'outline'}
                        disabled={salvando || !dados.pode_alterar}
                        onClick={() => onResponder(false)}
                    >
                        <span className="material-symbols-outlined text-[20px]">
                            {recusou ? 'check_circle' : 'radio_button_unchecked'}
                        </span>
                        Não vou participar
                    </Button>
                </div>

                {!dados.respondido && (
                    <p className="text-xs text-on-surface-variant mt-3">
                        Você ainda não respondeu.
                    </p>
                )}
            </section>

            {/* As orientações são o que a aba entrega em troca do "sim". */}
            {aceitou && <OrientacoesPresencial informacoes={dados.informacoes} />}

            {/* Disse "sim", mas a organização ainda não liberou: sem isso não há avaliação. */}
            {aceitou && (
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex items-start gap-3" aria-label="Aguardando liberação">
                    <span className="material-symbols-outlined text-primary-container text-2xl shrink-0">hourglass_top</span>
                    <div className="min-w-0">
                        <h2 className="font-display text-base font-semibold text-on-surface mb-1">
                            Aguardando a liberação da organização
                        </h2>
                        <p className="text-sm text-on-surface-variant">
                            No evento, procure a equipe da avaliação presencial para a organização liberar a sua
                            participação. Liberado, esta tela passa a mostrar o mapa dos estandes e os projetos
                            para você avaliar.
                        </p>
                    </div>
                </section>
            )}

            {recusou && (
                <p className="text-sm text-on-surface-variant">
                    Sem problema — sua avaliação online continua valendo normalmente.
                </p>
            )}
        </div>
    );
}

/** As orientações da organização para o dia do evento (local, horário, o que levar). */
export function OrientacoesPresencial({ informacoes, recolhivel = false }) {
    const corpo = informacoes ? (
        <p className="text-sm text-on-surface whitespace-pre-line">{informacoes}</p>
    ) : (
        <p className="text-sm text-on-surface-variant">
            A organização ainda não publicou as orientações. Elas aparecem aqui assim que forem divulgadas.
        </p>
    );

    if (recolhivel) {
        return (
            <details className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                <summary className="font-display text-base font-semibold text-on-surface cursor-pointer">
                    Orientações para o dia do evento
                </summary>
                <div className="mt-2">{corpo}</div>
            </details>
        );
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
            <h2 className="font-display text-base font-semibold text-on-surface mb-1">
                Orientações para o dia do evento
            </h2>
            {corpo}
        </section>
    );
}
