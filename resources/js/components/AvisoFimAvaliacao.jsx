import { useNavigate } from 'react-router-dom';

/**
 * Aviso da tela inicial do orientador: a avaliação online acabou.
 *
 * É o momento em que ele precisa voltar ao portal e não tem por que saber
 * disso — ninguém lhe manda um e-mail dizendo "os avaliadores terminaram". O
 * cartão avisa e leva, num clique, à aba **Ajustes e Pareceres**, onde está o
 * que a avaliação produziu: as sugestões de área e subárea, que ele decide, e
 * os pareceres, que ele lê.
 *
 * Quando aparece: só depois do fim do período de avaliação
 * (`edicoes.avaliacao_encerrada_em`), e só para quem **submeteu** algum projeto
 * — quem ficou no rascunho não teve projeto avaliado, e o aviso seria ruído.
 * Some quando o período de ajustes se encerra: aí não há mais o que acompanhar,
 * e as decisões tomadas continuam valendo.
 *
 * Nenhuma nota aparece aqui, pela mesma razão da aba: o número é da
 * organização.
 *
 * `janela` é o payload de GET /ajustes/janela.
 */
export default function AvisoFimAvaliacao({ janela, temProjetoSubmetido, className = '' }) {
    const navigate = useNavigate();

    if (!janela?.avaliacao_encerrada || !temProjetoSubmetido || janela.encerrada) return null;

    return (
        <section className={`bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 border-l-4 border-secondary ${className}`}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h2 className="font-display text-base font-semibold text-on-surface flex items-center gap-2">
                        <span className="material-symbols-outlined text-secondary">task_alt</span>
                        A avaliação online terminou
                    </h2>
                    <p className="text-sm text-on-surface-variant mt-1 max-w-2xl">
                        {janela.avaliacao_encerrada_em_label
                            ? <>Os avaliadores concluíram o trabalho em <strong className="text-on-surface">{janela.avaliacao_encerrada_em_label}</strong>. </>
                            : 'Os avaliadores concluíram o trabalho. '}
                        {janela.aberta ? (
                            <>
                                Em <strong className="text-on-surface">Ajustes e Pareceres</strong> você vê o que
                                eles disseram sobre cada projeto seu e responde às sugestões de área e subárea
                                {janela.ate_label ? <> — o prazo vai até <strong className="text-on-surface">{janela.ate_label}</strong></> : null}.
                            </>
                        ) : janela.de_label ? (
                            <>
                                A aba <strong className="text-on-surface">Ajustes e Pareceres</strong> abre em{' '}
                                <strong className="text-on-surface">{janela.de_label}</strong>, com o que os
                                avaliadores disseram sobre cada projeto seu.
                            </>
                        ) : (
                            <>
                                A organização ainda não abriu o período de ajustes. Quando abrir, o que os
                                avaliadores disseram aparece em{' '}
                                <strong className="text-on-surface">Ajustes e Pareceres</strong>.
                            </>
                        )}
                    </p>
                </div>

                <button
                    type="button"
                    onClick={() => navigate('/ajustes')}
                    className="shrink-0 inline-flex items-center gap-2 rounded-lg px-5 py-2.5 font-semibold bg-primary-container text-on-primary hover:bg-primary transition-colors"
                >
                    <span className="material-symbols-outlined text-[20px]">rule_settings</span>
                    AJUSTES E PARECERES
                </button>
            </div>
        </section>
    );
}
