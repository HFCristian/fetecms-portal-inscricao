import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getAjustes, getAjustesProjeto, decidirAjuste } from '../lib/ajustes.js';

// Como cada nível se apresenta. A ordem aqui é a ordem dos blocos na tela:
// o que foi bem primeiro, o que precisa de trabalho por último.
const NIVEIS = [
    {
        chave: 'forte', titulo: 'Pontos fortes', icone: 'trending_up',
        classe: 'bg-secondary-container/40 text-on-secondary-container border-secondary-container',
        vazio: 'Nenhuma etapa ficou nesta faixa.',
    },
    {
        chave: 'medio', titulo: 'Pontos médios', icone: 'trending_flat',
        classe: 'bg-surface-variant/50 text-on-surface border-outline-variant',
        vazio: 'Nenhuma etapa ficou nesta faixa.',
    },
    {
        chave: 'fraco', titulo: 'Pontos fracos', icone: 'trending_down',
        classe: 'bg-error-container/30 text-on-surface border-error/30',
        vazio: 'Nenhuma etapa ficou nesta faixa.',
    },
];

/** Uma linha do cartão: "2 avaliação(ões) · 1 sugestão(ões) · …". */
function resumoProjeto(p) {
    const partes = [
        p.avaliacoes === 0 ? 'Ainda sem avaliação concluída' : `${p.avaliacoes} avaliação(ões)`,
    ];

    if (p.sugestoes > 0) partes.push(`${p.sugestoes} sugestão(ões) · ${p.pendentes} sem resposta`);
    if (p.recomendacoes > 0) partes.push(`${p.recomendacoes} recomendação(ões)`);

    return partes.join(' · ');
}

/**
 * Uma sugestão de reclassificação: o que o projeto tem hoje, o que o avaliador
 * sugeriu e o botão de aceitar/desfazer.
 *
 * A sugestão NÃO some depois de decidida — até o fim do prazo o orientador pode
 * mudar de ideia.
 */
function Sugestao({ sugestao, salvando, onDecidir }) {
    return (
        <li className="border border-outline-variant/40 rounded-xl p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-semibold text-primary-container uppercase tracking-wide">
                        {sugestao.tipo_label} · {sugestao.avaliador}
                    </p>
                    <p className="text-sm text-on-surface mt-1">
                        <span className="text-on-surface-variant line-through">{sugestao.atual ?? 'sem classificação'}</span>
                        <span className="material-symbols-outlined text-[16px] align-[-3px] mx-1 text-on-surface-variant">arrow_forward</span>
                        <strong>{sugestao.sugerido}</strong>
                    </p>
                </div>

                <div className="flex items-center gap-2 shrink-0">
                    {sugestao.aceito && (
                        <span className="text-xs font-semibold px-2 py-1 rounded-full bg-secondary-container text-on-secondary-container">
                            Em vigor
                        </span>
                    )}
                    <Button
                        type="button"
                        variant={sugestao.aceito ? 'outline' : 'primary'}
                        disabled={salvando}
                        onClick={() => onDecidir(sugestao, !sugestao.aceito)}
                    >
                        {sugestao.aceito ? 'Desfazer' : 'Aceitar'}
                    </Button>
                </div>
            </div>
        </li>
    );
}

