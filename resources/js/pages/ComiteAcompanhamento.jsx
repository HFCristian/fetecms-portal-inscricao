import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, useConfirm } from '../components/ui.jsx';
import PainelComiteMapa from '../components/PainelComiteMapa.jsx';
import { getAcompanhamentoComite, retirarDesignacoesComite } from '../lib/comite.js';
import { extractErrors } from '../lib/auth.jsx';
import {
    corDoEstande, legendaComite, filtrarAvaliadores, estande, hora, localDoProjeto, SEM_PROJETO,
    colunasDoAvaliador,
} from '../lib/comiteAcompanhamento.js';

/** Tamanho de uma célula da grade, em unidades do viewBox (o mesmo da planta). */
const CELULA = 10;
const MARGEM = 6;

/** Fora da tela cheia, como o Mapa do Evento: 30s; projetado, 5s. */
export const POLLING_MS = 30000;
export const POLLING_TELA_CHEIA_MS = 5000;

const campoClass =
    'bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const SITUACOES_AVALIADOR = {
    avaliando: { label: 'Avaliando agora', classe: 'bg-primary-container text-on-primary' },
    designado: { label: 'Com designação', classe: 'bg-[#e9ddff] text-[#2a0058]' },
    livre: { label: 'Livre', classe: 'bg-surface-variant text-on-surface-variant' },
};

const SITUACOES_AVALIACAO = {
    em_avaliacao: 'em avaliação',
    designada: 'designada',
    iniciada: 'iniciada',
    concluida: 'enviada',
    expirada: 'prazo encerrado',
};

const ORIGENS = { estande: 'No estande', online: 'Online' };

/** O horário que importa em cada situação. */
function quando(p) {
    if (p.situacao === 'concluida') return p.concluida_em ? `enviada às ${hora(p.concluida_em)}` : 'enviada';
    if ((p.situacao === 'em_avaliacao' || p.situacao === 'iniciada') && p.iniciada_em) return `desde ${hora(p.iniciada_em)}`;

    return '';
}

/** O título do projeto e, embaixo, o local, a vaga e o horário que importa. */
function ConteudoProjeto({ p, selo = null }) {
    return (
        <>
            <span className="flex items-start justify-between gap-2">
                <span className="text-on-surface">{p.titulo}</span>
                {selo && <span className={`text-[11px] font-semibold rounded-full px-2 py-0.5 shrink-0 ${selo.classe}`}>{selo.label}</span>}
            </span>
            <span className="block text-xs text-on-surface-variant">
                {[localDoProjeto(p) || 'Sem estande', p.vaga_label, quando(p)].filter(Boolean).join(' · ')}
            </span>
        </>
    );
}

/** As três categorias do avaliador, na ordem do "Ver detalhes". */
const CATEGORIAS = [
    { chave: 'avaliados', titulo: 'Projetos avaliados', curto: 'Avaliados', cor: '#0ca30c', vazio: 'Nenhum projeto avaliado ainda.' },
    { chave: 'emAvaliacao', titulo: 'Projetos em avaliação', curto: 'Em avaliação', cor: '#43157A', vazio: 'Nenhum projeto em avaliação agora.' },
    { chave: 'designados', titulo: 'Projetos designados', curto: 'Designados', cor: '#6250d6', vazio: 'Nenhum projeto designado aguardando.' },
];

/**
 * O cartão compacto: de cada categoria, só a contagem e o primeiro projeto
 * (o avaliado mais recente, o primeiro em avaliação, o primeiro designado). O
 * resto está no "Ver detalhes".
 */
