import { useState } from 'react';
import { Button, Alert } from './ui.jsx';

const MIN = 5;

/**
 * Submissão de um rascunho pelo ADMIN (Projetos em rascunho).
 *
 * O orientador perdeu o prazo e quem envia é a organização: é um escape do
 * edital, então a justificativa é obrigatória e entra em Registros → Rascunhos
 * junto de tudo que o admin mexeu na inscrição.
 */
export default function SubmeterRascunhoDialog({ projeto, orientador, salvando, erro, onSubmeter, onFechar }) {
    const [justificativa, setJustificativa] = useState('');

    const texto = justificativa.trim();
    const podeSubmeter = texto.length >= MIN;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Submeter inscrição</h3>
                    <p className="text-sm text-on-surface-variant truncate">{projeto?.titulo || 'Sem título'}</p>
                    {orientador && (
                        <p className="text-xs text-on-surface-variant truncate">Orientador: {orientador}</p>
                    )}
                </div>

                <Alert type="info">
                    Você está submetendo a inscrição de outra pessoa, fora do prazo. A submissão é
                    irreversível para o orientador e fica registrada no seu nome.
                </Alert>

                {erro && <Alert>{erro}</Alert>}

                <label className="block">
                    <span className="text-sm font-semibold text-on-surface">
                        Justificativa <span className="text-error">*</span>
                    </span>
                    <textarea
                        value={justificativa}
                        onChange={(e) => setJustificativa(e.target.value)}
                        rows={4}
                        maxLength={500}
                        placeholder="Por que a organização está submetendo esta inscrição?"
                        className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                    />
                    <span className="text-xs text-on-surface-variant">
                        Mínimo de {MIN} caracteres. Fica visível em Registros → Rascunhos.
                    </span>
                </label>

                <div className="flex justify-end gap-3 pt-1">
                    <Button variant="outline" type="button" onClick={onFechar} disabled={salvando}>Cancelar</Button>
                    <Button
                        variant="success"
                        type="button"
                        loading={salvando}
                        disabled={!podeSubmeter}
                        onClick={() => onSubmeter(texto)}
                    >
                        <span className="material-symbols-outlined text-[20px]">verified</span>
                        Submeter
                    </Button>
                </div>
            </div>
        </div>
    );
}
