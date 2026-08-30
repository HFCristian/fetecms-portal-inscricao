import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert } from '../components/ui.jsx';
import { getListasFinais, baixarListaOficial } from '../lib/admin.js';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

/**
 * Avaliação online → Listas finais oficiais.
 *
 * Cada lista marcada como oficial no "Gerar lista final" fica registrada aqui.
 * A **vigente** é a que define os finalistas da feira — e, no credenciamento,
 * quem passa pelo balcão. O arquivo é sempre gerado a partir da composição
 * atual, então baixar de novo depois de uma alteração traz a versão nova.
 */
export default function AvaliacaoListasFinais() {
    const [listas, setListas] = useState(null);
    const [erro, setErro] = useState('');
    const [baixando, setBaixando] = useState(null);

    const carregar = useCallback(() => {
        getListasFinais()
            .then((l) => { setListas(l); setErro(''); })
            .catch(() => setErro('Não foi possível carregar as listas.'));
    }, []);

    useEffect(() => { carregar(); }, [carregar]);

    async function baixar(id) {
        setBaixando(id);
        setErro('');
        try {
            await baixarListaOficial(id);
        } catch {
            setErro('Não foi possível baixar o arquivo.');
        } finally {
            setBaixando(null);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/avaliacao/ranking" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Ranking dos projetos
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Listas finais oficiais</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                As listas geradas com <strong>Lista Final Oficial</strong> marcada. A lista{' '}
                <strong>vigente</strong> define os finalistas da feira. O arquivo sai sempre na
                composição atual da lista, então cada alteração gera um TXT novo.
            </p>

            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

            {listas === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : listas.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm max-w-3xl">
                    Nenhuma lista oficial ainda. Gere uma no Ranking dos projetos marcando
                    “Lista Final Oficial”.
                </div>
            ) : (
                <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40 max-w-3xl">
                    {listas.map((l) => (
                        <li key={l.id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-on-surface">
                                    {l.nome}
                                    {l.vigente && (
                                        <span className="ml-2 text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">
                                            vigente
                                        </span>
                                    )}
                                </p>
                                <p className="text-xs text-on-surface-variant">
                                    versão {l.versao} · {l.projetos} {l.projetos === 1 ? 'projeto' : 'projetos'}
                                    {l.gerada_por ? ` · gerada por ${l.gerada_por}` : ''}
                                </p>
                                <p className="text-xs text-on-surface-variant">
                                    criada em {dataHora(l.criada_em)}
                                    {l.atualizada_em !== l.criada_em ? ` · alterada em ${dataHora(l.atualizada_em)}` : ''}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" loading={baixando === l.id} onClick={() => baixar(l.id)}>
                                    <span className="material-symbols-outlined text-[20px]">download</span>
                                    Baixar TXT
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
