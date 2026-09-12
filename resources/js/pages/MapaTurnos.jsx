import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, Select, Toggle, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getTurnos,
    buscarFinalistas,
    salvarConfigTurnos,
    gerarTurnos,
    moverProjetoTurno,
    exportarTurnos,
} from '../lib/mapaEvento.js';

/**
 * Mapa do Evento → **Turnos de Apresentação**.
 *
 * A tela tem duas metades. Em cima, a **configuração**: quantos estandes cada
 * turno tem e as cinco regras, na ordem de prioridade — a primeira que alcança
 * um projeto decide o turno dele. Embaixo, a **lista gerada**, lado a lado, com
 * a troca manual de turno.
 *
 * As regras ficam uma abaixo da outra e são ligadas por interruptor porque é
 * assim que a organização pensa nelas: "este ano vamos usar só o vestibular e a
 * capital". Sem nenhuma ligada, a divisão é só o equilíbrio entre os dois
 * turnos — e isso é um resultado legítimo, não um erro.
 */

/** Uma regra com lista começa vazia; o nome é o que a identifica. */
const novaLista = (tipo) =>
    tipo === 'vestibular'
        ? { nome: '', turno: 'A', projetos: [] }
        : { nome: '', turno_indisponivel: 'A', origem: 'email', observacao: '', projetos: [] };

