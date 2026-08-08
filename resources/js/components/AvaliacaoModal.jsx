import { useEffect, useState } from 'react';
import { Button, Alert, Select, useConfirm } from './ui.jsx';
import { getAvaliacao, iniciarAvaliacao, concluirAvaliacao } from '../lib/avaliacao.js';

const PILL = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

// Rubrica: cada quesito vale de 0 a 10 (obrigatório) e aceita sugestões e
// comentários (opcional). A nota final é a soma dos três, calculada no backend.
const QUESITOS = [
    { key: 'video', titulo: 'Vídeo de apresentação', icon: 'movie' },
    { key: 'resumo', titulo: 'Resumo do projeto', icon: 'description' },
    { key: 'pesquisa', titulo: 'Projeto de pesquisa', icon: 'science' },
];

const NOTA_MAXIMA_QUESITO = 10;

const rubricaVazia = () =>
    Object.fromEntries(QUESITOS.flatMap((q) => [[`nota_${q.key}`, ''], [`comentario_${q.key}`, '']]));

/** Preenche o formulário com o que já estiver gravado na avaliação. */
const rubricaDe = (avaliacao) =>
    Object.fromEntries(
        QUESITOS.flatMap((q) => [
            [`nota_${q.key}`, avaliacao?.[`nota_${q.key}`] ?? ''],
            [`comentario_${q.key}`, avaliacao?.[`comentario_${q.key}`] ?? ''],
        ]),
    );

function Campo({ label, children }) {
    if (!children || (Array.isArray(children) && children.length === 0)) return null;
    return (
        <div>
            <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">{label}</p>
            <div className="text-sm text-on-surface">{children}</div>
        </div>
    );
}

// Um quesito da rubrica: nota obrigatória de 0 a 10 + comentário opcional.
function Quesito({ quesito, nota, comentario, onNota, onComentario, erros }) {
    const erroNota = erros?.[`nota_${quesito.key}`]?.[0];

    return (
        <div className="rounded-xl border border-outline-variant/40 p-4 space-y-3">
            <div className="flex items-center gap-2">
                <span className="material-symbols-outlined text-primary-container text-[20px]">{quesito.icon}</span>
                <h4 className="font-semibold text-on-surface">{quesito.titulo}</h4>
            </div>

            <div className="flex items-center gap-3">
                <label className="text-sm text-on-surface-variant" htmlFor={`nota-${quesito.key}`}>
                    Nota (0 a {NOTA_MAXIMA_QUESITO}) <span className="text-error">*</span>
                </label>
                <div className="w-24">
                    <Select
                        id={`nota-${quesito.key}`}
                        value={nota}
                        error={erroNota}
                        onChange={(e) => onNota(e.target.value)}
                    >
                        <option value="">—</option>
                        {Array.from({ length: NOTA_MAXIMA_QUESITO + 1 }, (_, n) => (
                            <option key={n} value={n}>{n}</option>
                        ))}
                    </Select>
                </div>
            </div>
            {erroNota && <p className="text-xs text-error">{erroNota}</p>}

            <div>
                <label className="block text-sm text-on-surface-variant mb-1" htmlFor={`comentario-${quesito.key}`}>
                    Sugestões e comentários <span className="text-on-surface-variant/70">(opcional)</span>
                </label>
                <textarea
                    id={`comentario-${quesito.key}`}
                    value={comentario}
                    onChange={(e) => onComentario(e.target.value)}
                    rows={3}
                    maxLength={2000}
                    className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none resize-y"
                    placeholder={`O que o time pode melhorar em "${quesito.titulo.toLowerCase()}"?`}
                />
            </div>
        </div>
    );
}

// Rubrica já enviada, em leitura (avaliação concluída).
function RubricaConcluida({ avaliacao }) {
    return (
        <div className="space-y-3">
            {QUESITOS.map((q) => (
                <div key={q.key} className="rounded-xl border border-outline-variant/40 p-4">
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <span className="material-symbols-outlined text-primary-container text-[20px]">{q.icon}</span>
                            <h4 className="font-semibold text-on-surface">{q.titulo}</h4>
                        </div>
                        <span className="text-lg font-bold text-secondary shrink-0">
                            {avaliacao[`nota_${q.key}`]}/{NOTA_MAXIMA_QUESITO}
                        </span>
                    </div>
                    {avaliacao[`comentario_${q.key}`] && (
                        <p className="mt-2 text-sm text-on-surface-variant whitespace-pre-line">
                            {avaliacao[`comentario_${q.key}`]}
                        </p>
                    )}
                </div>
            ))}
        </div>
    );
}

