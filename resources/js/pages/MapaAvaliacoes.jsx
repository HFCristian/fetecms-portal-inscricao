import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Select } from '../components/ui.jsx';
import DialogoAvaliacoesPresenciais from '../components/DialogoAvaliacoesPresenciais.jsx';
import { extractErrors, useAuth } from '../lib/auth.jsx';
import { getAvaliacoesMapa, filtrarAvaliacoes, ordenarAvaliacoes, baixarAvaliacoesMapa } from '../lib/mapaEvento.js';
import { getAvaliacoesRankingPresencial } from '../lib/presencial.js';

/** Tamanho de uma célula da grade, em unidades do viewBox (o mesmo da planta). */
const CELULA = 10;
const MARGEM = 6;
/** O dia corrente se atualiza sozinho; um dia passado não muda mais. */
const POLLING_MS = 30000;

const VAZIO = { cor: '#f3f1f6', borda: '#e6e0ee', texto: '#b9b2c4' };
const COR_ANDAMENTO = '#2a0058';
const COR_SAIU = '#2e2b33';

const rotulo = (n) => String(n).padStart(3, '0');
const hora = (d) => d?.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) ?? '—';

/** "1 de 2 enviada(s)" — e, embaixo, o que ainda está sendo preenchido. */
function Vaga({ vaga }) {
    return (
        <span className="tabular-nums">
            {vaga.enviadas} de {vaga.vagas}
            {vaga.em_andamento > 0 && (
                <span className="block text-xs text-on-surface-variant">{vaga.em_andamento} em andamento</span>
            )}
        </span>
    );
}

/** O que o estande tem além da categoria: em andamento, não pronto e saída. */
function Marcas({ linha }) {
    const marcas = [];

    if (linha.em_andamento > 0) marcas.push({ chave: 'andamento', texto: `${linha.em_andamento} em andamento`, icone: 'pending' });
    if (!linha.pronto) marcas.push({ chave: 'pronto', texto: linha.credenciado ? 'Aguardando checagem' : 'Sem credenciamento', icone: 'hourglass_empty' });
    if (linha.saida) marcas.push({ chave: 'saida', texto: `Saiu às ${linha.saida}`, icone: 'logout' });

    if (marcas.length === 0) return null;

    return (
        <span className="flex flex-wrap gap-1 mt-1">
            {marcas.map((m) => (
                <span key={m.chave} className="inline-flex items-center gap-0.5 rounded-full bg-surface-variant px-2 py-0.5 text-[11px] text-on-surface-variant">
                    <span className="material-symbols-outlined text-[13px]">{m.icone}</span>
                    {m.texto}
                </span>
            ))}
        </span>
    );
}

/**
 * Cabeçalho que ordena a lista (Sprint 249): clicar no que já está ativo
 * inverte a direção; no outro, começa crescente.
 */
function CabecalhoOrdenavel({ campo, rotulo, ordem, direcao, onOrdenar }) {
    const ativo = ordem === campo;
    const icone = !ativo ? 'unfold_more' : direcao === 'asc' ? 'arrow_upward' : 'arrow_downward';

    return (
        <th className="py-2 pr-3" aria-sort={!ativo ? 'none' : direcao === 'asc' ? 'ascending' : 'descending'}>
            <button
                type="button"
                onClick={() => onOrdenar(campo)}
                aria-label={`Ordenar por ${rotulo}`}
                className={`inline-flex items-center gap-0.5 uppercase hover:text-primary ${ativo ? 'text-primary font-semibold' : ''}`}
            >
                {rotulo}
                <span className="material-symbols-outlined text-[16px]" aria-hidden="true">{icone}</span>
            </button>
        </th>
    );
}

function descricao(linha) {
    return [
        `Estande ${linha.estande}`,
        linha.codigo,
        linha.titulo,
        linha.situacao_label,
        `comum ${linha.comum.enviadas} de ${linha.comum.vagas}`,
        `comitê ${linha.comite.enviadas} de ${linha.comite.vagas}`,
        linha.em_andamento > 0 ? `${linha.em_andamento} em andamento` : null,
        linha.pronto ? null : (linha.credenciado ? 'aguardando checagem' : 'sem credenciamento'),
        linha.saida ? `saiu às ${linha.saida}` : null,
    ].filter(Boolean).join(' · ');
}

