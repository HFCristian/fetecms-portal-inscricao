import { Alert } from './ui.jsx';

/**
 * Aviso da janela de inscrição na área do orientador: antes da abertura e
 * depois do encerramento explica por que os botões sumiram; no meio, lembra
 * até quando dá para submeter.
 *
 * `inscricoes` é o payload de GET /inscricoes (ou o campo `inscricoes` do resumo).
 */
export default function PrazoInscricoes({ inscricoes, className = '' }) {
    if (inscricoes?.nao_iniciadas) {
        return (
            <div className={className}>
                <Alert>
                    As inscrições ainda não começaram
                    {inscricoes.inicio_label ? <> — abrem em <strong>{inscricoes.inicio_label}</strong></> : null}.
                    Você já pode conferir seus dados; o cadastro de projetos libera na abertura.
                </Alert>
            </div>
        );
    }

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
