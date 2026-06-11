import { useEffect, useState } from 'react';

const errorClass = 'border-error focus:border-error focus:ring-error/20';

const inputClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-on-surface ' +
    'placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 ' +
    'transition-all outline-none';

export function Field({ label, error, required, children, hint }) {
    return (
        <div className="flex flex-col gap-1">
            {label && (
                <label className="text-sm font-semibold text-on-surface">
                    {label} {required && <span className="text-error">*</span>}
                </label>
            )}
            {children}
            {hint && !error && <p className="text-xs text-on-surface-variant">{hint}</p>}
            {error && <p className="text-xs text-error">{error}</p>}
        </div>
    );
}

export function Input({ error, ...props }) {
    return (
        <input
            {...props}
            className={`${inputClass} ${error ? 'border-error focus:border-error focus:ring-error/20' : ''}`}
        />
    );
}

// Converte ISO (yyyy-mm-dd) -> exibição br (dd/mm/aaaa). String vazia se inválido.
function isoToBr(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso ?? '');
    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

// Converte br (dd/mm/aaaa) -> ISO (yyyy-mm-dd). String vazia se incompleto/inválido.
function brToIso(br) {
    const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(br ?? '');
    return m ? `${m[3]}-${m[2]}-${m[1]}` : '';
}

// Aplica a máscara dd/mm/aaaa enquanto o usuário digita (só dígitos, barras automáticas).
function maskBr(value) {
    const d = (value ?? '').replace(/\D/g, '').slice(0, 8);
    if (d.length <= 2) return d;
    if (d.length <= 4) return `${d.slice(0, 2)}/${d.slice(2)}`;
    return `${d.slice(0, 2)}/${d.slice(2, 4)}/${d.slice(4)}`;
}

