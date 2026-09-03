import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import BuscaCombobox from '../components/BuscaCombobox.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getDesignacoes, retirarDesignacoes, getOpcoesAvaliadores } from '../lib/admin.js';

/**
 * Avaliação online → Designações: tudo que está na mão de cada avaliador, com
 * há quanto tempo, e a retirada em lote.
 *
 * O que está apenas *designada* sai sem perda; o que está *em avaliação* sai
 * descartando o rascunho do avaliador — por isso a confirmação separa os dois.
 * *Concluída* aparece na tabela (é histórico) mas não pode ser marcada.
 */
const COLUNAS = [
    { key: 'projeto', label: 'Projeto', alinhamento: 'text-left' },
    { key: 'area', label: 'Área', alinhamento: 'text-left' },
    { key: 'avaliador', label: 'Avaliador', alinhamento: 'text-left' },
    { key: 'situacao', label: 'Situação', alinhamento: 'text-left' },
    { key: 'designado_em', label: 'Designado há', alinhamento: 'text-left' },
];

const CORES_SITUACAO = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary-container text-on-secondary-container',
};

const selectClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

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

function ConfirmarRetirada({ linhas, salvando, erro, onConfirmar, onFechar }) {
    const emAndamento = linhas.filter((l) => l.situacao === 'em_andamento');

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4">
                <h3 className="font-display text-lg font-semibold text-on-surface">
                    Retirar {linhas.length} designação{linhas.length === 1 ? '' : 'ões'}
                </h3>

                {erro && <Alert>{erro}</Alert>}

                <p className="text-sm text-on-surface-variant">
                    Cada projeto volta ao bolo e é designado na hora para outro avaliador, pelas
                    prioridades do edital. Se não houver ninguém elegível, o projeto fica sem
                    designação e a tela avisa.
                </p>

                {emAndamento.length > 0 && (
                    <Alert>
                        {emAndamento.length} {emAndamento.length === 1 ? 'avaliação já foi iniciada' : 'avaliações já foram iniciadas'}
                        {' '}pelo avaliador. Retirar <strong>descarta o rascunho</strong> dela — não há como recuperar.
                    </Alert>
                )}

                <ul className="max-h-56 overflow-y-auto rounded-lg border border-outline-variant/40 divide-y divide-outline-variant/30">
                    {linhas.map((l) => (
                        <li key={l.id} className="px-3 py-2 text-sm">
                            <p className="text-on-surface truncate">{l.projeto}</p>
                            <p className="text-xs text-on-surface-variant truncate">
                                {l.avaliador} · {l.situacao_label} · {l.tempo_label}
                            </p>
                        </li>
                    ))}
                </ul>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button type="button" loading={salvando} onClick={onConfirmar}>Retirar e redesignar</Button>
                </div>
            </div>
        </div>
    );
}

