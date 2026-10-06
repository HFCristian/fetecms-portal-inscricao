import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getDistribuicaoPresencial, salvarDistribuicaoPresencial, definirTurnosAvaliador,
    distribuirPresencial, redistribuirPresencial, designarPresencial, retirarDesignacaoPresencial,
} from '../lib/presencial.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const cartao = 'bg-surface-container-lowest rounded-xl fetec-card-shadow p-4';

const SITUACAO = {
    em_andamento: ['Acontecendo agora', 'bg-secondary-container text-on-secondary-container'],
    margem: ['Nos 30 minutos de margem', 'bg-tertiary-container text-on-tertiary-container'],
    futuro: ['Ainda não começou', 'bg-surface-variant text-on-surface-variant'],
    encerrado: ['Encerrado', 'bg-surface-variant text-on-surface-variant'],
};

const separar = (chave) => {
    const [dia, turno] = (chave ?? '').split('|');
    return dia && turno ? { dia, turno } : {};
};

/** Os horários e os dois números da distribuição. */
function Configuracao({ dados, salvando, onSalvar }) {
    const [horarios, setHorarios] = useState(() => ({
        A: { inicio: dados.horarios?.A?.inicio ?? '', fim: dados.horarios?.A?.fim ?? '' },
        B: { inicio: dados.horarios?.B?.inicio ?? '', fim: dados.horarios?.B?.fim ?? '' },
    }));
    const [fila, setFila] = useState(String(dados.fila_avaliador ?? ''));
    const [porProjeto, setPorProjeto] = useState(String(dados.por_projeto ?? ''));

    const mudar = (turno, ponta, valor) => setHorarios((h) => ({ ...h, [turno]: { ...h[turno], [ponta]: valor } }));

    return (
        <section className={cartao}>
            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Turnos e números</h2>
            <p className="text-sm text-on-surface-variant mb-3">
                O horário de cada turno vale em todos os dias do evento
                {dados.evento_de_label ? ` (${dados.evento_de_label} a ${dados.evento_ate_label ?? dados.evento_de_label})` : ''}.
                Cada avaliação aceita escrita até o fim do turno mais {dados.margem_minutos} minutos.
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
                {(dados.turnos ?? []).map((t) => (
                    <fieldset key={t.value} className="border border-outline-variant/40 rounded-lg p-3">
                        <legend className="text-sm font-semibold text-on-surface px-1">{t.label}</legend>
                        <div className="flex gap-2">
                            <label className="flex-1 text-xs text-on-surface-variant">
                                Início
                                <input type="time" className={campoClass} aria-label={`Início do ${t.label}`}
                                    value={horarios[t.value]?.inicio ?? ''} onChange={(e) => mudar(t.value, 'inicio', e.target.value)} />
                            </label>
                            <label className="flex-1 text-xs text-on-surface-variant">
                                Fim
                                <input type="time" className={campoClass} aria-label={`Fim do ${t.label}`}
                                    value={horarios[t.value]?.fim ?? ''} onChange={(e) => mudar(t.value, 'fim', e.target.value)} />
                            </label>
                        </div>
                    </fieldset>
                ))}
                <label className="text-sm text-on-surface">
                    Projetos por avaliador, por turno
                    <input type="number" min="1" max="50" className={campoClass} aria-label="Projetos por avaliador"
                        value={fila} onChange={(e) => setFila(e.target.value)} />
                </label>
                <label className="text-sm text-on-surface">
                    Avaliações por projeto
                    <input type="number" min="1" max="20" className={campoClass} aria-label="Avaliações por projeto"
                        value={porProjeto} onChange={(e) => setPorProjeto(e.target.value)} />
                </label>
            </div>
            <div className="flex justify-end mt-3">
                <Button type="button" loading={salvando} onClick={() => onSalvar({
                    horarios,
                    fila_avaliador: fila === '' ? null : Number(fila),
                    por_projeto: porProjeto === '' ? null : Number(porProjeto),
                })}>
                    Salvar
                </Button>
            </div>
        </section>
    );
}

