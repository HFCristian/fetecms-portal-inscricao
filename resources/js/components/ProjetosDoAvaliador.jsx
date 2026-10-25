import { useEffect, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { baixarDeclaracaoAvaliador, getProjetosDoAvaliador } from '../lib/certificados.js';

/**
 * Os projetos que um avaliador avaliou, por fase, com o PDF da declaração
 * nominal — aberto em "Ver projetos avaliados" na conferência de nomes.
 */
export default function ProjetosDoAvaliador({ avaliadorId, onFechar }) {
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');

    useEffect(() => {
        getProjetosDoAvaliador(avaliadorId).then(setDados).catch(() => setErro('Não foi possível carregar os projetos.'));
    }, [avaliadorId]);

    return (
        <div className="mt-2 rounded-lg border border-outline-variant/50 p-3 text-sm space-y-3">
            {erro && <Alert>{erro}</Alert>}
            {!dados && !erro && <p className="text-xs text-on-surface-variant">Carregando…</p>}
            {dados && ['online', 'presencial'].map((fase) => (
                <div key={fase}>
                    <p className="font-semibold text-on-surface">
                        Fase {fase} — {dados[fase].length} projeto(s)
                    </p>
                    {dados[fase].length === 0 ? (
                        <p className="text-xs text-on-surface-variant">Nenhuma avaliação concluída.</p>
                    ) : (
                        <ol className="list-decimal pl-5 text-xs text-on-surface">
                            {dados[fase].map((p) => (
                                <li key={`${fase}-${p.projeto_id}`}>
                                    {p.titulo} <span className="text-on-surface-variant">· {p.area ?? '—'} · {p.concluida_em}</span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            ))}
            <div className="flex flex-wrap gap-2 justify-end">
                <Button type="button" variant="outline" onClick={onFechar}>Fechar</Button>
                <Button type="button" onClick={() => baixarDeclaracaoAvaliador(avaliadorId)}>
                    <span className="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                    Declaração (PDF)
                </Button>
            </div>
        </div>
    );
}
