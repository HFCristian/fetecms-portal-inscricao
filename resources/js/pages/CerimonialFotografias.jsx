import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getFotografiasEstandes, reservarFotografiaEstande, cancelarFotografiaEstande,
    enviarFotografiaEstande, excluirFotografiaEstande,
} from '../lib/cerimonial.js';

/** Tamanho de uma célula da grade, em unidades do viewBox (o mesmo da planta). */
const CELULA = 10;
const MARGEM = 6;
/** O mapa se atualiza sozinho: outro fotógrafo pode estar no ginásio ao mesmo tempo. */
const ATUALIZACAO_MS = 15000;

const SITUACOES = {
    registrado: { fill: '#006e1f', stroke: '#004d15', texto: '#ffffff', legenda: 'Registrado' },
    em_registro: { fill: '#fff4cc', stroke: '#b38f00', texto: '#5c4a00', legenda: 'Em registro' },
    sem_registro: { fill: '#ffffff', stroke: '#b9b2c4', texto: '#2a0058', legenda: 'Sem registro' },
};
const VAZIO = { fill: '#f3f1f6', stroke: '#e6e0ee', texto: '#b9b2c4' };

const rotulo = (n) => String(n).padStart(3, '0');
const mb = (bytes) => `${(bytes / 1024 / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;

/** "Estande 042 · FET.AGR-001 · Título". */
const nomeDoEstande = (linha) => [`Estande ${linha.estande}`, linha.codigo, linha.titulo].filter(Boolean).join(' · ');

function Dialogo({ titulo, children, onFechar }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label={titulo}>
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4 max-h-[92vh] overflow-y-auto">
                <div className="flex items-start justify-between gap-3">
                    <h3 className="font-display text-xl font-semibold text-on-surface">{titulo}</h3>
                    {onFechar && (
                        <button type="button" onClick={onFechar} aria-label="Fechar" className="text-on-surface-variant hover:text-on-surface">
                            <span className="material-symbols-outlined">close</span>
                        </button>
                    )}
                </div>
                {children}
            </div>
        </div>
    );
}

/** O estande, o projeto e o turno — no topo de cada passo, para não haver dúvida de qual é. */
function Identificacao({ linha }) {
    return (
        <div className="rounded-lg bg-surface-variant/50 px-3 py-2">
            <p className="text-lg font-display font-semibold text-primary">Estande {linha.estande}</p>
            <p className="text-sm text-on-surface">{linha.titulo}</p>
            <p className="text-xs text-on-surface-variant">
                {[linha.codigo, linha.turno_label, linha.area].filter(Boolean).join(' · ')}
            </p>
        </div>
    );
}

/**
 * Cerimonial → **Fotografias de estandes** (Sprint 247).
 *
 * O mapa do evento, num turno, com a situação da foto de cada estande:
 * registrado, em registro (alguém confirmou o estande e ainda não salvou) e
 * sem registro. Clicar num estande pergunta "Registrar fotografia do
 * estande?" — deixando claro qual estande é — e, confirmado, oferece a câmera
 * do aparelho ou um arquivo de até 100 MB. Antes de gravar, a prévia. A foto
 * registrada abre para ver, substituir ou excluir.
 *
 * A câmera é a do próprio aparelho (`capture`), não um vídeo na página: no
 * celular ela abre o app de câmera, com a resolução e o foco de verdade.
 */
export default function CerimonialFotografias() {
    const [turno, setTurno] = useState('A');
    const [dados, setDados] = useState(null);
    const [alerta, setAlerta] = useState('');
    const [info, setInfo] = useState('');
    const [linha, setLinha] = useState(null);
    const [etapa, setEtapa] = useState(null); // 'confirmar' | 'escolher' | 'previa' | 'detalhe'
    const [arquivo, setArquivo] = useState(null);
    const [previa, setPrevia] = useState(null);
    const [previaFalhou, setPreviaFalhou] = useState(false);
    const [progresso, setProgresso] = useState(null);
    const [ocupado, setOcupado] = useState(false);
    const [erro, setErro] = useState('');
    const [confirm, dialogoConfirmar] = useConfirm();
    const camera = useRef(null);
    const galeria = useRef(null);

    const carregar = useCallback(() => getFotografiasEstandes(turno)
        .then(setDados)
        .catch(() => setAlerta('Não foi possível carregar o mapa das fotografias.')), [turno]);

    useEffect(() => { carregar(); }, [carregar]);

    // Com um diálogo aberto o mapa não muda debaixo de quem está fotografando.
    useEffect(() => {
        if (etapa !== null) return undefined;
        const id = setInterval(carregar, ATUALIZACAO_MS);

        return () => clearInterval(id);
    }, [carregar, etapa]);

    // A prévia é um endereço do próprio navegador: solto ao trocar de arquivo.
    useEffect(() => () => { if (previa) URL.revokeObjectURL(previa); }, [previa]);

    const layout = dados?.layout ?? [];
    const estandes = dados?.estandes ?? {};
    const caixa = useMemo(() => (layout.length === 0
        ? { largura: 10, altura: 10 }
        : { largura: Math.max(...layout.map((e) => e.x)) + 1, altura: Math.max(...layout.map((e) => e.y)) + 1 }), [layout]);
    const maxMb = dados?.max_mb ?? 100;

    function fechar() {
        setLinha(null); setEtapa(null); setArquivo(null); setPrevia(null);
        setPreviaFalhou(false); setProgresso(null); setErro('');
    }

    /** Fechar no meio do registro devolve o estande. */
    async function desistir() {
        const projeto = linha?.projeto_id;
        const reservado = etapa === 'escolher' || etapa === 'previa';
        fechar();
        if (reservado && projeto) {
            await cancelarFotografiaEstande(projeto).catch(() => {});
            await carregar();
        }
    }

    function clicar(numero, l) {
        setAlerta(''); setInfo('');

        if (!l) {
            setInfo(`O estande ${rotulo(numero)} está vazio no ${dados?.turno_label ?? 'turno'}.`);
            return;
        }

        if (l.situacao === 'em_registro' && !l.em_registro?.meu) {
            setInfo(`${nomeDoEstande(l)}: ${l.em_registro?.por ?? 'outra pessoa'} está registrando a fotografia desde as ${l.em_registro?.desde_label}.`);
            return;
        }

        setLinha(l);
        setErro('');
        if (l.situacao === 'em_registro') setEtapa('escolher');
        else if (l.foto) setEtapa('detalhe');
        else setEtapa('confirmar');
    }

    async function reservar() {
        setOcupado(true); setErro('');
        try {
            setLinha(await reservarFotografiaEstande(linha.projeto_id));
            setEtapa('escolher');
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível reservar o estande.');
            await carregar();
        } finally {
            setOcupado(false);
        }
    }

    function escolher(ev) {
        const f = ev.target.files?.[0];
        ev.target.value = '';
        if (!f) return;

        if (f.size > maxMb * 1024 * 1024) {
            setErro(`A fotografia tem ${mb(f.size)}: o limite é ${maxMb} MB.`);
            return;
        }
        if (f.type && !f.type.startsWith('image/')) {
            setErro('Escolha uma imagem (JPG, PNG, WEBP ou HEIC).');
            return;
        }

        setErro('');
        setArquivo(f);
        setPreviaFalhou(false);
        setPrevia(URL.createObjectURL(f));
        setEtapa('previa');
    }

    async function registrar() {
        setOcupado(true); setErro(''); setProgresso(0);
        try {
            const resp = await enviarFotografiaEstande(linha.projeto_id, arquivo, setProgresso);
            const titulo = nomeDoEstande(linha);
            fechar();
            setInfo(`${titulo}: ${resp.meta?.message ?? 'fotografia registrada.'}`);
            await carregar();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível registrar a fotografia.');
            setProgresso(null);
        } finally {
            setOcupado(false);
        }
    }

    async function excluir() {
        const ok = await confirm({
            title: 'Excluir a fotografia?',
            message: `A fotografia do estande ${linha.estande} é apagada e o estande volta a "sem registro". Depois, é possível anexar outra.`,
            confirmLabel: 'Excluir',
            danger: true,
        });
        if (!ok) return;

        setOcupado(true); setErro('');
        try {
            await excluirFotografiaEstande(linha.foto.id);
            const titulo = nomeDoEstande(linha);
            fechar();
            setInfo(`${titulo}: fotografia excluída.`);
            await carregar();
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível excluir a fotografia.');
        } finally {
            setOcupado(false);
        }
    }

    const descricao = (numero, l) => (l
        ? `Estande ${rotulo(numero)} · ${l.titulo} · ${SITUACOES[l.situacao]?.legenda ?? ''}`
        : `Estande ${rotulo(numero)} · vazio`);

    return (
        <AppShell>
            <Link to="/admin/cerimonial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Cerimonial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Fotografias de estandes</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Clique no estande que você está fotografando. Cada projeto tem uma fotografia — o estande
                recebe um projeto de manhã e outro à tarde, então escolha o turno antes.
            </p>

            <div className="max-w-5xl space-y-3 mb-4">
                <Alert>{alerta}</Alert>
                <Alert type="info">{info}</Alert>
            </div>

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 max-w-5xl">
                <div className="flex flex-wrap items-center gap-2 mb-3" role="radiogroup" aria-label="Turno do mapa">
                    {(dados?.turnos ?? [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }]).map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            role="radio"
                            aria-checked={turno === t.value}
                            onClick={() => { setTurno(t.value); setInfo(''); }}
                            className={`px-3 py-1 rounded-full text-sm font-semibold border ${
                                turno === t.value
                                    ? 'bg-primary-container text-on-primary border-primary-container'
                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                            }`}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {dados === null ? (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : dados.total === 0 ? (
                    <p className="text-sm text-on-surface-variant">
                        Nenhum projeto com estande neste turno. Os estandes saem de Mapa do Evento → Estandes dos Projetos.
                    </p>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <svg
                                role="img"
                                aria-label="Planta do evento com a situação das fotografias"
                                viewBox={`0 0 ${caixa.largura * CELULA + MARGEM * 2} ${caixa.altura * CELULA + MARGEM * 2}`}
                                className="w-full h-auto min-w-[36rem]"
                            >
                                {layout.map((e) => {
                                    const l = estandes[e.numero] ?? null;
                                    const cores = l ? SITUACOES[l.situacao] ?? SITUACOES.sem_registro : VAZIO;
                                    const x0 = MARGEM + e.x * CELULA;
                                    const y0 = MARGEM + e.y * CELULA;

                                    return (
                                        <g
                                            key={e.numero}
                                            role="button"
                                            tabIndex={0}
                                            aria-label={descricao(e.numero, l)}
                                            style={{ cursor: l ? 'pointer' : 'default' }}
                                            onClick={() => clicar(e.numero, l)}
                                            onKeyDown={(ev) => { if (ev.key === 'Enter') clicar(e.numero, l); }}
                                        >
                                            <title>{descricao(e.numero, l)}</title>
                                            <rect
                                                x={x0}
                                                y={y0}
                                                width={CELULA - 1}
                                                height={CELULA - 1}
                                                rx="1.5"
                                                fill={cores.fill}
                                                stroke={cores.stroke}
                                                strokeWidth="0.6"
                                                strokeDasharray={!l ? '1.5 1' : undefined}
                                            />
                                            <text
                                                x={x0 + (CELULA - 1) / 2}
                                                y={y0 + (CELULA - 1) / 2 + 1.6}
                                                textAnchor="middle"
                                                fontSize="3.6"
                                                fontWeight="600"
                                                fill={cores.texto}
                                            >
                                                {rotulo(e.numero)}
                                            </text>
                                        </g>
                                    );
                                })}
                            </svg>
                        </div>

                        {(dados.fora_da_planta ?? []).length > 0 && (
                            <div className="mt-3">
                                <p className="text-xs text-on-surface-variant mb-1">Estandes fora da planta</p>
                                <div className="flex flex-wrap gap-2">
                                    {dados.fora_da_planta.map((l) => {
                                        const cores = SITUACOES[l.situacao] ?? SITUACOES.sem_registro;
                                        return (
                                            <button
                                                key={l.projeto_id}
                                                type="button"
                                                onClick={() => clicar(l.numero, l)}
                                                aria-label={descricao(l.numero, l)}
                                                className="rounded-lg border px-2.5 py-1 text-xs font-semibold"
                                                style={{ background: cores.fill, borderColor: cores.stroke, color: cores.texto }}
                                            >
                                                {l.estande} · {l.titulo}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        <ul className="flex flex-wrap gap-x-4 gap-y-1 mt-3 text-xs text-on-surface" aria-label="Legenda">
                            {Object.entries(SITUACOES).map(([chave, c]) => (
                                <li key={chave} className="inline-flex items-center gap-1">
                                    <span className="w-3 h-3 rounded-sm border" style={{ background: c.fill, borderColor: c.stroke }} />
                                    {c.legenda}
                                    <span className="text-on-surface-variant tabular-nums">({dados.resumo?.[chave] ?? 0})</span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </section>

            {linha && etapa === 'confirmar' && (
                <Dialogo titulo="Registrar fotografia do estande?" onFechar={fechar}>
                    <Identificacao linha={linha} />
                    <p className="text-sm text-on-surface-variant">
                        Você vai fotografar <strong className="text-on-surface">este estande</strong>. Confira o
                        número na placa antes de continuar: a foto fica gravada como a do estande{' '}
                        <strong className="text-on-surface">{linha.estande}</strong> no {linha.turno_label}.
                    </p>
                    <Alert>{erro}</Alert>
                    <div className="flex justify-end gap-3">
                        <Button type="button" variant="outline" onClick={fechar}>Cancelar</Button>
                        <Button type="button" loading={ocupado} onClick={reservar}>
                            Sim, estou no estande {linha.estande}
                        </Button>
                    </div>
                </Dialogo>
            )}

            {linha && etapa === 'escolher' && (
                <Dialogo titulo="Fotografia do estande" onFechar={desistir}>
                    <Identificacao linha={linha} />
                    <p className="text-sm text-on-surface-variant">
                        Tire a foto agora com a câmera do aparelho ou envie um arquivo de imagem de até {maxMb} MB.
                        Antes de gravar, você confere a prévia.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Button type="button" onClick={() => camera.current?.click()}>
                            <span className="material-symbols-outlined text-[20px]">photo_camera</span>
                            Tirar foto com a câmera
                        </Button>
                        <Button type="button" variant="outline" onClick={() => galeria.current?.click()}>
                            <span className="material-symbols-outlined text-[20px]">upload_file</span>
                            Enviar arquivo (até {maxMb} MB)
                        </Button>
                    </div>
                    <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" aria-label="Câmera do aparelho" onChange={escolher} />
                    <input ref={galeria} type="file" accept="image/*" className="hidden" aria-label="Arquivo da fotografia" onChange={escolher} />
                    <Alert>{erro}</Alert>
                    <div className="flex justify-end">
                        <Button type="button" variant="outline" onClick={desistir}>Cancelar</Button>
                    </div>
                </Dialogo>
            )}

            {linha && etapa === 'previa' && arquivo && (
                <Dialogo titulo="Confira a fotografia" onFechar={ocupado ? null : desistir}>
                    <Identificacao linha={linha} />
                    {previaFalhou ? (
                        <p className="text-sm rounded-lg bg-[#fff4cc] px-3 py-2 text-on-surface">
                            Este navegador não mostra a prévia deste formato (HEIC, por exemplo). A foto pode ser
                            registrada assim mesmo.
                        </p>
                    ) : (
                        <img
                            src={previa}
                            alt={`Prévia da fotografia do estande ${linha.estande}`}
                            className="w-full max-h-[50vh] object-contain rounded-lg bg-surface-variant/40"
                            onError={() => setPreviaFalhou(true)}
                        />
                    )}
                    <p className="text-xs text-on-surface-variant">{arquivo.name} · {mb(arquivo.size)}</p>
                    {progresso !== null && (
                        <div className="h-2 rounded-full bg-surface-variant overflow-hidden" role="progressbar" aria-valuenow={progresso} aria-valuemin={0} aria-valuemax={100} aria-label="Envio da fotografia">
                            <div className="h-full bg-primary-container transition-all" style={{ width: `${progresso}%` }} />
                        </div>
                    )}
                    <Alert>{erro}</Alert>
                    <div className="flex flex-wrap justify-end gap-3">
                        <Button type="button" variant="outline" disabled={ocupado} onClick={() => { setArquivo(null); setPrevia(null); setEtapa('escolher'); }}>
                            Escolher outra
                        </Button>
                        <Button type="button" loading={ocupado} onClick={registrar}>
                            Confirmar e registrar
                        </Button>
                    </div>
                </Dialogo>
            )}

            {linha && etapa === 'detalhe' && linha.foto && (
                <Dialogo titulo={`Estande ${linha.estande}`} onFechar={fechar}>
                    <Identificacao linha={linha} />
                    <img
                        src={linha.foto.url}
                        alt={`Fotografia do estande ${linha.estande}`}
                        className="w-full max-h-[50vh] object-contain rounded-lg bg-surface-variant/40"
                    />
                    <p className="text-xs text-on-surface-variant">
                        Registrada{linha.foto.registrado_por ? ` por ${linha.foto.registrado_por}` : ''} em {linha.foto.registrado_em_label}
                        {' · '}
                        <a href={linha.foto.url} target="_blank" rel="noreferrer" className="text-primary hover:underline">abrir em tamanho real</a>
                    </p>
                    <Alert>{erro}</Alert>
                    <div className="flex flex-wrap justify-end gap-3">
                        {linha.foto.pode_excluir && (
                            <Button type="button" variant="outline" disabled={ocupado} onClick={excluir}>
                                <span className="material-symbols-outlined text-[20px]">delete</span>
                                Excluir
                            </Button>
                        )}
                        <Button type="button" loading={ocupado} onClick={reservar}>
                            <span className="material-symbols-outlined text-[20px]">photo_camera</span>
                            Substituir fotografia
                        </Button>
                    </div>
                </Dialogo>
            )}

            {dialogoConfirmar}
        </AppShell>
    );
}