/** Pré-ativar: os turnos da agenda em que o avaliador vai trabalhar. */
function DialogoTurnos({ avaliador, ocorrencias, salvando, onSalvar, onFechar }) {
    const [marcados, setMarcados] = useState(() => new Set(avaliador.turnos));
    const alternar = (chave) => setMarcados((m) => {
        const novo = new Set(m);
        if (novo.has(chave)) novo.delete(chave); else novo.add(chave);
        return novo;
    });

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Turnos de {avaliador.nome}</h3>
                    <p className="text-sm text-on-surface-variant">Marque os turnos em que ele vai avaliar.</p>
                </div>
                <ul className="space-y-2 max-h-72 overflow-y-auto">
                    {ocorrencias.map((o) => (
                        <li key={o.chave}>
                            <label className="flex items-center gap-2 text-sm text-on-surface">
                                <input type="checkbox" checked={marcados.has(o.chave)} onChange={() => alternar(o.chave)}
                                    disabled={o.situacao === 'encerrado'} />
                                {o.rotulo}
                                {o.situacao === 'encerrado' && <span className="text-xs text-on-surface-variant">(encerrado)</span>}
                            </label>
                        </li>
                    ))}
                </ul>
                <div className="flex justify-end gap-3">
                    <Button type="button" variant="outline" onClick={onFechar} disabled={salvando}>Cancelar</Button>
                    <Button type="button" loading={salvando} onClick={() => onSalvar([...marcados])}>Salvar turnos</Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Avaliação presencial → **Distribuição** (Sprints 169–170).
 *
 * A tela trabalha um turno de um dia por vez. Primeiro a cabine: quem chegou é
 * ativado no turno (ou pré-ativado nos seguintes). Depois a distribuição, nos
 * moldes da online, só com os projetos do turno já credenciados e com o
 * estande checado. Por fim, as designações, com a retirada e a designação à
 * mão.
 */
export default function PresencialDistribuicao() {
    const [dados, setDados] = useState(null);
    const [chave, setChave] = useState('');
    const [busca, setBusca] = useState('');
    const [modoTeste, setModoTeste] = useState(false);
    const [salvando, setSalvando] = useState('');
    const [alert, setAlert] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [avisos, setAvisos] = useState([]);
    const [turnosDe, setTurnosDe] = useState(null);
    const [manual, setManual] = useState({ projeto: '', avaliador: '' });
    const [confirm, dialogoConfirmacao] = useConfirm();

    const carregar = useCallback(() => getDistribuicaoPresencial({ ...separar(chave), busca }, modoTeste)
        .then((d) => { setDados(d); if (!chave && d.foco) setChave(d.foco.chave); })
        .catch((e) => setAlert(extractErrors(e).message || 'Não foi possível carregar a distribuição.')), [chave, busca, modoTeste]);

    useEffect(() => { carregar(); }, [carregar]);

    const ocorrencia = separar(chave || dados?.foco?.chave);

    async function executar(nome, fn) {
        setSalvando(nome); setAlert(''); setSucesso(''); setAvisos([]);
        try {
            const resp = await fn();
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? '');
            setAvisos(resp.meta?.resultado?.ignoradas ?? []);
            return true;
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlert(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
            return false;
        } finally {
            setSalvando('');
        }
    }

    async function redistribuir() {
        const ok = await confirm({
            title: 'Redistribuir este turno?',
            message: 'O que foi distribuído e ainda não foi aberto volta ao bolo e é sorteado de novo. O que está em avaliação, o enviado e o designado à mão não se mexem.',
            confirmLabel: 'Redistribuir',
        });
        if (ok) executar('redistribuir', () => redistribuirPresencial(ocorrencia, modoTeste));
    }

    function alternarAtivo(a) {
        const turnos = a.ativo ? a.turnos.filter((t) => t !== chave) : [...a.turnos, chave];
        executar(`ativar-${a.id}`, () => definirTurnosAvaliador(a.id, turnos, ocorrencia, modoTeste));
    }

    const foco = dados?.ocorrencias?.find((o) => o.chave === chave) ?? dados?.foco;
    const resumo = dados?.resumo;
    const ativos = (dados?.avaliadores ?? []).filter((a) => a.ativo);
    const prontos = (dados?.projetos ?? []).filter((p) => p.pronto);

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Distribuição presencial</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Ative na cabine quem chegou para avaliar, distribua os projetos do turno e acompanhe as
                designações. Só entram projetos <strong>credenciados</strong> e com o estande{' '}
                <strong>checado</strong>.
            </p>

            {dados?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={(v) => { setModoTeste(v); setChave(''); }}
                        label="Modo de teste"
                        description="Conta demo: usa a lista final de demonstração e só os avaliadores demo."
                    />
                </div>
            )}

            <div className="max-w-4xl space-y-3 mb-4">
                <Alert>{alert}</Alert>
                <Alert type="info">{sucesso}</Alert>
                {avisos.length > 0 && (
                    <Alert type="info">
                        <ul className="list-disc pl-5">{avisos.map((a) => <li key={a}>{a}</li>)}</ul>
                    </Alert>
                )}
            </div>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="max-w-4xl space-y-4">
                    <Configuracao
                        key={JSON.stringify([dados.horarios, dados.fila_avaliador, dados.por_projeto])}
                        dados={dados}
                        salvando={salvando === 'config'}
                        onSalvar={(c) => executar('config', () => salvarDistribuicaoPresencial(c, ocorrencia, modoTeste))}
                    />

                    {!dados.configurada ? (
                        <Alert type="info">
                            Defina o período do evento (Parametrização → Datas e períodos) e o horário de ao menos um
                            turno para montar a agenda.
                        </Alert>
                    ) : !dados.lista ? (
                        <Alert type="info">A lista final ainda não foi gerada: sem finalistas, não há o que distribuir.</Alert>
                    ) : (
                        <>
                            <section className={cartao}>
                                <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="ocorrencia">Turno</label>
                                <div className="flex flex-wrap items-center gap-3">
                                    <select id="ocorrencia" className={`${campoClass} sm:w-96`} value={chave} onChange={(e) => setChave(e.target.value)}>
                                        {dados.ocorrencias.map((o) => <option key={o.chave} value={o.chave}>{o.rotulo}</option>)}
                                    </select>
                                    {foco && SITUACAO[foco.situacao] && (
                                        <span className={`text-xs font-semibold px-2 py-1 rounded-full ${SITUACAO[foco.situacao][1]}`}>
                                            {SITUACAO[foco.situacao][0]}
                                        </span>
                                    )}
                                </div>
                                {foco?.prazo_label && (
                                    <p className="text-xs text-on-surface-variant mt-2">
                                        As avaliações deste turno são aceitas até as {foco.prazo_label} ({foco.fim_label} + {dados.margem_minutos} min).
                                    </p>
                                )}
                            </section>

                            <section className={cartao}>
                                <div className="flex flex-wrap items-center justify-between gap-2 mb-2">
                                    <h2 className="font-display text-lg font-semibold text-on-surface">Avaliadores na cabine</h2>
                                    <span className="text-sm text-on-surface-variant">{ativos.length} ativado(s) neste turno</span>
                                </div>
                                <input className={`${campoClass} mb-3`} placeholder="Buscar avaliador por nome ou e-mail…" aria-label="Buscar avaliador"
                                    value={busca} onChange={(e) => setBusca(e.target.value)} />
                                {dados.avaliadores.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">Ninguém confirmou a avaliação presencial{busca ? ' neste recorte' : ''}.</p>
                                ) : (
                                    <ul className="divide-y divide-outline-variant/30">
                                        {dados.avaliadores.map((a) => (
                                            <li key={a.id} className="py-2 flex flex-wrap items-center gap-3">
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-semibold text-on-surface truncate">{a.nome}</p>
                                                    <p className="text-xs text-on-surface-variant truncate">
                                                        {[a.area, a.subarea].filter(Boolean).join(' · ') || 'Sem área'}
                                                        {` · ${a.no_turno} neste turno · ${a.turnos.length} turno(s) ativado(s)`}
                                                    </p>
                                                </div>
                                                <Button type="button" variant="outline" onClick={() => setTurnosDe(a)} aria-label={`Turnos de ${a.nome}`}>
                                                    <span className="material-symbols-outlined text-[18px]">event_available</span>
                                                    Turnos
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant={a.ativo ? 'primary' : 'outline'}
                                                    loading={salvando === `ativar-${a.id}`}
                                                    disabled={foco?.situacao === 'encerrado'}
                                                    onClick={() => alternarAtivo(a)}
                                                    aria-label={`${a.ativo ? 'Desativar' : 'Ativar'} ${a.nome} neste turno`}
                                                >
                                                    <span className="material-symbols-outlined text-[18px]">{a.ativo ? 'toggle_on' : 'toggle_off'}</span>
                                                    {a.ativo ? 'Ativado' : 'Ativar'}
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>

                            <section className={cartao}>
                                <h2 className="font-display text-lg font-semibold text-on-surface mb-2">Distribuição do turno</h2>
                                {resumo && (
                                    <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-3">
                                        {[
                                            ['Projetos do turno', resumo.projetos],
                                            ['Prontos', resumo.prontos],
                                            ['Sem credenciamento', resumo.sem_credenciamento],
                                            ['Sem checagem', resumo.sem_checagem],
                                            [`Com ${resumo.teto} avaliações`, resumo.cobertos],
                                        ].map(([rotulo, valor]) => (
                                            <div key={rotulo} className="rounded-lg border border-outline-variant/40 p-2 text-center">
                                                <div className="text-xl font-bold text-primary">{valor}</div>
                                                <div className="text-xs text-on-surface-variant">{rotulo}</div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <p className="text-xs text-on-surface-variant mb-3">
                                    Cada avaliador ativado recebe até {dados.fila_avaliador} projeto(s), em rodadas iguais, pela
                                    prioridade subárea → área → área correlata. Ao enviar uma avaliação, a fila dele é reposta.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" loading={salvando === 'distribuir'} disabled={foco?.situacao === 'encerrado'}
                                        onClick={() => executar('distribuir', () => distribuirPresencial(ocorrencia, modoTeste))}>
                                        <span className="material-symbols-outlined text-[20px]">shuffle</span>
                                        Distribuir
                                    </Button>
                                    <Button type="button" variant="outline" loading={salvando === 'redistribuir'} disabled={foco?.situacao === 'encerrado'}
                                        onClick={redistribuir}>
                                        <span className="material-symbols-outlined text-[20px]">restart_alt</span>
                                        Redistribuir
                                    </Button>
                                </div>
                            </section>

                            <section className={cartao}>
                                <h2 className="font-display text-lg font-semibold text-on-surface mb-2">Designações do turno</h2>
                                <div className="flex flex-col sm:flex-row gap-2 mb-3">
                                    <select className={campoClass} aria-label="Projeto para designar" value={manual.projeto}
                                        onChange={(e) => setManual((m) => ({ ...m, projeto: e.target.value }))}>
                                        <option value="">Projeto pronto…</option>
                                        {prontos.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.estande ? `${p.estande} · ` : ''}{p.titulo} ({p.avaliacoes})
                                            </option>
                                        ))}
                                    </select>
                                    <select className={campoClass} aria-label="Avaliador para designar" value={manual.avaliador}
                                        onChange={(e) => setManual((m) => ({ ...m, avaliador: e.target.value }))}>
                                        <option value="">Avaliador ativado…</option>
                                        {ativos.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                                    </select>
                                    <Button type="button" className="shrink-0" disabled={!manual.projeto || !manual.avaliador}
                                        loading={salvando === 'designar'}
                                        onClick={async () => {
                                            const ok = await executar('designar', () => designarPresencial(
                                                ocorrencia, [Number(manual.projeto)], [Number(manual.avaliador)], modoTeste,
                                            ));
                                            if (ok) setManual({ projeto: '', avaliador: '' });
                                        }}>
                                        Designar
                                    </Button>
                                </div>
                                {(dados.designacoes ?? []).length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">Nenhuma designação neste turno.</p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="text-left text-xs text-on-surface-variant border-b border-outline-variant/40">
                                                    <th className="py-2 pr-2">Estande</th>
                                                    <th className="py-2 pr-2">Projeto</th>
                                                    <th className="py-2 pr-2">Avaliador</th>
                                                    <th className="py-2 pr-2">Situação</th>
                                                    <th className="py-2" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {dados.designacoes.map((d) => (
                                                    <tr key={d.id} className="border-b border-outline-variant/20 last:border-0">
                                                        <td className="py-2 pr-2 tabular-nums">{d.estande ?? '—'}</td>
                                                        <td className="py-2 pr-2">{d.projeto}</td>
                                                        <td className="py-2 pr-2">
                                                            {d.avaliador}
                                                            {d.designacao_manual && <span className="ml-1 text-xs text-on-surface-variant">(à mão)</span>}
                                                        </td>
                                                        <td className="py-2 pr-2">{d.status_label}</td>
                                                        <td className="py-2 text-right">
                                                            {d.status !== 'concluida' && (
                                                                <button type="button" className="text-error text-xs font-semibold hover:underline"
                                                                    aria-label={`Retirar ${d.projeto} de ${d.avaliador}`}
                                                                    onClick={() => executar(`retirar-${d.id}`, () => retirarDesignacaoPresencial(d.id, ocorrencia, modoTeste))}>
                                                                    Retirar
                                                                </button>
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>
                        </>
                    )}
                </div>
            )}

            {turnosDe && (
                <DialogoTurnos
                    avaliador={turnosDe}
                    ocorrencias={dados?.ocorrencias ?? []}
                    salvando={salvando === 'turnos'}
                    onFechar={() => setTurnosDe(null)}
                    onSalvar={async (turnos) => {
                        if (await executar('turnos', () => definirTurnosAvaliador(turnosDe.id, turnos, ocorrencia, modoTeste))) {
                            setTurnosDe(null);
                        }
                    }}
                />
            )}
            {dialogoConfirmacao}
        </AppShell>
    );
}
