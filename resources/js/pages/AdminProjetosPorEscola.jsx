import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getProjetosPorLocalidade } from '../lib/admin.js';
import { ProjetosBarChart, Contagens, Carregando, Vazio } from '../components/LocalidadeUI.jsx';

export default function AdminProjetosPorEscola() {
    const [items, setItems] = useState(null);

    useEffect(() => {
        getProjetosPorLocalidade().then((d) => setItems(d.escolas)).catch(() => setItems([]));
    }, []);

    return (
        <AppShell>
            <Link to="/admin" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Painel do Administrador
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos por escola</h1>
            <p className="text-sm text-on-surface-variant mb-6">
                Inclui rascunhos e submetidos. Escolas sem projeto não aparecem.
            </p>

            {items === null ? (
                <Carregando />
            ) : items.length === 0 ? (
                <Vazio />
            ) : (
                <>
                    <ProjetosBarChart items={items} />
                    <ul className="space-y-2">
                        {items.map((e) => (
                            <li
                                key={e.id ?? e.nome}
                                className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex items-center justify-between gap-3"
                            >
                                <div className="min-w-0">
                                    <p className="font-semibold text-on-surface truncate">{e.nome}</p>
                                    <p className="text-xs text-on-surface-variant truncate">
                                        {[e.cidade, e.uf].filter(Boolean).join(' / ') || '—'}
                                    </p>
                                </div>
                                <Contagens total={e.total} submetidos={e.submetidos} rascunho={e.rascunho} />
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </AppShell>
    );
}
