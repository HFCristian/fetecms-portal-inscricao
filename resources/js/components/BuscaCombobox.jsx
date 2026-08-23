import { useEffect, useRef, useState } from 'react';

const inputClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-sm text-on-surface ' +
    'placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 ' +
    'transition-all outline-none disabled:opacity-60 disabled:cursor-not-allowed';

const norm = (s) => (s ?? '').trim().toLowerCase();

/**
 * Combobox de busca (só escolhe, nunca cria) com a mesma UX do SubareaCombobox:
 * digite para filtrar, clique para selecionar, Esc fecha.
 *
 * As opções chegam já na ordem em que devem aparecer — quem monta a lista decide
 * (na designação de avaliador, ordem alfabética).
 *
 * options: [{ id, nome, detalhe? }]  ·  value: option | null  ·  onChange(option | null)
 */
export default function BuscaCombobox({
    options = [], value = null, onChange, disabled = false, placeholder, vazio = 'Nada encontrado',
}) {
    const [query, setQuery] = useState(value?.nome ?? '');
    const [open, setOpen] = useState(false);
    const boxRef = useRef(null);

    // Ressincroniza o texto quando a seleção muda por fora (ex.: trocar o tipo de alvo).
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
    // Com uma opção já escolhida, o texto do input é o nome dela — mostrar a lista
    // inteira nesse caso é melhor do que devolver só ela mesma.
    const buscando = q.length > 0 && norm(q) !== norm(value?.nome);
    const filtradas = buscando ? options.filter((o) => norm(o.nome).includes(norm(q))) : options;

    function selecionar(op) {
        onChange?.(op);
        setQuery(op?.nome ?? '');
        setOpen(false);
    }

    function onKeyDown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (filtradas.length === 1) selecionar(filtradas[0]);
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    }

    return (
        <div className="relative" ref={boxRef}>
            <input
                type="text"
                role="combobox"
                aria-expanded={open}
                autoComplete="off"
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
                    {filtradas.map((o) => (
                        <li key={o.id}>
                            <button
                                type="button"
                                onMouseDown={(e) => { e.preventDefault(); selecionar(o); }}
                                className="w-full text-left px-3 py-2 text-sm hover:bg-surface-variant"
                            >
                                {o.nome}
                                {o.detalhe && <span className="text-on-surface-variant"> — {o.detalhe}</span>}
                            </button>
                        </li>
                    ))}
                    {filtradas.length === 0 && (
                        <li className="px-3 py-2 text-sm text-on-surface-variant">{vazio}</li>
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
