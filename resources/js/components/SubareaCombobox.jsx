import { useEffect, useRef, useState } from 'react';

const inputClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-on-surface ' +
    'placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 ' +
    'transition-all outline-none disabled:opacity-60 disabled:cursor-not-allowed';

const norm = (s) => (s ?? '').trim().toLowerCase();

/**
 * Combobox de subárea (catálogo global unificado): digite para filtrar as existentes
 * e selecionar, ou criar uma nova. Subárea é sempre opcional.
 *
 * - Em telas autenticadas, passe `create(nome) => Promise<{id,nome}>` (persiste na
 *   hora via API e devolve a subárea com id real).
 * - Sem `create` (cadastro público), o "Criar" emite { id: null, nome }; o backend
 *   materializa a subárea na transação do registro (via subarea_nome).
 *
 * value: { id, nome } | null   ·   onChange(sel: { id, nome } | null)
 */
export default function SubareaCombobox({
    options = [], value = null, onChange, create, disabled = false, placeholder,
}) {
    const [query, setQuery] = useState(value?.nome ?? '');
    const [open, setOpen] = useState(false);
    const [criando, setCriando] = useState(false);
    const boxRef = useRef(null);

    // Ressincroniza o texto quando a seleção externa muda (ex.: troca de área limpa).
    useEffect(() => { setQuery(value?.nome ?? ''); }, [value?.id, value?.nome]);

    // Fecha ao clicar fora.
    useEffect(() => {
        function onDocClick(e) {
            if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false);
        }
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, []);

    const q = query.trim();
    const filtradas = q ? options.filter((s) => norm(s.nome).includes(norm(q))) : options;
    const existeExata = options.some((s) => norm(s.nome) === norm(q));
    const podeCriar = q.length >= 2 && !existeExata;

    function selecionar(sub) {
        onChange?.(sub);
        setQuery(sub?.nome ?? '');
        setOpen(false);
    }

    async function criar() {
        if (!podeCriar || criando) return;
        if (create) {
            setCriando(true);
            try {
                selecionar(await create(q));
            } catch {
                setOpen(false); // erro tratado pelo formulário pai
            } finally {
                setCriando(false);
            }
        } else {
            selecionar({ id: null, nome: q });
        }
    }

    function onKeyDown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (filtradas.length === 1) selecionar(filtradas[0]);
            else if (podeCriar) criar();
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    }

    return (
        <div className="relative" ref={boxRef}>
            <input
                type="text"
                className={inputClass}
                value={query}
                disabled={disabled}
                placeholder={placeholder}
                onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                onFocus={() => setOpen(true)}
                onKeyDown={onKeyDown}
            />
            {value?.id != null && !open && (
                <span className="material-symbols-outlined absolute right-2 top-1/2 -translate-y-1/2 text-secondary text-[20px] pointer-events-none">
                    check_circle
                </span>
            )}
            {open && !disabled && (
                <ul className="absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-lg border border-outline-variant bg-surface-container-lowest shadow-lg">
                    {filtradas.map((s) => (
                        <li key={s.id}>
                            <button
                                type="button"
                                onMouseDown={(e) => { e.preventDefault(); selecionar(s); }}
                                className="w-full text-left px-3 py-2 text-sm hover:bg-surface-variant"
                            >
                                {s.nome}
                            </button>
                        </li>
                    ))}
                    {podeCriar && (
                        <li>
                            <button
                                type="button"
                                onMouseDown={(e) => { e.preventDefault(); criar(); }}
                                className="w-full text-left px-3 py-2 text-sm text-primary-container hover:bg-primary-fixed flex items-center gap-2"
                            >
                                <span className={`material-symbols-outlined text-[18px] ${criando ? 'animate-spin' : ''}`}>
                                    {criando ? 'progress_activity' : 'add'}
                                </span>
                                Criar “{q}”
                            </button>
                        </li>
                    )}
                    {filtradas.length === 0 && !podeCriar && (
                        <li className="px-3 py-2 text-sm text-on-surface-variant">Nada encontrado</li>
                    )}
                    {value && (
                        <li className="border-t border-outline-variant/40">
                            <button
                                type="button"
                                onMouseDown={(e) => { e.preventDefault(); selecionar(null); }}
                                className="w-full text-left px-3 py-2 text-xs text-on-surface-variant hover:bg-surface-variant flex items-center gap-2"
                            >
                                <span className="material-symbols-outlined text-[16px]">close</span>
                                Limpar seleção
                            </button>
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}
