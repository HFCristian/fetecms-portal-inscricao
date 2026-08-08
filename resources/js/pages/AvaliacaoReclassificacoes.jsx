import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert } from '../components/ui.jsx';
import { getReclassificacoes } from '../lib/admin.js';
import { loadAreas } from '../lib/catalogos.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const FILTROS_VAZIOS = { area_id: '', q: '', de: '', ate: '' };

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

function ProjetoCard({ p }) {
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

            {(p.area_mais_sugerida || p.subarea_mais_sugerida) && (
                <div className="px-4 py-2 flex flex-wrap gap-2 border-b border-outline-variant/30">
                    <Consenso rotulo="Área" dados={p.area_mais_sugerida} />
                    <Consenso rotulo="Subárea" dados={p.subarea_mais_sugerida} />
                </div>
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
    const [carregando, setCarregando] = useState(false);

    const buscar = useCallback((f) => {
        setCarregando(true); setErro('');
        return getReclassificacoes(f)
            .then(setLista)
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
                <>
                    <p className="text-sm text-on-surface-variant mb-3 max-w-3xl">
                        <strong>{lista.length}</strong> {lista.length === 1 ? 'projeto' : 'projetos'} ·{' '}
                        <strong>{totalSugestoes}</strong> {totalSugestoes === 1 ? 'sugestão' : 'sugestões'}
                    </p>
                    <div className="space-y-4 max-w-3xl">
                        {lista.map((p) => <ProjetoCard key={p.projeto_id} p={p} />)}
                    </div>
                </>
            )}
        </AppShell>
    );
}
