import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert } from '../components/ui.jsx';
import { getAviso, getAvisoLeitores, exportarAvisoCsv } from '../lib/admin.js';

// Os três recortes do relatório + o "todos" que abre a tela.
const FILTROS = [
    { key: '', label: 'Todos' },
    { key: 'fechado', label: 'Fecharam' },
    { key: 'visto', label: 'Viram' },
    { key: 'nao_visto', label: 'Não viram' },
];

const CORES = {
    fechado: 'bg-secondary-container text-on-secondary-container',
    visto: 'bg-primary-fixed text-primary-container',
    nao_visto: 'bg-surface-variant text-on-surface-variant',
};

function Numero({ valor, rotulo, cor = 'text-on-surface' }) {
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 text-center">
            <div className={`text-2xl font-bold ${cor}`}>{valor ?? 0}</div>
            <div className="text-xs text-on-surface-variant">{rotulo}</div>
        </div>
    );
}

export default function AdminAvisoDetalhe() {
    const { id } = useParams();
    const [aviso, setAviso] = useState(null);
    const [situacao, setSituacao] = useState('');
    const [busca, setBusca] = useState('');
    const [termo, setTermo] = useState('');
    const [page, setPage] = useState(1);
    const [lista, setLista] = useState(null);
    const [erro, setErro] = useState('');
    const [baixando, setBaixando] = useState(false);

    useEffect(() => {
        getAviso(id).then((r) => setAviso(r.data)).catch(() => setErro('Não foi possível carregar o aviso.'));
    }, [id]);

    const carregar = useCallback(() => {
        getAvisoLeitores(id, { situacao, q: termo, page })
            .then(setLista)
            .catch(() => setErro('Não foi possível carregar a lista.'));
    }, [id, situacao, termo, page]);

    useEffect(() => { carregar(); }, [carregar]);

    function filtrar(key) {
        setSituacao(key);
        setPage(1);
    }

    function buscar(e) {
        e.preventDefault();
        setTermo(busca.trim());
        setPage(1);
    }

    async function exportar() {
        setBaixando(true);
        try {
            await exportarAvisoCsv(id, { situacao, q: termo });
        } catch {
            setErro('Não foi possível exportar o CSV.');
        } finally {
            setBaixando(false);
        }
    }

    const linhas = lista?.data ?? [];
    const meta = lista?.meta;

    return (
        <AppShell>
            <Link to="/admin/comunicacao/avisos" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avisos
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Relatório do aviso</h1>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            {!aviso ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="max-w-3xl">
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-5">
                        <div className="flex items-center gap-2 flex-wrap mb-2">
                            <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${
                                aviso.ativo ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-variant text-on-surface-variant'
                            }`}>
                                {aviso.ativo ? 'No ar' : `Encerrado em ${aviso.encerrado_em}`}
                            </span>
                            <span className="text-xs text-on-surface-variant">
                                Publicado em {aviso.publicado_em} por {aviso.autor_nome}
                            </span>
                        </div>
                        <h2 className="font-display font-semibold text-primary">{aviso.titulo}</h2>
                        <p className="text-sm text-on-surface mt-1 whitespace-pre-line">{aviso.mensagem}</p>
                        {aviso.mensagem_original !== aviso.mensagem && (
                            <details className="mt-3">
                                <summary className="text-xs text-on-surface-variant cursor-pointer">Ver o texto com as variáveis</summary>
                                <p className="text-xs text-on-surface-variant mt-1 whitespace-pre-line">{aviso.mensagem_original}</p>
                            </details>
                        )}
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                        <Numero valor={aviso.destinatarios} rotulo="Orientadores ativos" />
                        <Numero valor={aviso.vistos} rotulo="Viram" cor="text-primary-container" />
                        <Numero valor={aviso.fechados} rotulo="Fecharam" cor="text-secondary" />
                        <Numero valor={aviso.nao_vistos} rotulo="Não viram" cor="text-error" />
                    </div>

                    <div className="flex flex-wrap gap-2 mb-3">
                        {FILTROS.map((f) => (
                            <button
                                key={f.key || 'todos'}
                                type="button"
                                onClick={() => filtrar(f.key)}
                                className={`px-4 py-1.5 rounded-full text-sm font-semibold border transition-colors ${
                                    situacao === f.key
                                        ? 'bg-primary-container text-on-primary border-primary-container'
                                        : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                }`}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>

                    <div className="flex flex-wrap gap-2 mb-4">
                        <form onSubmit={buscar} className="flex gap-2 flex-1 min-w-60">
                            <input
                                type="search"
                                aria-label="Buscar por nome ou e-mail"
                                placeholder="Buscar por nome ou e-mail…"
                                value={busca}
                                onChange={(e) => setBusca(e.target.value)}
                                className="flex-1 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                            />
                            <Button type="submit" variant="outline">Buscar</Button>
                        </form>
                        <Button type="button" variant="outline" loading={baixando} onClick={exportar}>
                            <span className="material-symbols-outlined text-[18px]">download</span>
                            Exportar CSV
                        </Button>
                    </div>

                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                        {linhas.length === 0 ? (
                            <p className="p-6 text-center text-sm text-on-surface-variant">Ninguém neste recorte.</p>
                        ) : (
                            <ul className="divide-y divide-outline-variant/30">
                                {linhas.map((p) => (
                                    <li key={p.id} className="px-4 py-3 flex items-center gap-3 flex-wrap">
                                        <span className="material-symbols-outlined text-primary-container">account_circle</span>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm text-on-surface truncate">{p.nome}</p>
                                            <p className="text-xs text-on-surface-variant truncate">{p.email}</p>
                                        </div>
                                        <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${CORES[p.situacao]}`}>
                                            {p.situacao_label}
                                        </span>
                                        <span className="text-xs text-on-surface-variant whitespace-nowrap">
                                            {p.fechado_em ? `Fechou em ${p.fechado_em}` : p.visto_em ? `Viu em ${p.visto_em}` : '—'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {meta && meta.ultima_pagina > 1 && (
                        <div className="flex items-center justify-between gap-3 mt-4">
                            <Button type="button" variant="outline" disabled={meta.pagina <= 1} onClick={() => setPage(meta.pagina - 1)}>
                                Anterior
                            </Button>
                            <span className="text-sm text-on-surface-variant">
                                Página {meta.pagina} de {meta.ultima_pagina} · {meta.total} pessoa(s)
                            </span>
                            <Button type="button" variant="outline" disabled={meta.pagina >= meta.ultima_pagina} onClick={() => setPage(meta.pagina + 1)}>
                                Próxima
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </AppShell>
    );
}
