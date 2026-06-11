import { useEffect, useRef, useState } from 'react';
import InstituicaoCreateDialog from './InstituicaoCreateDialog.jsx';

const inputClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-on-surface ' +
    'placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 ' +
    'transition-all outline-none disabled:opacity-60 disabled:cursor-not-allowed';

/**
 * Combobox de instituição (catálogo global). Busca no servidor conforme se digita
 * (debounce). "Cadastrar nova" abre um diálogo (nome + estado→cidade + tipo): em
 * telas autenticadas passe `create({nome,cidade_id,tipo}) => Promise<inst>` (persiste
 * na hora); sem `create` (cadastro público) emite { id:null, nome, cidade_id, tipo } e
 * o backend materializa no registro. O par (nome, cidade) diferencia homônimas.
 *
 * value: { id, nome } | null   ·   onChange(sel: { id, nome, cidade_id?, tipo? } | null)
 */
export default function InstituicaoCombobox({
    value = null, onChange, buscar, create, disabled = false, placeholder,
}) {
    const [query, setQuery] = useState(value?.nome ?? '');
    const [open, setOpen] = useState(false);
    const [resultados, setResultados] = useState([]);
    const [carregando, setCarregando] = useState(false);
    const [criando, setCriando] = useState(false);
    const [dialogAberto, setDialogAberto] = useState(false);
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

    function abrirCriacao() {
        if (q.length < 2) return;
        setOpen(false);
        setDialogAberto(true);
    }

    async function handleCriar(payload) {
        if (create) {
            setCriando(true);
            try {
                selecionar(await create(payload));
                setDialogAberto(false);
            } catch {
                // erro tratado pelo formulário pai
            } finally {
                setCriando(false);
            }
        } else {
            selecionar({ id: null, nome: payload.nome, cidade_id: payload.cidade_id, tipo: payload.tipo });
            setDialogAberto(false);
        }
    }

    function onKeyDown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (resultados.length === 1) selecionar(resultados[0]);
            else if (podeCriar) abrirCriacao();
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
                                onMouseDown={(e) => { e.preventDefault(); abrirCriacao(); }}
                                className="w-full text-left px-3 py-2 text-sm text-primary-container hover:bg-primary-fixed flex items-center gap-2"
                            >
                                <span className="material-symbols-outlined text-[18px]">add_business</span>
                                Cadastrar “{q}”…
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
            <InstituicaoCreateDialog
                open={dialogAberto}
                nomeInicial={q}
                loading={criando}
                onCancel={() => setDialogAberto(false)}
                onConfirm={handleCriar}
            />
        </div>
    );
}
