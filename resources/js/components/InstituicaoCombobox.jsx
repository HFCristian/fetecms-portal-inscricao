import { useEffect, useRef, useState } from 'react';

const inputClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-on-surface ' +
    'placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 ' +
    'transition-all outline-none disabled:opacity-60 disabled:cursor-not-allowed';

/**
 * Combobox de instituição (catálogo global). Busca no servidor conforme se digita
 * (debounce). Permite criar uma instituição nova; em telas autenticadas passe
 * `create(nome) => Promise<{id,nome}>` (persiste na hora); sem `create` (cadastro
 * público) o "Criar" emite { id: null, nome } e o backend materializa no registro.
 *
 * value: { id, nome } | null   ·   onChange(sel: { id, nome } | null)
 */
export default function InstituicaoCombobox({
    value = null, onChange, buscar, create, disabled = false, placeholder,
}) {
    const [query, setQuery] = useState(value?.nome ?? '');
    const [open, setOpen] = useState(false);
    const [resultados, setResultados] = useState([]);
    const [carregando, setCarregando] = useState(false);
    const [criando, setCriando] = useState(false);
    const boxRef = useRef(null);

    useEffect(() => { setQuery(value?.nome ?? ''); }, [value?.id, value?.nome]);

    useEffect(() => {
        function onDocClick(e) {
            if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false);
        }
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, []);

    const q = query.trim();

    // Busca no servidor (debounce) quando aberto e com pelo menos 2 caracteres.
    useEffect(() => {
        if (!open || q.length < 2) {
            setResultados([]);
            return undefined;
        }
        let ativo = true;
        setCarregando(true);
        const t = setTimeout(() => {
            buscar(q)
                .then((r) => { if (ativo) setResultados(r); })
                .catch(() => { if (ativo) setResultados([]); })
                .finally(() => { if (ativo) setCarregando(false); });
        }, 300);
        return () => { ativo = false; clearTimeout(t); };
    }, [q, open, buscar]);

    const existeExata = resultados.some((s) => s.nome.trim().toLowerCase() === q.toLowerCase());
    const podeCriar = q.length >= 2 && !existeExata && !carregando;

    function selecionar(sel) {
        onChange?.(sel);
        setQuery(sel?.nome ?? '');
        setOpen(false);
    }

    async function criar() {
        if (q.length < 2 || criando) return;
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
            if (resultados.length === 1) selecionar(resultados[0]);
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
                    {q.length < 2 && (
                        <li className="px-3 py-2 text-sm text-on-surface-variant">Digite ao menos 2 letras…</li>
                    )}
                    {carregando && (
                        <li className="px-3 py-2 text-sm text-on-surface-variant flex items-center gap-2">
                            <span className="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
                            Buscando…
                        </li>
                    )}
                    {!carregando && resultados.map((s) => (
                        <li key={s.id}>
                            <button
                                type="button"
                                onMouseDown={(e) => { e.preventDefault(); selecionar(s); }}
                                className="w-full text-left px-3 py-2 text-sm hover:bg-surface-variant"
                            >
                                {s.nome}
                                {s.cidade ? <span className="text-on-surface-variant"> — {s.cidade}</span> : null}
                            </button>
                        </li>
                    ))}
                    {!carregando && q.length >= 2 && resultados.length === 0 && !podeCriar && (
                        <li className="px-3 py-2 text-sm text-on-surface-variant">Nada encontrado</li>
                    )}
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
