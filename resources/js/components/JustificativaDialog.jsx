import { useState } from 'react';
import { Alert, Button } from './ui.jsx';

const MIN_JUSTIFICATIVA = 5;

/**
 * Pede a justificativa de uma alteração que fica registrada (Sprint 251): o
 * título diz o que vai acontecer, `projeto` o alvo e `ajuda` onde o registro
 * vai parar.
 */
export default function JustificativaDialog({ titulo, projeto, acao, ajuda, salvando, erro, onConfirmar, onFechar }) {
    const [texto, setTexto] = useState('');
    const pode = texto.trim().length >= MIN_JUSTIFICATIVA;

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label={titulo}>
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">{titulo}</h3>
                    {projeto && <p className="text-sm text-on-surface-variant truncate">{projeto}</p>}
                </div>
                {erro && <Alert>{erro}</Alert>}
                <label className="block">
                    <span className="text-sm font-semibold text-on-surface">Justificativa <span className="text-error">*</span></span>
                    <textarea
                        value={texto}
                        onChange={(e) => setTexto(e.target.value)}
                        rows={4}
                        maxLength={1000}
                        className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                    />
                    {ajuda && <span className="text-xs text-on-surface-variant">{ajuda}</span>}
                </label>
                <div className="flex justify-end gap-3">
                    <Button type="button" variant="outline" onClick={onFechar} disabled={salvando}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={!pode} onClick={() => onConfirmar(texto.trim())}>{acao}</Button>
                </div>
            </div>
        </div>
    );
}
