import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input, Select } from '../components/ui.jsx';
import { getFinalistas } from '../lib/credenciamento.js';
import { useModoTeste } from '../lib/modoTeste.js';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

/**
 * As duas seções da aba Credenciamento — "Credenciar" (`situacao=pendentes`) e
 * "Credenciados" (`situacao=credenciados`) — são a mesma lista pesquisável, com
 * filtro por área e categoria; o que muda é o recorte e o que cada linha faz.
 */
export default function CredenciamentoLista({ situacao = 'pendentes' }) {
    const navigate = useNavigate();
    const [teste] = useModoTeste();
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [filtros, setFiltros] = useState({ busca: '', area_id: '', categoria: '' });
    const [pagina, setPagina] = useState(1);

    const credenciados = situacao === 'credenciados';

    const carregar = useCallback(() => {
        const params = { page: pagina, situacao };
        if (filtros.busca.trim()) params.busca = filtros.busca.trim();
        if (filtros.area_id) params.area_id = filtros.area_id;
        if (filtros.categoria) params.categoria = filtros.categoria;

        getFinalistas(params, teste)
            .then((r) => { setDados(r); setErro(''); })
            .catch(() => setErro('Não foi possível carregar os finalistas.'));
    }, [filtros, pagina, situacao, teste]);

    useEffect(() => { carregar(); }, [carregar]);

    function alterarFiltro(campo, valor) {
        setPagina(1);
        setFiltros((f) => ({ ...f, [campo]: valor }));
    }

    const itens = dados?.data ?? [];
    const meta = dados?.meta;
    const config = meta?.config;

    return (
        <AppShell>
            <Link to="/admin/credenciamento" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Credenciamento
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">
                {credenciados ? 'Credenciados' : 'Credenciar'}
            </h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                {credenciados
                    ? 'Os finalistas que já passaram pelo balcão, com quem atendeu e o horário.'
                    : 'Os finalistas que ainda não foram credenciados. Abra um projeto para conferir a documentação de cada pessoa.'}
            </p>

            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

            {/* No ensaio a lista é a de demonstração: deixar isso explícito em toda
                tela evita alguém achar que credenciou gente de verdade. */}
            {config?.lista?.demo && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        <strong>Modo demo</strong> — projetos fictícios da lista “{config.lista.nome}”.
                    </Alert>
                </div>
            )}

            {config && !config.aberto && !credenciados && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        O credenciamento está fechado agora — dá para conferir as fichas, mas não
                        para credenciar.
                    </Alert>
                </div>
            )}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <Input
                    placeholder="Buscar por título, escola ou orientador"
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
                    {credenciados ? 'Ninguém credenciado com esses filtros.' : 'Nenhum finalista pendente com esses filtros.'}
                </div>
            ) : (
                <>
                    <p className="text-sm text-on-surface-variant mb-2">
                        {meta.total} {meta.total === 1 ? 'projeto' : 'projetos'}.
                    </p>
                    <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40">
                        {itens.map((p) => (
                            <li key={p.id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-on-surface truncate">{p.titulo}</p>
                                    <p className="text-xs text-on-surface-variant truncate">
                                        {[p.categoria_label, p.area, p.escola].filter(Boolean).join(' · ') || '—'}
                                    </p>
                                    <p className="text-xs text-on-surface-variant truncate">
                                        {p.orientador ? `Orientador: ${p.orientador}` : ''}
                                        {p.credenciado && (
                                            <span className="ml-2 text-secondary font-semibold">
                                                credenciado em {dataHora(p.credenciado_em)}
                                                {p.credenciado_por ? ` por ${p.credenciado_por}` : ''}
                                            </span>
                                        )}
                                    </p>
                                    {/* Atendimento em aberto: quem está com ele. */}
                                    {p.em_rascunho && (
                                        <p className="text-xs font-semibold text-primary-container mt-0.5">
                                            Em rascunho{p.rascunho_de ? ` — com ${p.rascunho_de}` : ''}
                                        </p>
                                    )}
                                    {/* O que ficou para trás: quem faltou e o kit que ninguém levou.
                                        Os dois se resolvem numa segunda visita ao balcão. */}
                                    {p.credenciado && (p.ausentes > 0 || p.kits_pendentes > 0) && (
                                        <p className="text-xs font-semibold text-error mt-0.5">
                                            {[
                                                p.ausentes > 0 && `${p.ausentes} ${p.ausentes === 1 ? 'pessoa faltou' : 'pessoas faltaram'}`,
                                                p.kits_pendentes > 0 && `${p.kits_pendentes} ${p.kits_pendentes === 1 ? 'kit não retirado' : 'kits não retirados'}`,
                                            ].filter(Boolean).join(' · ')}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="button"
                                    variant={credenciados ? 'outline' : 'primary'}
                                    onClick={() => navigate(`/admin/credenciamento/projetos/${p.id}`)}
                                >
                                    <span className="material-symbols-outlined text-[20px]">
                                        {credenciados ? 'visibility' : 'how_to_reg'}
                                    </span>
                                    {credenciados ? 'Ver ficha' : (p.em_rascunho ? 'Continuar' : 'Credenciar')}
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
