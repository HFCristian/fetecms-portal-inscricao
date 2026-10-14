import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, useConfirm } from '../components/ui.jsx';
import FiltroRecomendacoes, { SEM_RECORTE } from '../components/FiltroRecomendacoes.jsx';
import CartaoCredencial, { PilulaTipo } from '../components/CartaoCredencial.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getPremiacaoComite, premiarProjeto, retirarPremiacao, baixarPremiacaoComite,
} from '../lib/comite.js';
import { decidirRecomendacao } from '../lib/recomendacoes.js';

/** Os projetos premiados, cada um com o que recebeu — a mesma base vista pelo outro lado. */
function porProjeto(credenciais) {
    const mapa = new Map();

    for (const c of credenciais) {
        for (const p of c.projetos) {
            if (!mapa.has(p.id)) mapa.set(p.id, { ...p, recebidas: [] });
            mapa.get(p.id).recebidas.push({ id: c.id, nome: c.nome, tipo: c.tipo, tipo_label: c.tipo_label });
        }
    }

    return [...mapa.values()].sort((a, b) => a.titulo.localeCompare(b.titulo, 'pt-BR'));
}

/**
 * Comitê especial → **Premiação** (Sprint 190).
 *
 * As credenciais e os prêmios que a feira tem para dar, e em que projeto cada
 * um foi parar. A base é a de Avaliação presencial → Credenciais e Prêmios: o
 * que se anexa aqui aparece lá, no cerimonial e na lista de premiação, que
 * sai daqui no mesmo formato. O cadastro das credenciais continua lá — aqui só
 * se correlaciona.
 */
