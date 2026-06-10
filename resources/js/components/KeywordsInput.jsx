import { useState, useEffect } from 'react';
import { buscarPalavrasChave } from '../lib/catalogos.js';

const wordCount = (s) => s.trim().split(/\s+/).filter(Boolean).length;
const titleCase = (s) =>
    s.trim().replace(/\s+/g, ' ').replace(/\S+/g, (w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase());

/**
 * Palavras-chave do projeto: 3 a 5 itens, cada um com 1 a 5 palavras.
 * Autocomplete a partir da lista GLOBAL (compartilhada) — selecione uma existente
 * ou crie uma nova (que será persistida ao salvar o projeto).
 */
export default function KeywordsInput({ value = [], onChange, max = 5, min = 3 }) {
    const [text, setText] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [open, setOpen] = useState(false);

    const q = text.trim();
    const wc = q ? wordCount(q) : 0;
    const tooMany = wc > 5;
    const full = value.length >= max;

    // Busca sugestões na lista global (debounce).
    useEffect(() => {
        if (!q) { setSuggestions([]); return; }
        const t = setTimeout(() => {
            buscarPalavrasChave(q)
                .then((list) => {
                    const sel = value.map((v) => v.toLowerCase());
                    setSuggestions(list.filter((s) => !sel.includes(s.toLowerCase())));
                })
                .catch(() => {});
        }, 250);
        return () => clearTimeout(t);
    }, [q, value]);

    function add(raw) {
        const kw = titleCase(raw);
        const c = wordCount(kw);
        if (!kw || c < 1 || c > 5 || full) return;
        if (value.some((v) => v.toLowerCase() === kw.toLowerCase())) { setText(''); return; }
        onChange([...value, kw]);
        setText('');
        setSuggestions([]);
        setOpen(false);
    }
    const remove = (i) => onChange(value.filter((_, idx) => idx !== i));

    const exactInSug = suggestions.some((s) => s.toLowerCase() === q.toLowerCase());
    const dup = value.some((v) => v.toLowerCase() === q.toLowerCase());
    const canCreate = q && !tooMany && !exactInSug && !dup;

    return (
        <div>
            <div className="flex flex-wrap gap-2 mb-2">
                {value.map((kw, i) => (
                    <span key={kw} className="inline-flex items-center gap-1 bg-primary-fixed text-primary-container rounded-full pl-3 pr-1 py-1 text-sm font-semibold">
                        {kw}
                        <button type="button" onClick={() => remove(i)}
                            className="flex items-center justify-center w-5 h-5 rounded-full hover:bg-primary-container hover:text-white" aria-label={`Remover ${kw}`}>
                            <span className="material-symbols-outlined text-[14px]">close</span>
                        </button>
                    </span>
                ))}
            </div>

            <div className="relative">
                <input
                    value={text}
                    onChange={(e) => { setText(e.target.value); setOpen(true); }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => setTimeout(() => setOpen(false), 150)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            if (suggestions[0]) add(suggestions[0]);
                            else if (canCreate) add(text);
                        } else if (e.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                    disabled={full}
                    placeholder={full ? 'Máximo de 5 palavras-chave atingido' : 'Digite para buscar ou criar…'}
                    className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-on-surface placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none disabled:opacity-60"
                />

                {open && !full && (suggestions.length > 0 || canCreate || tooMany) && (
                    <ul className="absolute z-30 left-0 right-0 top-full mt-1 bg-surface-container-lowest border border-outline-variant rounded-lg shadow-lg max-h-60 overflow-auto py-1">
                        {suggestions.map((s) => (
                            <li key={s}>
                                <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => add(s)}
                                    className="w-full text-left px-4 py-2 flex items-center gap-2 hover:bg-surface-container-low text-on-surface text-sm">
                                    <span className="material-symbols-outlined text-[16px] text-primary-container">label</span>
                                    {s}
                                </button>
                            </li>
                        ))}
                        {canCreate && (
                            <li>
                                <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => add(text)}
                                    className="w-full text-left px-4 py-2 flex items-center gap-2 hover:bg-primary-fixed text-primary-container font-semibold text-sm">
                                    <span className="material-symbols-outlined text-[16px]">add_circle</span>
                                    Criar: “{titleCase(text)}”
                                </button>
                            </li>
                        )}
                        {tooMany && (
                            <li className="px-4 py-2 flex items-center gap-2 text-error text-sm">
                                <span className="material-symbols-outlined text-[16px]">error</span>
                                Cada palavra-chave pode ter no máximo 5 palavras.
                            </li>
                        )}
                    </ul>
                )}
            </div>

            <p className={`text-xs mt-1 ${value.length >= min ? 'text-secondary' : 'text-on-surface-variant'}`}>
                {value.length} / {max} · mínimo {min}; cada uma com 1 a 5 palavras.
            </p>
        </div>
    );
}
