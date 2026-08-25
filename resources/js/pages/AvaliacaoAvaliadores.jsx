import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, Select, useConfirm } from '../components/ui.jsx';
import PanoramaAvaliadores from '../components/PanoramaAvaliadores.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getAvaliacaoAvaliadores, exportarAvaliadoresCsv,
    definirLimiteAvaliador, definirDemoAvaliador, limparDadosDeTeste,
} from '../lib/admin.js';

// Colunas da tabela. `ordenar` é a chave que o backend entende.
const COLUNAS = [
    { key: 'nome', label: 'Avaliador', alinhamento: 'text-left' },
    { key: 'area', label: 'Área do conhecimento', alinhamento: 'text-left' },
    { key: 'em_avaliacao', label: 'Em avaliação', alinhamento: 'text-center' },
    { key: 'avaliou', label: 'Avaliadas', alinhamento: 'text-center' },
    { key: 'faltam', label: 'Faltantes', alinhamento: 'text-center' },
    { key: 'criado_em', label: 'Cadastro', alinhamento: 'text-center' },
];

// Cabeçalho clicável: alterna asc/desc na própria coluna, começa asc numa nova.
function Cabecalho({ coluna, ordenar, direcao, onOrdenar }) {
    const ativo = ordenar === coluna.key;

    return (
        <th scope="col" className={`px-3 py-2 text-xs font-semibold text-on-surface-variant ${coluna.alinhamento}`}>
            <button
                type="button"
                onClick={() => onOrdenar(coluna.key)}
                aria-label={`Ordenar por ${coluna.label}`}
                className={`inline-flex items-center gap-0.5 hover:text-primary transition-colors ${ativo ? 'text-primary' : ''}`}
            >
                {coluna.label}
                <span className="material-symbols-outlined text-[16px]">
                    {ativo ? (direcao === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more'}
                </span>
            </button>
        </th>
    );
}

// Modal para definir/remover o limite individual do avaliador.
function LimiteModal({ avaliador, onFechar, onSalvar, salvando }) {
    const [valor, setValor] = useState(avaliador.limite ?? '');

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Limitar avaliador</h3>
                    <p className="text-sm text-on-surface-variant truncate">{avaliador.nome}</p>
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="limite-avaliador">
                        Máximo de avaliações que pode assumir
                    </label>
                    <input
                        id="limite-avaliador"
                        type="number" min="0" max="50" value={valor}
                        onChange={(e) => setValor(e.target.value)}
                        className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                    />
                    <p className="text-xs text-on-surface-variant">
                        Ao atingir o limite, o avaliador não poderá assumir novos projetos. Avaliações já em
                        andamento podem ser concluídas mesmo excedendo o limite.
                    </p>
                </div>

                <div className="flex justify-end gap-2 pt-1 flex-wrap">
                    {avaliador.limite != null && (
                        <Button type="button" variant="outline" onClick={() => onSalvar(null)}>Remover limite</Button>
                    )}
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={valor === '' || Number(valor) < 0} onClick={() => onSalvar(Number(valor))}>
                        Salvar
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Avaliadores Online: uma tabela única com todos os avaliadores — busca por nome
 * ou e-mail, filtro por área, ordenação por qualquer coluna e export CSV do
 * mesmo recorte. Busca, filtro e ordenação são resolvidos no servidor.
 */
export default function AvaliacaoAvaliadores() {
    const [busca, setBusca] = useState('');
    const [filtros, setFiltros] = useState({ q: '', areaId: '', ordenar: 'nome', direcao: 'asc' });
    const [page, setPage] = useState(1);
    const [lista, setLista] = useState(null);
    const [meta, setMeta] = useState(null);
    const [limitando, setLimitando] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [exportando, setExportando] = useState(false);
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [confirm, dialogo] = useConfirm();

    // A busca é aplicada com debounce; os demais filtros, na hora.
    useEffect(() => {
        const t = setTimeout(() => {
            setFiltros((f) => (f.q === busca.trim() ? f : { ...f, q: busca.trim() }));
            setPage(1);
        }, 300);
        return () => clearTimeout(t);
    }, [busca]);

    const carregar = useCallback(() => {
        setAlert('');
        return getAvaliacaoAvaliadores({ ...filtros, page })
            .then((resp) => { setLista(resp.data); setMeta(resp.meta); })
            .catch((e) => { setLista([]); setAlert(extractErrors(e).message); });
    }, [filtros, page]);

    useEffect(() => { carregar(); }, [carregar]);

    function ordenarPor(coluna) {
        setFiltros((f) => ({
            ...f,
            ordenar: coluna,
            direcao: f.ordenar === coluna && f.direcao === 'asc' ? 'desc' : 'asc',
        }));
        setPage(1);
    }

    async function alternarDemo(a) {
        setAlert(''); setSuccess('');
        try {
            const resp = await definirDemoAvaliador(a.id, !a.is_demo);
            setSuccess(resp.meta?.message || 'Atualizado.');
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        }
    }

    async function limparTestes() {
        const ok = await confirm({
            title: 'Limpar dados de teste', danger: true, confirmLabel: 'Limpar',
            message: 'Isso apaga TODAS as avaliações dos avaliadores marcados como demo. Não afeta os avaliadores reais. Continuar?',
        });
        if (!ok) return;
        setAlert(''); setSuccess('');
        try {
            const resp = await limparDadosDeTeste();
            setSuccess(resp.meta?.message || 'Dados de teste limpos.');
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        }
    }

    async function salvarLimite(limite) {
        setSalvando(true); setAlert(''); setSuccess('');
        try {
            const resp = await definirLimiteAvaliador(limitando.id, limite);
            setSuccess(resp.meta?.message || 'Limite atualizado.');
            setLimitando(null);
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        } finally {
            setSalvando(false);
        }
    }

    async function exportar() {
        setAlert('');
        setExportando(true);
        try {
            await exportarAvaliadoresCsv(filtros);
        } catch {
            setAlert('Não foi possível gerar o CSV. Tente novamente.');
        } finally {
            setExportando(false);
        }
    }

    const areas = meta?.areas ?? [];
    const temFiltro = filtros.q !== '' || filtros.areaId !== '';

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliadores Online</h1>
            <p className="text-on-surface-variant mb-4 max-w-4xl">
                Panorama do corpo de avaliadores e o progresso de cada um. Busque por nome ou e-mail,
                filtre por área, ordene por qualquer coluna e exporte o recorte em CSV. Você pode limitar
                individualmente quantas avaliações cada um assume e marcar avaliadores de teste (demo).
            </p>

            <PanoramaAvaliadores />

            <div className="mb-4 flex flex-wrap gap-2 max-w-4xl">
                <button
                    type="button"
                    onClick={limparTestes}
                    className="inline-flex items-center gap-1 text-sm font-semibold text-error border border-error/40 rounded-lg px-3 py-1.5 hover:bg-error-container/40 transition-colors"
                >
                    <span className="material-symbols-outlined text-[18px]">delete_sweep</span>
                    Limpar dados de teste
                </button>
                <Button type="button" variant="outline" loading={exportando} onClick={exportar}>
                    <span className="material-symbols-outlined text-[20px]">download</span>
                    Exportar CSV
                </Button>
            </div>

            {alert && <div className="mb-4 max-w-4xl"><Alert>{alert}</Alert></div>}
            {success && <div className="mb-4 max-w-4xl"><Alert type="info">{success}</Alert></div>}

            <div className="max-w-4xl">
                <div className="flex flex-col md:flex-row gap-2 mb-3">
                    <div className="relative flex-1">
                        <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">search</span>
                        <input
                            type="text"
                            aria-label="Buscar avaliador"
                            className="w-full bg-surface-container-lowest border border-outline-variant rounded-lg pl-10 pr-3 py-2.5 text-on-surface placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 transition-all outline-none"
                            placeholder="Buscar por nome ou e-mail…"
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                        />
                    </div>
                    <div className="w-full md:w-72">
                        <Select
                            aria-label="Filtrar por área do conhecimento"
                            value={filtros.areaId}
                            onChange={(e) => { setFiltros((f) => ({ ...f, areaId: e.target.value })); setPage(1); }}
                        >
                            <option value="">Todas as áreas</option>
                            {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </Select>
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3 mb-2">
                    <p className="text-xs text-on-surface-variant">
                        {meta ? `${meta.total} ${meta.total === 1 ? 'avaliador' : 'avaliadores'}${temFiltro ? ' no filtro atual' : ''}.` : ''}
                    </p>
                    {temFiltro && (
                        <button
                            type="button"
                            onClick={() => { setBusca(''); setFiltros((f) => ({ ...f, q: '', areaId: '' })); setPage(1); }}
                            className="text-xs font-semibold text-primary hover:underline"
                        >
                            Limpar filtros
                        </button>
                    )}
                </div>

                {lista === null ? (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : lista.length === 0 ? (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant">
                        {temFiltro ? 'Nenhum avaliador neste filtro.' : 'Nenhum avaliador cadastrado ainda.'}
                    </div>
                ) : (
                    <>
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-x-auto">
                            <table className="w-full min-w-[52rem] text-sm">
                                <thead className="bg-surface-variant/40">
                                    <tr>
                                        {COLUNAS.map((c) => (
                                            <Cabecalho
                                                key={c.key}
                                                coluna={c}
                                                ordenar={filtros.ordenar}
                                                direcao={filtros.direcao}
                                                onOrdenar={ordenarPor}
                                            />
                                        ))}
                                        <th scope="col" className="px-3 py-2 text-xs font-semibold text-on-surface-variant text-right">Ações</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-outline-variant/30">
                                    {lista.map((a) => {
                                        const atingido = a.limite != null && (a.em_avaliacao + a.avaliou) >= a.limite;
                                        return (
                                            <tr key={a.id} className="hover:bg-surface-variant/30 transition-colors">
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-2 min-w-0">
                                                        <span className="material-symbols-outlined text-primary-container text-[20px]">account_circle</span>
                                                        <div className="min-w-0">
                                                            <p className="text-on-surface truncate">{a.nome}</p>
                                                            <p className="text-xs text-on-surface-variant truncate">{a.email}</p>
                                                        </div>
                                                        {a.limite != null && (
                                                            <span
                                                                title={atingido ? 'Limite atingido' : 'Limite definido'}
                                                                className={`text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${
                                                                    atingido ? 'bg-error-container text-on-error-container' : 'bg-surface-variant text-on-surface-variant'
                                                                }`}
                                                            >
                                                                Limite {a.limite}
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-on-surface-variant">
                                                    <p className="truncate">{a.area ?? 'Sem área'}</p>
                                                    {a.subarea && <p className="text-xs truncate">{a.subarea}</p>}
                                                </td>
                                                <td className="px-3 py-2 text-center font-bold text-primary-container">{a.em_avaliacao}</td>
                                                <td className="px-3 py-2 text-center font-bold text-secondary">{a.avaliou}</td>
                                                <td className="px-3 py-2 text-center font-bold text-on-surface">{a.faltam}</td>
                                                <td className="px-3 py-2 text-center text-xs text-on-surface-variant">{a.criado_em_label ?? '—'}</td>
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => alternarDemo(a)}
                                                            title={a.is_demo ? 'Remover marca de teste (demo)' : 'Marcar como avaliador de teste (demo)'}
                                                            aria-label={`Demo de ${a.nome}`}
                                                            aria-pressed={a.is_demo}
                                                            className={`shrink-0 inline-flex items-center gap-1 text-xs font-semibold rounded-lg px-2.5 py-1.5 border transition-colors ${
                                                                a.is_demo
                                                                    ? 'bg-primary-fixed text-primary-container border-primary-container/30'
                                                                    : 'text-on-surface-variant border-outline-variant hover:bg-surface-variant'
                                                            }`}
                                                        >
                                                            <span className="material-symbols-outlined text-[18px]">science</span>
                                                            Demo
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setLimitando(a)}
                                                            title="Limitar avaliador"
                                                            aria-label={`Limitar ${a.nome}`}
                                                            className="shrink-0 p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant transition-colors"
                                                        >
                                                            <span className="material-symbols-outlined text-[20px]">{a.limite != null ? 'lock' : 'lock_open'}</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {meta && meta.ultima_pagina > 1 && (
                            <div className="flex items-center justify-between gap-3 mt-4">
                                <span className="text-xs text-on-surface-variant">Página {meta.pagina_atual} de {meta.ultima_pagina}</span>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" disabled={meta.pagina_atual <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Anterior</Button>
                                    <Button type="button" variant="outline" disabled={meta.pagina_atual >= meta.ultima_pagina} onClick={() => setPage((p) => p + 1)}>Próxima</Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>

            {limitando && (
                <LimiteModal
                    avaliador={limitando}
                    onFechar={() => setLimitando(null)}
                    onSalvar={salvarLimite}
                    salvando={salvando}
                />
            )}
            {dialogo}
        </AppShell>
    );
}