function CartaoAvaliador({ a, onRetirado }) {
    const [aberto, setAberto] = useState(false);
    const situacao = SITUACOES_AVALIADOR[a.situacao];
    const colunas = colunasDoAvaliador(a);

    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex flex-col">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-semibold text-on-surface">{a.nome}</p>
                    <p className="text-xs text-on-surface-variant">
                        {[a.area, a.apenas_presencial ? 'Só presencial' : null].filter(Boolean).join(' · ') || 'Sem área'}
                    </p>
                </div>
                <span className={`text-xs font-semibold rounded-full px-2.5 py-1 ${situacao.classe}`}>{situacao.label}</span>
            </div>

            <dl className="mt-3 space-y-2">
                {CATEGORIAS.map((c) => {
                    const lista = colunas[c.chave];
                    const primeiro = lista[0];

                    return (
                        <div key={c.chave} className="min-w-0">
                            <dt className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-on-surface-variant">
                                <span className="w-2 h-2 rounded-sm shrink-0" style={{ background: c.cor }} />
                                {c.curto}
                                <span className="tabular-nums font-normal">({lista.length})</span>
                            </dt>
                            <dd className="mt-0.5 text-sm min-w-0">
                                {primeiro ? (
                                    <>
                                        <span className="block truncate text-on-surface" title={primeiro.titulo}>{primeiro.titulo}</span>
                                        <span className="block truncate text-xs text-on-surface-variant">
                                            {[ORIGENS[primeiro.origem], localDoProjeto(primeiro) || 'Sem estande', quando(primeiro)].filter(Boolean).join(' · ')}
                                            {lista.length > 1 && ` · +${lista.length - 1}`}
                                        </span>
                                    </>
                                ) : (
                                    <span className="text-xs text-on-surface-variant">—</span>
                                )}
                            </dd>
                        </div>
                    );
                })}
            </dl>

            <div className="mt-auto pt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                <button
                    type="button"
                    onClick={() => setAberto(true)}
                    aria-label={`Ver detalhes de ${a.nome}`}
                    className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
                >
                    <span className="material-symbols-outlined text-[18px]">view_column</span>
                    Ver detalhes
                </button>
                <span className="text-xs text-on-surface-variant">
                    {[
                        a.ultima_concluida_em ? `última enviada às ${hora(a.ultima_concluida_em)}` : null,
                        a.expiradas > 0 ? `${a.expiradas} com o prazo encerrado` : null,
                    ].filter(Boolean).join(' · ')}
                </span>
            </div>

            {aberto && <DialogoDetalhesAvaliador a={a} onFechar={() => setAberto(false)} onRetirado={onRetirado} />}
        </li>
    );
}

/** O selo da origem de cada projeto no diálogo de detalhes. */
const SELOS_ORIGEM = {
    estande: { label: 'No estande', classe: 'bg-surface-variant text-on-surface-variant' },
    online: { label: 'Online', classe: 'bg-[#e9ddff] text-[#2a0058]' },
};

/**
 * Uma coluna do diálogo: título com a contagem e os projetos. Com `marcados`,
 * cada projeto ganha uma caixa de marcação (a coluna dos designados, de onde se
 * remove a designação).
 */