/** Um bloco de etapas do mesmo nível. */
function BlocoNivel({ nivel, secoes }) {
    return (
        <div className={`border rounded-xl p-4 ${nivel.classe}`}>
            <h4 className="font-display text-sm font-semibold flex items-center gap-1 mb-2">
                <span className="material-symbols-outlined text-[18px]">{nivel.icone}</span>
                {nivel.titulo}
            </h4>
            {secoes.length === 0 ? (
                <p className="text-xs opacity-80">{nivel.vazio}</p>
            ) : (
                <ul className="flex flex-wrap gap-2">
                    {secoes.map((s) => (
                        <li key={s.chave} className="text-sm bg-surface/70 rounded-lg px-3 py-1.5">
                            {s.titulo}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Aba "Ajustes e Pareceres" do orientador. Depois da avaliação online, dentro
 * do período definido pelo admin, ele abre cada projeto e encontra num lugar só
 * tudo o que os avaliadores disseram:
 *
 * - as **sugestões de classificação**, que ele aceita ou desfaz;
 * - as **etapas da rubrica** agrupadas em pontos fortes, médios e fracos;
 * - e as **recomendações escritas**, só para leitura.
 *
 * Eram duas abas (Ajustes e Pareceres) sobre o mesmo material, na mesma janela:
 * quem lia a crítica numa tela tinha de trocar de tela para decidir.
 *
 * **Nota nenhuma aparece aqui** — nem a de cada etapa, nem a que o projeto
 * recebeu. O avaliador é anônimo ("Avaliador 1", "Avaliador 2").
 *
 * Fora do período a aba continua no menu, mas não abre — a tela explica por quê.
 */
export default function AjustesPareceres() {
    const [dados, setDados] = useState(null);
    const [aberto, setAberto] = useState(null);
    const [detalhe, setDetalhe] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [salvando, setSalvando] = useState(false);
    const [alert, setAlert] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback((teste) => getAjustes(teste)
        .then(setDados)
        .catch(() => setDados({ janela: { aberta: false, is_demo: false }, projetos: [] })), []);

    useEffect(() => { carregar(modoTeste); }, [carregar, modoTeste]);

    async function abrir(projeto) {
        setAlert(''); setSucesso('');
        setAberto(projeto);
        setDetalhe(null);
        try {
            setDetalhe(await getAjustesProjeto(projeto.id, modoTeste));
        } catch (e) {
            setAlert(extractErrors(e).message);
        }
    }

    async function decidir(sugestao, aceito) {
        setSalvando(true); setAlert(''); setSucesso('');
        try {
            const resp = await decidirAjuste(aberto.id, {
                avaliacao_id: sugestao.avaliacao_id,
                tipo: sugestao.tipo,
                aceito,
            }, modoTeste);
            setDetalhe(resp.data);
            setSucesso(resp.meta?.message ?? '');
            await carregar(modoTeste);
        } catch (e) {
            setAlert(extractErrors(e).message);
        } finally {
            setSalvando(false);
        }
    }

    const janela = dados?.janela;
    const secoes = detalhe?.secoes ?? [];
    const secoesDe = (chave) => secoes.filter((s) => s.nivel === chave);
    const naoAvaliadas = secoes.filter((s) => s.nivel === null);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Ajustes e Pareceres</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                O que os avaliadores disseram sobre os seus projetos: você decide se aceita a troca
                de área ou subárea — e pode mudar de ideia enquanto o período estiver aberto — e lê
                em que etapas o trabalho foi melhor ou pior. Os avaliadores são anônimos.
            </p>

            {janela?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Orientador demo: veja os ajustes e os pareceres mesmo fora do período definido pela organização."
                    />
                </div>
            )}

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : !janela.aberta ? (
                <div className="max-w-3xl bg-surface-container-lowest rounded-xl fetec-card-shadow p-6">
                    <span className="material-symbols-outlined text-primary-container text-3xl">lock_clock</span>
                    <h2 className="font-display text-lg font-semibold text-on-surface mt-2">
                        {janela.encerrada
                            ? 'O período de ajustes terminou'
                            : 'O período de ajustes ainda não começou'}
                    </h2>
                    <p className="text-sm text-on-surface-variant mt-1">
                        {janela.encerrada
                            ? `Esta aba ficou aberta até ${janela.ate_label}. As decisões que você tomou continuam valendo.`
                            : janela.de_label
                                ? `A aba abre em ${janela.de_label}, depois do fim da avaliação online.`
                                : 'A organização ainda não definiu a data. Quando definir, esta aba abre por aqui mesmo.'}
                    </p>
                </div>
            ) : aberto === null ? (
                <div className="max-w-3xl space-y-3">
                    <Alert>{alert}</Alert>
                    {dados.projetos.length === 0 ? (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-sm text-on-surface-variant">
                            Você ainda não tem projetos submetidos com avaliação concluída.
                        </div>
                    ) : dados.projetos.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            onClick={() => abrir(p)}
                            className="w-full text-left bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex items-center gap-3 hover:ring-2 hover:ring-primary-container/30 transition-all"
                        >
                            <span className="material-symbols-outlined text-primary-container">description</span>
                            <span className="min-w-0 grow">
                                <span className="block font-semibold text-on-surface truncate">{p.titulo}</span>
                                <span className="block text-xs text-on-surface-variant">
                                    {p.area ?? 'Sem área'}{p.subarea ? ` · ${p.subarea}` : ''}
                                </span>
                                <span className="block text-xs text-on-surface-variant mt-1">
                                    {resumoProjeto(p)}
                                </span>
                            </span>
                            <span className="material-symbols-outlined text-on-surface-variant">chevron_right</span>
                        </button>
                    ))}
                </div>
            ) : (
                <div className="max-w-3xl space-y-4">
                    <button
                        type="button"
                        onClick={() => { setAberto(null); setDetalhe(null); }}
                        className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"
                    >
                        <span className="material-symbols-outlined text-[18px]">arrow_back</span> Meus projetos
                    </button>

                    <Alert>{alert}</Alert>
                    <Alert type="info">{sucesso}</Alert>

                    {detalhe === null ? (
                        <div className="text-center py-10 text-on-surface-variant">
                            <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                        </div>
                    ) : (
                        <>
                            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                                <h2 className="font-display text-lg font-semibold text-on-surface">{detalhe.titulo}</h2>
                                <p className="text-sm text-on-surface-variant">
                                    Classificação atual: <strong>{detalhe.area ?? 'sem área'}</strong>
                                    {detalhe.subarea ? ` · ${detalhe.subarea}` : ''}
                                </p>
                                <p className="text-xs text-on-surface-variant mt-1">
                                    Resultado de {detalhe.avaliacoes} avaliação(ões) concluída(s).
                                </p>
                            </div>

                            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                                <h3 className="font-display text-base font-semibold text-on-surface mb-1">
                                    Sugestões de classificação
                                </h3>
                                <p className="text-xs text-on-surface-variant mb-3">
                                    Aceitar troca a classificação do projeto na hora. Todas as sugestões
                                    continuam aqui até o fim do prazo, então dá para desfazer.
                                </p>
                                {detalhe.sugestoes.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">
                                        Nenhum avaliador sugeriu trocar a área ou a subárea deste projeto.
                                    </p>
                                ) : (
                                    <ul className="space-y-3">
                                        {detalhe.sugestoes.map((s) => (
                                            <Sugestao
                                                key={`${s.avaliacao_id}-${s.tipo}`}
                                                sugestao={s}
                                                salvando={salvando}
                                                onDecidir={decidir}
                                            />
                                        ))}
                                    </ul>
                                )}
                            </section>

                            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                                <h3 className="font-display text-base font-semibold text-on-surface mb-1">
                                    Etapas da avaliação
                                </h3>
                                <p className="text-xs text-on-surface-variant mb-3">
                                    Cada etapa da rubrica, agrupada pelo desempenho do projeto. A nota não
                                    é divulgada — o que vale para você é onde o trabalho foi bem e onde
                                    ficou devendo.
                                </p>
                                <div className="space-y-3">
                                    {NIVEIS.map((n) => (
                                        <BlocoNivel key={n.chave} nivel={n} secoes={secoesDe(n.chave)} />
                                    ))}
                                </div>
                                {naoAvaliadas.length > 0 && (
                                    <p className="text-xs text-on-surface-variant mt-3">
                                        Sem resposta registrada em: {naoAvaliadas.map((s) => s.titulo).join(', ')}.
                                    </p>
                                )}
                            </section>

                            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                                <h3 className="font-display text-base font-semibold text-on-surface mb-1">
                                    Recomendações dos avaliadores
                                </h3>
                                <p className="text-xs text-on-surface-variant mb-3">
                                    Comentários escritos durante a avaliação. Não há nada a responder aqui.
                                </p>
                                {detalhe.recomendacoes.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">
                                        Nenhum avaliador deixou observação escrita neste projeto.
                                    </p>
                                ) : (
                                    <ul className="space-y-3">
                                        {detalhe.recomendacoes.map((r, i) => (
                                            <li key={`${r.avaliacao_id}-${r.tipo}-${i}`} className="border border-outline-variant/40 rounded-xl p-4">
                                                <p className="text-xs font-semibold text-primary-container uppercase tracking-wide">
                                                    {r.titulo} · {r.avaliador}
                                                </p>
                                                <p className="text-sm text-on-surface mt-1 whitespace-pre-line">{r.texto}</p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        </>
                    )}
                </div>
            )}
        </AppShell>
    );
}
