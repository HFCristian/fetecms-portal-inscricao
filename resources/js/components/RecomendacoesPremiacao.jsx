import { useCallback, useEffect, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { PilulaTipo } from './CartaoCredencial.jsx';
import StatusRecomendacao from './StatusRecomendacao.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getRecomendacoes, decidirRecomendacao } from '../lib/recomendacoes.js';

const FILTROS = [
    { valor: 'pendente', rotulo: 'Pendentes' },
    { valor: 'aprovada', rotulo: 'Aprovadas' },
    { valor: 'rejeitada', rotulo: 'Rejeitadas' },
    { valor: null, rotulo: 'Todas' },
];

const data = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '');

/**
 * As **recomendações do comitê especial** (Sprint 193): o avaliador da
 * comissão aponta um finalista para uma credencial ou prêmio, e a organização
 * aprova ou rejeita aqui.
 *
 * A decisão **só marca**. Anexar a credencial ao projeto continua sendo feito
 * no cartão da credencial, que é onde valem as vagas — duas recomendações
 * aprovadas para uma vaga só não estouram nada, e a organização escolhe quem
 * leva. A decisão pode ser trocada depois.
 */
export default function RecomendacoesPremiacao() {
    const [filtro, setFiltro] = useState('pendente');
    const [dados, setDados] = useState(null);
    const [ocupado, setOcupado] = useState(null);
    const [erro, setErro] = useState('');

    const carregar = useCallback(() => getRecomendacoes(filtro)
        .then((d) => { setDados(d); setErro(''); })
        .catch(() => setErro('Não foi possível carregar as recomendações do comitê.')), [filtro]);

    useEffect(() => { carregar(); }, [carregar]);

    async function decidir(r, status) {
        setOcupado(r.id); setErro('');
        try {
            await decidirRecomendacao(r.id, status);
            await carregar();
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível registrar a decisão.');
        } finally {
            setOcupado(null);
        }
    }

    const contagem = dados?.contagem ?? { pendente: 0, aprovada: 0, rejeitada: 0 };
    const total = contagem.pendente + contagem.aprovada + contagem.rejeitada;
    const recomendacoes = dados?.recomendacoes ?? [];

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl" aria-label="Recomendações do comitê">
            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">
                Recomendações do comitê
                {contagem.pendente > 0 && (
                    <span className="ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-900">
                        {contagem.pendente} pendente(s)
                    </span>
                )}
            </h2>
            <p className="text-sm text-on-surface-variant mb-3">
                Os avaliadores da comissão especial recomendam finalistas para cada credencial ou prêmio.
                Aprovar <strong>não premia</strong>: anexe a credencial ao projeto no cartão dela.
            </p>

            <div className="flex flex-wrap gap-2 mb-3" role="group" aria-label="Filtrar recomendações">
                {FILTROS.map((f) => (
                    <button
                        key={f.rotulo}
                        type="button"
                        aria-pressed={filtro === f.valor}
                        onClick={() => setFiltro(f.valor)}
                        className={`px-3 py-1 rounded-full text-xs font-semibold border transition-colors ${
                            filtro === f.valor
                                ? 'bg-primary-container text-on-primary border-primary-container'
                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                        }`}
                    >
                        {f.rotulo} ({f.valor ? contagem[f.valor] : total})
                    </button>
                ))}
            </div>

            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

            {dados === null ? (
                <p className="text-sm text-on-surface-variant">Carregando…</p>
            ) : recomendacoes.length === 0 ? (
                <p className="text-sm text-on-surface-variant">
                    {total === 0 ? 'O comitê ainda não fez nenhuma recomendação.' : 'Nenhuma recomendação nesta situação.'}
                </p>
            ) : (
                <ul className="divide-y divide-outline-variant/30">
                    {recomendacoes.map((r) => (
                        <li key={r.id} className="py-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <PilulaTipo tipo={r.credencial_tipo} label={r.credencial_tipo_label} />
                                        <span className="text-sm font-semibold text-on-surface">{r.credencial}</span>
                                        <StatusRecomendacao status={r.status} label={r.status_label} />
                                        {r.ja_premiado && (
                                            <span className="text-[11px] font-semibold text-secondary">já recebeu esta credencial</span>
                                        )}
                                    </div>
                                    <p className="text-sm text-on-surface mt-1">{r.projeto}</p>
                                    <p className="text-xs text-on-surface-variant">
                                        {[r.categoria, r.area].filter(Boolean).join(' · ')}
                                        {' · '}recomendado por {r.avaliador} em {data(r.criada_em)}
                                    </p>
                                    {r.justificativa && (
                                        <p className="text-sm text-on-surface-variant whitespace-pre-line mt-1 border-l-2 border-outline-variant pl-2">
                                            {r.justificativa}
                                        </p>
                                    )}
                                    {r.decidida_por && (
                                        <p className="text-xs text-on-surface-variant mt-1">
                                            {r.status_label} por {r.decidida_por} em {data(r.decidida_em)}
                                        </p>
                                    )}
                                </div>
                                <div className="flex gap-2 shrink-0">
                                    {r.status !== 'aprovada' && (
                                        <Button type="button" variant="success" disabled={ocupado !== null} loading={ocupado === r.id} onClick={() => decidir(r, 'aprovada')}>
                                            Aprovar
                                        </Button>
                                    )}
                                    {r.status !== 'rejeitada' && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="text-error border-error/40 hover:bg-error-container/40"
                                            disabled={ocupado !== null}
                                            onClick={() => decidir(r, 'rejeitada')}
                                        >
                                            Rejeitar
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
