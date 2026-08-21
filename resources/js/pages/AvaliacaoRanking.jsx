import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert } from '../components/ui.jsx';
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

/** Média com no máximo uma casa decimal, no formato pt_BR (ex.: 4,5). */
const formatarMedia = (valor) =>
    valor === null || valor === undefined ? '—' : String(Math.round(valor * 10) / 10).replace('.', ',');

function MediaQuesito({ rotulo, valor }) {
    return (
        <div className="text-center w-14">
            <div className="text-sm font-semibold text-on-surface">{formatarMedia(valor)}</div>
            <div className="text-[10px] text-on-surface-variant leading-tight">{rotulo}</div>
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

            <div className="hidden sm:flex items-center gap-1 shrink-0">
                <MediaQuesito rotulo="Vídeo" valor={p.medias_quesitos.video} />
                <MediaQuesito rotulo="Resumo" valor={p.medias_quesitos.resumo} />
                <MediaQuesito rotulo="Pesquisa" valor={p.medias_quesitos.pesquisa} />
                {/* Só projetos com documento de continuação têm este quesito. */}
                {p.medias_quesitos.continuidade !== null && p.medias_quesitos.continuidade !== undefined && (
                    <MediaQuesito rotulo="Continuação" valor={p.medias_quesitos.continuidade} />
                )}
            </div>

            <div className="text-right shrink-0 w-24">
                <div className="text-xl font-bold text-secondary">
                    {formatarMedia(p.media)}
                    <span className="text-xs font-normal text-on-surface-variant">/{p.nota_maxima}</span>
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
    const [areaId, setAreaId] = useState('');
    const [erro, setErro] = useState('');

    const buscar = useCallback((filtros) => {
        setErro('');
        return getRankingAvaliacao(filtros)
            .then(setLista)
            .catch(() => { setLista([]); setErro('Não foi possível carregar o ranking.'); });
    }, []);

    useEffect(() => {
        buscar({});
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, [buscar]);

    function onArea(valor) {
        setAreaId(valor);
        setLista(null);
        buscar({ area_id: valor });
    }

    const parciais = (lista ?? []).filter((p) => !p.completo).length;

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Ranking dos projetos</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Projetos que já receberam ao menos uma avaliação concluída, ordenados pela média das
                notas finais. Empate na média é desfeito por quem tem mais avaliações.
            </p>

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="ranking-area">
                    Área do conhecimento
                </label>
                <select id="ranking-area" className={campoClass} value={areaId} onChange={(e) => onArea(e.target.value)}>
                    <option value="">Todas as áreas</option>
                    {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                </select>
                <p className="text-xs text-on-surface-variant mt-2">
                    Projetos de áreas diferentes não competem entre si — filtre por área para o ranking que vale.
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