// Campo de data no formato brasileiro (dd/mm/aaaa) que mantém o valor em ISO
// (yyyy-mm-dd) no estado do form/API. Compatível com o helper set(name) que lê
// e.target.value. Emite '' enquanto a data estiver incompleta.
export function DateInput({ value, onChange, error, ...props }) {
    const [text, setText] = useState(() => isoToBr(value));

    // Ressincroniza quando o valor externo (ISO) for diferente do que está digitado,
    // sem apagar o texto parcial enquanto o usuário ainda digita.
    useEffect(() => {
        if ((value || '') !== (brToIso(text) || '')) setText(isoToBr(value));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    function handleChange(e) {
        const masked = maskBr(e.target.value);
        setText(masked);
        onChange?.({ target: { value: brToIso(masked) } });
    }

    return (
        <input
            {...props}
            type="text"
            inputMode="numeric"
            placeholder="dd/mm/aaaa"
            maxLength={10}
            value={text}
            onChange={handleChange}
            className={`${inputClass} ${error ? 'border-error focus:border-error focus:ring-error/20' : ''}`}
        />
    );
}

// Máscara de CPF: 000.000.000-00 (até 11 dígitos).
function maskCpf(value) {
    const d = (value ?? '').replace(/\D/g, '').slice(0, 11);
    if (d.length > 9) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
    if (d.length > 6) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6)}`;
    if (d.length > 3) return `${d.slice(0, 3)}.${d.slice(3)}`;
    return d;
}

// Máscara de telefone BR: (00) 0000-0000 (fixo) ou (00) 00000-0000 (celular).
function maskTelefone(value) {
    const d = (value ?? '').replace(/\D/g, '').slice(0, 11);
    if (d.length === 0) return '';
    if (d.length <= 2) return `(${d}`;
    const ddd = d.slice(0, 2);
    const resto = d.slice(2);
    if (resto.length <= 4) return `(${ddd}) ${resto}`;
    if (d.length <= 10) return `(${ddd}) ${resto.slice(0, 4)}-${resto.slice(4)}`;
    return `(${ddd}) ${resto.slice(0, 5)}-${resto.slice(5)}`;
}

// Máscara de CEP: 00000-000 (até 8 dígitos).
function maskCep(value) {
    const d = (value ?? '').replace(/\D/g, '').slice(0, 8);
    if (d.length > 5) return `${d.slice(0, 5)}-${d.slice(5)}`;
    return d;
}

// Input com máscara que mantém o valor já formatado no form. O backend normaliza
// para apenas dígitos (prepareForValidation), então enviar o valor mascarado é seguro.
// Segue o mesmo padrão controlado do DateInput: só ressincroniza com o valor externo
// quando os dígitos diferem (não atrapalha a digitação).
function MaskedInput({ value, onChange, error, mask, ...props }) {
    const onlyDigits = (s) => (s ?? '').replace(/\D/g, '');
    const [text, setText] = useState(() => mask(value));

    useEffect(() => {
        if (onlyDigits(value) !== onlyDigits(text)) setText(mask(value));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    function handleChange(e) {
        const masked = mask(e.target.value);
        setText(masked);
        onChange?.({ target: { value: masked } });
    }

    return (
        <input
            {...props}
            type="text"
            inputMode="numeric"
            value={text}
            onChange={handleChange}
            className={`${inputClass} ${error ? errorClass : ''}`}
        />
    );
}

export function CpfInput(props) {
    return <MaskedInput mask={maskCpf} maxLength={14} placeholder="000.000.000-00" {...props} />;
}

export function TelefoneInput(props) {
    return <MaskedInput mask={maskTelefone} maxLength={15} placeholder="(00) 00000-0000" {...props} />;
}

export function CepInput(props) {
    return <MaskedInput mask={maskCep} maxLength={9} placeholder="00000-000" {...props} />;
}

export function Select({ error, children, ...props }) {
    return (
        <select
            {...props}
            className={`${inputClass} appearance-none ${error ? 'border-error' : ''}`}
        >
            {children}
        </select>
    );
}

export function Button({ variant = 'primary', loading, children, className = '', ...props }) {
    const variants = {
        primary: 'bg-primary-container text-on-primary hover:bg-primary',
        success: 'bg-secondary text-on-secondary hover:bg-on-secondary-container',
        outline: 'border-2 border-primary-container text-primary-container hover:bg-primary-fixed',
    };
    return (
        <button
            {...props}
            disabled={loading || props.disabled}
            className={`inline-flex items-center justify-center gap-2 rounded-lg px-5 py-2.5 font-semibold transition-colors disabled:opacity-60 disabled:cursor-not-allowed ${variants[variant]} ${className}`}
        >
            {loading && (
                <span className="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>
            )}
            {children}
        </button>
    );
}

export function Toggle({ checked, onChange, label, description }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <div>
                <span className="text-sm font-semibold text-on-surface">{label}</span>
                {description && <p className="text-xs text-on-surface-variant mt-0.5">{description}</p>}
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`relative w-11 h-6 rounded-full transition-colors shrink-0 mt-0.5 ${checked ? 'bg-primary-container' : 'bg-surface-variant'}`}
            >
                <span className={`absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform ${checked ? 'translate-x-5' : ''}`} />
            </button>
        </div>
    );
}

export function Alert({ children, type = 'error' }) {
    if (!children) return null;
    const styles = {
        error: 'bg-error-container text-on-error-container',
        info: 'bg-surface-container-low text-on-surface border border-outline-variant',
    };
    return (
        <div className={`flex items-start gap-2 rounded-lg p-3 text-sm ${styles[type]}`} role="alert">
            <span className="material-symbols-outlined text-[20px] shrink-0">
                {type === 'error' ? 'error' : 'info'}
            </span>
            <span>{children}</span>
        </div>
    );
}

// Caixa de diálogo de confirmação (substitui window.confirm/window.alert).
// Exibe a mensagem e os botões de confirmar/cancelar. Use via o hook useConfirm.
export function ConfirmDialog({
    open, title = 'Confirmar ação', message,
    confirmLabel = 'Confirmar', cancelLabel = 'Cancelar',
    danger = false, hideCancel = false, onConfirm, onCancel,
}) {
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div className="flex items-center gap-3">
                    <span className={`material-symbols-outlined text-[28px] ${danger ? 'text-error' : 'text-primary-container'}`}>
                        {danger ? 'warning' : 'help'}
                    </span>
                    <h3 className="font-display text-xl font-semibold text-on-surface">{title}</h3>
                </div>
                {message && <p className="text-sm text-on-surface-variant whitespace-pre-line">{message}</p>}
                <div className="flex justify-end gap-3 pt-1">
                    {!hideCancel && (
                        <Button type="button" variant="outline" onClick={onCancel}>{cancelLabel}</Button>
                    )}
                    <Button type="button" variant={danger ? 'primary' : 'success'} onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </div>
            </div>
        </div>
    );
}

// Hook que devolve [confirm, dialogo]. confirm(opts) abre a caixa e resolve uma Promise
// com true/false. opts pode ser uma string (mensagem) ou um objeto
// { title, message, confirmLabel, cancelLabel, danger, hideCancel }.
export function useConfirm() {
    const [state, setState] = useState(null);

    const confirm = (opts) =>
        new Promise((resolve) => {
            const o = typeof opts === 'string' ? { message: opts } : (opts || {});
            setState({ ...o, resolve });
        });

    function fechar(valor) {
        if (state) state.resolve(valor);
        setState(null);
    }

    const dialogo = (
        <ConfirmDialog
            open={!!state}
            title={state?.title}
            message={state?.message}
            confirmLabel={state?.confirmLabel}
            cancelLabel={state?.cancelLabel}
            danger={state?.danger}
            hideCancel={state?.hideCancel}
            onConfirm={() => fechar(true)}
            onCancel={() => fechar(false)}
        />
    );

    return [confirm, dialogo];
}
