import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getPlanta, salvarPlanta, restaurarPlanta } from '../lib/mapaEvento.js';

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
 */

/** Tamanho de uma célula da grade, em unidades do viewBox. */
const CELULA = 10;
const MARGEM = 6;

/** Cor do estande conforme o que há nele — a identidade roxa do portal. */
function corDoEstande({ selecionado, ocupacao }) {
    if (selecionado) return { fill: '#43157A', stroke: '#2a0058', texto: '#ffffff' };
    if (ocupacao?.A && ocupacao?.B) return { fill: '#d8c9ef', stroke: '#43157A', texto: '#2a0058' };
    if (ocupacao?.A || ocupacao?.B) return { fill: '#efe7fa', stroke: '#7a5aa8', texto: '#2a0058' };

    return { fill: '#ffffff', stroke: '#cfc6db', texto: '#6b6577' };
}

export default function MapaPlanta() {
    const [dados, setDados] = useState(null);
    const [layout, setLayout] = useState(null);
    const [selecionado, setSelecionado] = useState(null);   // número do estande
    const [editando, setEditando] = useState(false);
    const [sujo, setSujo] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [novoNumero, setNovoNumero] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => {
        getPlanta()
            .then((d) => { setDados(d); setLayout(d.layout); })
            .catch(() => setAlerta('Não foi possível carregar a planta.'));
    }, []);

    const estandes = layout?.estandes ?? [];
    const ocupacao = dados?.ocupacao ?? {};

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
            const r = await salvarPlanta(layout);
            setDados(r.data);
            setLayout(r.data.layout);
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
            setSujo(false);
            setSucesso(r.meta?.message ?? 'Planta restaurada.');
            setAlerta('');
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    const detalhe = selecionado === null ? null : ocupacao[selecionado] ?? { numero: selecionado, A: null, B: null };

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/mapa" className="hover:text-primary">Mapa do Evento</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Mapa do Evento</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Mapa do Evento</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                A planta do ginásio. Clique num estande para ver quem apresenta nele no{' '}
                <strong>turno A (matutino)</strong> e no <strong>turno B (vespertino)</strong>. O
                desenho começa na prancha da montadora e pode ser ajustado — cada gravação vira uma
                versão nova.
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
                            onClick={() => { setLayout(dados.layout); setEditando(false); setSujo(false); }}
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

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 overflow-x-auto">
                    <svg
                        role="img"
                        aria-label="Planta do evento com os estandes"
                        viewBox={`0 0 ${caixa.largura * CELULA + MARGEM * 2} ${caixa.altura * CELULA + MARGEM * 2}`}
                        className="w-full h-auto min-w-[36rem]"
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
                            const cores = corDoEstande({
                                selecionado: e.numero === selecionado,
                                ocupacao: ocupacao[e.numero],
                            });

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

                                {['A', 'B'].map((turno) => {
                                    const projeto = detalhe[turno];
                                    const rotulo = turno === 'A' ? 'Turno A (matutino)' : 'Turno B (vespertino)';

                                    return (
                                        <div key={turno} className="mb-3 last:mb-0">
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
