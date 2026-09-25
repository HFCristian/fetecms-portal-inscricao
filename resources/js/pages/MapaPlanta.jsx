import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, Select, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getPlanta, salvarPlanta, restaurarPlanta,
    getSituacaoPlanta, getListaSituacao, baixarListaSituacao,
} from '../lib/mapaEvento.js';

/**
 * Mapa do Evento → **a planta do ginásio**.
 *
 * O desenho da prancha da montadora, em SVG, com a ocupação por cima: clicar num
 * estande abre quem apresenta nele **de manhã e à tarde**, que é a pergunta que
 * a organização faz o dia inteiro durante o evento ("quem está no 042?").
 *
 * A planta é **editável e versionada**. No modo de edição o estande selecionado
 * se move clicando numa célula vazia da grade — e não arrastando: no dia do
 * evento isso é usado no celular, e arrastar num SVG de 230 peças numa tela
 * pequena erra mais do que acerta. Cada gravação cria uma **versão nova**, então
 * nenhuma edição destrói o desenho que valeu antes.
 *
 * Durante a feira ela **muda de cor**: o projeto passa pelo balcão, o estande é
 * conferido, as avaliações chegam — e o ginásio inteiro conta isso de longe. O
 * seletor de **dia e turno** volta o mapa para como ele estava, e o mesmo
 * recorte sai em lista filtrável e exportável.
 */

/** Tamanho de uma célula da grade, em unidades do viewBox. */
const CELULA = 10;
const MARGEM = 6;

/** De quanto em quanto tempo o mapa ao vivo se recarrega sozinho. */
const POLLING_MS = 30000;

/**
 * Cor do estande por **ocupação** — quem apresenta nele, sem olhar o evento.
 * É a leitura que serve antes do dia da feira, quando nada aconteceu ainda.
 */
function corPorOcupacao({ selecionado, ocupacao }) {
    if (selecionado) return { fill: '#43157A', stroke: '#2a0058', texto: '#ffffff' };
    if (ocupacao?.A && ocupacao?.B) return { fill: '#d8c9ef', stroke: '#43157A', texto: '#2a0058' };
    if (ocupacao?.A || ocupacao?.B) return { fill: '#efe7fa', stroke: '#7a5aa8', texto: '#2a0058' };

    return { fill: '#ffffff', stroke: '#cfc6db', texto: '#6b6577' };
}

/**
 * Cor do estande por **situação** — por onde o projeto daquele turno já passou.
 *
 * O último estágio escurece a cada avaliação recebida: de longe dá para ver
 * quais estandes ainda esperam avaliador sem abrir nada. As cores vêm do
 * servidor (`SituacaoEstande::cor()`), para a legenda da tela, o desenho e o
 * PDF nunca discordarem.
 */
function corPorSituacao({ selecionado, linha }) {
    if (selecionado) return { fill: '#43157A', stroke: '#2a0058', texto: '#ffffff' };
    if (!linha) return { fill: '#ffffff', stroke: '#cfc6db', texto: '#6b6577' };

    const claro = linha.situacao === 'avaliado' || linha.situacao === 'checado';

    // Só o estágio de avaliação tem grau: 1 de 3 é mais claro que 3 de 3.
    const opacidade = linha.situacao === 'avaliado' && linha.avaliacoes_maximo
        ? 0.45 + 0.55 * Math.min(1, linha.avaliacoes / linha.avaliacoes_maximo)
        : 1;

    return {
        fill: linha.cor,
        stroke: '#2a0058',
        texto: claro && opacidade > 0.7 ? '#ffffff' : '#2a0058',
        opacidade,
    };
}

/** Uma rua: o nome escrito ao longo do corredor, deitado ou em pé. */
function Rua({ rua }) {
    if (!rua.nome) return null;

    const horizontal = rua.orientacao === 'h';
    const x = MARGEM + (horizontal ? ((rua.de + rua.ate) / 2) : rua.posicao) * CELULA;
    const y = MARGEM + (horizontal ? rua.posicao : ((rua.de + rua.ate) / 2)) * CELULA;

    return (
        <text
            x={x}
            y={y}
            textAnchor="middle"
            dominantBaseline="middle"
            fontSize="3.4"
            fontWeight="600"
            letterSpacing="0.6"
            fill="#7a5aa8"
            // A rua vertical é lida de baixo para cima, como numa planta baixa.
            transform={horizontal ? undefined : `rotate(-90 ${x} ${y})`}
            style={{ pointerEvents: 'none', textTransform: 'uppercase' }}
        >
            {rua.nome}
        </text>
    );
}

