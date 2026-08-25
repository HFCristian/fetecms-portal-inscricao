import { useEffect, useRef, useState } from 'react';
import { getAvisoAtivo, marcarAvisoVisto, fecharAviso } from '../lib/avisos.js';

const INTERVALO = 60000; // não há WebSocket no projeto: o card chega em até ~1 min

/**
 * Card do aviso publicado pelo admin. Aparece no topo da área do orientador
 * enquanto o aviso estiver no ar e a pessoa não o tiver fechado.
 *
 * O "visto" é registrado quando o card realmente chega à tela — é isso que o
 * relatório do admin conta como visualização.
 */
export default function AvisoCard({ ativo = true }) {
    const [aviso, setAviso] = useState(null);
    const [fechando, setFechando] = useState(false);
    const vistos = useRef(new Set());

    useEffect(() => {
        if (!ativo) return undefined;

        let cancelado = false;
        const buscar = () => getAvisoAtivo()
            .then((a) => { if (!cancelado) setAviso(a); })
            .catch(() => {});

        buscar();
        const id = setInterval(buscar, INTERVALO);
        return () => { cancelado = true; clearInterval(id); };
    }, [ativo]);

    // Registra a visualização uma única vez por aviso, quando ele entra na tela.
    useEffect(() => {
        if (!aviso?.id || vistos.current.has(aviso.id)) return;
        vistos.current.add(aviso.id);
        marcarAvisoVisto(aviso.id).catch(() => {});
    }, [aviso?.id]);

    async function fechar() {
        if (!aviso) return;
        setFechando(true);
        try {
            await fecharAviso(aviso.id);
        } catch {
            // Some da tela de qualquer forma: insistir num card fechado irrita mais
            // do que perder o registro.
        } finally {
            setAviso(null);
            setFechando(false);
        }
    }

    if (!aviso) return null;

    return (
        <div
            role="status"
            className="mb-5 rounded-xl border border-primary-container/40 bg-primary-fixed fetec-card-shadow p-4 flex items-start gap-3"
        >
            <span className="material-symbols-outlined text-primary-container shrink-0">campaign</span>
            <div className="min-w-0 flex-1">
                <h2 className="font-display font-semibold text-primary">{aviso.titulo}</h2>
                {/* A mensagem é texto puro: quebras de linha preservadas, sem HTML. */}
                <p className="text-sm text-on-surface mt-1 whitespace-pre-line">{aviso.mensagem}</p>
                {aviso.expira_em_label && (
                    <p className="text-xs text-on-surface-variant mt-2">
                        Este aviso sai da tela em <strong>{aviso.expira_em_label}</strong>.
                    </p>
                )}
                {aviso.publicado_em && (
                    <p className="text-xs text-on-surface-variant mt-2">Publicado em {aviso.publicado_em}</p>
                )}
            </div>
            <button
                type="button"
                onClick={fechar}
                disabled={fechando}
                title="Fechar aviso"
                aria-label="Fechar aviso"
                className="shrink-0 p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant transition-colors disabled:opacity-50"
            >
                <span className="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>
    );
}