/** Busca de finalista por título ou por participante, com resultado embutido. */
function BuscaProjeto({ escolhidos, onEscolher }) {
    const [termo, setTermo] = useState('');
    const [resultados, setResultados] = useState([]);
    const [buscando, setBuscando] = useState(false);
    const timer = useRef(null);

    useEffect(() => {
        if (termo.trim().length < 2) {
            setResultados([]);
            return undefined;
        }

        setBuscando(true);
        clearTimeout(timer.current);
        // Espera a pessoa parar de digitar: a lista final tem centenas de
        // projetos e cada tecla iria ao servidor.
        timer.current = setTimeout(() => {
            buscarFinalistas(termo.trim())
                .then(setResultados)
                .catch(() => setResultados([]))
                .finally(() => setBuscando(false));
        }, 300);

        return () => clearTimeout(timer.current);
    }, [termo]);

    const disponiveis = resultados.filter((r) => !escolhidos.includes(r.id));

    return (
        <div>
            <Input
                value={termo}
                onChange={(e) => setTermo(e.target.value)}
                placeholder="Buscar por projeto ou participante…"
                aria-label="Buscar por projeto ou participante"
            />
            {buscando && <p className="text-xs text-on-surface-variant mt-1">Buscando…</p>}
            {termo.trim().length >= 2 && !buscando && disponiveis.length === 0 && (
                <p className="text-xs text-on-surface-variant mt-1">Nenhum finalista encontrado.</p>
            )}
            {disponiveis.length > 0 && (
                <ul className="mt-2 border border-outline-variant/50 rounded-lg divide-y divide-outline-variant/30 max-h-52 overflow-y-auto">
                    {disponiveis.map((r) => (
                        <li key={r.id}>
                            <button
                                type="button"
                                onClick={() => { onEscolher(r); setTermo(''); setResultados([]); }}
                                className="w-full text-left p-2 hover:bg-surface-container-low transition-colors"
                            >
                                <span className="text-sm font-medium text-on-surface">{r.titulo}</span>
                                <span className="block text-xs text-on-surface-variant">
                                    {[r.escola, r.cidade, r.pessoas.slice(0, 3).join(', ')].filter(Boolean).join(' · ')}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Os projetos já escolhidos numa lista de regra, removíveis um a um. */
function Escolhidos({ projetos, nomes, onRemover }) {
    if (projetos.length === 0) {
        return <p className="text-xs text-on-surface-variant mt-2">Nenhum projeto nesta lista ainda.</p>;
    }

    return (
        <ul className="flex flex-wrap gap-2 mt-2">
            {projetos.map((id) => (
                <li key={id} className="inline-flex items-center gap-1 bg-primary-fixed text-primary-container rounded-full pl-3 pr-1 py-1 text-xs">
                    <span>{nomes[id] ?? `Projeto #${id}`}</span>
                    <button
                        type="button"
                        onClick={() => onRemover(id)}
                        aria-label={`Remover ${nomes[id] ?? `projeto ${id}`}`}
                        className="w-5 h-5 rounded-full hover:bg-primary-container/20 flex items-center justify-center"
                    >
                        <span className="material-symbols-outlined text-[14px]">close</span>
                    </button>
                </li>
            ))}
        </ul>
    );
}

export default function MapaTurnos() {
    const [dados, setDados] = useState(null);
    const [config, setConfig] = useState(null);
    const [lista, setLista] = useState(null);
    const [nomes, setNomes] = useState({});      // projeto_id → título, para os chips
    const [realocados, setRealocados] = useState([]);
    const [ocupado, setOcupado] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => {
        getTurnos()
            .then((d) => {
                setDados(d);
                setConfig(d.config);
                setLista(d.lista);
                // Os chips precisam de título; a lista gerada já os traz.
                const mapa = {};
                Object.values(d.lista?.turnos ?? {}).forEach((t) =>
                    t.projetos.forEach((p) => { mapa[p.projeto_id] = p.titulo; }));
                setNomes(mapa);
            })
            .catch(() => setAlerta('Não foi possível carregar os turnos.'));
    }, []);

    function falhar(e) {
        const { message, fields } = extractErrors(e);
        const primeiro = Object.values(fields ?? {})[0];
        setAlerta(primeiro || message || 'Não foi possível concluir.');
        setSucesso('');
    }

    /** Mexe numa regra sem perder o resto da configuração. */
    function alterarRegra(chave, mudanca) {
        setConfig((c) => ({
            ...c,
            regras: { ...c.regras, [chave]: { ...c.regras[chave], ...mudanca } },
        }));
    }

    function alterarLista(chave, indice, mudanca) {
        setConfig((c) => {
            const listas = [...c.regras[chave].listas];
            listas[indice] = { ...listas[indice], ...mudanca };
            return { ...c, regras: { ...c.regras, [chave]: { ...c.regras[chave], listas } } };
        });
    }

    async function salvar() {
        setOcupado(true);
        try {
            const r = await salvarConfigTurnos(config);
            setConfig(r.data);
            setSucesso(r.meta?.message ?? 'Configuração salva.');
            setAlerta('');
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    async function gerar() {
        // Regerar apaga a lista anterior inteira, trocas manuais incluídas.
        const manuais = Object.values(lista?.turnos ?? {})
            .flatMap((t) => t.projetos)
            .filter((p) => p.manual).length;

        if (lista?.gerada) {
            const ok = await confirm({
                title: 'Gerar a lista de novo',
                message: manuais > 0
                    ? `A lista atual será substituída — inclusive ${manuais} projeto(s) que você moveu à mão.`
                    : 'A lista atual será substituída pela nova. Só a última vale.',
                confirmLabel: 'Gerar de novo',
                danger: true,
            });
            if (!ok) return;
        }

        setOcupado(true);
        try {
            const r = await gerarTurnos(config);
            setLista(r.data);
            setRealocados(r.meta?.realocados ?? []);
            setSucesso(r.meta?.message ?? 'Lista gerada.');
            setAlerta('');
            const mapa = {};
            Object.values(r.data.turnos).forEach((t) => t.projetos.forEach((p) => { mapa[p.projeto_id] = p.titulo; }));
            setNomes((n) => ({ ...n, ...mapa }));
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    async function mover(projeto, destino) {
        setOcupado(true);
        try {
            const r = await moverProjetoTurno(projeto.projeto_id, destino);
            setLista(r.data);
            setSucesso(`"${projeto.titulo}" foi para o ${destino === 'A' ? 'turno A' : 'turno B'}.`);
            setAlerta('');
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    const catalogo = dados?.catalogo ?? [];
    const turnosOpcoes = dados?.turnos ?? [];

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/mapa" className="hover:text-primary">Mapa do Evento</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Turnos de Apresentação</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Turnos de Apresentação</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Divide os finalistas entre o <strong>turno A (matutino)</strong> e o{' '}
                <strong>turno B (vespertino)</strong>. As regras valem na ordem abaixo: a primeira
                que alcança um projeto decide o turno dele, e o que sobra é repartido para os dois
                turnos ficarem equilibrados.
            </p>

            {alerta && <div className="mb-4"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            {dados && !dados.lista_final && (
                <div className="mb-4">
                    <Alert type="warning">
                        Não há lista final oficial vigente. Publique a lista em Avaliação online →
                        Ranking dos projetos antes de dividir os turnos.
                    </Alert>
                </div>
            )}

            {dados?.lista_final && (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4 text-sm">
                    <span className="text-on-surface-variant">Lista final vigente:</span>{' '}
                    <strong className="text-on-surface">{dados.lista_final.nome}</strong>{' '}
                    <span className="text-on-surface-variant">
                        (v{dados.lista_final.versao} · {dados.lista_final.projetos} projetos)
                    </span>
                    {dados.gerado_em && (
                        <span className="block text-xs text-on-surface-variant mt-1">
                            Última lista de turnos gerada em{' '}
                            {new Date(dados.gerado_em).toLocaleString('pt-BR')}
                            {dados.gerado_por ? ` por ${dados.gerado_por}` : ''}.
                        </span>
                    )}
                </div>
            )}

            {config && (
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-4">
                    <h2 className="font-display text-lg font-semibold text-on-surface mb-3">Estandes disponíveis</h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-md">
                        {turnosOpcoes.map((t) => (
                            <Field key={t.value} label={t.label}>
                                <Input
                                    type="number"
                                    min="0"
                                    value={config.capacidade[t.value]}
                                    onChange={(e) => setConfig((c) => ({
                                        ...c,
                                        capacidade: { ...c.capacidade, [t.value]: Number(e.target.value) },
                                    }))}
                                />
                            </Field>
                        ))}
                    </div>
                    <p className="text-xs text-on-surface-variant mt-2">
                        O mesmo estande recebe um projeto de manhã e outro à tarde. Se a lista final
                        não couber nos dois turnos somados, a geração é recusada.
                    </p>
                </section>
            )}

            {config && (
                <section className="space-y-3 mb-4">
                    {catalogo.map((regra, ordem) => {
                        const atual = config.regras[regra.value];
                        const comListas = regra.tipo === 'listas';

                        return (
                            <div key={regra.value} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                                <div className="flex items-start gap-3">
                                    <span className="w-7 h-7 rounded-full bg-primary-fixed text-primary-container text-sm font-semibold flex items-center justify-center shrink-0">
                                        {ordem + 1}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <Toggle
                                            checked={!!atual.ativa}
                                            onChange={(v) => alterarRegra(regra.value, { ativa: v })}
                                            label={regra.label}
                                            description={regra.descricao}
                                        />
                                    </div>
                                </div>

                                {atual.ativa && !comListas && (
                                    <div className="mt-4 pl-10 max-w-xs">
                                        <Field label="Turno destes projetos">
                                            <Select
                                                value={atual.turno}
                                                onChange={(e) => alterarRegra(regra.value, { turno: e.target.value })}
                                            >
                                                {turnosOpcoes.map((t) => (
                                                    <option key={t.value} value={t.value}>{t.label}</option>
                                                ))}
                                            </Select>
                                        </Field>
                                    </div>
                                )}

                                {atual.ativa && comListas && (
                                    <div className="mt-4 pl-10 space-y-4">
                                        {atual.listas.map((l, i) => (
                                            <div key={i} className="border border-outline-variant/50 rounded-lg p-4">
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                    <Field label={regra.value === 'vestibular' ? 'Qual vestibular' : 'Qual justificativa'}>
                                                        <Input
                                                            value={l.nome}
                                                            onChange={(e) => alterarLista(regra.value, i, { nome: e.target.value })}
                                                            placeholder={regra.value === 'vestibular' ? 'UFMS 2026' : 'Ônibus chega ao meio-dia'}
                                                        />
                                                    </Field>

                                                    {regra.value === 'vestibular' ? (
                                                        <Field label="Apresentam no turno">
                                                            <Select
                                                                value={l.turno}
                                                                onChange={(e) => alterarLista(regra.value, i, { turno: e.target.value })}
                                                            >
                                                                {turnosOpcoes.map((t) => (
                                                                    <option key={t.value} value={t.value}>{t.label}</option>
                                                                ))}
                                                            </Select>
                                                        </Field>
                                                    ) : (
                                                        <>
                                                            <Field label="Chegou por">
                                                                <Select
                                                                    value={l.origem}
                                                                    onChange={(e) => alterarLista(regra.value, i, { origem: e.target.value })}
                                                                >
                                                                    {(dados?.origens ?? []).map((o) => (
                                                                        <option key={o.value} value={o.value}>{o.label}</option>
                                                                    ))}
                                                                </Select>
                                                            </Field>
                                                            <Field
                                                                label="Não pode estar no turno"
                                                                hint="O projeto vai para o outro turno."
                                                            >
                                                                <Select
                                                                    value={l.turno_indisponivel}
                                                                    onChange={(e) => alterarLista(regra.value, i, { turno_indisponivel: e.target.value })}
                                                                >
                                                                    {turnosOpcoes.map((t) => (
                                                                        <option key={t.value} value={t.value}>{t.label}</option>
                                                                    ))}
                                                                </Select>
                                                            </Field>
                                                        </>
                                                    )}
                                                </div>

                                                <div className="mt-3">
                                                    <BuscaProjeto
                                                        escolhidos={l.projetos}
                                                        onEscolher={(p) => {
                                                            setNomes((n) => ({ ...n, [p.id]: p.titulo }));
                                                            alterarLista(regra.value, i, { projetos: [...l.projetos, p.id] });
                                                        }}
                                                    />
                                                    <Escolhidos
                                                        projetos={l.projetos}
                                                        nomes={nomes}
                                                        onRemover={(id) => alterarLista(regra.value, i, {
                                                            projetos: l.projetos.filter((x) => x !== id),
                                                        })}
                                                    />
                                                </div>

                                                <div className="mt-3 text-right">
                                                    <Button
                                                        variant="outline"
                                                        onClick={() => alterarRegra(regra.value, {
                                                            listas: atual.listas.filter((_, x) => x !== i),
                                                        })}
                                                    >
                                                        Remover {regra.value === 'vestibular' ? 'vestibular' : 'justificativa'}
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}

                                        <Button
                                            variant="outline"
                                            onClick={() => alterarRegra(regra.value, {
                                                listas: [...atual.listas, novaLista(regra.value)],
                                            })}
                                        >
                                            {regra.value === 'vestibular' ? 'Adicionar vestibular' : 'Adicionar justificativa'}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </section>
            )}

            {config && (
                <div className="flex flex-wrap gap-3 mb-6">
                    <Button onClick={gerar} loading={ocupado} disabled={!dados?.lista_final}>
                        {lista?.gerada ? 'Gerar lista de novo' : 'Gerar lista de turnos'}
                    </Button>
                    <Button variant="outline" onClick={salvar} disabled={ocupado}>
                        Salvar configuração
                    </Button>
                </div>
            )}

            {realocados.length > 0 && (
                <div className="mb-4">
                    <Alert type="warning">
                        <p className="font-semibold mb-1">
                            {realocados.length} projeto(s) não couberam no turno pedido pela regra:
                        </p>
                        <ul className="list-disc pl-5 text-sm">
                            {realocados.map((r, i) => (
                                <li key={i}>{r.projeto}: {r.de} → {r.para}</li>
                            ))}
                        </ul>
                    </Alert>
                </div>
            )}

            {lista?.gerada && (
                <>
                    <div className="flex flex-wrap items-center gap-3 mb-3">
                        <h2 className="font-display text-lg font-semibold text-on-surface mr-auto">
                            Lista em vigor — {lista.total} projeto(s)
                        </h2>
                        {['pdf', 'txt', 'csv'].map((f) => (
                            <Button key={f} variant="outline" onClick={() => exportarTurnos(f)}>
                                Exportar {f.toUpperCase()}
                            </Button>
                        ))}
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {Object.values(lista.turnos).map((turno) => (
                            <section key={turno.turno} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                                <h3 className="font-display font-semibold text-primary mb-3">
                                    {turno.label} — {turno.total} projeto(s)
                                </h3>

                                {turno.projetos.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">Nenhum projeto neste turno.</p>
                                ) : (
                                    <ul className="divide-y divide-outline-variant/30">
                                        {turno.projetos.map((p, i) => (
                                            <li key={p.projeto_id} className="py-2 flex items-start gap-3">
                                                <span className="text-xs text-on-surface-variant pt-1 w-8 shrink-0">
                                                    {String(i + 1).padStart(3, '0')}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="text-sm font-medium text-on-surface block">{p.titulo}</span>
                                                    <span className="text-xs text-on-surface-variant block">
                                                        {[p.categoria, p.area, p.escola].filter(Boolean).join(' · ')}
                                                    </span>
                                                    <span className="text-xs text-primary-container block">{p.regra_label}</span>
                                                </span>
                                                <button
                                                    type="button"
                                                    disabled={ocupado}
                                                    onClick={() => mover(p, turno.turno === 'A' ? 'B' : 'A')}
                                                    title={`Mover para o turno ${turno.turno === 'A' ? 'B' : 'A'}`}
                                                    aria-label={`Mover ${p.titulo} para o turno ${turno.turno === 'A' ? 'B' : 'A'}`}
                                                    className="shrink-0 w-8 h-8 rounded-lg border border-outline-variant/60 text-on-surface-variant hover:text-primary hover:border-primary-container flex items-center justify-center disabled:opacity-50"
                                                >
                                                    <span className="material-symbols-outlined text-[18px]">
                                                        {turno.turno === 'A' ? 'arrow_forward' : 'arrow_back'}
                                                    </span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        ))}
                    </div>
                </>
            )}

            {confirmDialog}
        </AppShell>
    );
}
