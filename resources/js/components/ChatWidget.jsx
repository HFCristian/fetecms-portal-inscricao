import { useEffect, useRef, useState } from 'react';
import { getMinhaConversa, enviarMensagem } from '../lib/chat.js';
import { tempoRelativo } from '../lib/tempo.js';

// Mensagem de boas-vindas: tom amigável, deixa claro que NÃO é um chatbot e
// indica o e-mail para assuntos mais complexos.
const INTRO =
    'Olá! 👋 Aqui é o suporte da FETECMS — gente de verdade, não um robô. ' +
    'Manda sua dúvida que a gente responde assim que possível. Para perguntas mais ' +
    'complexas, o e-mail fetecms@gmail.com continua sendo o melhor caminho.';

export default function ChatWidget() {
    const [aberto, setAberto] = useState(false);
    const [conversa, setConversa] = useState(null);
    const [texto, setTexto] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState('');
    const fimRef = useRef(null);

    async function carregar() {
        try {
            setConversa(await getMinhaConversa());
        } catch {
            /* silencioso: o chat não deve quebrar a navegação */
        }
    }

    // Ao abrir, carrega a conversa e faz polling leve para receber respostas do suporte.
    useEffect(() => {
        if (!aberto) return undefined;
        carregar();
        const id = setInterval(carregar, 20000);
        return () => clearInterval(id);
    }, [aberto]);

    useEffect(() => {
        fimRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [conversa, aberto]);

    async function handleEnviar(e) {
        e.preventDefault();
        const corpo = texto.trim();
        if (!corpo || enviando) return;
        setEnviando(true);
        setErro('');
        try {
            setConversa(await enviarMensagem(corpo));
            setTexto('');
        } catch (err) {
            setErro(err?.response?.data?.message || 'Não foi possível enviar. Tente novamente.');
        } finally {
            setEnviando(false);
        }
    }

    const mensagens = conversa?.mensagens ?? [];

    return (
        <>
            {/* Botão flutuante */}
            <button
                type="button"
                onClick={() => setAberto((v) => !v)}
                aria-label={aberto ? 'Fechar chat de suporte' : 'Abrir chat de suporte'}
                className="fixed bottom-5 right-5 z-50 w-14 h-14 rounded-full bg-primary-container text-on-primary shadow-lg flex items-center justify-center hover:bg-primary transition-colors"
            >
                <span className="material-symbols-outlined text-[26px]">{aberto ? 'close' : 'chat'}</span>
            </button>

            {aberto && (
                <div
                    className="fixed bottom-24 right-5 z-50 w-[min(92vw,22rem)] h-[28rem] max-h-[70vh] flex flex-col bg-surface-container-lowest rounded-2xl fetec-card-shadow border border-outline-variant/30 overflow-hidden"
                    role="dialog"
                    aria-label="Chat de suporte da FETECMS"
                >
                    {/* Cabeçalho */}
                    <div className="bg-primary-container text-on-primary px-4 py-3 shrink-0">
                        <h2 className="font-display font-semibold leading-tight">Suporte FETECMS</h2>
                        <p className="text-xs opacity-90">Atendimento humano — respondemos aqui e por e-mail.</p>
                    </div>

                    {/* Mensagens */}
                    <div className="flex-1 overflow-y-auto p-3 space-y-2 bg-surface">
                        <div className="bg-surface-container-low text-on-surface-variant text-sm rounded-xl p-3">
                            {INTRO}
                        </div>
                        {mensagens.map((m) => {
                            const meu = m.autor === 'usuario';
                            return (
                                <div key={m.id} className={`flex ${meu ? 'justify-end' : 'justify-start'}`}>
                                    <div
                                        className={`max-w-[80%] rounded-2xl px-3 py-2 text-sm whitespace-pre-line ${
                                            meu
                                                ? 'bg-primary-container text-on-primary'
                                                : 'bg-surface-container-high text-on-surface'
                                        }`}
                                    >
                                        {m.corpo}
                                        <div className={`text-[10px] mt-1 ${meu ? 'text-on-primary/70' : 'text-on-surface-variant'}`}>
                                            {meu ? 'Você' : 'Suporte'} · {tempoRelativo(m.created_at)}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                        <div ref={fimRef} />
                    </div>

                    {/* Campo de envio */}
                    <form onSubmit={handleEnviar} className="p-2 border-t border-outline-variant/30 bg-surface-container-lowest shrink-0">
                        {erro && <p className="text-xs text-error px-1 pb-1">{erro}</p>}
                        <div className="flex items-end gap-2">
                            <textarea
                                value={texto}
                                onChange={(e) => setTexto(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && !e.shiftKey) {
                                        e.preventDefault();
                                        handleEnviar(e);
                                    }
                                }}
                                rows={1}
                                maxLength={2000}
                                placeholder="Escreva sua mensagem..."
                                className="flex-1 resize-none bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface outline-none focus:border-primary-container"
                            />
                            <button
                                type="submit"
                                disabled={enviando || !texto.trim()}
                                aria-label="Enviar mensagem"
                                className="shrink-0 w-10 h-10 rounded-full bg-primary-container text-on-primary flex items-center justify-center disabled:opacity-50 transition-colors hover:bg-primary"
                            >
                                <span className={`material-symbols-outlined text-[20px] ${enviando ? 'animate-spin' : ''}`}>
                                    {enviando ? 'progress_activity' : 'send'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </>
    );
}
