import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import ListaFinalDialog from '../components/ListaFinalDialog.jsx';
import { getRankingAvaliacao } from '../lib/admin.js';
import { loadAreas } from '../lib/catalogos.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

// Medalha para o pódio; do 4º em diante, só o número.
const MEDALHA = { 1: '🥇', 2: '🥈', 3: '🥉' };

function Posicao({ n }) {
    return (
        <span className="w-10 shrink-0 text-center" aria-label={`${n}º lugar`}>
            {MEDALHA[n]
                ? <span className="text-2xl leading-none">{MEDALHA[n]}</span>
                : <span className="text-lg font-bold text-on-surface-variant">{n}º</span>}
        </span>
    );
}

/** Média no formato pt_BR com duas casas (ex.: 6,74). */
const formatarMedia = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

/** Teto sem casas à toa (0,15 · 1,075 · 2). */
const formatarMaximo = (valor) => String(Math.round(Number(valor) * 10000) / 10000).replace('.', ',');

// Média de uma seção da rubrica: quanto o projeto tirou do que a seção vale.
function MediaSecao({ secao }) {
    return (
        <div className="text-center w-16" title={`${secao.titulo}: média de ${formatarMedia(secao.media)} de ${formatarMaximo(secao.maximo)}`}>
            <div className="text-sm font-semibold text-on-surface">{formatarMedia(secao.media)}</div>
            <div className="text-[10px] text-on-surface-variant leading-tight">
                {secao.titulo}
                <span className="block text-on-surface-variant/70">de {formatarMaximo(secao.maximo)}</span>
            </div>
        </div>
    );
}

function Linha({ p }) {
    return (
        <li className="px-4 py-3 flex items-center gap-3 flex-wrap">
            <Posicao n={p.posicao} />

            <div className="flex-1 min-w-0">
                <p className="text-sm text-on-surface truncate">{p.titulo}</p>
                <p className="text-xs text-on-surface-variant truncate">
                    {p.area ?? 'Sem área'}{p.categoria ? ` · ${p.categoria}` : ''}
                </p>
            </div>

            {/* Uma coluna por seção pontuada da rubrica — rola na horizontal quando não cabe. */}
            <div className="hidden sm:flex items-center gap-1 shrink-0 overflow-x-auto max-w-full">
                {(p.medias_secoes ?? []).map((secao) => (
                    <MediaSecao key={secao.chave} secao={secao} />
                ))}
            </div>

            <div className="text-right shrink-0 w-24">
                <div className="text-xl font-bold text-secondary">
                    {formatarMedia(p.media)}
                    <span className="text-xs font-normal text-on-surface-variant">/{formatarMaximo(p.nota_maxima)}</span>
                </div>
                <div className="text-[10px] text-on-surface-variant leading-tight">
                    {p.avaliacoes} {p.avaliacoes === 1 ? 'avaliação' : 'avaliações'}
                    {!p.completo && <span className="block text-error font-semibold">parcial</span>}
                </div>
            </div>
        </li>
    );
}

export default function AvaliacaoRanking() {
    const [lista, setLista] = useState(null);
    const [areas, setAreas] = useState([]);
    const [categorias, setCategorias] = useState([]);
    const [areaId, setAreaId] = useState('');
    const [categoria, setCategoria] = useState('');
    const [erro, setErro] = useState('');
    const [listaFinal, setListaFinal] = useState(false);

    const buscar = useCallback((filtros) => {
        setErro('');
        return getRankingAvaliacao(filtros)
            .then((resp) => { setLista(resp.data); setCategorias(resp.meta?.categorias ?? []); })
            .catch(() => { setLista([]); setErro('Não foi possível carregar o ranking.'); });
    }, []);

    useEffect(() => {
        buscar({});
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, [buscar]);

    // Área e categoria são independentes: o ranking sai do cruzamento das duas.
    function filtrar(campo, valor) {
        const filtros = { area_id: areaId, categoria, [campo]: valor };
        if (campo === 'area_id') setAreaId(valor); else setCategoria(valor);
        setLista(null);
        buscar(filtros);
    }

    const parciais = (lista ?? []).filter((p) => !p.completo).length;

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <div className="flex items-start justify-between gap-3 flex-wrap mb-6 max-w-3xl">
                <div className="min-w-0">
                    <h1 className="font-display text-2xl font-semibold text-primary mb-1">Ranking dos projetos</h1>
                    <p className="text-on-surface-variant">
                        Projetos que já receberam ao menos uma avaliação concluída, ordenados pela média das
                        notas finais. Empate na média é desfeito por quem tem mais avaliações.
                    </p>
                </div>
                <Button type="button" className="shrink-0" onClick={() => setListaFinal(true)}>
                    <span className="material-symbols-outlined text-[18px]">list_alt</span>
                    Gerar lista final
                </Button>
            </div>

            <ListaFinalDialog open={listaFinal} onClose={() => setListaFinal(false)} />

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="ranking-area">
                            Área do conhecimento
                        </label>
                        <select id="ranking-area" className={campoClass} value={areaId} onChange={(e) => filtrar('area_id', e.target.value)}>
                            <option value="">Todas as áreas</option>
                            {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="ranking-categoria">
                            Categoria
                        </label>
                        <select id="ranking-categoria" className={campoClass} value={categoria} onChange={(e) => filtrar('categoria', e.target.value)}>
                            <option value="">Todas as categorias</option>
                            {categorias.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                        </select>
                    </div>
                </div>
                <p className="text-xs text-on-surface-variant mt-2">
                    Projetos de áreas e categorias diferentes não competem entre si — filtre pelos dois para
                    o ranking que vale.
                </p>
            </div>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            {lista === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : lista.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhum projeto avaliado ainda.
                </div>
            ) : (
                <div className="max-w-3xl">
                    {parciais > 0 && (
                        <div className="mb-4">
                            <Alert type="info">
                                {parciais} {parciais === 1 ? 'projeto ainda não atingiu' : 'projetos ainda não atingiram'}{' '}
                                o mínimo de 3 avaliações — a média deles é <strong>parcial</strong> e a posição pode mudar.
                            </Alert>
                        </div>
                    )}
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                        <div className="px-4 py-3 bg-surface-variant/40 flex items-center justify-between gap-2">
                            <h2 className="font-display font-semibold text-on-surface">Classificação</h2>
                            <span className="text-xs text-on-surface-variant shrink-0">
                                {lista.length} {lista.length === 1 ? 'projeto' : 'projetos'}
                            </span>
                        </div>
                        <ul className="divide-y divide-outline-variant/30">
                            {lista.map((p) => <Linha key={p.projeto_id} p={p} />)}
                        </ul>
                    </div>
                </div>
            )}
        </AppShell>
    );
}
