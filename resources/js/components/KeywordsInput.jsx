import { useState } from 'react';

export default function KeywordsInput({ value = [], onChange, max = 5, min = 3 }) {
    const [text, setText] = useState('');

    function add() {
        const kw = text.trim();
        if (!kw) return;
        if (value.length >= max) return;
        if (value.some((v) => v.toLowerCase() === kw.toLowerCase())) {
            setText('');
            return;
        }
        onChange([...value, kw]);
        setText('');
    }

    function remove(i) {
        onChange(value.filter((_, idx) => idx !== i));
    }

    return (
        <div>
            <div className="flex flex-wrap gap-2 mb-2">
                {value.map((kw, i) => (
                    <span
                        key={kw}
                        className="inline-flex items-center gap-1 bg-primary-fixed text-primary-container rounded-full pl-3 pr-1 py-1 text-sm font-semibold"
                    >
                        {kw}
                        <button
                            type="button"
                            onClick={() => remove(i)}
                            className="flex items-center justify-center w-5 h-5 rounded-full hover:bg-primary-container hover:text-white"
                            aria-label={`Remover ${kw}`}
                        >
                            <span className="material-symbols-outlined text-[14px]">close</span>
                        </button>
                    </span>
                ))}
            </div>
            <input
                value={text}
                onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        add();
                    }
                }}
                disabled={value.length >= max}
                placeholder={value.length >= max ? 'Máximo de 5 atingido' : 'Digite e tecle Enter…'}
                className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-on-surface placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none disabled:opacity-60"
            />
            <p className={`text-xs mt-1 ${value.length >= min ? 'text-secondary' : 'text-on-surface-variant'}`}>
                {value.length} / {max} · mínimo {min} (validação completa na submissão)
            </p>
        </div>
    );
}
