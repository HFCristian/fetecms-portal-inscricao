import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getProjetosPorLocalidade } from '../lib/admin.js';
import { ListaComEscolas } from '../components/LocalidadeUI.jsx';

export default function AdminProjetosPorEstado() {
    const [items, setItems] = useState(null);

    useEffect(() => {
        getProjetosPorLocalidade().then((d) => setItems(d.estados)).catch(() => setItems([]));
    }, []);

    return (
        <AppShell>
            <Link to="/admin" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Painel do Administrador
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos por estado</h1>
            <p className="text-sm text-on-surface-variant mb-6">
                Inclui rascunhos e submetidos. Estados sem projeto não aparecem; expanda um estado para ver as escolas.
            </p>
            <ListaComEscolas items={items} />
        </AppShell>
    );
}
