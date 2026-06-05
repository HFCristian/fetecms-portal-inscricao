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
        <div className="flex items-start gap-3">
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`relative w-11 h-6 rounded-full transition-colors shrink-0 mt-0.5 ${checked ? 'bg-primary-container' : 'bg-surface-variant'}`}
            >
                <span className={`absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform ${checked ? 'translate-x-5' : ''}`} />
            </button>
            <div>
                <span className="text-sm font-semibold text-on-surface">{label}</span>
                {description && <p className="text-xs text-on-surface-variant mt-0.5">{description}</p>}
            </div>
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
