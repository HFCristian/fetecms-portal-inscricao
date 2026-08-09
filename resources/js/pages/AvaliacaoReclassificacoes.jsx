import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getReclassificacoes, aplicarReclassificacoes } from '../lib/admin.js';
import { loadAreas } from '../lib/catalogos.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const FILTROS_VAZIOS = { area_id: '', q: '', de: '', ate: '' };

// Os dois campos reclassificáveis, com o nome das chaves que o backend usa.
const TIPOS = [
    { chave: 'area', rotulo: 'Área', opcoes: 'opcoes_area', payload: 'area_id' },
    { chave: 'subarea', rotulo: 'Subárea', opcoes: 'opcoes_subarea', payload: 'subarea_id' },
];

const opcoesDe = (p, tipo) => p[tipo.opcoes] ?? [];

/** Sugestão a aplicar por padrão: a mais votada (o backend já ordena assim). */
const sugestaoPadrao = (p, tipo) => opcoesDe(p, tipo)[0] ?? null;

/** Item do payload a partir da seleção de um projeto; null se nada foi marcado. */
function itemDaSelecao(projetoId, escolha) {
    if (!escolha?.area && !escolha?.subarea) return null;
    return {
        projeto_id: projetoId,
        ...(escolha.area ? { area_id: escolha.area } : {}),
        ...(escolha.subarea ? { subarea_id: escolha.subarea } : {}),
    };
}

// Consenso: o que mais avaliadores sugeriram para o projeto.
function Consenso({ rotulo, dados }) {
    if (!dados) return null;
    return (
        <span className="inline-flex items-center gap-1 text-xs bg-primary-fixed text-primary-container rounded-full px-2 py-0.5">
            <span className="material-symbols-outlined text-[14px]">arrow_forward</span>
            {rotulo}: <strong>{dados.nome}</strong>
            <span className="opacity-70">({dados.votos})</span>
        </span>
    );
}

// Escolha de uma sugestão no modo lote: caixa de seleção + qual opção aplicar.
function EscolhaSugestao({ projeto, tipo, valor, onChange }) {
    const opcoes = opcoesDe(projeto, tipo);
    if (opcoes.length === 0) return null;

    const id = `sel-${tipo.chave}-${projeto.projeto_id}`;
    const marcado = valor != null;

    return (
        <div className="flex items-center gap-2 flex-wrap">
            <label className="flex items-center gap-2 text-sm text-on-surface cursor-pointer">
                <input
                    type="checkbox"
                    id={id}
                    checked={marcado}
                    onChange={(e) => onChange(e.target.checked ? opcoes[0].id : null)}
                    className="w-4 h-4 rounded text-primary-container"
                />
                {tipo.rotulo}
            </label>

            {opcoes.length === 1 ? (
                <span className={`text-sm ${marcado ? 'text-on-surface font-semibold' : 'text-on-surface-variant'}`}>
                    → {opcoes[0].nome}
                </span>
            ) : (
                <select
                    aria-label={`${tipo.rotulo} a aplicar em ${projeto.titulo}`}
                    className="bg-surface border border-outline-variant rounded-lg px-2 py-1 text-sm text-on-surface outline-none focus:border-primary-container disabled:opacity-50"
                    value={valor ?? opcoes[0].id}
                    disabled={!marcado}
                    onChange={(e) => onChange(Number(e.target.value))}
                >
                    {opcoes.map((o) => (
                        <option key={o.id} value={o.id}>{o.nome} ({o.votos})</option>
                    ))}
                </select>
            )}
        </div>
    );
}

