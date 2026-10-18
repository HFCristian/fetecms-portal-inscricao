import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getRankingPresencial } from '../lib/presencial.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

// Medalha para o pódio; do 4º em diante, só o número.
const MEDALHA = { 1: '🥇', 2: '🥈', 3: '🥉' };

// Três casas, como a rubrica presencial: os pesos foram escolhidos para desempatar.
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(3).replace('.', ',');

const peso = (valor) => String(valor ?? '').replace('.', ',');

const rotulo = (n) => String(n).padStart(3, '0');

function Posicao({ n }) {
    return (
        <span className="w-10 shrink-0 text-center" aria-label={`${n}º lugar`}>
            {MEDALHA[n]
                ? <span className="text-2xl leading-none">{MEDALHA[n]}</span>
                : <span className="text-lg font-bold text-on-surface-variant">{n}º</span>}
        </span>
    );
}

function Linha({ p }) {
    return (
        <li className="px-4 py-3 flex items-center gap-3 flex-wrap">
            <Posicao n={p.posicao} />

            <div className="flex-1 min-w-0">
                <p className="text-sm text-on-surface truncate">
                    {p.codigo && <span className="font-semibold text-primary-container mr-1">{p.codigo}</span>}
                    {p.titulo}
                </p>
                <p className="text-xs text-on-surface-variant truncate">
                    {p.area ?? 'Sem área'}{p.categoria ? ` · ${p.categoria}` : ''}
                    {p.estande ? ` · Estande ${rotulo(p.estande)}` : ''}{p.turno_label ? ` · ${p.turno_label}` : ''}
                </p>
                {/* As notas que formaram a média, sem quem avaliou: a da comissão em destaque. */}
                <p className="text-xs text-on-surface-variant mt-0.5">
                    {p.notas.map((n, i) => (
                        <span key={i} className={`mr-2 ${n.vaga === 'comite' ? 'font-semibold text-secondary' : ''}`}
                            title={n.vaga === 'comite' ? `Comissão especial · peso ${peso(n.peso)}` : 'Vaga comum · peso 1'}>
                            {nota(n.nota)}{n.vaga === 'comite' ? ` (comissão ×${peso(n.peso)})` : ''}
                        </span>
                    ))}
                </p>
            </div>

            <div className="text-right shrink-0 w-28">
                <div className="text-xl font-bold text-secondary">
                    {nota(p.media)}
                    <span className="text-xs font-normal text-on-surface-variant">/10</span>
                </div>
                <div className="text-[10px] text-on-surface-variant leading-tight">
                    {p.comuns} comum · {p.comite} comissão
                    {p.parcial && <span className="block text-error font-semibold">parcial</span>}
                </div>
            </div>
        </li>
    );
}

/**
 * Avaliação presencial → **Ranking presencial** (Sprint 222).
 *
 * A média de cada finalista nas avaliações do estande, ponderada: a avaliação
 * da comissão especial pesa mais (o peso é da edição, em Distribuição
 * presencial). Quem calcula é o servidor; a tela só mostra e filtra. Parcial
 * é quem ainda não teve todas as vagas preenchidas.
 */
export default function PresencialRanking() {
    const [dados, setDados] = useState(null);
    const [areaId, setAreaId] = useState('');
    const [categoria, setCategoria] = useState('');
    const [modoTeste, setModoTeste] = useState(false);
    const [erro, setErro] = useState('');

    const carregar = useCallback(() => {
        setErro('');
        return getRankingPresencial({ area_id: areaId, categoria }, modoTeste)
            .then(setDados)
            .catch((e) => {
                setDados((d) => d ?? { projetos: [], opcoes: { areas: [], categorias: [] } });
                setErro(extractErrors(e).message || 'Não foi possível carregar o ranking presencial.');
            });
    }, [areaId, categoria, modoTeste]);

    useEffect(() => { carregar(); }, [carregar]);

    const projetos = dados?.projetos ?? [];
    const parciais = projetos.filter((p) => p.parcial).length;
    const vagas = dados?.vagas;

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Ranking presencial</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Finalistas com ao menos uma avaliação presencial enviada, pela média ponderada das notas do
                estande
                {vagas ? (
                    <>
                        {' '}— cada projeto tem {vagas.comuns} vaga(s) comum(ns) e {vagas.comite} da comissão especial, e a
                        nota da comissão pesa <strong>{peso(vagas.peso_comite)}</strong>
                    </>
                ) : null}
                . Empate na média divide a posição.
            </p>

            {dados?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Conta demo: lê a lista final de demonstração."
                    />
                </div>
            )}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="ranking-presencial-area">
                            Área do conhecimento
                        </label>
                        <select id="ranking-presencial-area" className={campoClass} value={areaId} onChange={(e) => setAreaId(e.target.value)}>
                            <option value="">Todas as áreas</option>
                            {(dados?.opcoes?.areas ?? []).map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="ranking-presencial-categoria">
                            Categoria
                        </label>
                        <select id="ranking-presencial-categoria" className={campoClass} value={categoria} onChange={(e) => setCategoria(e.target.value)}>
                            <option value="">Todas as categorias</option>
                            {(dados?.opcoes?.categorias ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                        </select>
                    </div>
                </div>
            </div>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : !dados.lista && !erro ? (
                <div className="max-w-3xl"><Alert type="info">A lista final ainda não foi gerada: sem finalistas, não há ranking.</Alert></div>
            ) : projetos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhum projeto com avaliação presencial enviada{areaId || categoria ? ' neste recorte' : ''}.
                </div>
            ) : (
                <div className="max-w-3xl">
                    {parciais > 0 && (
                        <div className="mb-4">
                            <Alert type="info">
                                {parciais} {parciais === 1 ? 'projeto ainda não teve' : 'projetos ainda não tiveram'} todas as
                                vagas preenchidas — a média é <strong>parcial</strong> e a posição pode mudar.
                            </Alert>
                        </div>
                    )}
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                        <div className="px-4 py-3 bg-surface-variant/40 flex items-center justify-between gap-2 flex-wrap">
                            <h2 className="font-display font-semibold text-on-surface">Classificação</h2>
                            <span className="text-xs text-on-surface-variant">
                                {projetos.length} {projetos.length === 1 ? 'projeto' : 'projetos'}
                            </span>
                        </div>
                        <ul className="divide-y divide-outline-variant/30">
                            {projetos.map((p) => <Linha key={p.projeto_id} p={p} />)}
                        </ul>
                    </div>
                </div>
            )}
        </AppShell>
    );
}
