import { useEffect, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getOpcoesListaFinal, baixarListaFinal } from '../lib/admin.js';

const cotaClass =
    'w-20 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

/** Uma cota: campo em branco não limita nada; 0 deixa o recorte de fora. */
function Cota({ id, titulo, sigla, disponiveis, valor, onChange }) {
    return (
        <div className="flex items-center gap-2 py-1">
            <span className="text-xs font-bold text-primary-container w-9 shrink-0">{sigla}</span>
            <label htmlFor={id} className="text-sm text-on-surface flex-1 min-w-0 truncate">
                {titulo}
                <span className="text-xs text-on-surface-variant"> · {disponiveis} disponíve{disponiveis === 1 ? 'l' : 'is'}</span>
            </label>
            <input
                id={id}
                type="number"
                inputMode="numeric"
                min={0}
                placeholder="todos"
                aria-label={`Quantidade de ${titulo}`}
                value={valor ?? ''}
                onChange={(e) => onChange(e.target.value)}
                className={cotaClass}
            />
        </div>
    );
}

/**
 * "Gerar lista final": o admin escolhe quantos projetos quer no total, por
 * categoria e por área; quem entra é decidido pela média das notas, do melhor
 * para o pior. Campo em branco não limita nada. O arquivo sai agrupado por
 * categoria → área → título, com a numeração 001, 002… reiniciando a cada
 * categoria+área.
 */
export default function ListaFinalDialog({ open, onClose }) {
    const [opcoes, setOpcoes] = useState(null);
    const [total, setTotal] = useState('');
    const [categorias, setCategorias] = useState({});
    const [areas, setAreas] = useState({});
    const [gerando, setGerando] = useState(false);
    const [erro, setErro] = useState('');

    useEffect(() => {
        if (!open) return;
        setErro('');
        getOpcoesListaFinal().then(setOpcoes).catch(() => setErro('Não foi possível carregar as opções.'));
    }, [open]);

    if (!open) return null;

    const numeros = (mapa) => Object.fromEntries(
        Object.entries(mapa).filter(([, v]) => v !== '' && v !== null && v !== undefined)
            .map(([k, v]) => [k, Number(v)]),
    );

    async function gerar() {
        setGerando(true); setErro('');
        try {
            await baixarListaFinal({
                total: total === '' ? null : Number(total),
                categorias: numeros(categorias),
                areas: numeros(areas),
            });
            onClose();
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível gerar a lista. Tente novamente.');
        } finally {
            setGerando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 max-h-[90vh] overflow-auto">
                <h3 className="font-display text-xl font-semibold text-on-surface mb-1">Gerar lista final</h3>
                <p className="text-sm text-on-surface-variant mb-4">
                    Entram os projetos <strong>mais bem avaliados</strong> que couberem nas quantidades
                    abaixo — deixe em branco o que não quiser limitar. O arquivo sai agrupado por
                    categoria e área, em ordem alfabética de título, com a numeração reiniciando a cada
                    categoria+área.
                </p>

                {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

                {opcoes === null ? (
                    <div className="text-center py-8 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : (
                    <>
                        <div className="flex items-center gap-2 mb-4">
                            <label htmlFor="lista-total" className="text-sm font-semibold text-on-surface flex-1">
                                Total de projetos na lista
                                <span className="block text-xs font-normal text-on-surface-variant">
                                    {opcoes.total_disponivel} projeto(s) avaliados no total
                                </span>
                            </label>
                            <input
                                id="lista-total"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                placeholder="todos"
                                aria-label="Total de projetos na lista"
                                value={total}
                                onChange={(e) => setTotal(e.target.value)}
                                className={cotaClass}
                            />
                        </div>

                        <fieldset className="mb-4">
                            <legend className="text-sm font-semibold text-on-surface mb-1">Por categoria</legend>
                            {opcoes.categorias.map((c) => (
                                <Cota
                                    key={c.value}
                                    id={`lista-cat-${c.value}`}
                                    titulo={c.label}
                                    sigla={c.sigla}
                                    disponiveis={c.disponiveis}
                                    valor={categorias[c.value]}
                                    onChange={(v) => setCategorias((atual) => ({ ...atual, [c.value]: v }))}
                                />
                            ))}
                        </fieldset>

                        <fieldset className="mb-4">
                            <legend className="text-sm font-semibold text-on-surface mb-1">Por área do conhecimento</legend>
                            {opcoes.areas.map((a) => (
                                <Cota
                                    key={a.id}
                                    id={`lista-area-${a.id}`}
                                    titulo={a.nome}
                                    sigla={a.sigla}
                                    disponiveis={a.disponiveis}
                                    valor={areas[a.id]}
                                    onChange={(v) => setAreas((atual) => ({ ...atual, [a.id]: v }))}
                                />
                            ))}
                        </fieldset>
                    </>
                )}

                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                    <Button type="button" loading={gerando} disabled={opcoes === null} onClick={gerar}>
                        <span className="material-symbols-outlined text-[18px]">download</span>
                        Gerar TXT
                    </Button>
                </div>
            </div>
        </div>
    );
}
