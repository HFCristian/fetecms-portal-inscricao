import { useEffect, useRef, useState } from 'react';

/**
 * Balão de dúvida ("?") com as *Orientações para o Avaliador* de uma pergunta
 * da rubrica. O texto vem da API; sem texto, o balão não é desenhado — algumas
 * perguntas ainda não têm orientação no documento da organização.
 */
export default function AjudaBalao({ texto, rotulo = 'Orientações para o avaliador' }) {
    const [aberto, setAberto] = useState(false);
    const caixa = useRef(null);

    // Clique fora e Esc fecham o balão.
    useEffect(() => {
        if (!aberto) return undefined;

        const clique = (e) => { if (!caixa.current?.contains(e.target)) setAberto(false); };
        const tecla = (e) => { if (e.key === 'Escape') setAberto(false); };

        document.addEventListener('mousedown', clique);
        document.addEventListener('keydown', tecla);

        return () => {
            document.removeEventListener('mousedown', clique);
            document.removeEventListener('keydown', tecla);
        };
    }, [aberto]);

    if (!texto) return null;

    return (
        <div className="relative shrink-0" ref={caixa}>
            <button
                type="button"
                onClick={() => setAberto((v) => !v)}
                aria-expanded={aberto}
                aria-label={rotulo}
                className={`w-6 h-6 rounded-full border flex items-center justify-center transition-colors ${
                    aberto
                        ? 'border-primary-container bg-primary-fixed/60 text-primary-container'
                        : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                }`}
            >
                <span className="material-symbols-outlined text-[16px]">help</span>
            </button>

            {aberto && (
                <div
                    role="note"
                    className="absolute right-0 top-8 z-20 w-72 max-w-[70vw] rounded-xl border border-outline-variant bg-surface-container-lowest p-3 shadow-lg"
                >
                    <p className="text-xs font-semibold text-on-surface mb-1">{rotulo}</p>
                    <p className="text-xs text-on-surface-variant leading-relaxed">{texto}</p>
                </div>
            )}
        </div>
    );
}
