import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getCheckinInterpretes,
    getAcompanhamentoInterpretes,
    registrarChegadaInterprete,
    desfazerChegadaInterprete,
    ocorrenciaDaChave,
} from '../lib/interpretes.js';
import SeletorOcorrencia from '../components/SeletorOcorrencia.jsx';

/** De quanto em quanto tempo o acompanhamento se recarrega sozinho. */
const POLLING_MS = 30000;

const FILTROS = [
    { value: 'faltando', label: 'Faltando' },
    { value: 'presentes', label: 'Chegaram' },
    { value: 'todos', label: 'Todos' },
];

const rotulo = (n) => String(n).padStart(3, '0');

/** Pede a justificativa de desfazer uma chegada. */
function DialogoDesfazer({ interprete, onConfirmar, onFechar }) {
    const [texto, setTexto] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState('');

    async function confirmar() {
        setEnviando(true);
        setErro('');
        try {
            await onConfirmar(texto.trim());
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível desfazer.');
            setEnviando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Desfazer chegada">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <h3 className="font-display text-xl font-semibold text-on-surface">Desfazer a chegada de {interprete.nome}</h3>
                <p className="text-sm text-on-surface-variant">A chegada sai do turno e o motivo fica em Registros → Credenciamento.</p>
                {erro && <Alert>{erro}</Alert>}
                <textarea
                    rows={3}
                    aria-label="Justificativa"
                    placeholder="Por que desfazer? (mínimo 5 caracteres)"
                    value={texto}
                    onChange={(e) => setTexto(e.target.value)}
                    className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container outline-none"
                />
                <div className="flex justify-end gap-3">
                    <Button variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button onClick={confirmar} loading={enviando} disabled={texto.trim().length < 5}>Desfazer</Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Avaliação presencial → Intérpretes → **Acompanhamento**: para o dia e o
 * turno escolhidos, os intérpretes que deviam estar (os que atendem projetos
 * que apresentam no turno), quem já chegou e quem falta — e quem chegou sem
 * estar designado para o turno. Atualiza sozinho a cada 30 segundos.
 */
export default function PresencialInterpretesAcompanhamento() {
    const [config, setConfig] = useState(null);
    const [chave, setChave] = useState(null);
    const [dados, setDados] = useState(null);
    const [filtro, setFiltro] = useState('faltando');
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [desfazendo, setDesfazendo] = useState(null);
    const [marcando, setMarcando] = useState(null);

    useEffect(() => {
        getCheckinInterpretes()
            .then((c) => { setConfig(c); setChave(c.em_foco); })
            .catch((e) => setAlerta(extractErrors(e).message || 'Não foi possível carregar.'));
    }, []);

    const ocorrencia = ocorrenciaDaChave(chave);

    const carregar = useCallback(() => {
        const o = ocorrenciaDaChave(chave);
        if (!o) return;
        getAcompanhamentoInterpretes(o)
            .then(setDados)
            .catch((e) => setAlerta(extractErrors(e).message || 'Não foi possível carregar o acompanhamento.'));
    }, [chave]);

    useEffect(() => {
        carregar();
        const id = setInterval(carregar, POLLING_MS);
        return () => clearInterval(id);
    }, [carregar]);

    async function marcar(i) {
        setMarcando(i.id);
        try {
            await registrarChegadaInterprete(i.id, ocorrencia);
            setSucesso(`Chegada de ${i.nome} registrada.`);
            setAlerta('');
            carregar();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível registrar.');
        } finally {
            setMarcando(null);
        }
    }

    async function desfazer(justificativa) {
        await desfazerChegadaInterprete(desfazendo.id, ocorrencia, justificativa);
        setSucesso(`Chegada de ${desfazendo.nome} desfeita.`);
        setDesfazendo(null);
        carregar();
    }

    const linhas = (dados?.interpretes ?? []).filter((i) =>
        filtro === 'todos' || (filtro === 'faltando' ? !i.presente : i.presente));
    const totais = dados?.totais;

    const cards = totais ? [
        { titulo: 'Esperados no turno', valor: totais.esperados },
        { titulo: 'Chegaram', valor: totais.presentes, destaque: 'text-secondary' },
        { titulo: 'Faltando', valor: totais.faltando, destaque: totais.faltando > 0 ? 'text-error' : '' },
        { titulo: 'Chegaram sem designação no turno', valor: totais.extras },
    ] : [];

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/presencial" className="hover:text-primary">Avaliação presencial</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <Link to="/admin/presencial/interpretes" className="hover:text-primary">Intérpretes</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Acompanhamento</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Acompanhamento dos intérpretes</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Quem devia estar no turno — os intérpretes dos projetos que apresentam nele —, quem já
                passou pelo check-in e quem falta. A tela se atualiza sozinha a cada 30 segundos.
            </p>

            {alerta && <div className="mb-4 max-w-4xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-4xl"><Alert type="info">{sucesso}</Alert></div>}

            <div className="max-w-md mb-4">
                <SeletorOcorrencia config={config} chave={chave} onTrocar={(c) => { setChave(c); setSucesso(''); setDados(null); }} />
            </div>

            {totais && (
                <div className="grid gap-3 grid-cols-2 lg:grid-cols-4 max-w-5xl mb-4">
                    {cards.map((c) => (
                        <div key={c.titulo} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                            <p className="text-xs text-on-surface-variant">{c.titulo}</p>
                            <p className={`font-display text-3xl font-semibold ${c.destaque || 'text-on-surface'}`}>{c.valor}</p>
                        </div>
                    ))}
                </div>
            )}

            {dados && (
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 max-w-5xl">
                    <div className="flex flex-wrap gap-2 mb-3" role="group" aria-label="Filtrar">
                        {FILTROS.map((f) => (
                            <button
                                key={f.value}
                                type="button"
                                aria-pressed={filtro === f.value}
                                onClick={() => setFiltro(f.value)}
                                className={`px-3 py-1 rounded-full text-xs font-semibold border ${
                                    filtro === f.value
                                        ? 'bg-primary-container text-on-primary border-primary-container'
                                        : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                }`}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>

                    {linhas.length === 0 ? (
                        <p className="text-sm text-on-surface-variant">
                            {filtro === 'faltando' ? 'Ninguém faltando neste turno.' : 'Nenhum intérprete nesta lista.'}
                        </p>
                    ) : (
                        <ul className="divide-y divide-outline-variant/30">
                            {linhas.map((i) => (
                                <li key={i.id} className="py-2 flex flex-wrap items-center gap-3">
                                    <span className={`material-symbols-outlined ${i.presente ? 'text-secondary' : 'text-error'}`} aria-hidden="true">
                                        {i.presente ? 'check_circle' : 'schedule'}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="text-sm font-medium text-on-surface block">{i.nome}</span>
                                        <span className="text-xs text-on-surface-variant block">
                                            {[
                                                i.tipos_labels.join(', '),
                                                i.estandes.length ? `Estande ${i.estandes.map(rotulo).join(', ')}` : null,
                                                i.esperado ? null : 'sem designação neste turno',
                                            ].filter(Boolean).join(' · ')}
                                        </span>
                                        {i.projetos.length > 0 && (
                                            <span className="text-xs text-on-surface-variant block">{i.projetos.join('; ')}</span>
                                        )}
                                        {i.presente && (
                                            <span className="text-xs text-secondary block">
                                                Chegou às {i.checkin_em}{i.registrado_por ? ` · por ${i.registrado_por}` : ''}
                                            </span>
                                        )}
                                    </span>
                                    {i.presente ? (
                                        <Button variant="outline" onClick={() => setDesfazendo(i)}>Desfazer</Button>
                                    ) : (
                                        <Button variant="outline" loading={marcando === i.id} onClick={() => marcar(i)}>Marcar chegada</Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}

            {desfazendo && (
                <DialogoDesfazer interprete={desfazendo} onConfirmar={desfazer} onFechar={() => setDesfazendo(null)} />
            )}
        </AppShell>
    );
}
