import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, Select, useConfirm } from '../components/ui.jsx';
import PanoramaAvaliadores from '../components/PanoramaAvaliadores.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { loadAreas, loadSubareas } from '../lib/catalogos.js';
import {
    getAvaliacaoAvaliadores, exportarAvaliadoresCsv,
    definirLimiteAvaliador, definirDemoAvaliador, limparDadosDeTeste,
    definirComissaoAvaliador, adicionarAreaExtra, removerAreaExtra,
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
 * Áreas extras do avaliador: só o admin amplia o alcance de quem avalia. A
 * subárea é opcional — sem ela, o avaliador atende a área inteira.
 */
function AreasExtrasModal({ avaliador, onFechar, onMudou }) {
    const [areas, setAreas] = useState([]);
    const [subareas, setSubareas] = useState([]);
    const [areaId, setAreaId] = useState('');
    const [subareaId, setSubareaId] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');

    useEffect(() => { loadAreas().then(setAreas).catch(() => setAreas([])); }, []);

    useEffect(() => {
        setSubareaId('');
        if (!areaId) { setSubareas([]); return; }
        loadSubareas(areaId).then(setSubareas).catch(() => setSubareas([]));
    }, [areaId]);

    const extras = avaliador.areas_extras ?? [];

    async function executar(fn) {
        setSalvando(true); setErro('');
        try {
            const resp = await fn();
            onMudou(resp.data);
            setAreaId('');
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Áreas do avaliador</h3>
                    <p className="text-sm text-on-surface-variant truncate">{avaliador.nome}</p>
                </div>

                <div className="text-sm text-on-surface-variant">
                    Classificação do cadastro: <strong>{avaliador.area ?? 'Sem área'}</strong>
                    {avaliador.subarea ? <> · {avaliador.subarea}</> : null}. As áreas abaixo são liberações
                    do admin — elas valem na distribuição e na reposição da fila como se fossem dele.
                </div>

                {erro && <Alert>{erro}</Alert>}

                {extras.length === 0 ? (
                    <p className="text-sm text-on-surface-variant">Nenhuma área extra liberada.</p>
                ) : (
                    <ul className="divide-y divide-outline-variant/30 border border-outline-variant/40 rounded-lg">
                        {extras.map((extra) => (
                            <li key={extra.id} className="flex items-center gap-2 px-3 py-2">
                                <span className="material-symbols-outlined text-[18px] text-primary-container">add_circle</span>
                                <span className="flex-1 min-w-0 text-sm text-on-surface truncate">
                                    {extra.area}{extra.subarea ? ` · ${extra.subarea}` : ''}
                                </span>
                                <button
                                    type="button"
                                    aria-label={`Remover ${extra.area}`}
                                    disabled={salvando}
                                    onClick={() => executar(() => removerAreaExtra(avaliador.id, extra.id))}
                                    className="p-1.5 rounded-lg text-error hover:bg-error-container transition-colors disabled:opacity-40"
                                >
                                    <span className="material-symbols-outlined text-[20px]">delete</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="flex flex-wrap items-end gap-2">
                    <div className="flex-1 min-w-[10rem]">
                        <Select aria-label="Área a liberar" value={areaId} onChange={(e) => setAreaId(e.target.value)}>
                            <option value="">Escolha a área</option>
                            {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </Select>
                    </div>
                    <div className="flex-1 min-w-[10rem]">
                        <Select aria-label="Subárea a liberar (opcional)" value={subareaId} disabled={!areaId} onChange={(e) => setSubareaId(e.target.value)}>
                            <option value="">Área inteira</option>
                            {subareas.map((sub) => <option key={sub.id} value={sub.id}>{sub.nome}</option>)}
                        </Select>
                    </div>
                    <Button
                        type="button"
                        loading={salvando}
                        disabled={!areaId}
                        onClick={() => executar(() => adicionarAreaExtra(avaliador.id, Number(areaId), subareaId ? Number(subareaId) : null))}
                    >
                        Liberar
                    </Button>
                </div>

                <div className="flex justify-end pt-1">
                    <Button type="button" variant="outline" onClick={onFechar}>Fechar</Button>
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
    const [filtros, setFiltros] = useState({ q: '', areaId: '', situacao: '', ordenar: 'nome', direcao: 'asc' });
    const [page, setPage] = useState(1);
    const [lista, setLista] = useState(null);
    const [meta, setMeta] = useState(null);
    const [limitando, setLimitando] = useState(null);
    const [editandoAreas, setEditandoAreas] = useState(null);
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

    async function alternarComissao(a) {
        setAlert(''); setSuccess('');
        try {
            const resp = await definirComissaoAvaliador(a.id, !a.comissao_especial);
            setSuccess(resp.meta?.message || 'Atualizado.');
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        }
    }

    // O modal devolve a linha já atualizada: troca só ela, sem recarregar a tabela.
    function aplicarLinha(linha) {
        setLista((atual) => (atual ?? []).map((item) => (item.id === linha.id ? linha : item)));
        setEditandoAreas(linha);
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
    const temFiltro = filtros.q !== '' || filtros.areaId !== '' || filtros.situacao !== '';

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliadores Online</h1>
            <p className="text-on-surface-variant mb-4 max-w-4xl">
                Panorama do corpo de avaliadores e o progresso de cada um. Busque por nome ou e-mail,
                filtre por área, ordene por qualquer coluna e exporte o recorte em CSV. Você pode limitar
                individualmente quantas avaliações cada um assume, marcar avaliadores de teste (demo),
                incluir alguém na <strong>comissão especial</strong> e liberar <strong>outras áreas</strong>
                para um avaliador receber projetos além da que ele escolheu.
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
                    <div className="w-full md:w-56">
                        <Select
                            aria-label="Filtrar por situação"
                            value={filtros.situacao}
                            onChange={(e) => { setFiltros((f) => ({ ...f, situacao: e.target.value })); setPage(1); }}
                        >
                            <option value="">Todas as situações</option>
                            <option value="comissao">Comissão especial</option>
                            <option value="demo">Avaliadores de teste</option>
                            <option value="bloqueados">Com limite definido</option>
                        </Select>
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
                            onClick={() => { setBusca(''); setFiltros((f) => ({ ...f, q: '', areaId: '', situacao: '' })); setPage(1); }}
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
                                                        {a.comissao_especial && (
                                                            <span
                                                                title="Comissão especial"
                                                                className="text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap bg-secondary-container text-on-secondary-container"
                                                            >
                                                                Comissão
                                                            </span>
                                                        )}
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
                                                    {(a.areas_extras ?? []).length > 0 && (
                                                        <p className="text-xs text-primary-container truncate" title={(a.areas_extras ?? []).map((e) => e.area + (e.subarea ? ` · ${e.subarea}` : '')).join(', ')}>
                                                            + {a.areas_extras.length} área{a.areas_extras.length === 1 ? '' : 's'} liberada{a.areas_extras.length === 1 ? '' : 's'}
                                                        </p>
                                                    )}
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
                                                            onClick={() => alternarComissao(a)}
                                                            title={a.comissao_especial ? 'Tirar da comissão especial' : 'Incluir na comissão especial'}
                                                            aria-label={`Comissão especial de ${a.nome}`}
                                                            aria-pressed={a.comissao_especial}
                                                            className={`shrink-0 p-1.5 rounded-lg transition-colors ${
                                                                a.comissao_especial
                                                                    ? 'text-secondary hover:bg-secondary-container'
                                                                    : 'text-on-surface-variant hover:bg-surface-variant'
                                                            }`}
                                                        >
                                                            <span className="material-symbols-outlined text-[20px]">
                                                                {a.comissao_especial ? 'star' : 'star_border'}
                                                            </span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setEditandoAreas(a)}
                                                            title="Liberar outras áreas para este avaliador"
                                                            aria-label={`Áreas de ${a.nome}`}
                                                            className="shrink-0 p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant transition-colors"
                                                        >
                                                            <span className="material-symbols-outlined text-[20px]">library_add</span>
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

            {editandoAreas && (
                <AreasExtrasModal
                    avaliador={editandoAreas}
                    onFechar={() => setEditandoAreas(null)}
                    onMudou={aplicarLinha}
                />
            )}

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
