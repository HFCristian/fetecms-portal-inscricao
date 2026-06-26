import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Button } from '../components/ui.jsx';
import { getConversas, getConversa, responderConversa, atualizarStatusConversa, foiVista } from '../lib/chat.js';
import { tempoRelativo } from '../lib/tempo.js';
import ReciboLeitura from '../components/ReciboLeitura.jsx';

// Cores do "pill" de status (todas com tokens sólidos do design system).
const PILL = {
    nao_visualizada: 'bg-error-container text-on-error-container',
    visualizada: 'bg-surface-variant text-on-surface',
    em_tratamento: 'bg-primary-fixed text-primary-container',
    respondida: 'bg-secondary text-on-secondary',
    arquivada: 'bg-surface-variant text-on-surface-variant',
};

function Pill({ status, label }) {
    return (
        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${PILL[status] ?? 'bg-surface-variant text-on-surface'}`}>
            {label}
        </span>
    );
}

function Spinner() {
    return (
        <span
            className="inline-block w-6 h-6 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]"
            role="status"
            aria-label="Carregando"
        />
    );
}

// Uma linha da lista (resumo de uma conversa).
function LinhaConversa({ conversa, onAbrir }) {
    return (
        <button
            type="button"
            onClick={() => onAbrir(conversa)}
            className="w-full text-left flex items-start gap-3 px-4 py-3 hover:bg-surface-variant/60 transition-colors border-b border-outline-variant/30 last:border-0"
        >
            <span className="material-symbols-outlined text-primary-container mt-0.5">account_circle</span>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-semibold text-on-surface truncate">{conversa.usuario?.name ?? 'Usuário'}</span>
                    <span className="text-xs text-on-surface-variant">{conversa.usuario?.role_label}</span>
                    <Pill status={conversa.status} label={conversa.status_label} />
                </div>
                {conversa.ultima_mensagem && (
                    <p className="text-sm text-on-surface-variant truncate">{conversa.ultima_mensagem.corpo}</p>
                )}
            </div>
            <span className="text-xs text-on-surface-variant whitespace-nowrap shrink-0 mt-0.5">
                {tempoRelativo(conversa.ultima_mensagem_em)}
            </span>
        </button>
    );
}

// Seção em dropdown (accordion). `aberta` controla se inicia expandida.
function Secao({ titulo, icone, total, aberta, conversas, onAbrir, vazio }) {
    const [open, setOpen] = useState(aberta);
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="w-full flex items-center gap-3 px-4 py-3 text-left"
                aria-expanded={open}
            >
                <span className="material-symbols-outlined text-primary-container">{icone}</span>
                <span className="font-semibold text-on-surface flex-1">{titulo}</span>
                <span className="text-sm font-bold text-on-surface-variant bg-surface-variant rounded-full px-2.5 py-0.5">
                    {total}
                </span>
                <span className="material-symbols-outlined text-on-surface-variant">
                    {open ? 'expand_less' : 'expand_more'}
                </span>
            </button>
            {open && (
                <div className="border-t border-outline-variant/30">
                    {conversas.length === 0 ? (
                        <p className="px-4 py-6 text-sm text-on-surface-variant text-center">{vazio}</p>
                    ) : (
                        conversas.map((c) => <LinhaConversa key={c.id} conversa={c} onAbrir={onAbrir} />)
                    )}
                </div>
            )}
        </div>
    );
}

// Modal com o histórico completo + ações (status e resposta).
function DetalheConversa({ conversa, onFechar, onResponder, onStatus, salvando }) {
    const [resposta, setResposta] = useState('');

    async function enviar(e) {
        e.preventDefault();
        const corpo = resposta.trim();
        if (!corpo) return;
        await onResponder(corpo);
        setResposta('');
    }

    const mensagens = conversa.mensagens ?? [];
    // Recibo de leitura do lado do admin: o usuário vê as mensagens do suporte;
    // "vista" se usuario_visto_em for posterior ao envio. Texto só na última.
    const usuarioVistoEm = conversa.usuario_visto_em;
    const dosSuporte = mensagens.filter((m) => m.autor === 'suporte');
    const ultimaSuporteId = dosSuporte.length ? dosSuporte[dosSuporte.length - 1].id : null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg max-h-[88vh] flex flex-col overflow-hidden">
                {/* Cabeçalho */}
                <div className="flex items-start justify-between gap-3 px-5 py-4 border-b border-outline-variant/30">
                    <div className="min-w-0">
                        <h3 className="font-display text-lg font-semibold text-on-surface truncate">
                            {conversa.usuario?.name}
                        </h3>
                        <p className="text-xs text-on-surface-variant truncate">
                            {conversa.usuario?.role_label} · {conversa.usuario?.email}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        <Pill status={conversa.status} label={conversa.status_label} />
                        <button type="button" onClick={onFechar} aria-label="Fechar" className="text-on-surface-variant p-1">
                            <span className="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                {/* Histórico */}
                <div className="flex-1 overflow-y-auto p-4 space-y-2 bg-surface">
                    {mensagens.map((m) => {
                        const doSuporte = m.autor === 'suporte';
                        return (
                            <div key={m.id} className={`flex ${doSuporte ? 'justify-end' : 'justify-start'}`}>
                                <div
                                    className={`max-w-[80%] rounded-2xl px-3 py-2 text-sm whitespace-pre-line ${
                                        doSuporte ? 'bg-primary-container text-on-primary' : 'bg-surface-container-high text-on-surface'
                                    }`}
                                >
                                    {m.corpo}
                                    <div className={`text-[10px] mt-1 flex items-center gap-1 ${doSuporte ? 'text-on-primary/70 justify-end' : 'text-on-surface-variant'}`}>
                                        <span>{doSuporte ? 'Suporte' : 'Usuário'} · {tempoRelativo(m.created_at)}</span>
                                        {doSuporte && (
                                            <ReciboLeitura
                                                vista={foiVista(m.created_at, usuarioVistoEm)}
                                                ultima={m.id === ultimaSuporteId}
                                                vistoEm={usuarioVistoEm}
                                            />
                                        )}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {/* Ações de status */}
                <div className="flex flex-wrap gap-2 px-4 pt-3">
                    {conversa.status !== 'em_tratamento' && conversa.nao_respondida && (
                        <button
                            type="button"
                            onClick={() => onStatus('em_tratamento')}
                            disabled={salvando}
                            className="text-xs font-semibold px-3 py-1.5 rounded-lg border border-outline-variant text-on-surface-variant hover:bg-surface-variant transition-colors disabled:opacity-50"
                        >
                            Marcar em tratamento
                        </button>
                    )}
                    {conversa.status !== 'arquivada' && (
                        <button
                            type="button"
                            onClick={() => onStatus('arquivada')}
                            disabled={salvando}
                            className="text-xs font-semibold px-3 py-1.5 rounded-lg border border-outline-variant text-on-surface-variant hover:bg-surface-variant transition-colors disabled:opacity-50"
                        >
                            Arquivar
                        </button>
                    )}
                </div>

                {/* Resposta */}
                <form onSubmit={enviar} className="p-4 border-t border-outline-variant/30 mt-3 space-y-2">
                    <textarea
                        value={resposta}
                        onChange={(e) => setResposta(e.target.value)}
                        rows={3}
                        maxLength={5000}
                        placeholder="Escreva a resposta... (o usuário recebe por e-mail)"
                        className="w-full resize-none bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface outline-none focus:border-primary-container"
                    />
                    <div className="flex justify-end">
                        <Button type="submit" loading={salvando} disabled={!resposta.trim()}>
                            <span className="material-symbols-outlined text-[18px]">send</span>
                            Responder
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function AdminSuporte() {
    const [grupos, setGrupos] = useState(null);
    const [contagem, setContagem] = useState({ nao_respondidas: 0, respondidas: 0, arquivadas: 0 });
    const [selecionada, setSelecionada] = useState(null);
    const [salvando, setSalvando] = useState(false);

    const carregar = useCallback(async () => {
        const res = await getConversas();
        setGrupos(res.data);
        setContagem(res.meta?.contagem ?? { nao_respondidas: 0, respondidas: 0, arquivadas: 0 });
        // Sinaliza ao menu (AppShell) para reatualizar o badge "Suporte" na hora,
        // sem esperar o polling de 60s (ex.: logo após abrir/responder/arquivar).
        window.dispatchEvent(new Event('suporte:atualizar'));
    }, []);

    useEffect(() => {
        carregar().catch(() => setGrupos({ nao_respondidas: [], respondidas: [], arquivadas: [] }));
    }, [carregar]);

    // Enquanto uma conversa está aberta, atualiza o detalhe ao vivo (recibo de
    // leitura do usuário + novas mensagens). Cada refetch também registra o
    // suporte_visto_em no backend, mantendo o "visto" do usuário fresco.
    useEffect(() => {
        if (!selecionada) return undefined;
        const id = selecionada.id;
        const t = setInterval(() => {
            getConversa(id).then(setSelecionada).catch(() => {});
        }, 15000);
        return () => clearInterval(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selecionada?.id]);

    async function abrir(conversa) {
        // Abrir o detalhe marca como "visualizada" no backend.
        const detalhe = await getConversa(conversa.id);
        setSelecionada(detalhe);
        carregar().catch(() => {});
    }

    async function responder(corpo) {
        setSalvando(true);
        try {
            const atualizada = await responderConversa(selecionada.id, corpo);
            setSelecionada(atualizada);
            await carregar();
        } finally {
            setSalvando(false);
        }
    }

    async function mudarStatus(status) {
        setSalvando(true);
        try {
            const atualizada = await atualizarStatusConversa(selecionada.id, status);
            setSelecionada(atualizada);
            await carregar();
        } finally {
            setSalvando(false);
        }
    }

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Suporte</h1>
            <p className="text-on-surface-variant mb-6">Mensagens enviadas pelos orientadores e avaliadores.</p>

            {/* 2 cards: não respondidas / respondidas */}
            <div className="grid grid-cols-2 gap-4 mb-8 max-w-md">
                <div className="flex flex-col items-center text-center gap-1 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                    <span className="material-symbols-outlined text-error text-2xl">mark_email_unread</span>
                    <div className="text-3xl font-bold text-on-surface">{contagem.nao_respondidas}</div>
                    <div className="text-sm text-on-surface-variant">Não respondidas</div>
                </div>
                <div className="flex flex-col items-center text-center gap-1 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                    <span className="material-symbols-outlined text-secondary text-2xl">mark_email_read</span>
                    <div className="text-3xl font-bold text-on-surface">{contagem.respondidas}</div>
                    <div className="text-sm text-on-surface-variant">Respondidas</div>
                </div>
            </div>

            {!grupos ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <Spinner />
                </div>
            ) : (
                <div className="space-y-3 max-w-2xl">
                    <Secao
                        titulo="Não respondidas"
                        icone="mark_email_unread"
                        total={contagem.nao_respondidas}
                        aberta
                        conversas={grupos.nao_respondidas}
                        onAbrir={abrir}
                        vazio="Nenhuma mensagem aguardando resposta."
                    />
                    <Secao
                        titulo="Respondidas"
                        icone="check_circle"
                        total={contagem.respondidas}
                        aberta={false}
                        conversas={grupos.respondidas}
                        onAbrir={abrir}
                        vazio="Nenhuma mensagem respondida ainda."
                    />
                    <Secao
                        titulo="Arquivadas"
                        icone="inventory_2"
                        total={contagem.arquivadas}
                        aberta={false}
                        conversas={grupos.arquivadas}
                        onAbrir={abrir}
                        vazio="Nenhuma mensagem arquivada."
                    />
                </div>
            )}

            {selecionada && (
                <DetalheConversa
                    conversa={selecionada}
                    onFechar={() => setSelecionada(null)}
                    onResponder={responder}
                    onStatus={mudarStatus}
                    salvando={salvando}
                />
            )}
        </AppShell>
    );
}
