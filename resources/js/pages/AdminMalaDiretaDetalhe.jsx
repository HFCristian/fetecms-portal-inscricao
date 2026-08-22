import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    exportarMalaCsv,
    getMala,
    getMalaDestinatarios,
    reenviarFalhasMala,
} from '../lib/malaDireta.js';

const ESTILO_SITUACAO = {
    enviado: 'bg-secondary-container text-on-secondary-container',
    falha: 'bg-error-container text-on-error-container',
    invalido: 'bg-error-container text-on-error-container',
    pendente: 'bg-surface-variant text-on-surface-variant',
};

const ICONE_SITUACAO = {
    enviado: 'mark_email_read',
    falha: 'error',
    invalido: 'block',
    pendente: 'schedule',
};

function Cartao({ valor, rotulo, destaque = false }) {
    return (
        <div className={`rounded-xl px-4 py-3 ${destaque ? 'bg-error-container' : 'bg-surface-container-lowest fetec-card-shadow'}`}>
            <p className={`text-2xl font-display font-semibold leading-none ${destaque ? 'text-on-error-container' : 'text-primary'}`}>{valor}</p>
            <p className={`text-xs font-semibold mt-1 ${destaque ? 'text-on-error-container' : 'text-on-surface-variant'}`}>{rotulo}</p>
        </div>
    );
}

/** Barra de progresso do disparo — some quando a mala fecha. */
function Progresso({ totais }) {
    const pct = totais.total > 0 ? Math.round((totais.processados / totais.total) * 100) : 100;

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-4">
            <div className="flex items-center gap-2 mb-2">
                <span className="material-symbols-outlined text-primary-container animate-spin">progress_activity</span>
                <p className="font-semibold text-on-surface">Enviando as mensagens…</p>
                <span className="ml-auto text-sm font-semibold text-primary">{pct}%</span>
            </div>
            <div
                className="h-2 w-full rounded-full bg-surface-variant overflow-hidden"
                role="progressbar"
                aria-valuenow={pct}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label="Progresso do envio"
            >
                <div className="h-full bg-primary-container transition-all duration-500" style={{ width: `${pct}%` }} />
            </div>
            <p className="text-xs text-on-surface-variant mt-2">
                {totais.processados} de {totais.total} processados · {totais.enviado} enviados
                {totais.falha > 0 ? ` · ${totais.falha} com falha` : ''}
            </p>
            <p className="text-xs text-outline mt-1">
                Pode fechar esta tela: o envio continua no servidor.
            </p>
        </div>
    );
}

/**
 * Relatório de uma mala direta: progresso enquanto envia e, ao fim, a situação
 * de cada destinatário — com o motivo de quem falhou e reenvio das falhas.
 */
