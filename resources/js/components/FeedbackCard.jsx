import { useEffect, useRef, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getFeedbackPendente, marcarFeedbackVisto, dispensarFeedback, responderFeedback,
} from '../lib/feedback.js';

const INTERVALO = 60000; // não há WebSocket no projeto: o convite chega em até ~1 min

/**
 * O balão de feedback: aparece para quem o público do pedido alcança e ainda
 * não respondeu nem dispensou.
 *
 * Fechar dispensa **em definitivo** (o balão não volta), mas o questionário
 * continua acessível na área da pessoa — quem se arrepender ainda responde.
 *
 * As respostas são anônimas: o portal registra que você respondeu, não o que
 * você respondeu. O card diz isso na cara, porque é o que faz a pessoa
 * responder com franqueza.
 */
export function FormularioFeedback({ feedback, respostas, onResponder, erros = {} }) {
    return (
        <div className="space-y-5">
            {feedback.perguntas.map((p, i) => (
                <div key={p.id}>
                    <p className="text-sm font-semibold text-on-surface">
                        {i + 1}. {p.enunciado}
                        {!p.obrigatoria && (
                            <span className="ml-1 font-normal text-xs text-on-surface-variant">(opcional)</span>
                        )}
                    </p>
                    {p.limite && <p className="text-xs text-on-surface-variant mt-0.5">{p.limite}</p>}

                    {p.tipo === 'alternativa' ? (
                        <div role="radiogroup" aria-label={p.enunciado} className="mt-2 space-y-1.5">
                            {p.opcoes.map((opcao) => (
                                <label key={opcao} className="flex items-center gap-2 text-sm text-on-surface cursor-pointer">
                                    <input
                                        type="radio"
                                        name={`pergunta-${p.id}`}
                                        value={opcao}
                                        checked={respostas[p.id] === opcao}
                                        onChange={() => onResponder(p.id, opcao)}
                                        className="accent-primary-container"
                                    />
                                    {opcao}
                                </label>
                            ))}
                        </div>
                    ) : (
                        <textarea
                            aria-label={p.enunciado}
                            value={respostas[p.id] ?? ''}
                            onChange={(e) => onResponder(p.id, e.target.value)}
                            rows={3}
                            className="mt-2 w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                        />
                    )}

                    {erros[`respostas.${p.id}`] && (
                        <p className="text-xs text-error mt-1">{erros[`respostas.${p.id}`]}</p>
                    )}
                </div>
            ))}
        </div>
    );
}

export default function FeedbackCard({ ativo = true }) {
    const [feedback, setFeedback] = useState(null);
    const [aberto, setAberto] = useState(false);
    const [respostas, setRespostas] = useState({});
    const [erros, setErros] = useState({});
    const [alerta, setAlerta] = useState('');
    const [enviando, setEnviando] = useState(false);
    const vistos = useRef(new Set());

    useEffect(() => {
        if (!ativo) return undefined;

        let cancelado = false;
        const buscar = () => getFeedbackPendente()
            .then((f) => { if (!cancelado) setFeedback(f); })
            .catch(() => {});

        buscar();
        const id = setInterval(buscar, INTERVALO);
        return () => { cancelado = true; clearInterval(id); };
    }, [ativo]);

    // Registra o "visto" uma vez por pedido, quando o card entra na tela.
    useEffect(() => {
        if (!feedback?.id || vistos.current.has(feedback.id)) return;
        vistos.current.add(feedback.id);
        marcarFeedbackVisto(feedback.id).catch(() => {});
    }, [feedback?.id]);

    async function dispensar() {
        if (!feedback) return;
        const id = feedback.id;
        // Some da tela na hora: insistir num card fechado irrita mais do que
        // perder o registro se a chamada falhar.
        setFeedback(null);
        setAberto(false);
        dispensarFeedback(id).catch(() => {});
    }

    async function enviar() {
        setEnviando(true); setErros({}); setAlerta('');
        try {
            await responderFeedback(feedback.id, respostas);
            setFeedback(null);
            setAberto(false);
            setRespostas({});
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErros(fields ?? {});
            setAlerta(message || 'Confira as respostas e tente de novo.');
        } finally {
            setEnviando(false);
        }
    }

    if (!feedback) return null;

    return (
        <div className="mb-4 bg-surface-container-lowest rounded-xl fetec-card-shadow border-l-4 border-primary-container p-5">
            <div className="flex items-start gap-3">
                <span className="material-symbols-outlined text-primary-container">reviews</span>
                <div className="flex-1 min-w-0">
                    <h2 className="font-display font-semibold text-on-surface">{feedback.titulo}</h2>
                    {feedback.descricao && (
                        <p className="text-sm text-on-surface-variant mt-1">{feedback.descricao}</p>
                    )}
                    <p className="text-xs text-on-surface-variant mt-1">
                        {feedback.perguntas.length} pergunta(s) · <strong>respostas anônimas</strong> — registramos
                        que você respondeu, não o que você respondeu.
                    </p>

                    {aberto && (
                        <div className="mt-4">
                            {alerta && <div className="mb-3"><Alert>{alerta}</Alert></div>}
                            <FormularioFeedback
                                feedback={feedback}
                                respostas={respostas}
                                erros={erros}
                                onResponder={(id, valor) => setRespostas((r) => ({ ...r, [id]: valor }))}
                            />
                        </div>
                    )}

                    <div className="flex gap-2 flex-wrap mt-4">
                        {aberto ? (
                            <>
                                <Button type="button" loading={enviando} onClick={enviar}>Enviar respostas</Button>
                                <Button type="button" variant="outline" onClick={() => setAberto(false)}>
                                    Agora não
                                </Button>
                            </>
                        ) : (
                            <>
                                <Button type="button" onClick={() => setAberto(true)}>Responder</Button>
                                <Button type="button" variant="outline" onClick={dispensar}>
                                    Não quero responder
                                </Button>
                            </>
                        )}
                    </div>
                </div>
                <button
                    type="button"
                    onClick={dispensar}
                    aria-label="Fechar o convite de feedback"
                    className="p-1 rounded-lg text-on-surface-variant hover:bg-surface-variant transition-colors shrink-0"
                >
                    <span className="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>
        </div>
    );
}
