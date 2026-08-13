import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, useConfirm } from '../components/ui.jsx';
import { listarProjetos, removerProjeto, cancelarSubmissao } from '../lib/projetos.js';

const FILTROS = [
    { key: 'all', label: 'Todos' },
    { key: 'rascunho', label: 'Rascunho' },
    { key: 'submetido', label: 'Submetido' },
];

function StatusPill({ status, label }) {
    const map = {
        rascunho: 'bg-primary-fixed text-primary-container',
        submetido: 'bg-secondary-container text-on-secondary-container',
        aprovado: 'bg-secondary-container text-on-secondary-container',
        rejeitado: 'bg-error-container text-on-error-container',
    };
    return <span className={`text-xs font-semibold px-2 py-1 rounded-full ${map[status] ?? ''}`}>{label}</span>;
}

export default function Projetos() {
    const navigate = useNavigate();
    const [confirm, confirmDialog] = useConfirm();
    const [projetos, setProjetos] = useState([]);
    const [filtro, setFiltro] = useState('all');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const carregar = useCallback(() => {
        setLoading(true);
        listarProjetos()
            .then(setProjetos)
            .catch(() => setError('Não foi possível carregar seus projetos.'))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => carregar(), [carregar]);

    /** Mensagem do 422 quando a janela para desfazer a submissão já fechou. */
    function avisarBloqueio(error) {
        const dados = error?.response?.data;
        const motivos = dados?.motivos?.map((m) => m.message).join(' ');
        setError(motivos || dados?.message || 'Não foi possível concluir a ação.');
    }

    async function excluir(projeto) {
        const submetido = projeto.status !== 'rascunho';
        const ok = await confirm({
            title: submetido ? 'Excluir inscrição' : 'Excluir rascunho',
            message: submetido
                ? 'Excluir esta inscrição submetida? O projeto sai da feira junto com alunos, coorientador e anexos. Esta ação não pode ser desfeita.'
                : 'Excluir este rascunho? Esta ação não pode ser desfeita.',
            confirmLabel: 'Excluir',
            danger: true,
        });
        if (!ok) return;
        setError('');
        try {
            await removerProjeto(projeto.id);
            carregar();
        } catch (error) {
            avisarBloqueio(error);
        }
    }

    async function cancelar(projeto) {
        const ok = await confirm({
            title: 'Cancelar submissão',
            message: 'A inscrição volta para rascunho e sai da fila de avaliação. Você poderá editar e submeter de novo enquanto as inscrições estiverem abertas.',
            confirmLabel: 'Cancelar submissão',
            danger: true,
        });
        if (!ok) return;
        setError('');
        try {
            await cancelarSubmissao(projeto.id);
            carregar();
        } catch (error) {
            avisarBloqueio(error);
        }
    }

    const visiveis = projetos.filter((p) => filtro === 'all' || p.status === filtro);
    const total = projetos.length;
    const rascunhos = projetos.filter((p) => p.status === 'rascunho').length;
    const submetidos = projetos.filter((p) => p.status === 'submetido').length;

    return (
        <AppShell>
            <div className="flex items-end justify-between gap-4 mb-6">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-primary mb-1">Meus Projetos</h1>
                    <p className="text-on-surface-variant">Projetos inscritos por você como orientador.</p>
                </div>
                <button
                    onClick={() => navigate('/projetos/novo')}
                    className="inline-flex items-center gap-2 rounded-lg px-5 py-2.5 font-semibold bg-primary-container text-on-primary hover:bg-primary transition-colors"
                >
                    <span className="material-symbols-outlined text-[20px]">add</span>
                    NOVA INSCRIÇÃO
                </button>
            </div>

            <div className="grid grid-cols-3 gap-3 mb-6">
                {[
                    { v: total, l: 'Total', c: 'text-on-surface' },
                    { v: rascunhos, l: 'Rascunho', c: 'text-primary-container' },
                    { v: submetidos, l: 'Submetido', c: 'text-secondary' },
                ].map((s) => (
                    <div key={s.l} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 text-center">
                        <div className={`text-2xl font-bold ${s.c}`}>{s.v}</div>
                        <div className="text-xs text-on-surface-variant">{s.l}</div>
                    </div>
                ))}
            </div>

            <div className="flex flex-wrap gap-2 mb-5">
                {FILTROS.map((f) => (
                    <button
                        key={f.key}
                        onClick={() => setFiltro(f.key)}
                        className={`px-4 py-1.5 rounded-full text-sm font-semibold border transition-colors ${
                            filtro === f.key
                                ? 'bg-primary-container text-on-primary border-primary-container'
                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                        }`}
                    >
                        {f.label}
                    </button>
                ))}
            </div>

            {error && <Alert>{error}</Alert>}

            {loading ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : visiveis.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center">
                    <span className="material-symbols-outlined text-[48px] text-primary-container">folder_open</span>
                    <p className="text-on-surface mt-3 font-semibold">Nenhum projeto neste filtro</p>
                    <p className="text-on-surface-variant text-sm mt-1">Clique em "Nova inscrição" para começar um rascunho.</p>
                </div>
            ) : (
                <div className="space-y-4">
                    {visiveis.map((p) => (
                        <article key={p.id} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <div className="flex items-center gap-2 mb-1">
                                <StatusPill status={p.status} label={p.status_label} />
                                {p.updated_at && (
                                    <span className="text-xs text-on-surface-variant">
                                        Atualizado em {new Date(p.updated_at).toLocaleDateString('pt-BR')}
                                    </span>
                                )}
                            </div>
                            <h2 className="font-semibold text-on-surface text-lg">
                                {p.titulo || <span className="italic text-on-surface-variant">Sem título</span>}
                            </h2>
                            <p className="text-sm text-on-surface-variant">
                                {[p.instituicao, p.categoria_label, p.area].filter(Boolean).join(' · ') || '—'}
                            </p>
                            <div className="w-full flex flex-col align-middle flex-wrap md:flex-row gap-2 mt-4">
                                {p.status === 'rascunho' && (
                                    <button
                                        onClick={() => navigate(`/projetos/${p.id}/editar`)}
                                        className="max-w-80 md:w-auto w-[70vw] md:mx-0 mx-auto inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 hover:bg-surface-variant"
                                    >
                                        <span className="material-symbols-outlined text-[16px]">edit</span>
                                        Continuar edição
                                    </button>
                                )}
                                <button
                                    onClick={() => navigate(`/projetos/${p.id}/integrantes`)}
                                    className="max-w-80 md:w-auto w-[70vw] md:mx-0 mx-auto inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 hover:bg-surface-variant"
                                >
                                    <span className="material-symbols-outlined text-[16px]">groups</span>
                                    {p.status === 'rascunho' ? 'Integrantes' : 'Ver integrantes'}
                                </button>
                                {p.status === 'rascunho' && (
                                    <>
                                        <button
                                            onClick={() => navigate(`/projetos/${p.id}/resumo`)}
                                            className="max-w-80 md:w-auto w-[70vw] md:mx-0 mx-auto inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 text-secondary hover:bg-secondary-container/40"
                                        >
                                            <span className="material-symbols-outlined text-[16px]">send</span>
                                            Revisar e submeter
                                        </button>
                                        <button
                                            onClick={() => excluir(p)}
                                            className="max-w-80 md:w-auto w-[70vw] md:mx-0 mx-auto inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 text-error hover:bg-error-container/40"
                                        >
                                            <span className="material-symbols-outlined text-[16px]">delete</span>
                                            Excluir
                                        </button>
                                    </>
                                )}
                                {p.status === 'submetido' && (
                                    <button
                                        onClick={() => navigate(`/projetos/${p.id}/resumo`)}
                                        className="max-w-80 md:w-auto w-[70vw] md:mx-0 mx-auto inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 hover:bg-surface-variant"
                                    >
                                        <span className="material-symbols-outlined text-[16px]">visibility</span>
                                        Ver resumo
                                    </button>
                                )}
                                {/* Desfazer a submissão: só enquanto ninguém começou a avaliar
                                    e o período de avaliação não abriu. */}
                                {p.status === 'submetido' && p.pode_desfazer && (
                                    <>
                                        <button
                                            onClick={() => cancelar(p)}
                                            className="max-w-80 md:w-auto w-[70vw] md:mx-0 mx-auto inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 hover:bg-surface-variant"
                                        >
                                            <span className="material-symbols-outlined text-[16px]">undo</span>
                                            Cancelar submissão
                                        </button>
                                        <button
                                            onClick={() => excluir(p)}
                                            className="max-w-80 md:w-auto w-[70vw] md:mx-0 mx-auto inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 text-error hover:bg-error-container/40"
                                        >
                                            <span className="material-symbols-outlined text-[16px]">delete</span>
                                            Excluir inscrição
                                        </button>
                                    </>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            )}
            {confirmDialog}
        </AppShell>
    );
}
