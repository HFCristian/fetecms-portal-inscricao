import { useEffect, useState } from 'react';
import { getEdicoes, trocarEdicao } from '../lib/edicoes.js';

/**
 * Seletor de edição da feira, no topo do menu de qualquer papel.
 *
 * Trocar de edição troca TODO o escopo — projetos, prazos, limites, avaliações
 * —, então a tela é recarregada em seguida: manter na tela dados de uma edição
 * e ações de outra seria pior do que o pisca-pisca do reload.
 *
 * Com uma edição só (o caso normal durante o ano), o seletor não aparece: não
 * há o que escolher.
 */
export default function SeletorEdicao({ onTrocar }) {
    const [dados, setDados] = useState(null);
    const [trocando, setTrocando] = useState(false);

    useEffect(() => {
        getEdicoes().then(setDados).catch(() => setDados(null));
    }, []);

    const edicoes = dados?.edicoes ?? [];
    if (edicoes.length < 2) return null;

    async function trocar(e) {
        const valor = e.target.value;
        setTrocando(true);
        try {
            await trocarEdicao(valor === '' ? null : Number(valor));
            if (onTrocar) onTrocar();
            else window.location.reload();
        } catch {
            setTrocando(false);
        }
    }

    return (
        <label className="block mb-3">
            <span className="text-xs font-semibold text-on-surface-variant">Edição</span>
            <select
                value={dados.atual_id ?? ''}
                onChange={trocar}
                disabled={trocando}
                aria-label="Edição da feira"
                className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20 disabled:opacity-60"
            >
                {edicoes.map((e) => (
                    <option key={e.id} value={e.id}>
                        {e.nome} ({e.ano}){e.padrao ? ' · padrão' : ''}
                    </option>
                ))}
            </select>
        </label>
    );
}
