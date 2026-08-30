import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button } from '../components/ui.jsx';
import { getFeedbacks } from '../lib/feedback.js';

/**
 * Comunicação → Feedback: a lista dos pedidos criados.
 *
 * Cada linha leva à página de resultados daquele pedido — contagem por
 * alternativa e as respostas escritas, sempre anônimas.
 */
const CORES = {
    enviando: 'bg-primary-fixed text-primary-container',
    ativo: 'bg-secondary-container text-on-secondary-container',
    encerrado: 'bg-surface-variant text-on-surface-variant',
};

function Linha({ pedido }) {
    return (
        <Link
            to={`/admin/comunicacao/feedback/${pedido.id}`}
            className="block px-4 py-3 border-b border-outline-variant/30 last:border-0 hover:bg-surface-variant/40 transition-colors"
        >
            <div className="flex items-center gap-3 flex-wrap">
                <span className="material-symbols-outlined text-primary-container">reviews</span>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-on-surface truncate">{pedido.titulo}</span>
                        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${CORES[pedido.status] ?? CORES.encerrado}`}>
                            {pedido.status_label}
                        </span>
                    </div>
                    <p className="text-xs text-on-surface-variant mt-0.5">
                        {pedido.perguntas} pergunta(s) · {pedido.publicos.join(', ')}
                        {pedido.autor && ` · por ${pedido.autor}`} · {pedido.criado_em}
                    </p>
                </div>
                <div className="text-right shrink-0">
                    <div className="text-xl font-bold text-primary-container">
                        {pedido.respostas}
                        <span className="text-sm font-normal text-on-surface-variant">/{pedido.convidados}</span>
                    </div>
                    <div className="text-xs text-on-surface-variant">respostas</div>
                </div>
                <span className="material-symbols-outlined text-on-surface-variant">chevron_right</span>
            </div>
        </Link>
    );
}

export default function AdminFeedbacks() {
    const [pedidos, setPedidos] = useState(null);

    useEffect(() => { getFeedbacks().then(setPedidos).catch(() => setPedidos([])); }, []);

    return (
        <AppShell>
            <Link to="/admin/comunicacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Comunicação
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Feedback</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Questionários dirigidos a um recorte da base. Quem é alcançado vê um convite ao entrar no
                portal e recebe um e-mail. <strong>As respostas são anônimas</strong>: os resultados dizem
                quantos responderam cada coisa, nunca quem respondeu o quê.
            </p>

            <div className="mb-6">
                <Link to="/admin/comunicacao/feedback/novo">
                    <Button type="button">
                        <span className="material-symbols-outlined text-[20px]">add</span>
                        Novo pedido de feedback
                    </Button>
                </Link>
            </div>

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow max-w-4xl overflow-hidden">
                {pedidos === null ? (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : pedidos.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-on-surface-variant">
                        Nenhum pedido de feedback criado nesta edição.
                    </p>
                ) : (
                    pedidos.map((p) => <Linha key={p.id} pedido={p} />)
                )}
            </div>
        </AppShell>
    );
}
