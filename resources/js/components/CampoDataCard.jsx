import { useEffect, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';

/**
 * Card de uma data-chave da edição (abertura/prazo das inscrições, liberação/
 * encerramento da avaliação). A hora vai como "hora de parede" — o servidor a
 * interpreta no fuso de Campo Grande, sem shift de UTC do navegador.
 *
 * `onSalvar(valor|null)` devolve a resposta da API ({ data, meta }); o card
 * repassa o novo config para `onSalvo` e mostra a mensagem do backend — que é
 * também onde moram os erros de ordem (abertura depois do prazo, por exemplo).
 */
export default function CampoDataCard({
    titulo, descricao, status, valor, ariaLabel, salvarLabel = 'Salvar data',
    onSalvar, onSalvo, className = 'mb-6',
}) {
    const [campo, setCampo] = useState(valor || '');
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    useEffect(() => { setCampo(valor || ''); }, [valor]);

    async function salvar(data) {
        setSalvando(true); setMsg(''); setErro('');
        try {
            const resp = await onSalvar(data);
            onSalvo?.(resp.data);
            setMsg(resp.meta?.message || 'Data salva.');
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível salvar. Tente novamente.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div className={`bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-3xl ${className}`}>
            <div className="flex items-center gap-2 flex-wrap mb-3">
                <h2 className="font-display text-primary font-semibold">{titulo}</h2>
                {status && <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${status.cor}`}>{status.txt}</span>}
            </div>
            <div className="text-sm text-on-surface-variant mb-3">{descricao}</div>

            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

            <div className="flex items-end gap-2 flex-wrap">
                <input
                    type="datetime-local"
                    aria-label={ariaLabel ?? titulo}
                    value={campo}
                    onChange={(e) => setCampo(e.target.value)}
                    className="bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                />
                <Button type="button" loading={salvando} disabled={!campo} onClick={() => salvar(campo || null)}>
                    {salvarLabel}
                </Button>
                {valor && (
                    <Button type="button" variant="outline" disabled={salvando} onClick={() => salvar(null)}>
                        Remover
                    </Button>
                )}
            </div>
        </div>
    );
}
