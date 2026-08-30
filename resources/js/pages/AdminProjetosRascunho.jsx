import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Select, Input, Alert } from '../components/ui.jsx';
import { getProjetosRascunho } from '../lib/admin.js';

/**
 * Projetos em rascunho (Projetos → Projetos por área → "Projetos em rascunho").
 *
 * A tela existe para o depois do prazo: as inscrições que ficaram pela metade
 * e que a organização decide, caso a caso, terminar e submeter. Cada linha leva
 * às telas do orientador em modo admin; o que fecha o trabalho é a submissão,
 * que pede justificativa.
 */
export default function AdminProjetosRascunho() {
    const navigate = useNavigate();
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [filtros, setFiltros] = useState({ busca: '', area_id: '', categoria: '' });
    const [pagina, setPagina] = useState(1);

    const carregar = useCallback(() => {
        const params = { page: pagina };
        if (filtros.busca.trim()) params.busca = filtros.busca.trim();
        if (filtros.area_id) params.area_id = filtros.area_id;
        if (filtros.categoria) params.categoria = filtros.categoria;

        getProjetosRascunho(params)
            .then((r) => { setDados(r); setErro(''); })
            .catch(() => setErro('Não foi possível carregar os rascunhos.'));
    }, [filtros, pagina]);

    useEffect(() => { carregar(); }, [carregar]);

    function alterarFiltro(campo, valor) {
        setPagina(1);
        setFiltros((f) => ({ ...f, [campo]: valor }));
    }

    const itens = dados?.data ?? [];
    const meta = dados?.meta;

    return (
        <AppShell>
            <Link to="/admin/projetos-por-area" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Projetos por área
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos em rascunho</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Inscrições que o orientador não chegou a submeter. Você pode terminar de preencher e
                submeter mesmo com o prazo encerrado — a submissão exige justificativa, e tudo o que
                você alterar fica em <Link to="/admin/registros/rascunhos" className="underline">Registros → Rascunhos</Link>.
            </p>

            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

            {/* Filtros */}
            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <Input
                    placeholder="Buscar por título ou orientador"
                    value={filtros.busca}
                    onChange={(e) => alterarFiltro('busca', e.target.value)}
                />
                <Select value={filtros.area_id} onChange={(e) => alterarFiltro('area_id', e.target.value)}>
                    <option value="">Todas as áreas</option>
                    {(meta?.areas ?? []).map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                </Select>
                <Select value={filtros.categoria} onChange={(e) => alterarFiltro('categoria', e.target.value)}>
                    <option value="">Todas as categorias</option>
                    {(meta?.categorias ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </Select>
            </div>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : itens.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
                    Nenhum projeto em rascunho com esses filtros.
                </div>
            ) : (
                <>
                    <p className="text-sm text-on-surface-variant mb-2">
                        {meta.total} {meta.total === 1 ? 'projeto' : 'projetos'} em rascunho.
                    </p>
                    <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40">
                        {itens.map((p) => (
                            <li key={p.id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-on-surface truncate">
                                        {p.titulo || <span className="italic text-on-surface-variant">Sem título</span>}
                                    </p>
                                    <p className="text-xs text-on-surface-variant truncate">
                                        {[p.orientador, p.categoria_label, p.area].filter(Boolean).join(' · ') || '—'}
                                    </p>
                                    <p className="text-xs text-on-surface-variant">
                                        {p.alunos} {p.alunos === 1 ? 'aluno' : 'alunos'}
                                        {p.pronto ? (
                                            <span className="ml-2 text-secondary font-semibold">pronto para submeter</span>
                                        ) : (
                                            <span className="ml-2 text-error font-semibold">
                                                {p.pendencias} {p.pendencias === 1 ? 'pendência' : 'pendências'}
                                            </span>
                                        )}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant={p.pronto ? 'success' : 'outline'}
                                    onClick={() => navigate(`/admin/projetos-rascunho/${p.id}/${p.pronto ? 'resumo' : 'editar'}`)}
                                >
                                    <span className="material-symbols-outlined text-[20px]">send</span>
                                    Submeter
                                </Button>
                            </li>
                        ))}
                    </ul>

                    {meta.ultima_pagina > 1 && (
                        <div className="flex items-center justify-center gap-3 mt-4">
                            <Button variant="outline" type="button" disabled={pagina <= 1} onClick={() => setPagina((n) => n - 1)}>
                                Anterior
                            </Button>
                            <span className="text-sm text-on-surface-variant">
                                Página {meta.pagina_atual} de {meta.ultima_pagina}
                            </span>
                            <Button variant="outline" type="button" disabled={pagina >= meta.ultima_pagina} onClick={() => setPagina((n) => n + 1)}>
                                Próxima
                            </Button>
                        </div>
                    )}
                </>
            )}
        </AppShell>
    );
}