/**
 * Mapa do Evento → **avaliações por projeto** (Sprint 248).
 *
 * O dashboard da tela cheia diz quantas avaliações cada projeto recebeu; aqui
 * se vê **quais faltam** — comuns e da comissão especial —, que é o que decide
 * para onde mandar o próximo avaliador. Cada projeto cai numa categoria (no
 * padrão 2 + 1: faltam 3, faltam 2 com o comitê, faltam 2 comuns, só o comitê,
 * só uma comum, fechada), contada pelas avaliações **enviadas**; o que está em
 * andamento aparece à parte.
 *
 * Os botões das categorias filtram o mapa e a lista juntos — o estande fora do
 * filtro fica apagado, não some, para a planta continuar sendo lida pelo lugar
 * — e as duas exportações em PDF levam o mesmo recorte.
 *
 * Sprint 249: a lista ordena por estande ou por situação (o PDF sai na mesma
 * ordem), e cada linha abre o **Ver avaliações** do Ranking presencial, onde
 * a avaliação lançada no estande errado se transfere para o projeto certo.
 */
export default function MapaAvaliacoes() {
    const { user } = useAuth();
    const podeVerAvaliacoes = !user?.conta_temporaria;
    const [params, setParams] = useSearchParams();
    const [turno, setTurno] = useState(params.get('turno') === 'B' ? 'B' : 'A');
    const [dia, setDia] = useState(params.get('dia') ?? '');
    const [dados, setDados] = useState(null);
    const [meta, setMeta] = useState(null);
    const [alerta, setAlerta] = useState('');
    const [atualizadoEm, setAtualizadoEm] = useState(null);
    const [categorias, setCategorias] = useState([]);
    const [emAndamento, setEmAndamento] = useState(false);
    const [destacado, setDestacado] = useState(null);
    const [baixando, setBaixando] = useState(null);
    const [ordem, setOrdem] = useState('estande');
    const [direcao, setDirecao] = useState('asc');
    const [info, setInfo] = useState('');
    // O diálogo de Ver avaliações: { dados, carregando, erro, aviso }.
    const [avaliacoes, setAvaliacoes] = useState(null);

    const carregar = useCallback(() => getAvaliacoesMapa({ turno, dia: dia || undefined })
        .then((r) => {
            setDados(r.data);
            setMeta(r.meta ?? null);
            setAtualizadoEm(new Date());
            setAlerta('');
        })
        .catch((e) => setAlerta(extractErrors(e).message || 'Não foi possível carregar as avaliações.')), [turno, dia]);

    useEffect(() => { carregar(); }, [carregar]);

    // O endereço acompanha dia e turno: recarregar a página ou mandar o link
    // abre no mesmo recorte.
    useEffect(() => {
        setParams({ turno, ...(dia ? { dia } : {}) }, { replace: true });
    }, [turno, dia, setParams]);

    const dias = useMemo(() => meta?.dias ?? [], [meta]);
    const ehHoje = !dia || Boolean(dias.find((d) => d.value === dia)?.hoje);

    // Sem dia no endereço, o seletor mostra hoje (quando hoje é dia do evento).
    useEffect(() => {
        const hoje = dias.find((d) => d.hoje)?.value;
        if (!dia && hoje) setDia(hoje);
    }, [dias, dia]);

    useEffect(() => {
        if (!ehHoje) return undefined;
        const id = setInterval(carregar, POLLING_MS);
        return () => clearInterval(id);
    }, [ehHoje, carregar]);

    const linhas = useMemo(() => dados?.linhas ?? [], [dados]);
    const visiveis = useMemo(() => filtrarAvaliacoes(linhas, { categorias, emAndamento }), [linhas, categorias, emAndamento]);
    const ordenadas = useMemo(
        () => ordenarAvaliacoes(visiveis, dados?.categorias ?? [], { ordem, direcao }),
        [visiveis, dados, ordem, direcao],
    );
    const visivel = useMemo(() => new Set(visiveis.map((l) => l.projeto_id)), [visiveis]);
    const porNumero = useMemo(() => Object.fromEntries(linhas.map((l) => [l.numero, l])), [linhas]);
    const cores = useMemo(() => Object.fromEntries((dados?.categorias ?? []).map((c) => [c.chave, c])), [dados]);
    const filtroAtivo = categorias.length > 0 || emAndamento;

    const planta = dados?.planta ?? { estandes: [], marcacoes: [] };
    const caixa = useMemo(() => {
        const xs = [...planta.estandes.map((e) => e.x), ...planta.marcacoes.map((m) => m.x)];
        const ys = [...planta.estandes.map((e) => e.y), ...planta.marcacoes.map((m) => m.y)];
        const maxX = Math.max(0, ...planta.estandes.map((e) => e.x + 1), ...planta.marcacoes.map((m) => m.x + m.largura));
        const maxY = Math.max(0, ...planta.estandes.map((e) => e.y + 1), ...planta.marcacoes.map((m) => m.y + m.altura));
        const minX = xs.length ? Math.min(...xs) : 0;
        const minY = ys.length ? Math.min(...ys) : 0;

        return { minX, minY, largura: maxX - minX, altura: maxY - minY };
    }, [planta]);
    const px = (x) => MARGEM + (x - caixa.minX) * CELULA;
    const py = (y) => MARGEM + (y - caixa.minY) * CELULA;
    const foraDaPlanta = linhas.filter((l) => !l.na_planta);

    function alternarCategoria(chave) {
        setCategorias((atual) => (atual.includes(chave) ? atual.filter((c) => c !== chave) : [...atual, chave]));
    }

    function limparFiltros() {
        setCategorias([]);
        setEmAndamento(false);
    }

    function destacar(linha) {
        if (!linha || !visivel.has(linha.projeto_id)) return;
        setDestacado(linha.projeto_id);
        document.getElementById(`avaliacao-${linha.projeto_id}`)?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    }

    function ordenarPor(campo) {
        if (campo === ordem) {
            setDirecao((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setOrdem(campo);
            setDirecao('asc');
        }
    }

    function verAvaliacoes(linha) {
        setInfo('');
        setAvaliacoes({ dados: null, carregando: true, erro: '', aviso: '' });
        getAvaliacoesRankingPresencial(linha.projeto_id)
            .then((d) => setAvaliacoes({ dados: d, carregando: false, erro: '', aviso: '' }))
            .catch((e) => setAvaliacoes({
                dados: null, carregando: false, aviso: '',
                erro: extractErrors(e).message || 'Não foi possível carregar as avaliações deste projeto.',
            }));
    }

    // Excluir e transferir devolvem o projeto sem a avaliação; a tela recarrega
    // para a situação dos dois projetos mudar no mapa e na lista.
    function aoMudarAvaliacao(resposta, padrao) {
        const texto = [resposta.meta?.message ?? padrao, resposta.meta?.aviso].filter(Boolean).join(' ');
        setAvaliacoes({ dados: resposta.data, carregando: false, erro: '', aviso: texto });
        setInfo(texto);
        carregar();
    }

    async function baixar(tipo) {
        setBaixando(tipo);
        try {
            await baixarAvaliacoesMapa(tipo, { dia, turno, categorias, emAndamento, ordem, direcao });
        } catch {
            setAlerta(`Não foi possível gerar o PDF ${tipo === 'mapa' ? 'do mapa' : 'da lista'}.`);
        } finally {
            setBaixando(null);
        }
    }

    const opcoesDeDia = dias.some((d) => d.hoje) ? dias : [{ value: '', label: 'Agora', hoje: true }, ...dias];

    return (
        <AppShell>
            <Link to="/admin/mapa/planta" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Mapa do Evento
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliações por projeto</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Quais avaliações presenciais faltam em cada projeto do turno — as comuns e a da comissão
                especial. A situação conta só as avaliações <strong className="text-on-surface">enviadas</strong>;
                as que estão em andamento aparecem à parte.
            </p>

            <div className="max-w-6xl space-y-3 mb-4">
                <Alert>{alerta}</Alert>
                <Alert type="info">{info}</Alert>
            </div>

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 max-w-6xl mb-4 space-y-4">
                <div className="flex flex-wrap items-end gap-3">
                    <label className="text-sm text-on-surface-variant">
                        <span className="block mb-1">Dia</span>
                        <Select aria-label="Dia" value={dia} onChange={(e) => setDia(e.target.value)}>
                            {opcoesDeDia.map((d) => <option key={d.value || 'agora'} value={d.value}>{d.label}</option>)}
                        </Select>
                    </label>
                    <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Turno">
                        {(meta?.turnos ?? [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }]).map((t) => (
                            <button
                                key={t.value}
                                type="button"
                                role="radio"
                                aria-checked={turno === t.value}
                                onClick={() => { setTurno(t.value); setDestacado(null); }}
                                className={`px-3 py-1.5 rounded-full text-sm font-semibold border ${
                                    turno === t.value
                                        ? 'bg-primary-container text-on-primary border-primary-container'
                                        : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                    <p className="text-xs text-on-surface-variant ml-auto" aria-live="polite">
                        {dados && (
                            <>
                                Situação em {dados.corte_label} · {dados.vagas.comuns} comum(ns) + {dados.vagas.comite} do comitê por projeto
                                <br />
                                {ehHoje ? `Atualizado às ${hora(atualizadoEm)} · atualiza a cada 30 segundos` : 'Dia encerrado: não se atualiza.'}
                            </>
                        )}
                    </p>
                </div>

                {dados && (
                    <div>
                        <div className="flex items-baseline justify-between gap-2 mb-2">
                            <p className="text-sm font-semibold text-on-surface">Filtrar por situação</p>
                            {filtroAtivo && (
                                <button type="button" onClick={limparFiltros} className="text-sm text-primary hover:underline">
                                    Limpar filtros
                                </button>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2" aria-label="Filtros de situação">
                            {dados.categorias.map((c) => {
                                const ativo = categorias.includes(c.chave);
                                return (
                                    <button
                                        key={c.chave}
                                        type="button"
                                        aria-pressed={ativo}
                                        onClick={() => alternarCategoria(c.chave)}
                                        className={`inline-flex items-center gap-2 rounded-lg border-2 px-3 py-1.5 text-sm ${
                                            ativo ? 'border-primary-container bg-primary-fixed text-on-surface font-semibold' : 'border-outline-variant text-on-surface hover:bg-surface-variant'
                                        }`}
                                    >
                                        <span className="w-3.5 h-3.5 rounded-sm border shrink-0" style={{ background: c.cor, borderColor: c.borda }} />
                                        {c.label}
                                        <span className="tabular-nums text-on-surface-variant">{c.total}</span>
                                    </button>
                                );
                            })}
                            <button
                                type="button"
                                aria-pressed={emAndamento}
                                onClick={() => setEmAndamento((v) => !v)}
                                className={`inline-flex items-center gap-2 rounded-lg border-2 px-3 py-1.5 text-sm ${
                                    emAndamento ? 'border-primary-container bg-primary-fixed text-on-surface font-semibold' : 'border-dashed border-outline-variant text-on-surface hover:bg-surface-variant'
                                }`}
                            >
                                <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: COR_ANDAMENTO }} />
                                Com avaliação em andamento
                                <span className="tabular-nums text-on-surface-variant">{dados.marcas.em_andamento}</span>
                            </button>
                        </div>
                        <p className="text-xs text-on-surface-variant mt-2">
                            Marque uma ou mais situações; nenhuma marcada mostra todas. "Em andamento" soma com as situações marcadas.
                        </p>
                    </div>
                )}
            </section>

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 max-w-6xl mb-4" aria-label="Mapa das avaliações">
                <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <h2 className="font-display text-lg font-semibold text-primary">Mapa</h2>
                    <Button variant="outline" loading={baixando === 'mapa'} disabled={!dados} onClick={() => baixar('mapa')}>
                        <span className="material-symbols-outlined text-[20px]">picture_as_pdf</span>
                        Exportar mapa (PDF)
                    </Button>
                </div>

                {dados === null ? (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : (
                    <>
                        {dados.total === 0 && (
                            <p className="text-sm text-on-surface-variant mb-3">
                                Nenhum projeto com estande neste turno. Os estandes saem de Mapa do Evento → Estandes dos Projetos.
                            </p>
                        )}
                        <div className="overflow-x-auto">
                            <svg
                                role="img"
                                aria-label="Planta do evento com as avaliações que faltam em cada estande"
                                viewBox={`0 0 ${caixa.largura * CELULA + MARGEM * 2} ${caixa.altura * CELULA + MARGEM * 2}`}
                                className="w-full h-auto min-w-[36rem]"
                            >
                                {planta.marcacoes.map((m, i) => (
                                    <g key={`marca-${i}`}>
                                        <rect x={px(m.x)} y={py(m.y)} width={m.largura * CELULA} height={m.altura * CELULA} rx="2" fill="#006e1f" opacity="0.12" />
                                        <text x={px(m.x + m.largura / 2)} y={py(m.y + m.altura / 2) + 1.4} textAnchor="middle" fontSize="4" fill="#006e1f">
                                            {m.rotulo}
                                        </text>
                                    </g>
                                ))}

                                {planta.estandes.map((e) => {
                                    const l = porNumero[e.numero] ?? null;
                                    const c = l ? cores[l.situacao] ?? VAZIO : VAZIO;
                                    const apagado = l !== null && !visivel.has(l.projeto_id);
                                    const emFoco = l !== null && l.projeto_id === destacado;
                                    const x0 = px(e.x);
                                    const y0 = py(e.y);
                                    const lado = CELULA - 1;
                                    const titulo = l ? descricao(l) : `Estande ${rotulo(e.numero)} · sem projeto neste turno`;

                                    return (
                                        <g
                                            key={e.numero}
                                            role={l && !apagado ? 'button' : undefined}
                                            tabIndex={l && !apagado ? 0 : undefined}
                                            aria-label={titulo}
                                            opacity={apagado ? 0.18 : 1}
                                            style={{ cursor: l && !apagado ? 'pointer' : 'default' }}
                                            onClick={() => destacar(l)}
                                            onKeyDown={(ev) => { if (ev.key === 'Enter') destacar(l); }}
                                        >
                                            <title>{titulo}</title>
                                            <rect
                                                x={x0}
                                                y={y0}
                                                width={lado}
                                                height={lado}
                                                rx="1.5"
                                                fill={c.cor}
                                                stroke={emFoco ? '#2a0058' : c.borda}
                                                strokeWidth={emFoco ? 1.4 : l && !l.pronto ? 0.8 : 0.6}
                                                strokeDasharray={l && !l.pronto ? '1.6 1' : undefined}
                                            />
                                            {l?.saida && (
                                                <line x1={x0 + 1} y1={y0 + lado - 1} x2={x0 + lado - 1} y2={y0 + 1} stroke={COR_SAIU} strokeWidth="0.6" />
                                            )}
                                            <text
                                                x={x0 + lado / 2}
                                                y={y0 + lado / 2 + 1.3}
                                                textAnchor="middle"
                                                fontSize="3.4"
                                                fontWeight="600"
                                                fill={c.texto}
                                            >
                                                {rotulo(e.numero)}
                                            </text>
                                            {l?.em_andamento > 0 && (
                                                <circle cx={x0 + lado - 1.3} cy={y0 + 1.3} r="1.3" fill={COR_ANDAMENTO} stroke="#ffffff" strokeWidth="0.4" />
                                            )}
                                        </g>
                                    );
                                })}
                            </svg>
                        </div>

                        {foraDaPlanta.length > 0 && (
                            <div className="mt-3">
                                <p className="text-xs text-on-surface-variant mb-1">Estandes fora da planta</p>
                                <div className="flex flex-wrap gap-2">
                                    {foraDaPlanta.map((l) => {
                                        const c = cores[l.situacao] ?? VAZIO;
                                        return (
                                            <button
                                                key={l.projeto_id}
                                                type="button"
                                                onClick={() => destacar(l)}
                                                aria-label={descricao(l)}
                                                className={`rounded-lg border px-2.5 py-1 text-xs font-semibold ${visivel.has(l.projeto_id) ? '' : 'opacity-20'}`}
                                                style={{ background: c.cor, borderColor: c.borda, color: c.texto }}
                                            >
                                                {l.estande} · {l.titulo}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        <ul className="flex flex-wrap gap-x-4 gap-y-1 mt-3 text-xs text-on-surface" aria-label="Marcas do mapa">
                            <li className="inline-flex items-center gap-1">
                                <span className="w-2.5 h-2.5 rounded-full" style={{ background: COR_ANDAMENTO }} />
                                Com avaliação em andamento <span className="text-on-surface-variant tabular-nums">({dados.marcas.em_andamento})</span>
                            </li>
                            <li className="inline-flex items-center gap-1">
                                <span className="w-3 h-3 rounded-sm border border-dashed border-on-surface-variant" />
                                Sem credenciamento ou checagem <span className="text-on-surface-variant tabular-nums">({dados.marcas.nao_pronto})</span>
                            </li>
                            <li className="inline-flex items-center gap-1">
                                <svg viewBox="0 0 12 12" className="w-3 h-3" aria-hidden="true">
                                    <rect x="0.5" y="0.5" width="11" height="11" rx="1.5" fill="none" stroke="#8f889b" />
                                    <line x1="2" y1="10" x2="10" y2="2" stroke={COR_SAIU} strokeWidth="1.4" />
                                </svg>
                                Saiu antes do horário <span className="text-on-surface-variant tabular-nums">({dados.marcas.saiu})</span>
                            </li>
                            {filtroAtivo && <li className="text-on-surface-variant">Estandes apagados estão fora do filtro.</li>}
                        </ul>
                    </>
                )}
            </section>

            {dados && (
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 max-w-6xl" aria-label="Lista de projetos">
                    <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <h2 className="font-display text-lg font-semibold text-primary">
                            Projetos <span className="text-sm font-normal text-on-surface-variant">({visiveis.length} de {dados.total})</span>
                        </h2>
                        <Button variant="outline" loading={baixando === 'lista'} onClick={() => baixar('lista')}>
                            <span className="material-symbols-outlined text-[20px]">picture_as_pdf</span>
                            Exportar lista (PDF)
                        </Button>
                    </div>

                    {visiveis.length === 0 ? (
                        <p className="text-sm text-on-surface-variant">Nenhum projeto neste recorte.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs uppercase text-on-surface-variant border-b border-outline-variant">
                                        <CabecalhoOrdenavel campo="estande" rotulo="Estande" ordem={ordem} direcao={direcao} onOrdenar={ordenarPor} />
                                        <th className="py-2 pr-3">Código</th>
                                        <th className="py-2 pr-3">Projeto</th>
                                        <th className="py-2 pr-3">Turno</th>
                                        <th className="py-2 pr-3">Comum (enviadas)</th>
                                        <th className="py-2 pr-3">Comitê (enviadas)</th>
                                        <CabecalhoOrdenavel campo="situacao" rotulo="Situação" ordem={ordem} direcao={direcao} onOrdenar={ordenarPor} />
                                        {podeVerAvaliacoes && <th className="py-2"><span className="sr-only">Avaliações</span></th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {ordenadas.map((l) => {
                                        const c = cores[l.situacao] ?? VAZIO;
                                        return (
                                            <tr
                                                key={l.projeto_id}
                                                id={`avaliacao-${l.projeto_id}`}
                                                onClick={() => setDestacado(l.projeto_id)}
                                                className={`border-b border-outline-variant/60 align-top cursor-pointer ${l.projeto_id === destacado ? 'bg-primary-fixed' : 'hover:bg-surface-container-low'}`}
                                            >
                                                <td className="py-2 pr-3 font-semibold tabular-nums">{l.estande}</td>
                                                <td className="py-2 pr-3 whitespace-nowrap">{l.codigo ?? '—'}</td>
                                                <td className="py-2 pr-3">
                                                    <span className="text-on-surface">{l.titulo}</span>
                                                    <span className="block text-xs text-on-surface-variant">
                                                        {[l.categoria, l.area].filter(Boolean).join(' · ')}
                                                    </span>
                                                    <Marcas linha={l} />
                                                </td>
                                                <td className="py-2 pr-3">{dados.turno}</td>
                                                <td className="py-2 pr-3"><Vaga vaga={l.comum} /></td>
                                                <td className="py-2 pr-3"><Vaga vaga={l.comite} /></td>
                                                <td className="py-2 pr-3">
                                                    <span className="inline-flex items-center gap-1.5">
                                                        <span className="w-3 h-3 rounded-sm border shrink-0" style={{ background: c.cor, borderColor: c.borda }} />
                                                        {l.situacao_label}
                                                    </span>
                                                </td>
                                                {podeVerAvaliacoes && (
                                                    <td className="py-2 text-right">
                                                        <button
                                                            type="button"
                                                            onClick={(ev) => { ev.stopPropagation(); setDestacado(l.projeto_id); verAvaliacoes(l); }}
                                                            aria-label={`Ver avaliações do estande ${l.estande}`}
                                                            className="inline-flex items-center gap-1 whitespace-nowrap text-sm font-semibold text-primary hover:underline"
                                                        >
                                                            <span className="material-symbols-outlined text-[18px]">fact_check</span>
                                                            Ver avaliações
                                                        </button>
                                                    </td>
                                                )}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            )}

            {avaliacoes && (
                <DialogoAvaliacoesPresenciais
                    dados={avaliacoes.dados}
                    carregando={avaliacoes.carregando}
                    erro={avaliacoes.erro}
                    aviso={avaliacoes.aviso}
                    onExcluida={(r) => aoMudarAvaliacao(r, 'Avaliação excluída.')}
                    onTransferida={(r) => aoMudarAvaliacao(r, 'Avaliação transferida.')}
                    onFechar={() => setAvaliacoes(null)}
                />
            )}
        </AppShell>
    );
}
