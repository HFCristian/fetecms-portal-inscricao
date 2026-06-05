import { useState } from 'react';
import { Alert } from './ui.jsx';
import { enviarDocumento, removerDocumento } from '../lib/documentos.js';
import { extractErrors } from '../lib/auth.jsx';

function formatBytes(b) {
    return b > 1024 * 1024 ? (b / 1024 / 1024).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB';
}

/**
 * Campo de anexo para um tipo de documento, com pré-visualização:
 * PDF é exibido inline (iframe); DOCX mostra cartão com nome/tamanho + baixar.
 */
export default function DocumentoUpload({ projetoId, tipo, label, required, documentos, onChanged }) {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState('');

    const docs = (documentos ?? []).filter((d) => d.tipo === tipo);

    async function onUpload(e) {
        const file = e.target.files?.[0];
        if (!file) return;
        setError('');
        setUploading(true);
        try {
            await enviarDocumento(projetoId, file, tipo);
            await onChanged();
        } catch (err) {
            setError(extractErrors(err).message);
        } finally {
            setUploading(false);
            e.target.value = '';
        }
    }

    async function onRemove(docId) {
        await removerDocumento(docId);
        await onChanged();
    }

    return (
        <div>
            <label className="text-sm font-semibold text-on-surface">
                {label} {required && <span className="text-error">*</span>}
            </label>

            {!projetoId ? (
                <p className="mt-1 text-sm text-on-surface-variant italic">
                    Salve o rascunho para habilitar o anexo deste arquivo.
                </p>
            ) : (
                <div className="mt-2 space-y-3">
                    {error && <Alert>{error}</Alert>}

                    <label className="inline-flex items-center gap-2 rounded-lg px-4 py-2 font-semibold border-2 border-primary-container text-primary-container hover:bg-primary-fixed cursor-pointer text-sm">
                        <span className="material-symbols-outlined text-[18px]">upload_file</span>
                        {uploading ? 'Enviando…' : (docs.length ? 'Enviar outro arquivo' : 'Selecionar PDF ou DOCX')}
                        <input type="file" accept=".pdf,.docx" className="sr-only" onChange={onUpload} disabled={uploading} />
                    </label>

                    {docs.map((d) => (
                        <div key={d.id} className="rounded-lg border border-outline-variant overflow-hidden">
                            <div className="flex items-center gap-3 bg-surface-container-low px-3 py-2">
                                <span className="material-symbols-outlined text-primary-container">description</span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-on-surface truncate">{d.nome_original}</p>
                                    <p className="text-xs text-on-surface-variant">{formatBytes(d.tamanho_bytes)}</p>
                                </div>
                                <a href={d.download_url} target="_blank" rel="noreferrer" className="text-sm text-primary-container hover:underline">Baixar</a>
                                <button type="button" onClick={() => onRemove(d.id)} className="text-sm text-error hover:underline">Remover</button>
                            </div>
                            {d.is_pdf ? (
                                <iframe
                                    src={d.preview_url}
                                    title={`Pré-visualização de ${d.nome_original}`}
                                    className="w-full bg-surface"
                                    style={{ height: '420px' }}
                                    loading="lazy"
                                />
                            ) : (
                                <div className="px-3 py-4 text-sm text-on-surface-variant bg-surface">
                                    Pré-visualização inline não disponível para DOCX. Use “Baixar” para abrir o arquivo.
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
