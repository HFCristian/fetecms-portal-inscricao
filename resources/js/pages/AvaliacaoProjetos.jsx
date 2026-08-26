import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, Select } from '../components/ui.jsx';
import BuscaCombobox from '../components/BuscaCombobox.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getAvaliacaoProjetos, exportarProjetosAvaliacaoCsv, getOpcoesAvaliadores, designarProjeto,
} from '../lib/admin.js';
import { loadAreas, loadSubareas } from '../lib/catalogos.js';

// Colunas da tabela. `key` é a chave de ordenação que o backend entende.
const COLUNAS = [
    { key: 'titulo', label: 'Projeto', alinhamento: 'text-left' },
    { key: 'area', label: 'Área do conhecimento', alinhamento: 'text-left' },
    { key: 'categoria', label: 'Categoria', alinhamento: 'text-left' },
    { key: 'em_avaliacao', label: 'Em avaliação', alinhamento: 'text-center' },
    { key: 'realizadas', label: 'Realizadas', alinhamento: 'text-center' },
    { key: 'faltantes', label: 'Faltantes', alinhamento: 'text-center' },
];

// Card de resumo de uma área: quantos projetos estão com 0, 1, 2 e 3+ avaliações
// concluídas. Responde aos mesmos filtros da tabela.
function CardArea({ resumo, minPorProjeto, minUniforme }) {
    const faixas = [
        { key: 'zero', label: '0', cor: 'text-error' },
        { key: 'uma', label: '1', cor: 'text-on-surface' },
        { key: 'duas', label: '2', cor: 'text-primary-container' },
        { key: 'tres_ou_mais', label: '3+', cor: 'text-secondary' },
    ];

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
            <h3 className="font-display text-sm font-semibold text-on-surface truncate" title={resumo.area}>
                {resumo.area}
            </h3>
            <p className="text-xs text-on-surface-variant mb-2">
                {resumo.total} {resumo.total === 1 ? 'projeto' : 'projetos'} · {resumo.completos} com o mínimo
                {minUniforme ? ` de ${minPorProjeto}` : ' da categoria'}
            </p>
            <div className="grid grid-cols-4 gap-1">
                {faixas.map((f) => (
                    <div key={f.key} className="text-center">
                        <div className={`text-lg font-bold ${f.cor}`}>{resumo[f.key]}</div>
                        <div className="text-[10px] text-on-surface-variant leading-tight" aria-hidden="true">{f.label}</div>
                        <span className="sr-only">{`${resumo[f.key]} projetos com ${f.label} avaliações`}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

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

const selectClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

// Modal de designação: avaliador específico, ou todos de uma área/subárea.
// O alvo é escolhido por busca — digite o nome e a lista filtra.
function DesignarModal({ projeto, avaliadores, areas, onFechar, onDesignar, salvando }) {
    const [tipo, setTipo] = useState('avaliador');
    const [alvo, setAlvo] = useState(null);
    const [subareas, setSubareas] = useState([]);
    const [comissao, setComissao] = useState([]);
    const [selecionados, setSelecionados] = useState([]);

    // Ao escolher "subárea", carrega as subáreas da área do projeto.
    useEffect(() => {
        setAlvo(null);
        if (tipo === 'subarea' && projeto.area_id) {
            loadSubareas(projeto.area_id).then(setSubareas).catch(() => setSubareas([]));
        }
    }, [tipo, projeto.area_id]);

    // Comissão especial: a lista dos membros só é buscada quando o admin escolhe
    // designar para ela.
    useEffect(() => {
        setSelecionados([]);
        if (tipo === 'comissao' || tipo === 'comissao_selecionada') {
            getOpcoesAvaliadores(true).then(setComissao).catch(() => setComissao([]));
        }
    }, [tipo]);

    const ehComissao = tipo === 'comissao' || tipo === 'comissao_selecionada';

    const opcoes = tipo === 'avaliador' ? avaliadores
        : tipo === 'area' ? areas.map((a) => ({ id: a.id, nome: a.nome }))
            : subareas.map((s) => ({ id: s.id, nome: s.nome }));

    const rotulo = tipo === 'avaliador' ? 'Avaliador' : tipo === 'area' ? 'Área' : 'Subárea';

    const alternar = (id) => setSelecionados((atual) => (
        atual.includes(id) ? atual.filter((x) => x !== id) : [...atual, id]
    ));

    const podeDesignar = ehComissao
        ? (tipo === 'comissao' ? comissao.length > 0 : selecionados.length > 0)
        : Boolean(alvo);

    function confirmar() {
        if (ehComissao) {
            onDesignar({
                tipo: 'comissao',
                // Comissão inteira manda a lista vazia; a seleção manda os marcados.
                avaliador_ids: tipo === 'comissao_selecionada' ? selecionados : [],
            });
            return;
        }
        if (alvo) {
            onDesignar({ tipo, alvo_id: Number(alvo.id) });
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Designar avaliação</h3>
                    <p className="text-sm text-on-surface-variant truncate">{projeto.titulo}</p>
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="designar-tipo">Designar para</label>
                    <select id="designar-tipo" className={selectClass} value={tipo} onChange={(e) => setTipo(e.target.value)}>
                        <option value="avaliador">Um avaliador específico</option>
                        <option value="area">Todos os avaliadores de uma área</option>
                        <option value="subarea">Todos os avaliadores de uma subárea</option>
                        <option value="comissao">Comissão especial (todos)</option>
                        <option value="comissao_selecionada">Comissão especial (selecionar)</option>
                    </select>
                </div>

                {ehComissao ? (
                    <div className="space-y-1">
                        <p className="text-sm font-semibold text-on-surface">Comissão especial</p>
                        {comissao.length === 0 ? (
                            <p className="text-xs text-on-surface-variant">
                                Nenhum avaliador está marcado como comissão especial. Marque em
                                “Avaliadores Online”.
                            </p>
                        ) : tipo === 'comissao' ? (
                            <p className="text-sm text-on-surface-variant">
                                O projeto será designado aos <strong>{comissao.length}</strong> membros da
                                comissão especial.
                            </p>
                        ) : (
                            <ul className="max-h-52 overflow-y-auto divide-y divide-outline-variant/30 border border-outline-variant/40 rounded-lg">
                                {comissao.map((membro) => (
                                    <li key={membro.id}>
                                        <label className="flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-surface-variant/40">
                                            <input
                                                type="checkbox"
                                                checked={selecionados.includes(membro.id)}
                                                onChange={() => alternar(membro.id)}
                                                className="accent-[color:var(--color-primary-container,#43157A)]"
                                            />
                                            <span className="text-sm text-on-surface truncate">{membro.nome}</span>
                                            {membro.area && <span className="text-xs text-on-surface-variant truncate">{membro.area}</span>}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                ) : (
                    <div className="space-y-1">
                        <label className="text-sm font-semibold text-on-surface">{rotulo}</label>
                        <BuscaCombobox
                            options={opcoes}
                            value={alvo}
                            onChange={setAlvo}
                            placeholder={tipo === 'avaliador' ? 'Digite o nome do avaliador…' : `Digite o nome da ${rotulo.toLowerCase()}…`}
                        />
                        {tipo === 'subarea' && subareas.length === 0 && (
                            <p className="text-xs text-on-surface-variant">Nenhuma subárea na área deste projeto.</p>
                        )}
                    </div>
                )}

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={!podeDesignar} onClick={confirmar}>
                        Designar
                    </Button>
                </div>
            </div>
        </div>
    );
}

export default function AvaliacaoProjetos() {
    const [busca, setBusca] = useState('');
    const [filtros, setFiltros] = useState({ q: '', areaId: '', categoria: '', ordenar: 'titulo', direcao: 'asc' });
    const [page, setPage] = useState(1);
    const [lista, setLista] = useState(null);
    const [meta, setMeta] = useState(null);
    const [avaliadores, setAvaliadores] = useState([]);
    const [areas, setAreas] = useState([]);
    const [designando, setDesignando] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [exportando, setExportando] = useState(false);
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');

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
        return getAvaliacaoProjetos({ ...filtros, page })
            .then((resp) => { setLista(resp.data); setMeta(resp.meta); })
            .catch((e) => { setLista([]); setAlert(extractErrors(e).message); });
    }, [filtros, page]);

    useEffect(() => { carregar(); }, [carregar]);

    useEffect(() => {
        // Opções da designação: lista plana de avaliadores em ordem alfabética.
        getOpcoesAvaliadores()
            .then((opcoes) => setAvaliadores(
                opcoes.map((a) => ({ id: a.id, nome: a.nome, detalhe: a.area })),
            ))
            .catch(() => setAvaliadores([]));
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, []);

    function ordenarPor(coluna) {
        setFiltros((f) => ({
            ...f,
            ordenar: coluna,
            direcao: f.ordenar === coluna && f.direcao === 'asc' ? 'desc' : 'asc',
        }));
        setPage(1);
    }

    async function designar(payload) {
        setSalvando(true); setAlert(''); setSuccess('');
        try {
            const resp = await designarProjeto(designando.id, payload);
            setSuccess(resp.meta?.message || 'Designação criada.');
            setDesignando(null);
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
            await exportarProjetosAvaliacaoCsv(filtros);
        } catch {
            setAlert('Não foi possível gerar o CSV. Tente novamente.');
        } finally {
            setExportando(false);
        }
    }

    const resumoAreas = meta?.resumo_areas ?? [];
    const minPorProjeto = meta?.min_por_projeto ?? 3;
    // Mínimo por categoria: o card fala em "mínimo da categoria" em vez de um número.
    const minUniforme = meta?.min_por_projeto_uniforme ?? true;
    const areasFiltro = meta?.areas ?? [];
    const categorias = meta?.categorias ?? [];
    const temFiltro = filtros.q !== '' || filtros.areaId !== '' || filtros.categoria !== '';

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos submetidos</h1>
            <p className="text-on-surface-variant mb-4 max-w-4xl">
                Todos os projetos submetidos e o progresso das avaliações de cada um. Busque pelo título,
                filtre por área e categoria, ordene por qualquer coluna e exporte o recorte em CSV. Designe
                manualmente um projeto para um avaliador ou para todos de uma área/subárea.
            </p>

            {resumoAreas.length > 0 && (
                <section aria-label="Resumo por área do conhecimento" className="mb-4 max-w-4xl">
                    <p className="text-xs text-on-surface-variant mb-2">
                        Projetos por número de avaliações concluídas (0, 1, 2 e 3 ou mais), por área.
                        Os filtros da tabela valem aqui também.
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        {resumoAreas.map((r) => (
                            <CardArea
                                key={r.area_id ?? 'sem-area'}
                                resumo={r}
                                minPorProjeto={minPorProjeto}
                                minUniforme={minUniforme}
                            />
                        ))}
                    </div>
                </section>
            )}

            <div className="mb-4 flex flex-wrap gap-2 max-w-4xl">
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
                            aria-label="Buscar projeto"
                            className="w-full bg-surface-container-lowest border border-outline-variant rounded-lg pl-10 pr-3 py-2.5 text-on-surface placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 transition-all outline-none"
                            placeholder="Buscar pelo título do projeto…"
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                        />
                    </div>
                    <div className="w-full md:w-52">
                        <Select
                            aria-label="Filtrar por categoria"
                            value={filtros.categoria}
                            onChange={(e) => { setFiltros((f) => ({ ...f, categoria: e.target.value })); setPage(1); }}
                        >
                            <option value="">Todas as categorias</option>
                            {categorias.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                        </Select>
                    </div>
                    <div className="w-full md:w-64">
                        <Select
                            aria-label="Filtrar por área do conhecimento"
                            value={filtros.areaId}
                            onChange={(e) => { setFiltros((f) => ({ ...f, areaId: e.target.value })); setPage(1); }}
                        >
                            <option value="">Todas as áreas</option>
                            {areasFiltro.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </Select>
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3 mb-2">
                    <p className="text-xs text-on-surface-variant">
                        {meta ? `${meta.total} ${meta.total === 1 ? 'projeto' : 'projetos'}${temFiltro ? ' no filtro atual' : ''}.` : ''}
                    </p>
                    {temFiltro && (
                        <button
                            type="button"
                            onClick={() => { setBusca(''); setFiltros((f) => ({ ...f, q: '', areaId: '', categoria: '' })); setPage(1); }}
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
                        {temFiltro ? 'Nenhum projeto neste filtro.' : 'Nenhum projeto submetido ainda.'}
                    </div>
                ) : (
                    <>
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-x-auto">
                            <table className="w-full min-w-[54rem] text-sm">
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
                                    {lista.map((p) => (
                                        <tr key={p.id} className="hover:bg-surface-variant/30 transition-colors">
                                            <td className="px-3 py-2">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <span className="material-symbols-outlined text-primary-container text-[20px]">description</span>
                                                    <span className="text-on-surface truncate">{p.titulo}</span>
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 text-on-surface-variant">
                                                <p className="truncate">{p.area ?? 'Sem área'}</p>
                                                {p.subarea && <p className="text-xs truncate">{p.subarea}</p>}
                                            </td>
                                            <td className="px-3 py-2 text-xs text-on-surface-variant">{p.categoria_label ?? '—'}</td>
                                            <td className="px-3 py-2 text-center font-bold text-primary-container">{p.em_avaliacao}</td>
                                            <td className="px-3 py-2 text-center font-bold text-secondary">{p.realizadas}</td>
                                            <td className="px-3 py-2 text-center font-bold text-on-surface">{p.faltantes}</td>
                                            <td className="px-3 py-2 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => setDesignando({ id: p.id, titulo: p.titulo, area_id: p.area_id })}
                                                    aria-label={`Designar ${p.titulo}`}
                                                    className="shrink-0 inline-flex items-center gap-1 text-sm font-semibold text-primary-container hover:text-primary border border-outline-variant rounded-lg px-3 py-1.5 hover:bg-surface-variant transition-colors"
                                                >
                                                    <span className="material-symbols-outlined text-[18px]">assignment_ind</span>
                                                    Designar
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
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

            {designando && (
                <DesignarModal
                    projeto={designando}
                    avaliadores={avaliadores}
                    areas={areas}
                    onFechar={() => setDesignando(null)}
                    onDesignar={designar}
                    salvando={salvando}
                />
            )}
        </AppShell>
    );
}
