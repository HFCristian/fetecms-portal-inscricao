import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getMalas } from '../lib/malaDireta.js';

/** Pílula de situação da mala (uma mala concluída com falhas fica em destaque). */
function StatusPill({ mala }) {
    const enviando = mala.status === 'enviando';
    const comFalhas = !enviando && (mala.totais.falha > 0 || mala.totais.invalido > 0);
    const estilo = enviando
        ? 'bg-primary-fixed text-primary-container'
        : comFalhas
            ? 'bg-error-container text-on-error-container'
            : 'bg-secondary-container text-on-secondary-container';

    return (
        <span className={`inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-full shrink-0 ${estilo}`}>
            <span className="material-symbols-outlined text-[14px]">
                {enviando ? 'sync' : comFalhas ? 'error' : 'check_circle'}
            </span>
            {enviando ? 'Enviando' : comFalhas ? 'Concluída com falhas' : 'Concluída'}
        </span>
    );
}

function MalaCard({ mala }) {
    const data = mala.enviado_em ? new Date(mala.enviado_em) : null;
    const { totais } = mala;

    return (
        <Link
            to={`/admin/mala-direta/${mala.id}`}
            className="block bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 hover:bg-surface-variant/40 transition-colors"
        >
            <div className="flex flex-wrap items-start justify-between gap-2 mb-1">
                <h2 className="font-semibold text-on-surface">{mala.nome}</h2>
                <StatusPill mala={mala} />
            </div>
            <p className="text-sm text-on-surface-variant truncate">{mala.assunto}</p>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs text-on-surface-variant">
                <span>
                    {data ? `${data.toLocaleDateString('pt-BR')} às ${data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}` : '—'}
                </span>
                <span className="inline-flex items-center gap-1">
                    <span className="material-symbols-outlined text-[16px]">group</span>
                    {totais.total} {totais.total === 1 ? 'destinatário' : 'destinatários'}
                </span>
                <span className="inline-flex items-center gap-1 text-secondary">
                    <span className="material-symbols-outlined text-[16px]">mark_email_read</span>
                    {totais.enviado} enviados
                </span>
                {(totais.falha > 0 || totais.invalido > 0) && (
                    <span className="inline-flex items-center gap-1 text-error font-semibold">
                        <span className="material-symbols-outlined text-[16px]">report</span>
                        {totais.falha + totais.invalido} com problema
                    </span>
                )}
                {mala.autor_nome && <span>por {mala.autor_nome}</span>}
            </div>
        </Link>
    );
}

/**
 * Mala direta: histórico das mensagens disparadas, da mais recente para a mais
 * antiga. O detalhe de cada uma traz o progresso e o relatório de envio.
 */
export default function AdminMalaDireta() {
    const [lista, setLista] = useState(null);
    const [meta, setMeta] = useState(null);
    const [page, setPage] = useState(1);
    const [alert, setAlert] = useState('');

    const carregar = useCallback(() => {
        setLista(null);
        setAlert('');
        getMalas({ page })
            .then((resp) => { setLista(resp.data); setMeta(resp.meta); })
            .catch((e) => { setLista([]); setAlert(extractErrors(e).message); });
    }, [page]);

    useEffect(() => carregar(), [carregar]);

    // Uma mala ainda enviando muda sozinha: recarrega de tempos em tempos.
    useEffect(() => {
        if (!lista?.some((m) => m.status === 'enviando')) return undefined;
        const t = setInterval(() => {
            getMalas({ page })
                .then((resp) => { setLista(resp.data); setMeta(resp.meta); })
                .catch(() => {});
        }, 5000);
        return () => clearInterval(t);
    }, [lista, page]);

    return (
        <AppShell>
            <div className="flex flex-wrap items-end justify-between gap-3 mb-6">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-primary mb-1">Mala direta</h1>
                    <p className="text-on-surface-variant max-w-3xl">
                        Comunicados enviados por e-mail para um recorte da base — orientadores,
                        avaliadores ou uma lista sua. As mensagens disparadas ficam abaixo, da mais
                        recente para a mais antiga.
                    </p>
                </div>
                <Link to="/admin/mala-direta/nova">
                    <Button type="button">
                        <span className="material-symbols-outlined text-[20px]">mail</span>
                        Nova mala direta
                    </Button>
                </Link>
            </div>

            {alert && <div className="mb-4 max-w-4xl"><Alert>{alert}</Alert></div>}

            <div className="max-w-4xl">
                {lista === null ? (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : lista.length === 0 ? (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-8 text-center">
                        <span className="material-symbols-outlined text-[40px] text-outline">forward_to_inbox</span>
                        <p className="text-on-surface-variant text-sm mt-2">
                            Nenhuma mensagem disparada ainda.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-3">
                            {lista.map((mala) => <MalaCard key={mala.id} mala={mala} />)}
                        </div>
                        {meta && meta.ultima_pagina > 1 && (
                            <div className="flex items-center justify-between gap-3 mt-4">
                                <span className="text-xs text-on-surface-variant">Página {meta.pagina_atual} de {meta.ultima_pagina}</span>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" disabled={meta.pagina_atual <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Anterior</Button>
                                    <Button type="button" variant="outline" disabled={meta.pagina_atual >= meta.ultima_pagina} onClick={() => setPage((p) => p + 1)}>Próxima</Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppShell>
    );
}