function ProjetoCard({ p, modoLote, escolha, onEscolha, onAplicar, aplicando, ocupado }) {
    const temSugestao = TIPOS.some((t) => opcoesDe(p, t).length > 0);

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
            <div className="px-4 py-3 bg-surface-variant/40 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-display font-semibold text-on-surface truncate">{p.titulo}</h2>
                    <p className="text-xs text-on-surface-variant mt-0.5">
                        Hoje em <strong>{p.area ?? '—'}</strong>
                        {p.subarea ? <> · <strong>{p.subarea}</strong></> : null}
                    </p>
                </div>
                <span className="shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full bg-error-container text-on-error-container">
                    {p.total_sugestoes} {p.total_sugestoes === 1 ? 'sugestão' : 'sugestões'}
                </span>
            </div>

            {modoLote ? (
                <div className="px-4 py-3 border-b border-outline-variant/30 space-y-2 bg-surface-container-low/40">
                    <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">
                        Aplicar neste projeto
                    </p>
                    {TIPOS.map((t) => (
                        <EscolhaSugestao
                            key={t.chave}
                            projeto={p}
                            tipo={t}
                            valor={escolha?.[t.chave] ?? null}
                            onChange={(v) => onEscolha(p.projeto_id, t.chave, v)}
                        />
                    ))}
                </div>
            ) : (
                (p.area_mais_sugerida || p.subarea_mais_sugerida) && (
                    <div className="px-4 py-2 flex flex-wrap items-center gap-2 border-b border-outline-variant/30">
                        <Consenso rotulo="Área" dados={p.area_mais_sugerida} />
                        <Consenso rotulo="Subárea" dados={p.subarea_mais_sugerida} />
                        {temSugestao && (
                            <Button
                                type="button"
                                variant="outline"
                                className="ml-auto px-3! py-1! text-sm"
                                loading={aplicando}
                                disabled={ocupado && !aplicando}
                                onClick={() => onAplicar(p)}
                            >
                                <span className="material-symbols-outlined text-[18px]">published_with_changes</span>
                                Aplicar sugestão
                            </Button>
                        )}
                    </div>
                )
            )}

            <ul className="divide-y divide-outline-variant/30">
                {p.sugestoes.map((s) => (
                    <li key={s.avaliacao_id} className="px-4 py-2.5 text-sm flex items-start gap-3 flex-wrap">
                        <span className="material-symbols-outlined text-on-surface-variant text-[18px]">person</span>
                        <div className="flex-1 min-w-0">
                            <p className="text-on-surface truncate">{s.avaliador ?? 'Avaliador removido'}</p>
                            <p className="text-xs text-on-surface-variant">
                                {s.area_sugerida && <>Área → <strong>{s.area_sugerida}</strong></>}
                                {s.area_sugerida && s.subarea_sugerida && ' · '}
                                {s.subarea_sugerida && <>Subárea → <strong>{s.subarea_sugerida}</strong></>}
                            </p>
                        </div>
                        <span className="text-xs text-on-surface-variant shrink-0">{s.avaliada_em}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function AvaliacaoReclassificacoes() {
    const [lista, setLista] = useState(null);
    const [areas, setAreas] = useState([]);
    const [filtros, setFiltros] = useState(FILTROS_VAZIOS);
    const [erro, setErro] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [carregando, setCarregando] = useState(false);
    // Qual ação está em andamento: id do projeto, 'lote', ou null. Assim só o
    // botão acionado mostra o spinner, em vez de todos ao mesmo tempo.
    const [aplicandoId, setAplicandoId] = useState(null);
    const [modoLote, setModoLote] = useState(false);
    // { [projetoId]: { area: id|null, subarea: id|null } }
    const [selecao, setSelecao] = useState({});
    const [confirm, dialogo] = useConfirm();

    const buscar = useCallback((f) => {
        setCarregando(true); setErro('');
        return getReclassificacoes(f)
            .then((dados) => { setLista(dados); setSelecao({}); })
            .catch(() => { setLista([]); setErro('Não foi possível carregar as sugestões.'); })
            .finally(() => setCarregando(false));
    }, []);

    useEffect(() => {
        buscar(FILTROS_VAZIOS);
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, [buscar]);

    const setCampo = (campo, valor) => setFiltros((f) => ({ ...f, [campo]: valor }));

    function onSubmit(e) {
        e.preventDefault();
        buscar(filtros);
    }

    function limpar() {
        setFiltros(FILTROS_VAZIOS);
        buscar(FILTROS_VAZIOS);
    }

    const onEscolha = (projetoId, chave, valor) =>
        setSelecao((s) => ({ ...s, [projetoId]: { ...s[projetoId], [chave]: valor } }));

    /** Marca, em todos os projetos, a sugestão mais votada de cada campo. */
    function selecionarTodos() {
        setSelecao(Object.fromEntries((lista ?? []).map((p) => [
            p.projeto_id,
            Object.fromEntries(TIPOS.map((t) => [t.chave, sugestaoPadrao(p, t)?.id ?? null])),
        ])));
    }

    const itensSelecionados = (lista ?? [])
        .map((p) => itemDaSelecao(p.projeto_id, selecao[p.projeto_id]))
        .filter(Boolean);

    const todosMarcados = (lista ?? []).length > 0
        && itensSelecionados.length === (lista ?? []).length
        && (lista ?? []).every((p) => TIPOS.every(
            (t) => opcoesDe(p, t).length === 0 || selecao[p.projeto_id]?.[t.chave] != null,
        ));

    async function enviar(itens, mensagem, id) {
        const ok = await confirm({
            title: 'Aplicar reclassificação', confirmLabel: 'Aplicar', message: mensagem,
        });
        if (!ok) return;

        setAplicandoId(id); setErro(''); setSucesso('');
        try {
            const resp = await aplicarReclassificacoes(itens);
            const limpas = resp.data.filter((r) => r.subarea_limpa).length;
            setSucesso(
                (resp.meta?.message || 'Reclassificação aplicada.')
                + (limpas > 0 ? ` A subárea de ${limpas} projeto(s) foi limpa por não pertencer à nova área.` : ''),
            );
            await buscar(filtros);
        } catch (e) {
            setErro(extractErrors(e).message);
        } finally {
            setAplicandoId(null);
        }
    }

    /** Botão de um projeto só: aplica o consenso (área e/ou subárea). */
    function aplicarUm(p) {
        const escolha = Object.fromEntries(TIPOS.map((t) => [t.chave, sugestaoPadrao(p, t)?.id ?? null]));
        const trocas = TIPOS
            .filter((t) => escolha[t.chave])
            .map((t) => `${t.rotulo} → ${sugestaoPadrao(p, t).nome}`)
            .join(' · ');

        return enviar([itemDaSelecao(p.projeto_id, escolha)], `"${p.titulo}"\n\n${trocas}`, p.projeto_id);
    }

    function aplicarSelecionados() {
        return enviar(
            itensSelecionados,
            `${itensSelecionados.length} projeto(s) serão reclassificados com as sugestões marcadas. Continuar?`,
            'lote',
        );
    }

    const totalSugestoes = (lista ?? []).reduce((s, p) => s + p.total_sugestoes, 0);

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Reclassificações sugeridas</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Projetos em que algum avaliador marcou a área ou a subárea como incorreta ao concluir a
                avaliação. O destaque mostra a opção mais votada entre os avaliadores.
            </p>

            <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div className="sm:col-span-2">
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="filtro-q">
                            Nome do projeto
                        </label>
                        <input
                            id="filtro-q"
                            type="search"
                            className={campoClass}
                            value={filtros.q}
                            onChange={(e) => setCampo('q', e.target.value)}
                            placeholder="Buscar por trecho do título…"
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="filtro-area">
                            Área do conhecimento
                        </label>
                        <select
                            id="filtro-area"
                            className={campoClass}
                            value={filtros.area_id}
                            onChange={(e) => setCampo('area_id', e.target.value)}
                        >
                            <option value="">Todas as áreas</option>
                            {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="filtro-de">
                            Avaliado de
                        </label>
                        <input id="filtro-de" type="date" className={campoClass}
                            value={filtros.de} onChange={(e) => setCampo('de', e.target.value)} />
                    </div>
                    <div>
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="filtro-ate">
                            Avaliado até
                        </label>
                        <input id="filtro-ate" type="date" className={campoClass}
                            value={filtros.ate} onChange={(e) => setCampo('ate', e.target.value)} />
                    </div>
                </div>
                <div className="flex items-center gap-2 mt-3">
                    <Button type="submit" loading={carregando}>
                        <span className="material-symbols-outlined text-[18px]">search</span>
                        Filtrar
                    </Button>
                    <Button type="button" variant="outline" onClick={limpar}>Limpar</Button>
                </div>
            </form>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            {lista === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : lista.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhum projeto com sugestão de reclassificação
                    {Object.values(filtros).some(Boolean) ? ' para estes filtros.' : ' por enquanto.'}
                </div>
            ) : (
                <div className="max-w-3xl">
                    <div className="flex items-center justify-between gap-3 flex-wrap mb-3">
                        <p className="text-sm text-on-surface-variant">
                            <strong>{lista.length}</strong> {lista.length === 1 ? 'projeto' : 'projetos'} ·{' '}
                            <strong>{totalSugestoes}</strong> {totalSugestoes === 1 ? 'sugestão' : 'sugestões'}
                        </p>
                        <label className="flex items-center gap-2 text-sm font-semibold text-on-surface cursor-pointer">
                            <input
                                type="checkbox"
                                checked={modoLote}
                                onChange={(e) => { setModoLote(e.target.checked); setSelecao({}); }}
                                className="w-4 h-4 rounded text-primary-container"
                            />
                            Aceitar várias sugestões de uma vez
                        </label>
                    </div>

                    {modoLote && (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4 flex items-center justify-between gap-3 flex-wrap">
                            <label className="flex items-center gap-2 text-sm text-on-surface cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={todosMarcados}
                                    onChange={(e) => (e.target.checked ? selecionarTodos() : setSelecao({}))}
                                    className="w-4 h-4 rounded text-primary-container"
                                />
                                Selecionar todos
                            </label>
                            <div className="flex items-center gap-2 flex-wrap">
                                <span className="text-sm text-on-surface-variant">
                                    {itensSelecionados.length} selecionado(s)
                                </span>
                                <Button
                                    type="button"
                                    loading={aplicandoId === 'lote'}
                                    disabled={itensSelecionados.length === 0}
                                    onClick={aplicarSelecionados}
                                >
                                    <span className="material-symbols-outlined text-[18px]">published_with_changes</span>
                                    Aplicar selecionadas
                                </Button>
                            </div>
                        </div>
                    )}

                    <div className="space-y-4">
                        {lista.map((p) => (
                            <ProjetoCard
                                key={p.projeto_id}
                                p={p}
                                modoLote={modoLote}
                                escolha={selecao[p.projeto_id]}
                                onEscolha={onEscolha}
                                onAplicar={aplicarUm}
                                aplicando={aplicandoId === p.projeto_id}
                                ocupado={aplicandoId !== null}
                            />
                        ))}
                    </div>
                </div>
            )}
            {dialogo}
        </AppShell>
    );
}