export default function ComitePremiacao() {
    const [credenciais, setCredenciais] = useState(null);
    const [candidatos, setCandidatos] = useState([]);
    const [ocupado, setOcupado] = useState(false);
    // O recorte das recomendações do comitê dentro dos cartões (Sprint 201).
    const [recorte, setRecorte] = useState(SEM_RECORTE);
    const [resumoRecomendacoes, setResumoRecomendacoes] = useState(null);
    const [confirmar, dialogoConfirmacao] = useConfirm();
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback(() => getPremiacaoComite(recorte)
        .then((r) => {
            setCredenciais(r.data);
            setCandidatos(r.meta?.candidatos ?? []);
            setResumoRecomendacoes(r.meta?.recomendacoes ?? null);
        })
        .catch(() => { setCredenciais([]); setAlerta('Não foi possível carregar as credenciais e prêmios.'); }), [recorte]);

    useEffect(() => { carregar(); }, [carregar]);

    async function acao(fn, mensagem) {
        setOcupado(true); setAlerta(''); setSucesso('');
        try {
            await fn();
            await carregar();
            setSucesso(mensagem);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
        } finally {
            setOcupado(false);
        }
    }

    async function baixar() {
        setAlerta('');
        try {
            await baixarPremiacaoComite();
        } catch {
            setAlerta('Não foi possível baixar a lista de premiação.');
        }
    }

    const premiados = useMemo(() => porProjeto(credenciais ?? []), [credenciais]);

    return (
        <AppShell>
            <Link to="/admin/comite" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Comitê especial
            </Link>
            <div className="flex flex-wrap items-start justify-between gap-3 mb-1 max-w-3xl">
                <h1 className="font-display text-2xl font-semibold text-primary">Premiação</h1>
                <div className="flex flex-wrap gap-2">
                    <Link
                        to="/admin/comite/premiacao/painel"
                        className="inline-flex items-center justify-center gap-2 rounded-lg px-5 py-2.5 font-semibold bg-primary-container text-on-primary hover:bg-primary transition-colors"
                    >
                        <span className="material-symbols-outlined text-[20px]">leaderboard</span>
                        Painel da premiação
                    </Link>
                    <Button type="button" variant="outline" onClick={baixar}>
                        <span className="material-symbols-outlined text-[20px]">download</span>
                        Lista de premiação (TXT)
                    </Button>
                </div>
            </div>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Diga que projeto recebeu cada <strong>credencial</strong> ou <strong>prêmio</strong> da
                feira. Só finalistas podem ser premiados. O que você anexar aqui vale em todo o portal —
                no cerimonial e na lista de premiação. O cadastro das credenciais fica em Avaliação
                presencial → Credenciais e Prêmios.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            <FiltroRecomendacoes
                valor={recorte}
                onChange={setRecorte}
                opcoes={resumoRecomendacoes?.opcoes}
                pendentes={resumoRecomendacoes?.pendentes ?? 0}
            />

            {credenciais === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : credenciais.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhuma credencial ou prêmio ativo nesta edição. O cadastro é feito em Avaliação
                    presencial → Credenciais e Prêmios.
                </div>
            ) : (
                <>
                    {candidatos.length === 0 && (
                        <div className="mb-4 max-w-3xl">
                            <Alert type="info">
                                Não há lista final ativa: sem finalistas, não há projeto para premiar.
                            </Alert>
                        </div>
                    )}

                    <p className="text-sm text-on-surface-variant mb-3">
                        {credenciais.length} credencial(is) e prêmio(s) · {premiados.length} projeto(s) premiado(s)
                    </p>

                    <ul className="space-y-3 max-w-3xl">
                        {credenciais.map((c) => (
                            <CartaoCredencial
                                key={c.id}
                                credencial={c}
                                candidatos={candidatos}
                                ocupado={ocupado}
                                rotuloAnexar="Premiar"
                                onAtribuir={(cred, projeto) => acao(
                                    () => premiarProjeto(cred.id, projeto.id),
                                    `${projeto.nome} premiado com ${cred.nome}.`,
                                )}
                                onRetirar={(cred, projeto) => acao(
                                    () => retirarPremiacao(cred.id, projeto.id),
                                    `${cred.nome} retirado de ${projeto.titulo}.`,
                                )}
                                onDecidir={(ids, status, r) => acao(
                                    () => Promise.all(ids.map((id) => decidirRecomendacao(id, status))),
                                    `Recomendação de ${r.projeto} ${status === 'aprovada' ? 'aprovada' : 'rejeitada'}.`,
                                )}
                                onCancelarPremiacao={async (cred, projeto) => {
                                    const ok = await confirmar({
                                        title: 'Cancelar premiação',
                                        message: `${cred.nome} sai de ${projeto.titulo} e a vaga volta. A recomendação continua aprovada.`,
                                        confirmLabel: 'Cancelar premiação',
                                        cancelLabel: 'Voltar',
                                        danger: true,
                                    });
                                    if (ok) {
                                        acao(() => retirarPremiacao(cred.id, projeto.id), `Premiação de ${projeto.titulo} cancelada.`);
                                    }
                                }}
                            />
                        ))}
                    </ul>

                    {premiados.length > 0 && (
                        <section className="mt-8 max-w-3xl" aria-label="Projetos premiados">
                            <h2 className="font-display text-lg font-semibold text-on-surface mb-2">Projetos premiados</h2>
                            <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/30">
                                {premiados.map((p) => (
                                    <li key={p.id} className="p-3">
                                        <p className="text-sm font-medium text-on-surface">{p.titulo}</p>
                                        <p className="text-xs text-on-surface-variant">
                                            {[p.categoria, p.area].filter(Boolean).join(' · ')}
                                        </p>
                                        <div className="mt-1 flex flex-wrap gap-1.5">
                                            {p.recebidas.map((r) => (
                                                <span key={r.id} className="inline-flex items-center gap-1 text-xs text-on-surface">
                                                    <PilulaTipo tipo={r.tipo} label={r.tipo_label} />
                                                    {r.nome}
                                                </span>
                                            ))}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </>
            )}
            {dialogoConfirmacao}
        </AppShell>
    );
}