function ColunaDetalhes({ titulo, cor, projetos, vazio, rodape = null, marcados = null, onAlternar, cabecalho = null }) {
    return (
        <section className="bg-surface rounded-xl p-3 flex flex-col min-h-0" aria-label={titulo}>
            <h4 className="flex items-center gap-2 font-display font-semibold text-on-surface mb-2">
                <span className="w-2.5 h-2.5 rounded-sm" style={{ background: cor }} />
                {titulo}
                <span className="ml-auto text-sm font-normal text-on-surface-variant tabular-nums">{projetos.length}</span>
            </h4>
            {cabecalho}
            {projetos.length === 0 ? (
                <p className="text-sm text-on-surface-variant">{vazio}</p>
            ) : (
                <ul className="space-y-2 overflow-y-auto fetec-scroll">
                    {projetos.map((p) => (
                        <li key={p.avaliacao_id} className="text-sm">
                            {marcados ? (
                                <label className="flex items-start gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        className="mt-1 w-4 h-4 rounded text-primary-container shrink-0"
                                        checked={marcados.includes(p.avaliacao_id)}
                                        onChange={() => onAlternar(p.avaliacao_id)}
                                        aria-label={`Marcar ${p.titulo}`}
                                    />
                                    <span className="min-w-0 flex-1"><ConteudoProjeto p={p} selo={SELOS_ORIGEM[p.origem]} /></span>
                                </label>
                            ) : (
                                <ConteudoProjeto p={p} selo={SELOS_ORIGEM[p.origem]} />
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {rodape && <p className="text-xs text-on-surface-variant mt-2">{rodape}</p>}
        </section>
    );
}

/**
 * "Ver detalhes" do avaliador: os projetos dele em três colunas — avaliados,
 * em avaliação e designados —, no estande e pelas designações do comitê.
 *
 * Na coluna dos designados, a coordenação marca projetos e **remove a
 * designação**: só o que o avaliador ainda não abriu, e ninguém é designado no
 * lugar. Quem decide o que pode sair é o servidor.
 */
function DialogoDetalhesAvaliador({ a, onFechar, onRetirado }) {
    const colunas = colunasDoAvaliador(a);
    const [marcados, setMarcados] = useState([]);
    const [retirando, setRetirando] = useState(false);
    const [aviso, setAviso] = useState(null);
    const [confirm, confirmDialog] = useConfirm();

    // Depois de recarregar, sobra marcado só o que ainda está na coluna.
    const validos = marcados.filter((id) => colunas.designados.some((p) => p.avaliacao_id === id));
    const todos = colunas.designados.length > 0 && validos.length === colunas.designados.length;

    useEffect(() => {
        const aoTeclar = (e) => { if (e.key === 'Escape') onFechar(); };
        document.addEventListener('keydown', aoTeclar);
        return () => document.removeEventListener('keydown', aoTeclar);
    }, [onFechar]);

    const alternar = (id) => setMarcados(validos.includes(id) ? validos.filter((x) => x !== id) : [...validos, id]);

    async function remover() {
        const escolhidos = colunas.designados.filter((p) => validos.includes(p.avaliacao_id));
        const ok = await confirm({
            title: 'Remover designação',
            message: `Tirar ${escolhidos.length} projeto(s) de ${a.nome}?\n\n`
                + escolhidos.map((p) => `• ${p.titulo}`).join('\n')
                + '\n\nNinguém é designado no lugar. A designação online sai também de Avaliação online → Designações.',
            confirmLabel: 'Remover',
            danger: true,
        });
        if (!ok) return;

        setRetirando(true);
        setAviso(null);
        try {
            const r = await retirarDesignacoesComite(a.id, validos);
            setMarcados([]);
            setAviso({ tipo: 'info', texto: r.meta?.message ?? 'Designação removida.' });
            await onRetirado?.();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAviso({ tipo: 'error', texto: Object.values(fields ?? {})[0] || message || 'Não foi possível remover a designação.' });
        } finally {
            setRetirando(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-label={`Detalhes de ${a.nome}`}
            onClick={(e) => { if (e.target === e.currentTarget) onFechar(); }}
        >
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-5xl p-6 max-h-[90vh] flex flex-col">
                <div className="flex items-start justify-between gap-3 mb-4">
                    <div className="min-w-0">
                        <h3 className="font-display text-xl font-semibold text-on-surface">{a.nome}</h3>
                        <p className="text-sm text-on-surface-variant">
                            {[a.area, SITUACOES_AVALIADOR[a.situacao]?.label, a.email].filter(Boolean).join(' · ')}
                        </p>
                    </div>
                    <button type="button" onClick={onFechar} className="text-on-surface-variant hover:text-primary" aria-label="Fechar os detalhes">
                        <span className="material-symbols-outlined">close</span>
                    </button>
                </div>

                {aviso && <div className="mb-3"><Alert type={aviso.tipo}>{aviso.texto}</Alert></div>}

                <div className="grid gap-3 md:grid-cols-3 min-h-0 overflow-y-auto md:overflow-hidden">
                    {CATEGORIAS.map((c) => (
                        <ColunaDetalhes
                            key={c.chave}
                            titulo={c.titulo}
                            cor={c.cor}
                            projetos={colunas[c.chave]}
                            vazio={c.vazio}
                            rodape={c.chave === 'emAvaliacao' && a.expiradas > 0
                                ? `${a.expiradas} avaliação(ões) no estande com o prazo encerrado sem envio.`
                                : null}
                            {...(c.chave === 'designados' && colunas.designados.length > 0 ? {
                                marcados: validos,
                                onAlternar: alternar,
                                cabecalho: (
                                    <div className="flex flex-wrap items-center justify-between gap-2 mb-2">
                                        <button
                                            type="button"
                                            className="text-xs font-semibold text-primary hover:underline"
                                            onClick={() => setMarcados(todos ? [] : colunas.designados.map((p) => p.avaliacao_id))}
                                        >
                                            {todos ? 'Desmarcar todos' : 'Marcar todos'}
                                        </button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="!px-3 !py-1.5 text-xs"
                                            loading={retirando}
                                            disabled={validos.length === 0}
                                            onClick={remover}
                                        >
                                            <span className="material-symbols-outlined text-[16px]" aria-hidden="true">person_remove</span>
                                            Remover designação{validos.length > 0 ? ` (${validos.length})` : ''}
                                        </Button>
                                    </div>
                                ),
                            } : {})}
                        />
                    ))}
                </div>
            </div>
            {confirmDialog}
        </div>
    );
}

function AbaAvaliadores({ avaliadores, onRetirado }) {
    const [busca, setBusca] = useState('');
    const [situacao, setSituacao] = useState('');
    const lista = useMemo(() => filtrarAvaliadores(avaliadores, busca, situacao), [avaliadores, busca, situacao]);
    const conta = (s) => avaliadores.filter((a) => a.situacao === s).length;

    if (avaliadores.length === 0) {
        return (
            <p className="text-sm text-on-surface-variant">
                Ninguém está marcado como comissão especial. Marque os avaliadores em Avaliação online → Avaliadores.
            </p>
        );
    }

    return (
        <div>
            <p className="text-sm text-on-surface-variant mb-3">
                {avaliadores.length} avaliador(es) na comissão · <strong className="text-on-surface">{conta('avaliando')}</strong> avaliando agora ·{' '}
                {conta('designado')} com designação · {conta('livre')} livre(s)
            </p>
            <div className="flex flex-wrap gap-2 mb-4">
                <input
                    className={`${campoClass} flex-1 min-w-[14rem]`}
                    placeholder="Buscar por avaliador, projeto, código ou estande"
                    aria-label="Buscar por avaliador, projeto, código ou estande"
                    value={busca}
                    onChange={(e) => setBusca(e.target.value)}
                />
                <select className={campoClass} aria-label="Situação do avaliador" value={situacao} onChange={(e) => setSituacao(e.target.value)}>
                    <option value="">Todas as situações</option>
                    {Object.entries(SITUACOES_AVALIADOR).map(([v, s]) => <option key={v} value={v}>{s.label}</option>)}
                </select>
            </div>
            {lista.length === 0 ? (
                <p className="text-sm text-on-surface-variant">Nenhum avaliador encontrado.</p>
            ) : (
                <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{lista.map((a) => <CartaoAvaliador key={a.id} a={a} onRetirado={onRetirado} />)}</ul>
            )}
        </div>
    );
}

/** O que está no estande clicado: o projeto e cada avaliação do comitê nele. */
function DetalheEstande({ linha, onFechar }) {
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4" role="region" aria-label={`Estande ${estande(linha.numero)}`}>
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-xs text-on-surface-variant">
                        {[`Estande ${estande(linha.numero)}`, linha.codigo, linha.area, linha.categoria].filter(Boolean).join(' · ')}
                    </p>
                    <p className="font-semibold text-on-surface">{linha.titulo}</p>
                    {!linha.pronto && <p className="text-xs text-on-surface-variant mt-0.5">Ainda não credenciado e checado.</p>}
                </div>
                <button type="button" onClick={onFechar} className="text-on-surface-variant hover:text-primary" aria-label="Fechar o estande">
                    <span className="material-symbols-outlined">close</span>
                </button>
            </div>
            {linha.avaliacoes.length === 0 ? (
                <p className="text-sm text-on-surface-variant mt-2">Nenhuma avaliação do comitê neste projeto.</p>
            ) : (
                <ul className="mt-2 space-y-1 text-sm">
                    {linha.avaliacoes.map((av) => (
                        <li key={av.avaliacao_id} className="flex flex-wrap justify-between gap-x-3">
                            <span className="text-on-surface">{av.avaliador}</span>
                            <span className="text-xs text-on-surface-variant">
                                {[
                                    ORIGENS[av.origem],
                                    SITUACOES_AVALIACAO[av.situacao],
                                    av.situacao === 'concluida' ? hora(av.concluida_em) : hora(av.iniciada_em),
                                    av.vaga_label,
                                ].filter(Boolean).join(' · ')}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function AbaMapa({ dados, onTurno, telaCheia, onTelaCheia, palco, atualizadoEm, semConexao }) {
    const [painelAberto, setPainelAberto] = useState(true);
    const [selecionado, setSelecionado] = useState(null);

    const layout = dados.mapa.layout ?? [];
    const porNumero = dados.mapa.estandes ?? {};
    const fora = dados.mapa.fora_da_planta ?? [];
    const linhas = useMemo(() => [...Object.values(porNumero), ...fora], [porNumero, fora]);
    const vagas = dados.vagas_comite;
    const linhaSelecionada = selecionado === null ? null : linhas.find((l) => l.numero === selecionado) ?? null;

    const caixa = useMemo(() => (layout.length === 0
        ? { largura: 10, altura: 10 }
        : { largura: Math.max(...layout.map((e) => e.x)) + 1, altura: Math.max(...layout.map((e) => e.y)) + 1 }), [layout]);

    const descricao = (numero, linha) => (linha
        ? `Estande ${estande(numero)} · ${linha.titulo} · ${corDoEstande(linha, vagas).label}`
        : `Estande ${estande(numero)} · sem projeto no turno`);

    return (
        <div
            ref={palco}
            className={telaCheia ? 'bg-surface p-4 h-full overflow-auto flex flex-col' : ''}
        >
            <div className="flex flex-wrap items-center gap-2 mb-3">
                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Turno do mapa">
                    {dados.turnos.map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            role="radio"
                            aria-checked={dados.turno === t.value}
                            onClick={() => { onTurno(t.value); setSelecionado(null); }}
                            className={`px-3 py-1 rounded-full text-sm font-semibold border ${
                                dados.turno === t.value
                                    ? 'bg-primary-container text-on-primary border-primary-container'
                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                            }`}
                        >
                            {t.label}
                            {dados.turno_em_andamento === t.value && <span className="ml-1 font-normal">· agora</span>}
                        </button>
                    ))}
                </div>
                <span className="text-xs text-on-surface-variant" role="status">
                    {semConexao
                        ? 'Sem conexão — mostrando o último retrato'
                        : atualizadoEm && `Ao vivo · atualizado às ${atualizadoEm.toLocaleTimeString('pt-BR')}`}
                </span>
                <div className="ml-auto flex gap-2">
                    <button
                        type="button"
                        onClick={() => setPainelAberto(!painelAberto)}
                        aria-pressed={painelAberto}
                        className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-outline-variant text-sm font-semibold text-on-surface hover:bg-surface-variant"
                    >
                        <span className="material-symbols-outlined text-[18px]">dashboard</span>
                        Dashboard
                    </button>
                    <button
                        type="button"
                        onClick={onTelaCheia}
                        className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-outline-variant text-sm font-semibold text-on-surface hover:bg-surface-variant"
                    >
                        <span className="material-symbols-outlined text-[18px]">{telaCheia ? 'fullscreen_exit' : 'fullscreen'}</span>
                        {telaCheia ? 'Sair da tela cheia' : 'Tela cheia'}
                    </button>
                </div>
            </div>

            <div className={`grid gap-4 ${telaCheia ? 'flex-1 min-h-0' : ''} ${painelAberto ? 'xl:grid-cols-[1fr_22rem]' : ''}`}>
                <div className="min-w-0 space-y-3">
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-3 overflow-x-auto">
                        <svg
                            role="img"
                            aria-label="Planta do evento com as avaliações do comitê"
                            viewBox={`0 0 ${caixa.largura * CELULA + MARGEM * 2} ${caixa.altura * CELULA + MARGEM * 2}`}
                            className={telaCheia ? 'w-full h-auto max-h-[75vh]' : 'w-full h-auto min-w-[36rem]'}
                        >
                            {layout.map((e) => {
                                const linha = porNumero[e.numero] ?? null;
                                const cores = corDoEstande(linha, vagas);
                                const x0 = MARGEM + e.x * CELULA;
                                const y0 = MARGEM + e.y * CELULA;

                                return (
                                    <g
                                        key={e.numero}
                                        role="button"
                                        tabIndex={0}
                                        aria-label={descricao(e.numero, linha)}
                                        aria-pressed={selecionado === e.numero}
                                        style={{ cursor: linha ? 'pointer' : 'default' }}
                                        onClick={() => linha && setSelecionado(e.numero)}
                                        onKeyDown={(ev) => { if (ev.key === 'Enter' && linha) setSelecionado(e.numero); }}
                                    >
                                        <title>{descricao(e.numero, linha)}</title>
                                        <rect
                                            x={x0}
                                            y={y0}
                                            width={CELULA - 1}
                                            height={CELULA - 1}
                                            rx="1.5"
                                            fill={cores.fill}
                                            stroke={selecionado === e.numero ? '#ba1a1a' : cores.stroke}
                                            strokeWidth={selecionado === e.numero ? 1.2 : 0.6}
                                            strokeDasharray={cores.tracejado ? '1.5 1' : undefined}
                                        />
                                        <text
                                            x={x0 + (CELULA - 1) / 2}
                                            y={y0 + (CELULA - 1) / 2 + 1.6}
                                            textAnchor="middle"
                                            fontSize="3.6"
                                            fontWeight="600"
                                            fill={cores.texto}
                                        >
                                            {estande(e.numero)}
                                        </text>
                                        {linha && linha.concluidas > 0 && (
                                            <>
                                                <circle cx={x0 + CELULA - 2.2} cy={y0 + 1.6} r="1.6" fill="#ffffff" stroke="#0a7a0a" strokeWidth="0.3" />
                                                <text x={x0 + CELULA - 2.2} y={y0 + 2.3} textAnchor="middle" fontSize="2" fontWeight="700" fill="#0a7a0a">
                                                    {linha.concluidas}
                                                </text>
                                            </>
                                        )}
                                    </g>
                                );
                            })}
                        </svg>
                    </div>

                    {fora.length > 0 && (
                        <div>
                            <p className="text-xs text-on-surface-variant mb-1">Estandes fora da planta</p>
                            <div className="flex flex-wrap gap-2">
                                {fora.map((l) => {
                                    const cores = corDoEstande(l, vagas);
                                    return (
                                        <button
                                            key={l.numero}
                                            type="button"
                                            onClick={() => setSelecionado(l.numero)}
                                            aria-label={descricao(l.numero, l)}
                                            className="rounded-lg border px-2.5 py-1 text-xs font-semibold"
                                            style={{ background: cores.fill, borderColor: cores.stroke, color: cores.texto }}
                                        >
                                            {estande(l.numero)} · {l.titulo}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-on-surface-variant" aria-label="Legenda do mapa do comitê">
                        {legendaComite(linhas, vagas).map((l) => (
                            <li key={l.chave} className="inline-flex items-center gap-1">
                                <span className="w-3 h-3 rounded-sm border" style={{ background: l.cor, borderColor: l.borda }} />
                                {l.label}
                                <span className="tabular-nums">({l.total})</span>
                            </li>
                        ))}
                        <li className="inline-flex items-center gap-1">
                            <span className="w-3 h-3 rounded-sm border" style={{ background: SEM_PROJETO.fill, borderColor: SEM_PROJETO.stroke }} />
                            {SEM_PROJETO.label}
                        </li>
                        <li className="inline-flex items-center gap-1">
                            <span className="w-3 h-3 rounded-sm border border-dashed border-[#b9b2c4]" />
                            Ainda não credenciado e checado
                        </li>
                        <li className="inline-flex items-center gap-1">
                            <span className="w-3 h-3 rounded-full border border-[#0a7a0a] bg-white" />
                            Avaliações enviadas pelo comitê
                        </li>
                    </ul>

                    {linhaSelecionada && <DetalheEstande linha={linhaSelecionada} onFechar={() => setSelecionado(null)} />}
                </div>

                {painelAberto && (
                    <PainelComiteMapa
                        linhas={linhas}
                        avaliadores={dados.avaliadores}
                        vagas={vagas}
                        turnoLabel={dados.turno_label}
                        onFechar={() => setPainelAberto(false)}
                    />
                )}
            </div>
        </div>
    );
}

/**
 * Comitê especial → **Acompanhamento das avaliações**.
 *
 * Duas vistas do mesmo retrato, que se recarrega sozinho: **Avaliadores** —
 * cada membro da comissão com o que está avaliando agora, o que tem designado
 * e quanto já enviou — e **Mapa** — a planta do evento pintada só pelas
 * avaliações do comitê, com o dashboard ao lado. "Avaliação do comitê" é a
 * avaliação no estande feita por avaliador da comissão especial.
 */
export default function ComiteAcompanhamento() {
    const [aba, setAba] = useState('avaliadores');
    const [turno, setTurno] = useState(null); // nulo: o servidor escolhe o turno em foco
    const [dados, setDados] = useState(null);
    const [alerta, setAlerta] = useState('');
    const [semConexao, setSemConexao] = useState(false);
    const [atualizadoEm, setAtualizadoEm] = useState(null);
    const [telaCheia, setTelaCheia] = useState(false);
    const palco = useRef(null);
    const carregou = useRef(false);

    const carregar = useCallback(() => getAcompanhamentoComite(turno ?? undefined)
        .then((d) => {
            carregou.current = true;
            setDados(d);
            setAtualizadoEm(new Date());
            setSemConexao(false);
            setAlerta('');
        })
        // Falha no meio do evento: a tela fica com o último retrato e avisa.
        .catch(() => (carregou.current ? setSemConexao(true) : setAlerta('Não foi possível carregar o acompanhamento.'))), [turno]);

    useEffect(() => { carregar(); }, [carregar]);

    useEffect(() => {
        const id = setInterval(carregar, telaCheia ? POLLING_TELA_CHEIA_MS : POLLING_MS);
        return () => clearInterval(id);
    }, [carregar, telaCheia]);

    useEffect(() => {
        const aoTrocar = () => setTelaCheia(Boolean(document.fullscreenElement));
        document.addEventListener('fullscreenchange', aoTrocar);
        return () => document.removeEventListener('fullscreenchange', aoTrocar);
    }, []);

    async function alternarTelaCheia() {
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else await palco.current?.requestFullscreen?.();
        } catch {
            // Navegador sem a API (ou recusou): o mapa continua na página.
        }
    }

    return (
        <AppShell>
            <Link to="/admin/comite" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Comitê especial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Acompanhamento das avaliações</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                O que cada avaliador da <strong>comissão especial</strong> está avaliando no estande e o que
                recebeu em <strong>Designações do comitê</strong>, e o mapa do evento só com o trabalho do comitê.
                A tela se atualiza sozinha.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}

            <div className="flex gap-1 border-b border-outline-variant/50 mb-4" role="tablist" aria-label="Vista do acompanhamento">
                {[['avaliadores', 'Avaliadores', 'groups'], ['mapa', 'Mapa', 'map']].map(([valor, label, icone]) => (
                    <button
                        key={valor}
                        type="button"
                        role="tab"
                        aria-selected={aba === valor}
                        onClick={() => setAba(valor)}
                        className={`inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold border-b-2 -mb-px ${
                            aba === valor ? 'border-primary-container text-primary' : 'border-transparent text-on-surface-variant hover:text-primary'
                        }`}
                    >
                        <span className="material-symbols-outlined text-[18px]">{icone}</span>
                        {label}
                    </button>
                ))}
            </div>

            {!dados ? (
                !alerta && <p className="text-sm text-on-surface-variant">Carregando…</p>
            ) : aba === 'avaliadores' ? (
                <AbaAvaliadores avaliadores={dados.avaliadores} onRetirado={carregar} />
            ) : (
                <AbaMapa
                    dados={dados}
                    onTurno={setTurno}
                    telaCheia={telaCheia}
                    onTelaCheia={alternarTelaCheia}
                    palco={palco}
                    atualizadoEm={atualizadoEm}
                    semConexao={semConexao}
                />
            )}
        </AppShell>
    );
}
