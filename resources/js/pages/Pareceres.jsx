import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getPareceres, getParecerProjeto } from '../lib/pareceres.js';

/** Nota em pt_BR com duas casas (7,35). */
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

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
 * Aba "Pareceres" do orientador: o resultado da avaliação online de cada
 * projeto que ele submeteu.
 *
 * Mostra a **nota média** do projeto e o que os avaliadores escreveram, sempre
 * anônimos ("Avaliador 1", "Avaliador 2"). As etapas da rubrica (título,
 * resumo, introdução…) aparecem **sem nota**, agrupadas em pontos fortes,
 * médios e fracos — o orientador precisa saber onde melhorar, não refazer a
 * conta que já está fechada.
 *
 * A janela é a mesma da aba Ajustes; fora dela a aba abre explicando o motivo.
 */
export default function Pareceres() {
    const [dados, setDados] = useState(null);
    const [aberto, setAberto] = useState(null);
    const [detalhe, setDetalhe] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [alert, setAlert] = useState('');

    const carregar = useCallback((teste) => getPareceres(teste)
        .then(setDados)
        .catch(() => setDados({ janela: { aberta: false, is_demo: false }, projetos: [] })), []);

    useEffect(() => { carregar(modoTeste); }, [carregar, modoTeste]);

    async function abrir(projeto) {
        setAlert('');
        setAberto(projeto);
        setDetalhe(null);
        try {
            setDetalhe(await getParecerProjeto(projeto.id, modoTeste));
        } catch (e) {
            setAlert(extractErrors(e).message);
        }
    }

    const janela = dados?.janela;
    const secoesDe = (chave) => (detalhe?.secoes ?? []).filter((s) => s.nivel === chave);
    const naoAvaliadas = (detalhe?.secoes ?? []).filter((s) => s.nivel === null);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Pareceres</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                O resultado da avaliação online dos seus projetos: a nota média, o que cada
                avaliador escreveu e em que etapas o trabalho foi melhor ou pior. Os avaliadores
                são anônimos.
            </p>

            {janela?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Orientador demo: veja a aba de pareceres mesmo fora do período definido pela organização."
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
                        {janela.encerrada ? 'O período de consulta terminou' : 'Os pareceres ainda não foram liberados'}
                    </h2>
                    <p className="text-sm text-on-surface-variant mt-1">
                        {janela.encerrada
                            ? `Esta aba ficou aberta até ${janela.ate_label}.`
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
                                    {p.avaliacoes === 0
                                        ? 'Ainda sem avaliação concluída'
                                        : `${p.avaliacoes} avaliação(ões)`}
                                    {p.recomendacoes > 0 && ` · ${p.recomendacoes} recomendação(ões)`}
                                </span>
                            </span>
                            <span className="text-right shrink-0">
                                <span className="block text-xl font-bold text-secondary">{nota(p.media)}</span>
                                <span className="block text-[10px] text-on-surface-variant leading-tight">
                                    de {nota(p.nota_maxima)}
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

                    {detalhe === null ? (
                        <div className="text-center py-10 text-on-surface-variant">
                            <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                        </div>
                    ) : (
                        <>
                            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex items-center gap-4">
                                <div className="min-w-0 grow">
                                    <h2 className="font-display text-lg font-semibold text-on-surface">{detalhe.titulo}</h2>
                                    <p className="text-sm text-on-surface-variant">
                                        {detalhe.area ?? 'sem área'}{detalhe.subarea ? ` · ${detalhe.subarea}` : ''}
                                    </p>
                                    <p className="text-xs text-on-surface-variant mt-1">
                                        Média de {detalhe.avaliacoes} avaliação(ões) concluída(s).
                                    </p>
                                </div>
                                <div className="text-right shrink-0">
                                    <div className="text-3xl font-bold text-secondary">{nota(detalhe.media)}</div>
                                    <div className="text-xs text-on-surface-variant">de {nota(detalhe.nota_maxima)}</div>
                                </div>
                            </div>

                            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                                <h3 className="font-display text-base font-semibold text-on-surface mb-1">
                                    Etapas da avaliação
                                </h3>
                                <p className="text-xs text-on-surface-variant mb-3">
                                    Cada etapa da rubrica, agrupada pelo desempenho do projeto. A nota de
                                    cada etapa não é divulgada — o que vale é a nota final acima.
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
                                    Observações dos avaliadores
                                </h3>
                                <p className="text-xs text-on-surface-variant mb-3">
                                    Comentários escritos durante a avaliação. Os avaliadores são anônimos.
                                </p>
                                {detalhe.recomendacoes.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">
                                        Nenhum avaliador deixou observação escrita neste projeto.
                                    </p>
                                ) : (
                                    <ul className="space-y-3">
                                        {detalhe.recomendacoes.map((r, i) => (
                                            <li key={`${r.avaliador}-${r.tipo}-${i}`} className="border border-outline-variant/40 rounded-xl p-4">
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
