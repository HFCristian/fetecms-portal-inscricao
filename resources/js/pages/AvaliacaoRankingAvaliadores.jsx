import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert } from '../components/ui.jsx';
import { getRankingAvaliadores } from '../lib/admin.js';

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

/** "Campo Grande/MS", "MS" ou "—", conforme o que o avaliador informou. */
function localidade(a) {
    if (a.cidade && a.estado) return `${a.cidade}/${a.estado}`;
    return a.cidade || a.estado || '—';
}

export default function AvaliacaoRankingAvaliadores() {
    const [lista, setLista] = useState(null);
    const [erro, setErro] = useState('');

    useEffect(() => {
        getRankingAvaliadores()
            .then(setLista)
            .catch(() => { setLista([]); setErro('Não foi possível carregar o ranking.'); });
    }, []);

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Ranking dos avaliadores</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Quem mais concluiu avaliações. Entra na lista quem já concluiu ao menos uma; empate divide
                a posição. Avaliadores de teste (demo) ficam de fora.
            </p>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            {lista === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : lista.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhuma avaliação concluída ainda.
                </div>
            ) : (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-x-auto max-w-3xl">
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead className="bg-surface-variant/40">
                            <tr>
                                <th scope="col" className="px-3 py-2 text-xs font-semibold text-on-surface-variant text-center">#</th>
                                <th scope="col" className="px-3 py-2 text-xs font-semibold text-on-surface-variant text-left">Avaliador</th>
                                <th scope="col" className="px-3 py-2 text-xs font-semibold text-on-surface-variant text-left">Área do conhecimento</th>
                                <th scope="col" className="px-3 py-2 text-xs font-semibold text-on-surface-variant text-left">Cidade/UF</th>
                                <th scope="col" className="px-3 py-2 text-xs font-semibold text-on-surface-variant text-center">Em avaliação</th>
                                <th scope="col" className="px-3 py-2 text-xs font-semibold text-on-surface-variant text-center">Avaliações</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-outline-variant/30">
                            {lista.map((a) => (
                                <tr key={a.avaliador_id} className="hover:bg-surface-variant/30 transition-colors">
                                    <td className="px-3 py-2 text-center"><Posicao n={a.posicao} /></td>
                                    <td className="px-3 py-2 text-on-surface truncate">{a.nome}</td>
                                    <td className="px-3 py-2 text-on-surface-variant truncate">{a.area ?? 'Sem área'}</td>
                                    <td className="px-3 py-2 text-on-surface-variant truncate" title={a.estado_nome ?? ''}>{localidade(a)}</td>
                                    <td className="px-3 py-2 text-center font-bold text-primary-container">{a.em_avaliacao}</td>
                                    <td className="px-3 py-2 text-center text-xl font-bold text-secondary">{a.concluidas}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppShell>
    );
}
