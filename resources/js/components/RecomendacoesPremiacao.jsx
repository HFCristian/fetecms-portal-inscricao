import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Input, Select } from './ui.jsx';
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
const SEM_RECORTE = { q: '', area_id: '', categoria: '' };

/**
 * `atualizacao` muda quando a página mexe nos cartões (premiar pela
 * aprovada, retirar): a lista recarrega para o "já recebeu" acompanhar.
 * `onDecidido` avisa a página, que recarrega os cartões com a aprovada nova.
 */
export default function RecomendacoesPremiacao({ atualizacao = 0, onDecidido = null }) {
    const [filtro, setFiltro] = useState('pendente');
    // O recorte pelo projeto (Sprint 194): a busca só vale ao enviar, os
    // seletores valem na hora.
    const [recorte, setRecorte] = useState(SEM_RECORTE);
    const [busca, setBusca] = useState('');
    const [dados, setDados] = useState(null);
    const [ocupado, setOcupado] = useState(null);
    const [erro, setErro] = useState('');

    const carregar = useCallback(() => getRecomendacoes(filtro, recorte)
        .then((d) => { setDados(d); setErro(''); })
        .catch(() => setErro('Não foi possível carregar as recomendações do comitê.')), [filtro, recorte, atualizacao]);

    useEffect(() => { carregar(); }, [carregar]);

    const recortando = recorte.q !== '' || recorte.area_id !== '' || recorte.categoria !== '';
    const opcoes = dados?.opcoes ?? { areas: [], categorias: [] };

    async function decidir(r, status) {
        setOcupado(r.id); setErro('');
        try {
            await decidirRecomendacao(r.id, status);
            await carregar();
            onDecidido?.();
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

            <form
                className="grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] gap-2 mb-3"
                onSubmit={(e) => { e.preventDefault(); setRecorte((r) => ({ ...r, q: busca.trim() })); }}
                aria-label="Filtrar por projeto"
            >
                <div className="flex gap-2">
                    <Input
                        aria-label="Buscar pelo título do projeto"
                        placeholder="Buscar pelo título do projeto"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                    />
                    <Button type="submit" variant="outline">Buscar</Button>
                </div>
                <Select
                    aria-label="Área do conhecimento"
                    value={recorte.area_id}
                    onChange={(e) => setRecorte((r) => ({ ...r, area_id: e.target.value }))}
                >
                    <option value="">Todas as áreas</option>
                    {opcoes.areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                </Select>
                <Select
                    aria-label="Categoria"
                    value={recorte.categoria}
                    onChange={(e) => setRecorte((r) => ({ ...r, categoria: e.target.value }))}
                >
                    <option value="">Todas as categorias</option>
                    {opcoes.categorias.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </Select>
            </form>
            {recortando && (
                <button
                    type="button"
                    className="text-xs text-primary hover:underline mb-3"
                    onClick={() => { setRecorte(SEM_RECORTE); setBusca(''); }}
                >
                    Limpar filtros
                </button>
            )}

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
                    {total === 0 && !recortando
                        ? 'O comitê ainda não fez nenhuma recomendação.'
                        : 'Nenhuma recomendação com esses filtros.'}
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
