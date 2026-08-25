import { useEffect, useRef, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';

/**
 * Card de um parâmetro numérico da edição (os mínimos de avaliações). Mesma
 * mecânica do CampoDataCard: `onSalvar(valor)` devolve a resposta da API
 * ({ data, meta }), o card repassa o novo config para `onSalvo` e mostra a
 * mensagem — ou o erro de validação — vinda do backend.
 */
export default function CampoNumeroCard({
    titulo, descricao, valor, ariaLabel, salvarLabel = 'Salvar',
    min = 1, max = 50, onSalvar, onSalvo, className = 'mb-6',
}) {
    const [campo, setCampo] = useState(valor ?? '');
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    // Sincroniza só quando o valor do servidor MUDA. Sem a guarda, o efeito de
    // montagem chega depois do primeiro toque e apaga o que a pessoa digitou.
    const anterior = useRef(valor);
    useEffect(() => {
        if (anterior.current !== valor) {
            anterior.current = valor;
            setCampo(valor ?? '');
        }
    }, [valor]);

    const numero = Number(campo);
    const valido = campo !== '' && Number.isInteger(numero) && numero >= min && numero <= max;

    async function salvar() {
        setSalvando(true); setMsg(''); setErro('');
        try {
            const resp = await onSalvar(numero);
            onSalvo?.(resp.data);
            setMsg(resp.meta?.message || 'Valor salvo.');
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
                <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container">
                    {valor ?? '—'} {valor === 1 ? 'avaliação' : 'avaliações'}
                </span>
            </div>
            <div className="text-sm text-on-surface-variant mb-3">{descricao}</div>

            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

            <div className="flex items-end gap-2 flex-wrap">
                <input
                    type="number"
                    inputMode="numeric"
                    min={min}
                    max={max}
                    aria-label={ariaLabel ?? titulo}
                    value={campo}
                    onChange={(e) => setCampo(e.target.value)}
                    className="w-24 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                />
                <Button type="button" loading={salvando} disabled={!valido} onClick={salvar}>
                    {salvarLabel}
                </Button>
            </div>
        </div>
    );
}