// Modal de avaliação: lê o projeto inteiro, inicia (sem cancelar) e conclui
// preenchendo a rubrica (3 quesitos de 0 a 10 + comentários), nota final 0–30.
export default function AvaliacaoModal({ avaliacaoId, teste, onFechar, onAtualizado }) {
    const [dados, setDados] = useState(null); // { avaliacao, projeto } | false (erro)
    const [rubrica, setRubrica] = useState(rubricaVazia);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [erros, setErros] = useState({}); // erros de validação por campo (422)
    const [confirm, dialogo] = useConfirm();

    useEffect(() => {
        getAvaliacao(avaliacaoId, teste)
            .then((d) => { setDados(d); setRubrica(rubricaDe(d.avaliacao)); })
            .catch(() => setDados(false));
    }, [avaliacaoId, teste]);

    const setCampo = (campo, valor) => setRubrica((r) => ({ ...r, [campo]: valor }));

    async function iniciar() {
        const ok = await confirm({
            title: 'Iniciar avaliação', confirmLabel: 'Iniciar',
            message: 'Ao iniciar, você não poderá cancelar nem trocar de projeto até concluir esta avaliação. Continuar?',
        });
        if (!ok) return;
        setSalvando(true); setErro('');
        try {
            const a = await iniciarAvaliacao(avaliacaoId, teste);
            setDados((d) => ({ ...d, avaliacao: a }));
            onAtualizado?.();
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível iniciar.');
        } finally {
            setSalvando(false);
        }
    }

    async function concluir() {
        const ok = await confirm({
            title: 'Enviar avaliação', confirmLabel: 'Enviar',
            message: `Nota final: ${total} de ${notaMaxima}. Depois de enviada, a avaliação não pode ser alterada. Continuar?`,
        });
        if (!ok) return;

        setSalvando(true); setErro(''); setErros({});
        try {
            const a = await concluirAvaliacao(avaliacaoId, payload(), teste);
            setDados((d) => ({ ...d, avaliacao: a }));
            onAtualizado?.();
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível concluir.');
            setErros(e?.response?.data?.errors ?? {});
        } finally {
            setSalvando(false);
        }
    }

    /** Notas viram número; comentário em branco vira null. */
    function payload() {
        return Object.fromEntries(
            QUESITOS.flatMap((q) => [
                [`nota_${q.key}`, rubrica[`nota_${q.key}`] === '' ? null : Number(rubrica[`nota_${q.key}`])],
                [`comentario_${q.key}`, rubrica[`comentario_${q.key}`].trim() || null],
            ]),
        );
    }

    const av = dados && dados.avaliacao;
    const p = dados && dados.projeto;
    const notaMaxima = av?.nota_maxima ?? QUESITOS.length * NOTA_MAXIMA_QUESITO;
    const notasPreenchidas = QUESITOS.filter((q) => rubrica[`nota_${q.key}`] !== '');
    const completa = notasPreenchidas.length === QUESITOS.length;
    const total = notasPreenchidas.reduce((s, q) => s + Number(rubrica[`nota_${q.key}`]), 0);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <div className="flex items-start justify-between gap-3 px-5 py-4 border-b border-outline-variant/30">
                    <h3 className="font-display text-lg font-semibold text-on-surface truncate">
                        {p ? p.titulo : 'Avaliação'}
                    </h3>
                    <div className="flex items-center gap-2 shrink-0">
                        {av && (
                            <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${PILL[av.status] ?? 'bg-surface-variant'}`}>
                                {av.status_label}
                            </span>
                        )}
                        <button type="button" onClick={onFechar} aria-label="Fechar" className="text-on-surface-variant p-1">
                            <span className="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-5 space-y-4">
                    {dados === null ? (
                        <div className="text-center py-8 text-on-surface-variant">
                            <span className="inline-block w-6 h-6 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                        </div>
                    ) : dados === false ? (
                        <Alert>Não foi possível carregar o projeto.</Alert>
                    ) : (
                        <>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <Campo label="Categoria">{p.categoria}</Campo>
                                <Campo label="Área">{p.area}{p.subarea ? ` · ${p.subarea}` : ''}</Campo>
                                <Campo label="Instituição">{p.instituicao}</Campo>
                                <Campo label="Coorientador">{p.coorientador}</Campo>
                            </div>
                            <Campo label="Estudantes">{p.alunos?.length ? p.alunos.join(', ') : null}</Campo>
                            <Campo label="Palavras-chave">
                                {p.palavras_chave?.length ? (
                                    <div className="flex flex-wrap gap-1 mt-1">
                                        {p.palavras_chave.map((k) => (
                                            <span key={k} className="text-xs bg-surface-variant text-on-surface-variant rounded-full px-2 py-0.5">{k}</span>
                                        ))}
                                    </div>
                                ) : null}
                            </Campo>
                            <Campo label="Resumo">
                                <p className="whitespace-pre-line mt-1">{p.resumo}</p>
                            </Campo>
                            {p.link_video && (
                                <Campo label="Vídeo">
                                    <a href={p.link_video} target="_blank" rel="noreferrer" className="text-primary-container hover:underline break-all">{p.link_video}</a>
                                </Campo>
                            )}
                            {p.documentos?.length > 0 && (
                                <Campo label="Documentos">
                                    <ul className="mt-1 space-y-1">
                                        {p.documentos.map((d) => (
                                            <li key={d.id}>
                                                <a href={d.download_url} className="inline-flex items-center gap-1 text-primary-container hover:underline">
                                                    <span className="material-symbols-outlined text-[18px]">download</span>
                                                    {d.nome_original || d.tipo_label}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </Campo>
                            )}
                        </>
                    )}

                    {av?.status === 'em_andamento' && (
                        <section className="pt-2 space-y-3">
                            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">
                                Avaliação
                            </h4>
                            {QUESITOS.map((q) => (
                                <Quesito
                                    key={q.key}
                                    quesito={q}
                                    erros={erros}
                                    nota={rubrica[`nota_${q.key}`]}
                                    comentario={rubrica[`comentario_${q.key}`]}
                                    onNota={(v) => setCampo(`nota_${q.key}`, v)}
                                    onComentario={(v) => setCampo(`comentario_${q.key}`, v)}
                                />
                            ))}
                        </section>
                    )}

                    {av?.status === 'concluida' && (
                        <section className="pt-2 space-y-3">
                            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">
                                Avaliação enviada
                            </h4>
                            <RubricaConcluida avaliacao={av} />
                        </section>
                    )}
                </div>

                {av && (
                    <div className="px-5 py-4 border-t border-outline-variant/30">
                        {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
                        {av.status === 'designada' && (
                            <div className="flex justify-end">
                                <Button type="button" loading={salvando} onClick={iniciar}>Iniciar avaliação</Button>
                            </div>
                        )}
                        {av.status === 'em_andamento' && (
                            <div className="flex items-center justify-between gap-3 flex-wrap">
                                <p className="text-sm text-on-surface-variant">
                                    Nota final{' '}
                                    <strong className="text-lg text-primary-container">{total}</strong>
                                    <span className="text-on-surface-variant/70"> de {notaMaxima}</span>
                                    {!completa && <span className="block text-xs">Dê nota aos três quesitos para enviar.</span>}
                                </p>
                                <Button type="button" variant="success" loading={salvando} disabled={!completa} onClick={concluir}>
                                    Enviar avaliação
                                </Button>
                            </div>
                        )}
                        {av.status === 'concluida' && (
                            <p className="text-sm text-on-surface text-right">
                                Avaliação concluída — nota{' '}
                                <strong className="text-secondary">{av.nota} de {notaMaxima}</strong>.
                            </p>
                        )}
                    </div>
                )}
            </div>
            {dialogo}
        </div>
    );
}