export default function MapaPlanta() {
    const [dados, setDados] = useState(null);
    const [filtros, setFiltros] = useState(null);      // opções vindas do servidor
    const [layout, setLayout] = useState(null);
    const [ruas, setRuas] = useState([]);
    const [selecionado, setSelecionado] = useState(null);   // número do estande
    const [editando, setEditando] = useState(false);
    const [sujo, setSujo] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [novoNumero, setNovoNumero] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    // --- Situação ao vivo ---------------------------------------------
    const [modo, setModo] = useState('situacao');       // 'situacao' | 'ocupacao'
    const [dia, setDia] = useState('');
    const [turno, setTurno] = useState('A');
    const [situacao, setSituacao] = useState(null);
    const [pronto, setPronto] = useState(false);
    const [telaCheia, setTelaCheia] = useState(false);
    const palco = useRef(null);

    // --- Lista filtrada -----------------------------------------------
    const [criterio, setCriterio] = useState('credenciamento');
    const [valor, setValor] = useState('sim');
    const [lista, setLista] = useState(null);
    const [carregandoLista, setCarregandoLista] = useState(false);

    useEffect(() => {
        getPlanta()
            .then((r) => {
                setDados(r.data);
                setLayout(r.data.layout);
                setRuas(r.data.ruas ?? []);
                setFiltros(r.meta?.filtros ?? null);
                setDia(r.meta?.filtros?.dias?.find((d) => d.hoje)?.value ?? '');
                setPronto(true);
            })
            .catch(() => setAlerta('Não foi possível carregar a planta.'));
    }, []);

    const carregarSituacao = useCallback(() => {
        // Espera a planta chegar: o dia padrão sai dela, e disparar antes faria
        // uma consulta a mais em toda abertura da tela.
        if (modo !== 'situacao' || !pronto) return;
        getSituacaoPlanta({ dia: dia || undefined, turno })
            .then(setSituacao)
            .catch(() => setSituacao(null));
    }, [modo, dia, turno, pronto]);

    useEffect(() => { carregarSituacao(); }, [carregarSituacao]);

    // O mapa do dia corrente anda sozinho; um dia passado não muda mais, então
    // recarregá-lo seria bater no servidor à toa.
    const ehHoje = !dia || filtros?.dias?.find((d) => d.value === dia)?.hoje;

    useEffect(() => {
        if (modo !== 'situacao' || !ehHoje || editando || !pronto) return undefined;
        const id = setInterval(carregarSituacao, POLLING_MS);
        return () => clearInterval(id);
    }, [modo, ehHoje, editando, pronto, carregarSituacao]);

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
            // Navegador que recusa (ou não tem a API): a tela segue como está.
            setAlerta('Este navegador não permitiu abrir o mapa em tela cheia.');
        }
    }

    const estandes = layout?.estandes ?? [];
    const ocupacao = dados?.ocupacao ?? {};
    const porNumero = situacao?.estandes ?? {};

    /** A moldura do desenho acompanha o que existe nele. */
    const caixa = useMemo(() => {
        if (estandes.length === 0) return { largura: 10, altura: 10 };

        return {
            largura: Math.max(...estandes.map((e) => e.x)) + 1,
            altura: Math.max(...estandes.map((e) => e.y)) + 1,
        };
    }, [estandes]);

    /** As células livres da grade — os destinos possíveis no modo de edição. */
    const livres = useMemo(() => {
        if (!editando) return [];

        const ocupadas = new Set(estandes.map((e) => `${e.x},${e.y}`));
        const celulas = [];

        for (let y = 0; y <= caixa.altura; y++) {
            for (let x = 0; x <= caixa.largura; x++) {
                if (!ocupadas.has(`${x},${y}`)) celulas.push({ x, y });
            }
        }

        return celulas;
    }, [editando, estandes, caixa]);

    function falhar(e) {
        const { message, fields } = extractErrors(e);
        setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
        setSucesso('');
    }

    function moverPara(x, y) {
        if (selecionado === null) return;

        setLayout((l) => ({
            ...l,
            estandes: l.estandes.map((e) => (e.numero === selecionado ? { ...e, x, y } : e)),
        }));
        setSujo(true);
    }

    function renumerar(numero) {
        const alvo = Number(numero);
        if (!alvo || selecionado === null) return;

        if (estandes.some((e) => e.numero === alvo && e.numero !== selecionado)) {
            setAlerta(`O estande ${alvo} já existe na planta.`);
            return;
        }

        setLayout((l) => ({
            ...l,
            estandes: l.estandes.map((e) => (e.numero === selecionado ? { ...e, numero: alvo } : e)),
        }));
        setSelecionado(alvo);
        setSujo(true);
        setAlerta('');
    }

    function acrescentar() {
        const proximo = estandes.length === 0 ? 1 : Math.max(...estandes.map((e) => e.numero)) + 1;
        const celula = livres[0] ?? { x: caixa.largura, y: 0 };

        setLayout((l) => ({ ...l, estandes: [...l.estandes, { numero: proximo, ...celula }] }));
        setSelecionado(proximo);
        setSujo(true);
    }

    /** Batiza (ou apaga o nome de) um corredor. */
    function nomearRua(chave, nome) {
        setRuas((atuais) => atuais.map((r) => (r.chave === chave ? { ...r, nome } : r)));
        setSujo(true);
    }

    async function remover() {
        if (selecionado === null) return;

        const ok = await confirm({
            title: 'Remover estande',
            message: `O estande ${selecionado} sai da planta. A distribuição dos projetos não muda — se houver projeto nele, ele deixa de aparecer no mapa.`,
            confirmLabel: 'Remover',
            danger: true,
        });
        if (!ok) return;

        setLayout((l) => ({ ...l, estandes: l.estandes.filter((e) => e.numero !== selecionado) }));
        setSelecionado(null);
        setSujo(true);
    }

    async function salvar() {
        setOcupado(true);
        try {
            // Só o nome de cada rua vai: a posição é recalculada do desenho.
            const nomes = Object.fromEntries(
                ruas.filter((r) => (r.nome ?? '').trim() !== '').map((r) => [r.chave, r.nome.trim()]),
            );

            const r = await salvarPlanta({ ...layout, ruas: nomes });
            setDados(r.data);
            setLayout(r.data.layout);
            setRuas(r.data.ruas ?? []);
            setSujo(false);
            setEditando(false);
            setSucesso(r.meta?.message ?? 'Planta salva.');
            setAlerta('');
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    async function restaurar(versao) {
        const ok = await confirm({
            title: `Restaurar a versão ${versao.versao}`,
            message: 'O desenho daquela versão volta a valer — gravado como uma versão nova, para o histórico continuar completo.',
            confirmLabel: 'Restaurar',
        });
        if (!ok) return;

        setOcupado(true);
        try {
            const r = await restaurarPlanta(versao.id);
            setDados(r.data);
            setLayout(r.data.layout);
            setRuas(r.data.ruas ?? []);
            setSujo(false);
            setSucesso(r.meta?.message ?? 'Planta restaurada.');
            setAlerta('');
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    const params = () => ({ criterio, valor, dia: dia || undefined, turno });

    async function consultarLista() {
        setCarregandoLista(true);
        try {
            setLista(await getListaSituacao(params()));
            setAlerta('');
        } catch (e) { falhar(e); } finally { setCarregandoLista(false); }
    }

    async function exportarLista(formato) {
        try {
            await baixarListaSituacao(formato, params());
        } catch (e) { falhar(e); }
    }

    const detalhe = selecionado === null ? null : ocupacao[selecionado] ?? { numero: selecionado, A: null, B: null };
    const situacaoDoSelecionado = selecionado === null ? null : porNumero[selecionado] ?? null;
    const tipoDoCriterio = filtros?.criterios?.find((c) => c.value === criterio)?.tipo ?? 'booleano';

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/mapa" className="hover:text-primary">Mapa do Evento</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Mapa do Evento</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Mapa do Evento</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                A planta do ginásio. Clique num estande para ver quem apresenta nele e por onde o
                projeto já passou. Durante a feira o mapa <strong>muda de cor</strong> sozinho — o
                credenciamento pinta o estande, a checagem o marca como pronto para avaliação e cada
                avaliação recebida o escurece.
            </p>

            {alerta && <div className="mb-4"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            <div className="flex flex-wrap items-center gap-3 mb-4">
                <span className="text-sm text-on-surface-variant mr-auto">
                    {estandes.length} estande(s) no desenho · {dados?.ocupados ?? 0} com projeto
                    {dados?.versao ? ` · versão ${dados.versao} em vigor` : ' · planta ainda não salva'}
                </span>

                {!editando && (
                    <Button variant="outline" onClick={() => { setEditando(true); setSucesso(''); }}>
                        Editar planta
                    </Button>
                )}

                {editando && (
                    <>
                        <Button variant="outline" onClick={acrescentar}>Adicionar estande</Button>
                        <Button variant="outline" onClick={remover} disabled={selecionado === null}>
                            Remover selecionado
                        </Button>
                        <Button onClick={salvar} loading={ocupado} disabled={!sujo}>
                            Salvar como nova versão
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setLayout(dados.layout);
                                setRuas(dados.ruas ?? []);
                                setEditando(false);
                                setSujo(false);
                            }}
                        >
                            Cancelar
                        </Button>
                    </>
                )}
            </div>

            {editando && (
                <div className="mb-4">
                    <Alert type="warning">
                        Modo de edição: escolha um estande e clique numa célula vazia para movê-lo.
                        Nada muda para ninguém até você salvar — e salvar cria uma versão nova.
                    </Alert>
                </div>
            )}

            {/* Dia, turno e modo de cor: é por aqui que o mapa volta no tempo. */}
            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4 flex flex-wrap items-end gap-3">
                <Field label="Cor do mapa">
                    <Select aria-label="Cor do mapa" value={modo} onChange={(e) => setModo(e.target.value)}>
                        <option value="situacao">Situação no evento</option>
                        <option value="ocupacao">Ocupação (os dois turnos)</option>
                    </Select>
                </Field>

                {modo === 'situacao' && (
                    <>
                        <Field label="Turno">
                            <Select aria-label="Turno" value={turno} onChange={(e) => setTurno(e.target.value)}>
                                {(filtros?.turnos ?? []).map((t) => (
                                    <option key={t.value} value={t.value}>{t.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Dia do evento" hint="Um dia anterior mostra o mapa como ele estava no fim daquele dia.">
                            <Select aria-label="Dia do evento" value={dia} onChange={(e) => setDia(e.target.value)}>
                                {(filtros?.dias ?? []).map((d) => (
                                    <option key={d.value} value={d.value}>{d.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <Button variant="outline" onClick={carregarSituacao}>
                            <span className="material-symbols-outlined text-[20px]">refresh</span>
                            Atualizar
                        </Button>
                    </>
                )}

                <Button variant="outline" onClick={alternarTelaCheia}>
                    <span className="material-symbols-outlined text-[20px]">
                        {telaCheia ? 'fullscreen_exit' : 'fullscreen'}
                    </span>
                    {telaCheia ? 'Sair da tela cheia' : 'Tela cheia'}
                </Button>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div
                    ref={palco}
                    className="lg:col-span-2 bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 overflow-x-auto"
                >
                    {/* Em tela cheia o fundo do palco precisa existir: o elemento
                        sai do fluxo da página e levaria o `body` junto. */}
                    {telaCheia && (
                        <div className="flex items-center justify-between mb-3">
                            <h2 className="font-display text-lg font-semibold text-primary">
                                {situacao ? `${situacao.turno_label} · ${situacao.corte_label}` : 'Mapa do Evento'}
                            </h2>
                            <Button variant="outline" onClick={alternarTelaCheia}>Sair da tela cheia</Button>
                        </div>
                    )}

                    <svg
                        role="img"
                        aria-label="Planta do evento com os estandes"
                        viewBox={`0 0 ${caixa.largura * CELULA + MARGEM * 2} ${caixa.altura * CELULA + MARGEM * 2}`}
                        className={`w-full h-auto min-w-[36rem] ${telaCheia ? 'max-h-[85vh]' : ''}`}
                    >
                        {/* As células livres só aparecem no modo de edição: são
                            o destino de quem está selecionado. */}
                        {livres.map((c) => (
                            <rect
                                key={`livre-${c.x}-${c.y}`}
                                x={MARGEM + c.x * CELULA}
                                y={MARGEM + c.y * CELULA}
                                width={CELULA - 1}
                                height={CELULA - 1}
                                rx="1.5"
                                fill="#f7f4fb"
                                stroke="#e6e0ee"
                                strokeDasharray="2 2"
                                style={{ cursor: selecionado === null ? 'default' : 'pointer' }}
                                onClick={() => moverPara(c.x, c.y)}
                            />
                        ))}

                        {ruas.map((r) => <Rua key={r.chave} rua={r} />)}

                        {(layout?.marcacoes ?? []).map((m, i) => (
                            <g key={`marca-${i}`}>
                                <rect
                                    x={MARGEM + m.x * CELULA}
                                    y={MARGEM + m.y * CELULA}
                                    width={m.largura * CELULA}
                                    height={m.altura * CELULA}
                                    rx="2"
                                    fill="#006e1f"
                                    opacity="0.12"
                                />
                                <text
                                    x={MARGEM + (m.x + m.largura / 2) * CELULA}
                                    y={MARGEM + (m.y + m.altura / 2) * CELULA + 2}
                                    textAnchor="middle"
                                    fontSize="4"
                                    fill="#006e1f"
                                >
                                    {m.rotulo}
                                </text>
                            </g>
                        ))}

                        {estandes.map((e) => {
                            const cores = modo === 'situacao'
                                ? corPorSituacao({ selecionado: e.numero === selecionado, linha: porNumero[e.numero] })
                                : corPorOcupacao({ selecionado: e.numero === selecionado, ocupacao: ocupacao[e.numero] });

                            return (
                                <g
                                    key={e.numero}
                                    role="button"
                                    tabIndex={0}
                                    aria-label={`Estande ${String(e.numero).padStart(3, '0')}`}
                                    style={{ cursor: 'pointer' }}
                                    onClick={() => setSelecionado(e.numero)}
                                    onKeyDown={(ev) => { if (ev.key === 'Enter') setSelecionado(e.numero); }}
                                >
                                    <rect
                                        x={MARGEM + e.x * CELULA}
                                        y={MARGEM + e.y * CELULA}
                                        width={CELULA - 1}
                                        height={CELULA - 1}
                                        rx="1.5"
                                        fill={cores.fill}
                                        fillOpacity={cores.opacidade ?? 1}
                                        stroke={cores.stroke}
                                        strokeWidth="0.6"
                                    />
                                    <text
                                        x={MARGEM + e.x * CELULA + (CELULA - 1) / 2}
                                        y={MARGEM + e.y * CELULA + (CELULA - 1) / 2 + 1.6}
                                        textAnchor="middle"
                                        fontSize="4"
                                        fontWeight="600"
                                        fill={cores.texto}
                                    >
                                        {String(e.numero).padStart(3, '0')}
                                    </text>
                                </g>
                            );
                        })}
                    </svg>

                    <div className="flex flex-wrap gap-4 mt-3 text-xs text-on-surface-variant">
                        {modo === 'situacao' ? (
                            (filtros?.legenda ?? []).map((l) => (
                                <span key={l.value} className="inline-flex items-center gap-1">
                                    <span className="w-3 h-3 rounded-sm border" style={{ background: l.cor, borderColor: '#2a0058' }} />
                                    {l.label}
                                    {situacao?.resumo?.[l.value] !== undefined && ` (${situacao.resumo[l.value]})`}
                                </span>
                            ))
                        ) : (
                            <>
                                <span className="inline-flex items-center gap-1">
                                    <span className="w-3 h-3 rounded-sm border" style={{ background: '#d8c9ef', borderColor: '#43157A' }} />
                                    Ocupado nos dois turnos
                                </span>
                                <span className="inline-flex items-center gap-1">
                                    <span className="w-3 h-3 rounded-sm border" style={{ background: '#efe7fa', borderColor: '#7a5aa8' }} />
                                    Ocupado em um turno
                                </span>
                                <span className="inline-flex items-center gap-1">
                                    <span className="w-3 h-3 rounded-sm border" style={{ background: '#ffffff', borderColor: '#cfc6db' }} />
                                    Vazio
                                </span>
                            </>
                        )}
                    </div>
                </div>

                <div className="space-y-4">
                    <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                        {detalhe === null ? (
                            <p className="text-sm text-on-surface-variant">
                                Clique num estande para ver quem apresenta nele.
                            </p>
                        ) : (
                            <>
                                <h2 className="font-display text-lg font-semibold text-primary mb-3">
                                    Estande {String(detalhe.numero).padStart(3, '0')}
                                </h2>

                                {editando && (
                                    <div className="mb-4 flex items-end gap-2">
                                        <Field label="Número">
                                            <Input
                                                type="number"
                                                min="1"
                                                aria-label="Número do estande"
                                                value={novoNumero}
                                                onChange={(ev) => setNovoNumero(ev.target.value)}
                                                placeholder={String(detalhe.numero)}
                                            />
                                        </Field>
                                        <Button
                                            variant="outline"
                                            onClick={() => { renumerar(novoNumero); setNovoNumero(''); }}
                                        >
                                            Renumerar
                                        </Button>
                                    </div>
                                )}

                                {situacaoDoSelecionado && (
                                    <div className="mb-3 rounded-lg border border-outline-variant/40 p-3">
                                        <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">
                                            {situacao?.turno_label} · situação
                                        </p>
                                        <p className="text-sm font-semibold text-on-surface mt-1">
                                            {situacaoDoSelecionado.situacao_label}
                                        </p>
                                        <p className="text-xs text-on-surface-variant">
                                            {situacaoDoSelecionado.credenciado
                                                ? `Credenciado em ${situacaoDoSelecionado.credenciado_em}`
                                                : 'Ainda não passou pelo credenciamento'}
                                        </p>
                                        <p className="text-xs text-on-surface-variant">
                                            {situacaoDoSelecionado.checado
                                                ? `Estande checado em ${situacaoDoSelecionado.checado_em}`
                                                : 'Estande ainda não checado'}
                                        </p>
                                        <p className="text-xs text-on-surface-variant">
                                            {situacaoDoSelecionado.avaliacoes} de {situacaoDoSelecionado.avaliacoes_maximo}{' '}
                                            avaliação(ões) · faltam {situacaoDoSelecionado.avaliacoes_faltantes}
                                        </p>
                                    </div>
                                )}

                                {['A', 'B'].map((t) => {
                                    const projeto = detalhe[t];
                                    const rotulo = t === 'A' ? 'Turno A (matutino)' : 'Turno B (vespertino)';

                                    return (
                                        <div key={t} className="mb-3 last:mb-0">
                                            <h3 className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide mb-1">
                                                {rotulo}
                                            </h3>
                                            {projeto ? (
                                                <div className="text-sm">
                                                    <p className="font-medium text-on-surface">{projeto.titulo}</p>
                                                    <p className="text-xs text-on-surface-variant">
                                                        {[projeto.categoria, projeto.area].filter(Boolean).join(' · ')}
                                                    </p>
                                                    <p className="text-xs text-on-surface-variant">
                                                        {[projeto.escola, projeto.cidade].filter(Boolean).join(' · ')}
                                                    </p>
                                                    <p className="text-xs text-on-surface-variant">
                                                        Orientador(a): {projeto.orientador}
                                                    </p>
                                                </div>
                                            ) : (
                                                <p className="text-sm text-on-surface-variant">Sem projeto neste turno.</p>
                                            )}
                                        </div>
                                    );
                                })}
                            </>
                        )}
                    </section>

                    {/* As ruas são achadas no desenho; aqui só se dá nome a elas. */}
                    {editando && ruas.length > 0 && (
                        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <h2 className="font-display font-semibold text-on-surface mb-1">Ruas do evento</h2>
                            <p className="text-xs text-on-surface-variant mb-3">
                                Os corredores entre as ilhas de estandes, encontrados no próprio
                                desenho. Dê nome aos que a organização usa — quem fica em branco não
                                aparece no mapa.
                            </p>
                            <ul className="space-y-2">
                                {ruas.map((r) => (
                                    <li key={r.chave} className="flex items-center gap-2">
                                        <span className="material-symbols-outlined text-[18px] text-on-surface-variant">
                                            {r.orientacao === 'h' ? 'swap_horiz' : 'swap_vert'}
                                        </span>
                                        <Input
                                            aria-label={`Nome do corredor ${r.chave}`}
                                            value={r.nome ?? ''}
                                            placeholder={r.orientacao === 'h' ? 'Corredor horizontal' : 'Corredor vertical'}
                                            onChange={(ev) => nomearRua(r.chave, ev.target.value)}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {/* O mesmo recorte do mapa, em lista — para conferir e levar. */}
                    <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                        <h2 className="font-display font-semibold text-on-surface mb-3">Lista por situação</h2>

                        <div className="space-y-3">
                            <Field label="Filtrar por">
                                <Select
                                    aria-label="Filtrar por"
                                    value={criterio}
                                    onChange={(e) => {
                                        setCriterio(e.target.value);
                                        setValor(e.target.value.startsWith('avaliacoes_') ? '0' : 'sim');
                                        setLista(null);
                                    }}
                                >
                                    {(filtros?.criterios ?? []).map((c) => (
                                        <option key={c.value} value={c.value}>{c.label}</option>
                                    ))}
                                </Select>
                            </Field>

                            <Field label={tipoDoCriterio === 'numero' ? 'Quantidade' : 'Situação'}>
                                <Select aria-label="Valor do filtro" value={valor} onChange={(e) => setValor(e.target.value)}>
                                    {tipoDoCriterio === 'numero'
                                        ? Array.from({ length: (filtros?.max_avaliacoes ?? 3) + 1 }, (_, n) => (
                                            <option key={n} value={String(n)}>{n}</option>
                                        ))
                                        : (
                                            <>
                                                <option value="sim">Já passou</option>
                                                <option value="nao">Ainda não passou</option>
                                            </>
                                        )}
                                </Select>
                            </Field>

                            <div className="flex flex-wrap gap-2">
                                <Button onClick={consultarLista} loading={carregandoLista}>Ver lista</Button>
                                <Button variant="outline" onClick={() => exportarLista('csv')}>CSV</Button>
                                <Button variant="outline" onClick={() => exportarLista('txt')}>TXT</Button>
                                <Button variant="outline" onClick={() => exportarLista('pdf')}>PDF</Button>
                            </div>
                        </div>

                        {lista && (
                            <div className="mt-4">
                                <p className="text-sm text-on-surface-variant mb-2">
                                    <strong>{lista.total}</strong> projeto(s) · {lista.criterio_label}:{' '}
                                    {lista.valor_label} · {lista.turno_label}
                                </p>
                                {lista.linhas.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">Nenhum projeto neste recorte.</p>
                                ) : (
                                    <ul className="divide-y divide-outline-variant/30 max-h-80 overflow-y-auto fetec-scroll">
                                        {lista.linhas.map((l) => (
                                            <li key={l.projeto_id} className="py-2">
                                                <p className="text-sm text-on-surface">
                                                    <span className="font-semibold">{l.estande}</span> · {l.titulo}
                                                </p>
                                                <p className="text-xs text-on-surface-variant">
                                                    {l.situacao_label} · {l.avaliacoes} de {l.avaliacoes_maximo} avaliação(ões)
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </section>

                    {(dados?.versoes ?? []).length > 0 && (
                        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <h2 className="font-display font-semibold text-on-surface mb-3">Versões da planta</h2>
                            <ul className="divide-y divide-outline-variant/30">
                                {dados.versoes.map((v) => (
                                    <li key={v.id} className="py-2 flex items-center gap-3">
                                        <span className="min-w-0 flex-1">
                                            <span className="text-sm text-on-surface">
                                                v{v.versao} · {v.estandes} estandes
                                                {v.vigente && <span className="text-primary-container"> · em vigor</span>}
                                            </span>
                                            <span className="block text-xs text-on-surface-variant">
                                                {v.autor ?? '—'} · {v.criada_em ? new Date(v.criada_em).toLocaleDateString('pt-BR') : ''}
                                            </span>
                                        </span>
                                        {!v.vigente && (
                                            <Button variant="outline" onClick={() => restaurar(v)} disabled={ocupado}>
                                                Restaurar
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </div>

            {confirmDialog}
        </AppShell>
    );
}
