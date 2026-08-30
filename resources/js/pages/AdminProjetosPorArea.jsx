import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import GrupoArea, { BotoesExpandir, useAreasAbertas } from '../components/GrupoArea.jsx';
import { getProjetosPorArea, getInscricoesConfig } from '../lib/admin.js';

const STATUS_PILL = {
    rascunho: 'bg-primary-fixed text-primary-container',
    submetido: 'bg-secondary-container text-on-secondary-container',
    aprovado: 'bg-secondary-container text-on-secondary-container',
    rejeitado: 'bg-error-container text-on-error-container',
};

// Um card por área do conhecimento, no mesmo formato do "Projetos por status" do painel.
function CardArea({ grupo }) {
    return (
        <div className="flex flex-col items-center text-center gap-2 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
            <span className="material-symbols-outlined text-primary-container text-2xl">category</span>
            <div className="flex gap-4 py-2">
                <div>
                    <div className="text-3xl font-bold text-secondary">{grupo.submetidos}</div>
                    <div className="text-xs text-on-surface-variant">Submetidos</div>
                </div>
                <div>
                    <div className="text-3xl font-bold text-primary-container">{grupo.rascunho}</div>
                    <div className="text-xs text-on-surface-variant">Rascunho</div>
                </div>
            </div>
            <div className="text-sm text-on-surface-variant">{grupo.area}</div>
        </div>
    );
}

export default function AdminProjetosPorArea() {
    const [grupos, setGrupos] = useState(null);
    // O botão "Projetos em rascunho" só existe depois do prazo: antes disso o
    // orientador ainda pode submeter sozinho, e a organização não entra na
    // inscrição dele.
    const [inscricoes, setInscricoes] = useState(null);
    const abertas = useAreasAbertas();

    useEffect(() => { getProjetosPorArea().then(setGrupos).catch(() => setGrupos([])); }, []);
    useEffect(() => { getInscricoesConfig().then(setInscricoes).catch(() => setInscricoes(null)); }, []);

    // O grupo "sem área" vem com area_id null: a chave do acordeão precisa ser estável.
    const chave = (g) => g.area_id ?? 0;

    return (
        <AppShell>
            <Link to="/admin" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Painel do Administrador
            </Link>
            <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos por área do conhecimento</h1>
                    <p className="text-sm text-on-surface-variant">
                        Inclui rascunhos. Projetos sem área aparecem em “Área ainda não informada”. Clique na
                        área para abrir a lista.
                    </p>
                </div>
                {inscricoes?.encerradas && (
                    <Link
                        to="/admin/projetos-rascunho"
                        className="shrink-0 inline-flex items-center gap-2 rounded-lg bg-primary-container px-4 py-2.5 text-sm font-semibold text-on-primary hover:opacity-90 transition-opacity"
                    >
                        <span className="material-symbols-outlined text-[20px]">edit_note</span>
                        Projetos em rascunho
                    </Link>
                )}
            </div>

            {grupos === null ? (
                <div className="text-center py-6 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    {grupos.length > 0 && (
                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                            {grupos.map((g) => <CardArea key={chave(g)} grupo={g} />)}
                        </div>
                    )}

                    {grupos.length === 0 ? (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
                            Nenhum projeto cadastrado ainda.
                        </div>
                    ) : (
                        <>
                            <div className="mb-4">
                                <BotoesExpandir ids={grupos.map(chave)} controle={abertas} />
                            </div>
                            <div className="space-y-4">
                                {grupos.map((g) => (
                                    <GrupoArea
                                        key={chave(g)}
                                        titulo={g.area}
                                        itens={g.projetos}
                                        singular="projeto"
                                        plural="projetos"
                                        rotuloKey="titulo"
                                        ordemPadraoLabel="Título (A–Z)"
                                        aberto={abertas.estaAberto(chave(g))}
                                        onToggle={() => abertas.alternar(chave(g))}
                                        renderItem={(p) => (
                                            <li key={p.id} className="px-4 py-2 flex items-center gap-3">
                                                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full shrink-0 ${STATUS_PILL[p.status] ?? ''}`}>
                                                    {p.status_label}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-medium text-on-surface truncate">
                                                        {p.titulo || <span className="italic text-on-surface-variant">Sem título</span>}
                                                    </p>
                                                    <p className="text-xs text-on-surface-variant truncate">
                                                        {p.categoria_label || '—'}
                                                    </p>
                                                </div>
                                            </li>
                                        )}
                                    />
                                ))}
                            </div>
                        </>
                    )}
                </>
            )}
        </AppShell>
    );
}
