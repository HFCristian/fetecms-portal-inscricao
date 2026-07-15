import { useEffect, useState } from 'react';
import { Button, Alert, useConfirm } from './ui.jsx';
import { getAvaliacao, iniciarAvaliacao, concluirAvaliacao } from '../lib/avaliacao.js';

const PILL = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

function Campo({ label, children }) {
    if (!children || (Array.isArray(children) && children.length === 0)) return null;
    return (
        <div>
            <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">{label}</p>
            <div className="text-sm text-on-surface">{children}</div>
        </div>
    );
}

// Modal de avaliação: lê o projeto inteiro, inicia (sem cancelar) e conclui com nota 1–10.
export default function AvaliacaoModal({ avaliacaoId, teste, onFechar, onAtualizado }) {
    const [dados, setDados] = useState(null); // { avaliacao, projeto } | false (erro)
    const [nota, setNota] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [confirm, dialogo] = useConfirm();

    useEffect(() => {
        getAvaliacao(avaliacaoId, teste)
            .then((d) => { setDados(d); setNota(d.avaliacao.nota ?? ''); })
            .catch(() => setDados(false));
    }, [avaliacaoId, teste]);

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
        setSalvando(true); setErro('');
        try {
            const a = await concluirAvaliacao(avaliacaoId, Number(nota), teste);
            setDados((d) => ({ ...d, avaliacao: a }));
            onAtualizado?.();
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível concluir.');
        } finally {
            setSalvando(false);
        }
    }

    const av = dados && dados.avaliacao;
    const p = dados && dados.projeto;

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
                            <div className="flex items-end justify-end gap-3 flex-wrap">
                                <div>
                                    <label className="block text-sm font-semibold text-on-surface mb-1">Nota (1 a 10)</label>
                                    <select
                                        value={nota}
                                        onChange={(e) => setNota(e.target.value)}
                                        className="bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface outline-none focus:border-primary-container"
                                    >
                                        <option value="">—</option>
                                        {Array.from({ length: 10 }, (_, i) => i + 1).map((n) => <option key={n} value={n}>{n}</option>)}
                                    </select>
                                </div>
                                <Button type="button" variant="success" loading={salvando} disabled={nota === ''} onClick={concluir}>
                                    Concluir avaliação
                                </Button>
                            </div>
                        )}
                        {av.status === 'concluida' && (
                            <p className="text-sm text-on-surface text-right">
                                Avaliação concluída — nota <strong className="text-secondary">{av.nota}</strong>.
                            </p>
                        )}
                    </div>
                )}
            </div>
            {dialogo}
        </div>
    );
}