export default function AdminMalaDiretaDetalhe() {
    const { id } = useParams();
    const [confirmar, dialogo] = useConfirm();
    const [mala, setMala] = useState(null);
    const [situacao, setSituacao] = useState('');
    const [pagina, setPagina] = useState(1);
    const [lista, setLista] = useState(null);
    const [metaLista, setMetaLista] = useState(null);
    const [alert, setAlert] = useState('');
    const [aviso, setAviso] = useState('');
    const [exportando, setExportando] = useState(false);
    const [reenviando, setReenviando] = useState(false);
    const [verMensagem, setVerMensagem] = useState(false);

    const carregarMala = useCallback(
        () => getMala(id).then(setMala).catch((e) => setAlert(extractErrors(e).message)),
        [id],
    );

    const carregarLista = useCallback(() => {
        getMalaDestinatarios(id, { status: situacao || undefined, page: pagina })
            .then((resp) => { setLista(resp.data); setMetaLista(resp.meta); })
            .catch((e) => { setLista([]); setAlert(extractErrors(e).message); });
    }, [id, situacao, pagina]);

    useEffect(() => { carregarMala(); }, [carregarMala]);
    useEffect(() => { carregarLista(); }, [carregarLista]);

    // Enquanto envia, o progresso vem de polling curto.
    useEffect(() => {
        if (mala?.status !== 'enviando') return undefined;
        const t = setInterval(() => { carregarMala(); carregarLista(); }, 2000);
        return () => clearInterval(t);
    }, [mala?.status, carregarMala, carregarLista]);

    async function exportar() {
        setAlert('');
        setExportando(true);
        try {
            await exportarMalaCsv(id);
        } catch {
            setAlert('Não foi possível gerar o CSV. Tente novamente.');
        } finally {
            setExportando(false);
        }
    }

    async function reenviar() {
        const ok = await confirmar({
            title: 'Reenviar as falhas',
            message: `Vamos tentar de novo os ${mala.totais.falha} e-mail(s) que falharam. Os endereços inválidos continuam de fora.`,
            confirmLabel: 'Reenviar',
        });
        if (!ok) return;

        setAlert('');
        setReenviando(true);
        try {
            const resp = await reenviarFalhasMala(id);
            setMala(resp.data);
            setAviso(`${resp.meta.reenviados} e-mail(s) recolocado(s) na fila.`);
            carregarLista();
        } catch (e) {
            setAlert(extractErrors(e).message);
        } finally {
            setReenviando(false);
        }
    }

    if (!mala) {
        return (
            <AppShell>
                {alert ? <div className="max-w-4xl"><Alert>{alert}</Alert></div> : (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                )}
            </AppShell>
        );
    }

    const { totais } = mala;
    const problemas = totais.falha + totais.invalido;
    const data = mala.enviado_em ? new Date(mala.enviado_em) : null;

    return (
        <AppShell>
            <div className="mb-6">
                <Link to="/admin/mala-direta" className="text-sm font-semibold text-primary hover:underline inline-flex items-center gap-1">
                    <span className="material-symbols-outlined text-[18px]">arrow_back</span>
                    Voltar para as mensagens
                </Link>
                <h1 className="font-display text-2xl font-semibold text-primary mt-2 mb-1">{mala.nome}</h1>
                <p className="text-on-surface-variant">
                    {mala.assunto}
                    {data ? ` · enviada em ${data.toLocaleDateString('pt-BR')} às ${data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}` : ''}
                    {mala.autor_nome ? ` · por ${mala.autor_nome}` : ''}
                </p>
            </div>

            {alert && <div className="mb-4 max-w-4xl"><Alert>{alert}</Alert></div>}
            {aviso && <div className="mb-4 max-w-4xl"><Alert type="info">{aviso}</Alert></div>}

            <div className="max-w-4xl">
                {mala.status === 'enviando' && <Progresso totais={totais} />}

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                    <Cartao valor={totais.total} rotulo="destinatários" />
                    <Cartao valor={totais.enviado} rotulo="enviados" />
                    <Cartao valor={totais.falha} rotulo="falhas no envio" destaque={totais.falha > 0} />
                    <Cartao valor={totais.invalido} rotulo="e-mails inválidos" destaque={totais.invalido > 0} />
                </div>

                {/* Ficha interna da mala: justificativa, solicitante e a mensagem enviada */}
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-4">
                    <dl className="grid sm:grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt className="text-xs font-semibold text-on-surface-variant">Justificativa</dt>
                            <dd className="text-on-surface whitespace-pre-line">{mala.justificativa}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold text-on-surface-variant">Solicitante</dt>
                            <dd className="text-on-surface">{mala.solicitante || '—'}</dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-xs font-semibold text-on-surface-variant">Públicos</dt>
                            <dd className="text-on-surface">
                                {[
                                    ...(mala.publicos_labels ?? []),
                                    ...(mala.emails_personalizados > 0 ? [`Lista personalizada (${mala.emails_personalizados})`] : []),
                                ].join(' · ') || '—'}
                            </dd>
                        </div>
                    </dl>
                    <button
                        type="button"
                        onClick={() => setVerMensagem((v) => !v)}
                        className="text-sm font-semibold text-primary hover:underline mt-3 inline-flex items-center gap-1"
                    >
                        <span className="material-symbols-outlined text-[18px]">{verMensagem ? 'expand_less' : 'expand_more'}</span>
                        {verMensagem ? 'Ocultar a mensagem enviada' : 'Ver a mensagem enviada'}
                    </button>
                    {verMensagem && (
                        <div className="mt-3 rounded-lg bg-surface-variant/50 p-4 text-sm text-on-surface whitespace-pre-line">
                            {mala.corpo}
                        </div>
                    )}
                </div>

                {/* Relatório por destinatário */}
                <div className="flex flex-wrap items-center gap-2 mb-3">
                    <div className="flex flex-wrap gap-2">
                        {[{ value: '', label: 'Todos' }, ...(metaLista?.situacoes ?? [])].map((s) => {
                            const ativo = situacao === s.value;
                            return (
                                <button
                                    key={s.value || 'todos'}
                                    type="button"
                                    aria-pressed={ativo}
                                    onClick={() => { setSituacao(s.value); setPagina(1); }}
                                    className={`px-3 py-1.5 rounded-full text-sm font-semibold border transition-colors ${
                                        ativo
                                            ? 'bg-primary-container text-on-primary border-primary-container'
                                            : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                    }`}
                                >
                                    {s.label}
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex gap-2 ml-auto">
                        {totais.falha > 0 && (
                            <Button type="button" variant="outline" loading={reenviando} onClick={reenviar}>
                                <span className="material-symbols-outlined text-[20px]">refresh</span>
                                Reenviar falhas
                            </Button>
                        )}
                        <Button type="button" variant="outline" loading={exportando} onClick={exportar}>
                            <span className="material-symbols-outlined text-[20px]">download</span>
                            Exportar CSV
                        </Button>
                    </div>
                </div>

                {problemas > 0 && mala.status !== 'enviando' && (
                    <div className="mb-3">
                        <Alert>
                            {problemas} {problemas === 1 ? 'endereço não recebeu' : 'endereços não receberam'} a mensagem.
                            Filtre por “Falha no envio” ou “E-mail inválido” para ver o motivo de cada um.
                        </Alert>
                    </div>
                )}

                {lista === null ? (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : lista.length === 0 ? (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
                        Nenhum destinatário nesta situação.
                    </div>
                ) : (
                    <>
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 divide-y divide-outline-variant/30">
                            {lista.map((d) => (
                                <div key={d.id} className="flex flex-col sm:flex-row sm:items-center gap-2 py-3">
                                    <span className={`inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-full shrink-0 ${ESTILO_SITUACAO[d.status] ?? ''}`}>
                                        <span className="material-symbols-outlined text-[14px]">{ICONE_SITUACAO[d.status] ?? 'label'}</span>
                                        {d.status_label}
                                    </span>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm text-on-surface truncate">
                                            <strong>{d.email}</strong>
                                            {d.nome ? <span className="text-on-surface-variant"> · {d.nome}</span> : null}
                                            {d.papel_label ? <span className="text-on-surface-variant"> · {d.papel_label}</span> : null}
                                        </p>
                                        <p className="text-xs text-on-surface-variant truncate">
                                            {(d.origens_labels ?? []).join(' · ')}
                                            {d.projetos_total > 0 ? ` · ${d.projetos_total} projeto(s)` : ''}
                                        </p>
                                        {d.erro && <p className="text-xs text-error">{d.erro}</p>}
                                    </div>
                                    <span className="text-xs text-on-surface-variant shrink-0 sm:text-right">
                                        {d.enviado_em ? new Date(d.enviado_em).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—'}
                                    </span>
                                </div>
                            ))}
                        </div>
                        {metaLista && metaLista.ultima_pagina > 1 && (
                            <div className="flex items-center justify-between gap-3 mt-4">
                                <span className="text-xs text-on-surface-variant">Página {metaLista.pagina_atual} de {metaLista.ultima_pagina}</span>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" disabled={metaLista.pagina_atual <= 1} onClick={() => setPagina((p) => Math.max(1, p - 1))}>Anterior</Button>
                                    <Button type="button" variant="outline" disabled={metaLista.pagina_atual >= metaLista.ultima_pagina} onClick={() => setPagina((p) => p + 1)}>Próxima</Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
            {dialogo}
        </AppShell>
    );
}
