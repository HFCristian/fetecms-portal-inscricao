import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, Toggle, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getEstandes,
    salvarConfigEstandes,
    gerarEstandes,
    moverProjetoEstande,
    exportarEstandes,
} from '../lib/mapaEvento.js';

/**
 * Mapa do Evento → **Estandes dos Projetos**.
 *
 * Sabido o turno de cada projeto, aqui se diz **em que número** ele fica. A
 * regra é por categoria, e a faixa é escrita como se escreve no papel —
 * `1-4, 7-9, 10-52` —, porque é assim que a planta do ginásio é conversada.
 *
 * Duas listas saem de uma distribuição, uma por turno: o mesmo estande recebe um
 * projeto de manhã e outro à tarde. Depois, arrastar não: a troca é feita pelo
 * número, e mandar um projeto para um estande ocupado **troca os dois de
 * lugar** — que é o que a organização faz de fato ao remanejar um corredor.
 */
export default function MapaEstandes() {
    const [dados, setDados] = useState(null);
    const [config, setConfig] = useState(null);
    const [lista, setLista] = useState(null);
    const [avisos, setAvisos] = useState([]);
    const [movendo, setMovendo] = useState(null);   // { projeto_id, titulo, numero }
    const [destino, setDestino] = useState('');
    const [ocupado, setOcupado] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => {
        getEstandes()
            .then((d) => { setDados(d); setConfig(d.config); setLista(d.lista); })
            .catch(() => setAlerta('Não foi possível carregar os estandes.'));
    }, []);

    function falhar(e) {
        const { message, fields } = extractErrors(e);
        setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
        setSucesso('');
    }

    function alterarRegra(categoria, mudanca) {
        setConfig((c) => ({
            ...c,
            regras: { ...c.regras, [categoria]: { ...c.regras[categoria], ...mudanca } },
        }));
    }

    async function salvar() {
        setOcupado(true);
        try {
            const r = await salvarConfigEstandes(config);
            setConfig(r.data);
            setSucesso(r.meta?.message ?? 'Faixas salvas.');
            setAlerta('');
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    async function gerar() {
        if (lista?.gerada) {
            const manuais = Object.values(lista.turnos)
                .flatMap((t) => t.estandes)
                .filter((e) => e.manual).length;

            const ok = await confirm({
                title: 'Distribuir os estandes de novo',
                message: manuais > 0
                    ? `A distribuição atual será substituída — inclusive ${manuais} troca(s) que você fez à mão.`
                    : 'A distribuição atual será substituída. Só a última vale.',
                confirmLabel: 'Distribuir de novo',
                danger: true,
            });
            if (!ok) return;
        }

        setOcupado(true);
        try {
            const r = await gerarEstandes(config);
            setLista(r.data);
            setAvisos(r.meta?.avisos ?? []);
            setSucesso(r.meta?.message ?? 'Estandes distribuídos.');
            setAlerta('');
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    async function confirmarMovimento(ev) {
        ev.preventDefault();
        const numero = Number(destino);
        if (!movendo || !numero) return;

        setOcupado(true);
        try {
            const r = await moverProjetoEstande(movendo.projeto_id, numero);
            setLista(r.data);
            setSucesso(`"${movendo.titulo}" foi para o estande ${String(numero).padStart(3, '0')}.`);
            setAlerta('');
            setMovendo(null);
            setDestino('');
        } catch (e) { falhar(e); } finally { setOcupado(false); }
    }

    const categorias = dados?.categorias ?? [];

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/mapa" className="hover:text-primary">Mapa do Evento</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Estandes dos Projetos</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Estandes dos Projetos</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Distribui os projetos de cada turno pelos números de estande. Reserve uma faixa
                para cada categoria — <code className="text-sm">1-40, 61-70</code> — e quem não
                tiver faixa ocupa os números que sobraram. O mesmo estande recebe um projeto de
                manhã e outro à tarde.
            </p>

            {alerta && <div className="mb-4"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            {dados && !dados.turnos_gerados && (
                <div className="mb-4">
                    <Alert type="warning">
                        A lista de turnos ainda não foi gerada — é dela que sai quem apresenta em
                        cada horário. <Link to="/admin/mapa/turnos" className="underline">Gerar turnos</Link>.
                    </Alert>
                </div>
            )}

            {config && (
                <section className="space-y-3 mb-4">
                    {categorias.map((cat) => {
                        const regra = config.regras[cat.value];

                        return (
                            <div key={cat.value} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                                <Toggle
                                    checked={!!regra.ativa}
                                    onChange={(v) => alterarRegra(cat.value, { ativa: v })}
                                    label={cat.label}
                                    description={`${cat.total} projeto(s) nesta categoria entre os finalistas.`}
                                />

                                {regra.ativa && (
                                    <div className="mt-4 max-w-md">
                                        <Field
                                            label="Estandes desta categoria"
                                            hint="Números avulsos e intervalos: 1, 2, 3 ou 1-4, 7-9, 10-52."
                                        >
                                            <Input
                                                value={regra.faixa}
                                                onChange={(e) => alterarRegra(cat.value, { faixa: e.target.value })}
                                                placeholder="1-40, 61-70"
                                            />
                                        </Field>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </section>
            )}

            {config && (
                <div className="flex flex-wrap gap-3 mb-6">
                    <Button onClick={gerar} loading={ocupado} disabled={!dados?.turnos_gerados}>
                        {lista?.gerada ? 'Distribuir de novo' : 'Distribuir estandes'}
                    </Button>
                    <Button variant="outline" onClick={salvar} disabled={ocupado}>
                        Salvar faixas
                    </Button>
                </div>
            )}

            {avisos.length > 0 && (
                <div className="mb-4">
                    <Alert type="warning">
                        <ul className="list-disc pl-5 text-sm">
                            {avisos.map((a, i) => <li key={i}>{a.turno}: {a.motivo}</li>)}
                        </ul>
                    </Alert>
                </div>
            )}

            {lista?.gerada && (
                <>
                    <div className="flex flex-wrap items-center gap-3 mb-3">
                        <h2 className="font-display text-lg font-semibold text-on-surface mr-auto">
                            Distribuição em vigor — {lista.total} estande(s) ocupado(s)
                        </h2>
                        {['pdf', 'txt', 'csv'].map((f) => (
                            <Button key={f} variant="outline" onClick={() => exportarEstandes(f)}>
                                Exportar {f.toUpperCase()}
                            </Button>
                        ))}
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {Object.values(lista.turnos).map((turno) => (
                            <section key={turno.turno} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                                <h3 className="font-display font-semibold text-primary mb-3">
                                    {turno.label} — {turno.total} estande(s)
                                </h3>

                                {turno.estandes.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">Nenhum projeto neste turno.</p>
                                ) : (
                                    <ul className="divide-y divide-outline-variant/30">
                                        {turno.estandes.map((e) => (
                                            <li key={e.projeto_id} className="py-2 flex items-start gap-3">
                                                <span className="shrink-0 w-11 h-8 rounded-lg bg-primary-fixed text-primary-container text-sm font-semibold flex items-center justify-center">
                                                    {e.estande}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="text-sm font-medium text-on-surface block">{e.titulo}</span>
                                                    <span className="text-xs text-on-surface-variant block">
                                                        {[e.categoria, e.area, e.escola].filter(Boolean).join(' · ')}
                                                    </span>
                                                    {e.manual && (
                                                        <span className="text-xs text-primary-container block">Movido à mão</span>
                                                    )}
                                                </span>
                                                <button
                                                    type="button"
                                                    disabled={ocupado}
                                                    onClick={() => { setMovendo(e); setDestino(String(e.numero)); }}
                                                    aria-label={`Trocar o estande de ${e.titulo}`}
                                                    title="Trocar de estande"
                                                    className="shrink-0 w-8 h-8 rounded-lg border border-outline-variant/60 text-on-surface-variant hover:text-primary hover:border-primary-container flex items-center justify-center disabled:opacity-50"
                                                >
                                                    <span className="material-symbols-outlined text-[18px]">swap_horiz</span>
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

            {movendo && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <form
                        onSubmit={confirmarMovimento}
                        className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4"
                    >
                        <h3 className="font-display text-xl font-semibold text-on-surface">Trocar de estande</h3>
                        <p className="text-sm text-on-surface-variant">
                            <strong>{movendo.titulo}</strong> está no estande {movendo.estande}. Se o
                            número novo já tiver dono, os dois trocam de lugar.
                        </p>

                        <Field label="Novo número">
                            <Input
                                type="number"
                                min="1"
                                autoFocus
                                aria-label="Novo número"
                                value={destino}
                                onChange={(ev) => setDestino(ev.target.value)}
                            />
                        </Field>

                        <div className="flex justify-end gap-3">
                            <Button type="button" variant="outline" onClick={() => { setMovendo(null); setDestino(''); }}>
                                Cancelar
                            </Button>
                            <Button type="submit" loading={ocupado}>Trocar</Button>
                        </div>
                    </form>
                </div>
            )}

            {confirmDialog}
        </AppShell>
    );
}
