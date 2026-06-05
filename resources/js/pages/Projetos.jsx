import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert } from '../components/ui.jsx';
import { listarProjetos, removerProjeto } from '../lib/projetos.js';

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

    async function excluir(id) {
        if (!window.confirm('Excluir este rascunho? Esta ação não pode ser desfeita.')) return;
        await removerProjeto(id);
        carregar();
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
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
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
                            <div className="flex flex-wrap gap-2 mt-4">
                                <button
                                    onClick={() => navigate(`/projetos/${p.id}/editar`)}
                                    className="inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 hover:bg-surface-variant"
                                >
                                    <span className="material-symbols-outlined text-[16px]">edit</span>
                                    {p.status === 'rascunho' ? 'Continuar edição' : 'Ver/editar'}
                                </button>
                                <button
                                    onClick={() => navigate(`/projetos/${p.id}/integrantes`)}
                                    className="inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 hover:bg-surface-variant"
                                >
                                    <span className="material-symbols-outlined text-[16px]">groups</span>
                                    Integrantes
                                </button>
                                {p.status === 'rascunho' && (
                                    <>
                                        <button
                                            onClick={() => navigate(`/projetos/${p.id}/resumo`)}
                                            className="inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 text-secondary hover:bg-secondary-container/40"
                                        >
                                            <span className="material-symbols-outlined text-[16px]">send</span>
                                            Revisar e submeter
                                        </button>
                                        <button
                                            onClick={() => excluir(p.id)}
                                            className="inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 text-error hover:bg-error-container/40"
                                        >
                                            <span className="material-symbols-outlined text-[16px]">delete</span>
                                            Excluir
                                        </button>
                                    </>
                                )}
                                {p.status === 'submetido' && (
                                    <button
                                        onClick={() => navigate(`/projetos/${p.id}/resumo`)}
                                        className="inline-flex items-center gap-1 text-sm border border-outline-variant rounded-lg px-3 py-2 hover:bg-surface-variant"
                                    >
                                        <span className="material-symbols-outlined text-[16px]">visibility</span>
                                        Ver resumo
                                    </button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </AppShell>
    );
}