export default function AvaliacaoDesignacoes() {
    const [busca, setBusca] = useState('');
    const [filtros, setFiltros] = useState({
        q: '', areaId: '', categoria: '', situacao: '', avaliadorId: '',
        ordenar: 'designado_em', direcao: 'asc',
    });
    const [page, setPage] = useState(1);
    const [lista, setLista] = useState(null);
    const [meta, setMeta] = useState(null);
    const [avaliadores, setAvaliadores] = useState([]);
    const [avaliadorSel, setAvaliadorSel] = useState(null);
    const [marcadas, setMarcadas] = useState([]);
    const [confirmando, setConfirmando] = useState(false);
    const [salvando, setSalvando] = useState(false);
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');

    useEffect(() => {
        const t = setTimeout(() => {
            setFiltros((f) => (f.q === busca.trim() ? f : { ...f, q: busca.trim() }));
            setPage(1);
        }, 300);
        return () => clearTimeout(t);
    }, [busca]);

    const carregar = useCallback(() => {
        setAlert('');
        return getDesignacoes({ ...filtros, page })
            .then((resp) => { setLista(resp.data); setMeta(resp.meta); })
            .catch((e) => { setLista([]); setAlert(extractErrors(e).message); });
    }, [filtros, page]);

    useEffect(() => { carregar(); }, [carregar]);

    useEffect(() => {
        getOpcoesAvaliadores()
            .then((opcoes) => setAvaliadores(opcoes.map((a) => ({ id: a.id, nome: a.nome, detalhe: a.area }))))
            .catch(() => setAvaliadores([]));
    }, []);

    function ordenarPor(coluna) {
        setFiltros((f) => ({
            ...f,
            ordenar: coluna,
            direcao: f.ordenar === coluna && f.direcao === 'asc' ? 'desc' : 'asc',
        }));
        setPage(1);
    }

    function trocarFiltro(campo, valor) {
        setFiltros((f) => ({ ...f, [campo]: valor }));
        setPage(1);
    }

    const linhas = lista ?? [];
    const retiraveis = useMemo(() => linhas.filter((l) => l.pode_retirar), [linhas]);
    const selecionadas = useMemo(
        () => linhas.filter((l) => marcadas.includes(l.id)),
        [linhas, marcadas],
    );
    const todasMarcadas = retiraveis.length > 0 && retiraveis.every((l) => marcadas.includes(l.id));

    function alternar(id) {
        setMarcadas((m) => (m.includes(id) ? m.filter((x) => x !== id) : [...m, id]));
    }

    function alternarTodas() {
        const ids = retiraveis.map((l) => l.id);
        setMarcadas((m) => (todasMarcadas ? m.filter((x) => !ids.includes(x)) : [...new Set([...m, ...ids])]));
    }

    async function retirar() {
        setSalvando(true); setAlert(''); setSuccess('');
        try {
            const resp = await retirarDesignacoes(selecionadas.map((l) => l.id));
            setSuccess(resp.meta?.message || 'Designações retiradas.');
            setMarcadas([]);
            setConfirmando(false);
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        } finally {
            setSalvando(false);
        }
    }

    const resumo = meta?.resumo ?? null;
    const areasFiltro = meta?.areas ?? [];
    const categorias = meta?.categorias ?? [];
    const situacoes = meta?.situacoes ?? [];

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Designações</h1>
            <p className="text-on-surface-variant mb-4 max-w-4xl">
                Todos os projetos designados, com <strong>há quanto tempo</strong> cada um está com o
                avaliador. Marque as designações que quer tirar de alguém: elas voltam ao bolo e são
                redesignadas na hora para outro avaliador. Avaliação concluída fica no histórico e
                não sai.
            </p>

            {resumo && (
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 max-w-4xl">
                    {[
                        { label: 'Designações', valor: resumo.total, cor: 'text-primary' },
                        { label: 'Não abertas', valor: resumo.designada, cor: 'text-on-surface' },
                        { label: 'Em avaliação', valor: resumo.em_andamento, cor: 'text-primary-container' },
                        { label: 'Concluídas', valor: resumo.concluida, cor: 'text-secondary' },
                    ].map((card) => (
                        <div key={card.label} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                            <p className={`font-display text-2xl font-semibold ${card.cor}`}>{card.valor}</p>
                            <p className="text-xs text-on-surface-variant">{card.label}</p>
                        </div>
                    ))}
                </div>
            )}

            {alert && <div className="mb-4 max-w-4xl"><Alert>{alert}</Alert></div>}
            {success && <div className="mb-4 max-w-4xl"><Alert type="info">{success}</Alert></div>}

            <div className="max-w-4xl">
                <div className="flex flex-col md:flex-row gap-2 mb-3">
                    <div className="relative flex-1">
                        <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">search</span>
                        <input
                            type="text"
                            aria-label="Buscar por projeto ou avaliador"
                            placeholder="Buscar por projeto ou avaliador…"
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            className={`${selectClass} pl-10`}
                        />
                    </div>
                    <select
                        aria-label="Filtrar por área"
                        className={`${selectClass} md:w-56`}
                        value={filtros.areaId}
                        onChange={(e) => trocarFiltro('areaId', e.target.value)}
                    >
                        <option value="">Todas as áreas</option>
                        {areasFiltro.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                    </select>
                    <select
                        aria-label="Filtrar por categoria"
                        className={`${selectClass} md:w-48`}
                        value={filtros.categoria}
                        onChange={(e) => trocarFiltro('categoria', e.target.value)}
                    >
                        <option value="">Todas as categorias</option>
                        {categorias.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                    </select>
                    <select
                        aria-label="Filtrar por situação"
                        className={`${selectClass} md:w-44`}
                        value={filtros.situacao}
                        onChange={(e) => trocarFiltro('situacao', e.target.value)}
                    >
                        <option value="">Todas as situações</option>
                        {situacoes.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                </div>

                <div className="mb-3 md:w-72">
                    <BuscaCombobox
                        options={avaliadores}
                        value={avaliadorSel}
                        onChange={(a) => { setAvaliadorSel(a); trocarFiltro('avaliadorId', a?.id ?? ''); }}
                        placeholder="Filtrar por avaliador…"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-2 mb-3">
                    <Button
                        type="button"
                        disabled={selecionadas.length === 0}
                        onClick={() => setConfirmando(true)}
                    >
                        <span className="material-symbols-outlined text-[20px]">swap_horiz</span>
                        Retirar {selecionadas.length > 0 ? `(${selecionadas.length})` : 'selecionadas'}
                    </Button>
                    {selecionadas.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setMarcadas([])}
                            className="text-sm text-on-surface-variant hover:text-primary"
                        >
                            Limpar seleção
                        </button>
                    )}
                </div>

                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-surface-variant/40">
                                <tr>
                                    <th scope="col" className="px-3 py-2 w-10">
                                        <input
                                            type="checkbox"
                                            aria-label="Marcar todas as designações retiráveis desta página"
                                            checked={todasMarcadas}
                                            disabled={retiraveis.length === 0}
                                            onChange={alternarTodas}
                                            className="accent-primary-container"
                                        />
                                    </th>
                                    {COLUNAS.map((coluna) => (
                                        <Cabecalho
                                            key={coluna.key}
                                            coluna={coluna}
                                            ordenar={filtros.ordenar}
                                            direcao={filtros.direcao}
                                            onOrdenar={ordenarPor}
                                        />
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-outline-variant/30">
                                {lista === null ? (
                                    <tr><td colSpan={COLUNAS.length + 1} className="px-3 py-8 text-center text-on-surface-variant">Carregando…</td></tr>
                                ) : linhas.length === 0 ? (
                                    <tr><td colSpan={COLUNAS.length + 1} className="px-3 py-8 text-center text-on-surface-variant">Nenhuma designação neste recorte.</td></tr>
                                ) : linhas.map((l) => (
                                    <tr key={l.id} className="hover:bg-surface-variant/20">
                                        <td className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label={`Retirar ${l.projeto} de ${l.avaliador}`}
                                                checked={marcadas.includes(l.id)}
                                                disabled={!l.pode_retirar}
                                                onChange={() => alternar(l.id)}
                                                className="accent-primary-container disabled:opacity-30"
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <p className="text-on-surface truncate max-w-[16rem]" title={l.projeto}>{l.projeto}</p>
                                            {l.categoria_label && <p className="text-xs text-on-surface-variant">{l.categoria_label}</p>}
                                        </td>
                                        <td className="px-3 py-2 text-on-surface-variant">{l.area ?? 'Sem área'}</td>
                                        <td className="px-3 py-2">
                                            <p className="text-on-surface truncate max-w-[12rem]" title={l.avaliador}>{l.avaliador}</p>
                                            {l.designacao_manual && <p className="text-xs text-primary-container">designação manual</p>}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${CORES_SITUACAO[l.situacao] ?? ''}`}>
                                                {l.situacao_label}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-on-surface-variant whitespace-nowrap">
                                            {l.tempo_label}
                                            <span className="block text-xs">{l.designado_em_label}</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {meta && meta.ultima_pagina > 1 && (
                    <div className="flex items-center justify-between gap-2 mt-3">
                        <span className="text-xs text-on-surface-variant">Página {meta.pagina_atual} de {meta.ultima_pagina}</span>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" disabled={meta.pagina_atual <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Anterior</Button>
                            <Button type="button" variant="outline" disabled={meta.pagina_atual >= meta.ultima_pagina} onClick={() => setPage((p) => p + 1)}>Próxima</Button>
                        </div>
                    </div>
                )}
            </div>

            {confirmando && (
                <ConfirmarRetirada
                    linhas={selecionadas}
                    salvando={salvando}
                    erro={alert}
                    onConfirmar={retirar}
                    onFechar={() => setConfirmando(false)}
                />
            )}
        </AppShell>
    );
}
