import { useState } from 'react';

export const compararTexto = (a, b) =>
    String(a ?? '').localeCompare(String(b ?? ''), 'pt-BR', { sensitivity: 'base' });

/**
 * Monta as opções "maior primeiro"/"menor primeiro" de cada métrica numérica.
 * metricas: [{ key, label }] — key é o campo do item.
 */
export function ordensDe(metricas) {
    return metricas.flatMap(({ key, label }) => [
        { value: `${key}:desc`, label: `${label} (maior primeiro)`, key, dir: -1 },
        { value: `${key}:asc`, label: `${label} (menor primeiro)`, key, dir: 1 },
    ]);
}

const selectClass =
    'bg-surface border border-outline-variant rounded-lg px-2 py-1 text-xs text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

/**
 * Card de uma área do conhecimento com a lista compactável dos seus itens
 * (projetos ou avaliadores) e ordenação própria.
 *
 * O cabeçalho inteiro abre/fecha; a ordenação vive ao lado dele e só aparece
 * com a lista aberta — cada área ordena a sua própria lista. Empate na métrica
 * cai para a ordem alfabética.
 */
export default function GrupoArea({
    titulo, itens, singular, plural, aberto, onToggle,
    rotuloKey = 'nome', metricas = [], ordemPadraoLabel = 'Ordem alfabética', renderItem,
}) {
    const [ordem, setOrdem] = useState('');
    const opcoes = ordensDe(metricas);
    const escolhida = opcoes.find((o) => o.value === ordem);

    const lista = [...itens].sort((a, b) => {
        if (escolhida) {
            const diff = ((a[escolhida.key] ?? 0) - (b[escolhida.key] ?? 0)) * escolhida.dir;
            if (diff !== 0) return diff;
        }
        return compararTexto(a[rotuloKey], b[rotuloKey]);
    });

    const n = itens.length;

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
            <div className="px-4 py-3 bg-surface-variant/40 flex items-center gap-2">
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={aberto}
                    className="flex-1 min-w-0 flex items-center gap-2 text-left"
                >
                    <span className="material-symbols-outlined text-on-surface-variant text-[20px] shrink-0">
                        {aberto ? 'expand_more' : 'chevron_right'}
                    </span>
                    <h2 className="font-display font-semibold text-on-surface truncate">{titulo}</h2>
                    <span className="text-xs text-on-surface-variant shrink-0">
                        {n} {n === 1 ? singular : plural}
                    </span>
                </button>

                {aberto && metricas.length > 0 && (
                    <label className="shrink-0 flex items-center gap-1 text-xs text-on-surface-variant">
                        <span className="hidden sm:inline">Ordenar por</span>
                        <select
                            className={selectClass}
                            value={ordem}
                            aria-label={`Ordenar ${plural} de ${titulo}`}
                            onChange={(e) => setOrdem(e.target.value)}
                        >
                            <option value="">{ordemPadraoLabel}</option>
                            {opcoes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </label>
                )}
            </div>

            {aberto && (
                n === 0
                    ? <p className="px-4 py-3 text-sm text-on-surface-variant">Nenhum item nesta área.</p>
                    : <ul className="divide-y divide-outline-variant/30">{lista.map(renderItem)}</ul>
            )}
        </div>
    );
}

/** Controla quais áreas estão abertas + os botões "expandir/recolher todas". */
export function useAreasAbertas() {
    const [abertos, setAbertos] = useState(() => new Set());

    return {
        estaAberto: (id) => abertos.has(id),
        alternar: (id) => setAbertos((s) => {
            const novo = new Set(s);
            if (!novo.delete(id)) novo.add(id);
            return novo;
        }),
        abrirTodas: (ids) => setAbertos(new Set(ids)),
        fecharTodas: () => setAbertos(new Set()),
        algumAberto: abertos.size > 0,
    };
}

/** Par de botões "Expandir todas"/"Recolher todas" acima da lista de áreas. */
export function BotoesExpandir({ ids, controle }) {
    const todasAbertas = ids.length > 0 && ids.every((id) => controle.estaAberto(id));

    return (
        <button
            type="button"
            onClick={() => (todasAbertas ? controle.fecharTodas() : controle.abrirTodas(ids))}
            className="inline-flex items-center gap-1 text-sm font-semibold text-primary-container hover:text-primary border border-outline-variant rounded-lg px-3 py-1.5 hover:bg-surface-variant transition-colors"
        >
            <span className="material-symbols-outlined text-[18px]">
                {todasAbertas ? 'unfold_less' : 'unfold_more'}
            </span>
            {todasAbertas ? 'Recolher todas' : 'Expandir todas'}
        </button>
    );
}
