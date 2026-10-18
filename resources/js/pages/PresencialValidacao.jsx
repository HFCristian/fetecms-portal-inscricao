import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input, Toggle, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getValidacaoPresencial, liberarAvaliadoresPresencial, revogarLiberacaoPresencial,
} from '../lib/presencial.js';

const cartao = 'bg-surface-container-lowest rounded-xl fetec-card-shadow p-4';

const SITUACOES = [
    { value: 'todos', label: 'Todos' },
    { value: 'aguardando', label: 'Aguardando liberação' },
    { value: 'liberados', label: 'Liberados' },
];

/**
 * Avaliação presencial → **Validação de avaliadores** (Sprint 223).
 *
 * Quem confirmou o presencial — o avaliador online que disse "sim" e a conta
 * criada em Cadastro de avaliadores — só avalia no estande e só entra na
 * distribuição depois de **liberado** aqui. A liberação vale para o evento
 * inteiro; revogar devolve ao bolo o que ainda estava só designado para ele.
 */
export default function PresencialValidacao() {
    const [dados, setDados] = useState(null);
    const [busca, setBusca] = useState('');
    // O termo aplicado: a busca vai por submit, não a cada tecla.
    const [termo, setTermo] = useState('');
    const [situacao, setSituacao] = useState('aguardando');
    const [modoTeste, setModoTeste] = useState(false);
    const [marcados, setMarcados] = useState(() => new Set());
    const [salvando, setSalvando] = useState('');
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [avisos, setAvisos] = useState([]);
    const [confirm, dialogo] = useConfirm();

    const filtros = { busca: termo, situacao };

    const carregar = useCallback(() => getValidacaoPresencial({ busca: termo, situacao }, modoTeste)
        .then((d) => { setDados(d); setMarcados(new Set()); })
        .catch((e) => setAlerta(extractErrors(e).message || 'Não foi possível carregar os avaliadores.')), [termo, situacao, modoTeste]);

    useEffect(() => { carregar(); }, [carregar]);

    async function executar(nome, fn) {
        setSalvando(nome); setAlerta(''); setSucesso(''); setAvisos([]);
        try {
            const resp = await fn();
            setDados(resp.data);
            setMarcados(new Set());
            setSucesso(resp.meta?.message ?? '');
            setAvisos(resp.meta?.resultado?.ignorados ?? []);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
        } finally {
            setSalvando('');
        }
    }

    const liberar = (ids) => executar(ids.length === 1 ? `liberar-${ids[0]}` : 'lote', () => liberarAvaliadoresPresencial(ids, filtros, modoTeste));

    async function revogar(a) {
        const ok = await confirm({
            title: 'Revogar a liberação?',
            message: `${a.nome} deixa de avaliar no estande. O que estava só designado para ele volta ao bolo; o que ele já abriu fica em leitura.`,
            confirmLabel: 'Revogar',
        });
        if (ok) executar(`revogar-${a.id}`, () => revogarLiberacaoPresencial(a.id, filtros, modoTeste));
    }

    const alternar = (id) => setMarcados((m) => {
        const novo = new Set(m);
        if (novo.has(id)) novo.delete(id); else novo.add(id);
        return novo;
    });

    const avaliadores = dados?.avaliadores ?? [];
    const pendentes = avaliadores.filter((a) => !a.liberado);
    const todosMarcados = pendentes.length > 0 && pendentes.every((a) => marcados.has(a.id));
    const resumo = dados?.resumo;

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Validação de avaliadores</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Quem confirmou a avaliação presencial — inclusive as contas criadas em{' '}
                <Link to="/admin/presencial/avaliadores" className="underline">Cadastro de avaliadores</Link> — só avalia no
                estande e só recebe designação depois de <strong>liberado</strong> aqui. A liberação vale para o evento
                inteiro.
            </p>

            {dados?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Conta demo: mostra e libera só os avaliadores demo."
                    />
                </div>
            )}

            <div className="max-w-4xl space-y-3 mb-4">
                <Alert>{alerta}</Alert>
                <Alert type="info">{sucesso}</Alert>
                {avisos.length > 0 && (
                    <Alert type="info">
                        <ul className="list-disc pl-5">{avisos.map((a) => <li key={a}>{a}</li>)}</ul>
                    </Alert>
                )}
            </div>

            {resumo && (
                <div className="grid grid-cols-3 gap-3 mb-4 max-w-4xl">
                    {[
                        ['Confirmaram o presencial', resumo.total],
                        ['Liberados', resumo.liberados],
                        ['Aguardando liberação', resumo.aguardando],
                    ].map(([rotulo, valor]) => (
                        <div key={rotulo} className={`${cartao} text-center`}>
                            <div className="text-2xl font-bold text-primary">{valor}</div>
                            <div className="text-xs text-on-surface-variant">{rotulo}</div>
                        </div>
                    ))}
                </div>
            )}

            <section className={`${cartao} max-w-4xl`}>
                <div className="flex flex-wrap items-center gap-3 mb-3">
                    <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Situação">
                        {SITUACOES.map((s) => (
                            <button
                                key={s.value}
                                type="button"
                                role="radio"
                                aria-checked={situacao === s.value}
                                onClick={() => setSituacao(s.value)}
                                className={`px-3 py-1 rounded-full text-sm font-semibold border ${
                                    situacao === s.value
                                        ? 'bg-primary-container text-on-primary border-primary-container'
                                        : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                }`}
                            >
                                {s.label}
                            </button>
                        ))}
                    </div>
                    <form onSubmit={(e) => { e.preventDefault(); setTermo(busca.trim()); }} className="flex gap-2 ml-auto">
                        <Input aria-label="Buscar por nome ou e-mail" placeholder="Buscar por nome ou e-mail" value={busca} onChange={(e) => setBusca(e.target.value)} />
                        <Button type="submit" variant="outline">Buscar</Button>
                    </form>
                </div>

                {pendentes.length > 0 && (
                    <div className="flex flex-wrap items-center gap-3 mb-2 border-b border-outline-variant/40 pb-2">
                        <label className="flex items-center gap-2 text-sm text-on-surface">
                            <input
                                type="checkbox"
                                checked={todosMarcados}
                                onChange={() => setMarcados(todosMarcados ? new Set() : new Set(pendentes.map((a) => a.id)))}
                                aria-label="Marcar todos os que aguardam"
                            />
                            Marcar todos os que aguardam
                        </label>
                        <Button type="button" className="ml-auto" disabled={marcados.size === 0} loading={salvando === 'lote'}
                            onClick={() => liberar([...marcados])}>
                            <span className="material-symbols-outlined text-[18px]">how_to_reg</span>
                            Liberar marcados ({marcados.size})
                        </Button>
                    </div>
                )}

                {dados === null ? (
                    <div className="text-center py-8 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : avaliadores.length === 0 ? (
                    <p className="text-sm text-on-surface-variant py-4 text-center">
                        {situacao === 'aguardando' && !termo
                            ? 'Ninguém aguardando liberação.'
                            : 'Nenhum avaliador neste recorte.'}
                    </p>
                ) : (
                    <ul className="divide-y divide-outline-variant/30">
                        {avaliadores.map((a) => (
                            <li key={a.id} className="py-3 flex flex-wrap items-center gap-3">
                                {!a.liberado && (
                                    <input type="checkbox" checked={marcados.has(a.id)} onChange={() => alternar(a.id)} aria-label={`Marcar ${a.nome}`} />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-on-surface">
                                        {a.nome}
                                        {a.apenas_presencial && (
                                            <span className="ml-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-surface-variant text-on-surface-variant">Só presencial</span>
                                        )}
                                        {a.comissao && (
                                            <span className="ml-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">Comissão</span>
                                        )}
                                    </p>
                                    <p className="text-xs text-on-surface-variant break-all">{a.email}</p>
                                    <p className="text-xs text-on-surface-variant">
                                        {[a.area, a.subarea].filter(Boolean).join(' · ') || 'Sem área'}
                                        {` · ${a.avaliacoes} avaliação(ões) no estande`}
                                        {a.liberado && ` · liberado${a.liberado_por ? ` por ${a.liberado_por}` : ''}${a.liberado_em_label ? ` em ${a.liberado_em_label}` : ''}`}
                                    </p>
                                </div>
                                {a.liberado ? (
                                    <Button type="button" variant="outline" loading={salvando === `revogar-${a.id}`}
                                        onClick={() => revogar(a)} aria-label={`Revogar ${a.nome}`}>
                                        <span className="material-symbols-outlined text-[18px]">block</span>
                                        Revogar
                                    </Button>
                                ) : (
                                    <Button type="button" loading={salvando === `liberar-${a.id}`}
                                        onClick={() => liberar([a.id])} aria-label={`Liberar ${a.nome}`}>
                                        <span className="material-symbols-outlined text-[18px]">how_to_reg</span>
                                        Liberar
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            {dialogo}
        </AppShell>
    );
}
