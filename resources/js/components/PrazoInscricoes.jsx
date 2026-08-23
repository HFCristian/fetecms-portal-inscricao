import { Alert } from './ui.jsx';

/**
 * Aviso do prazo de submissão na área do orientador: enquanto está aberto,
 * lembra a data; depois de encerrado, explica por que os botões sumiram.
 *
 * `inscricoes` é o payload de GET /inscricoes (ou o campo `inscricoes` do resumo).
 */
export default function PrazoInscricoes({ inscricoes, className = '' }) {
    if (!inscricoes?.prazo_label) return null;

    if (inscricoes.encerradas) {
        return (
            <div className={className}>
                <Alert>
                    As inscrições foram encerradas em <strong>{inscricoes.prazo_label}</strong>. Seus
                    projetos continuam visíveis, mas não é mais possível criar, editar, submeter ou
                    cancelar. Fale com a organização pelo suporte se precisar de ajuda.
                </Alert>
            </div>
        );
    }

    return (
        <p className={`text-sm text-on-surface-variant flex items-center gap-1 ${className}`}>
            <span className="material-symbols-outlined text-[18px]">event_available</span>
            Inscrições abertas até <strong className="text-on-surface">{inscricoes.prazo_label}</strong>.
        </p>
    );
}
