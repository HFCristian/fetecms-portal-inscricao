import { useEffect, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { getOpcoesExportacaoLista, exportarListaFinal } from '../lib/admin.js';

/**
 * Lista final → **Exportar** (Sprint 160).
 *
 * A mesma lista atende a meia dúzia de trabalhos do evento, cada um com outras
 * colunas: a lista nominal do credenciamento, os crachás, os certificados, os
 * contatos, a planilha por projeto. Os **modelos prontos** baixam direto; o
 * construtor abaixo deixa escolher o nível (por pessoa ou por projeto), as
 * colunas e o formato.
 */
export default function ExportarListaFinal({ listaId }) {
    const [opcoes, setOpcoes] = useState(null);
    const [nivel, setNivel] = useState('pessoa');
    const [colunas, setColunas] = useState([]);
    const [formato, setFormato] = useState('xlsx');
    const [baixando, setBaixando] = useState(null);
    const [erro, setErro] = useState('');

    useEffect(() => {
        getOpcoesExportacaoLista()
            .then((o) => {
                setOpcoes(o);
                const nominal = o.modelos.find((m) => m.chave === 'nominal');
                if (nominal) { setNivel(nominal.nivel); setColunas(nominal.colunas); }
            })
            .catch(() => setErro('Não foi possível carregar as opções de exportação.'));
    }, []);

    async function baixar(chave, criterio) {
        setBaixando(chave); setErro('');
        try {
            await exportarListaFinal(listaId, criterio);
        } catch {
            setErro('Não foi possível gerar o arquivo.');
        } finally {
            setBaixando(null);
        }
    }

    function trocarNivel(novo) {
        setNivel(novo);
        // As colunas de um nível não valem no outro: começa com as três primeiras.
        setColunas((opcoes?.colunas?.[novo] ?? []).slice(0, 3).map((c) => c.chave));
    }

    const alternar = (chave) => setColunas((atuais) => (
        atuais.includes(chave) ? atuais.filter((c) => c !== chave) : [...atuais, chave]
    ));

    if (!opcoes && !erro) return null;

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mt-6 max-w-3xl" aria-label="Exportar a lista">
            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Exportar</h2>
            <p className="text-sm text-on-surface-variant mb-3">
                A lista em planilha, com as colunas de cada uso. Os modelos baixam direto; abaixo dá para
                montar o seu recorte.
            </p>

            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

            {opcoes && (
                <>
                    <ul className="grid sm:grid-cols-2 gap-2 mb-5">
                        {opcoes.modelos.map((m) => (
                            <li key={m.chave} className="rounded-lg border border-outline-variant/50 p-3 flex flex-col gap-2">
                                <div>
                                    <p className="text-sm font-semibold text-on-surface">{m.rotulo}</p>
                                    <p className="text-xs text-on-surface-variant">{m.descricao}</p>
                                </div>
                                <div className="flex gap-2 mt-auto">
                                    {['xlsx', 'csv'].map((f) => (
                                        <Button
                                            key={f}
                                            type="button"
                                            variant="outline"
                                            loading={baixando === `${m.chave}-${f}`}
                                            onClick={() => baixar(`${m.chave}-${f}`, { nivel: m.nivel, colunas: m.colunas, formato: f, modelo: m.chave })}
                                        >
                                            <span className="material-symbols-outlined text-[18px]">download</span>
                                            {f === 'xlsx' ? 'Excel' : 'CSV'}
                                        </Button>
                                    ))}
                                </div>
                            </li>
                        ))}
                    </ul>

                    <fieldset className="border-t border-outline-variant/40 pt-4">
                        <legend className="text-sm font-semibold text-on-surface">Montar recorte</legend>
                        <div className="flex flex-wrap gap-4 text-sm my-2">
                            {opcoes.niveis.map((n) => (
                                <label key={n.valor} className="inline-flex items-center gap-2">
                                    <input type="radio" name="nivel-exportacao" checked={nivel === n.valor} onChange={() => trocarNivel(n.valor)} />
                                    {n.rotulo}
                                </label>
                            ))}
                        </div>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-1 text-sm mb-3">
                            {(opcoes.colunas[nivel] ?? []).map((c) => (
                                <label key={c.chave} className="inline-flex items-center gap-2">
                                    <input type="checkbox" checked={colunas.includes(c.chave)} onChange={() => alternar(c.chave)} />
                                    {c.rotulo}
                                </label>
                            ))}
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <select
                                aria-label="Formato"
                                value={formato}
                                onChange={(e) => setFormato(e.target.value)}
                                className="rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                            >
                                <option value="xlsx">Excel (.xlsx)</option>
                                <option value="csv">CSV</option>
                            </select>
                            <Button
                                type="button"
                                disabled={colunas.length === 0}
                                loading={baixando === 'recorte'}
                                onClick={() => baixar('recorte', {
                                    nivel,
                                    // Na ordem da tela, não na ordem em que foram marcadas.
                                    colunas: (opcoes.colunas[nivel] ?? []).map((c) => c.chave).filter((c) => colunas.includes(c)),
                                    formato,
                                })}
                            >
                                <span className="material-symbols-outlined text-[20px]">table_view</span>
                                Baixar recorte
                            </Button>
                        </div>
                    </fieldset>
                </>
            )}
        </section>
    );
}
