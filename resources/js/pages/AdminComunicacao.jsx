import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';

// Card de acesso a um canal de comunicação com a base.
function CardComunicacao({ to, icon, titulo, descricao }) {
    return (
        <Link
            to={to}
            className="group bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 flex items-start gap-4 hover:ring-2 hover:ring-primary-container/30 transition-all"
        >
            <span className="w-12 h-12 rounded-xl bg-primary-fixed text-primary-container flex items-center justify-center shrink-0">
                <span className="material-symbols-outlined text-[26px]">{icon}</span>
            </span>
            <div className="min-w-0">
                <h2 className="font-display text-lg font-semibold text-on-surface group-hover:text-primary transition-colors">{titulo}</h2>
                <p className="text-sm text-on-surface-variant mt-1">{descricao}</p>
            </div>
            <span className="material-symbols-outlined text-on-surface-variant ml-auto self-center group-hover:translate-x-0.5 transition-transform">chevron_right</span>
        </Link>
    );
}

export default function AdminComunicacao() {
    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Comunicação</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Como a organização fala com a base: por e-mail, para um recorte que você monta, ou pelo
                card que aparece na tela de quem está conectado agora.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardComunicacao
                    to="/admin/mala-direta"
                    icon="forward_to_inbox"
                    titulo="Mala direta"
                    descricao="Comunicado por e-mail para os públicos que você combinar, com prévia, disparo pela fila e relatório de falhas."
                />
                <CardComunicacao
                    to="/admin/comunicacao/modelos"
                    icon="drafts"
                    titulo="Modelos de e-mail"
                    descricao="O texto dos e-mails que o portal manda sozinho — cadastro, projeto submetido e convite de feedback."
                />
                <CardComunicacao
                    to="/admin/comunicacao/avisos"
                    icon="campaign"
                    titulo="Avisos"
                    descricao="O card na tela dos orientadores conectados, com o relatório de quem viu, fechou ou ainda não viu."
                />
                <CardComunicacao
                    to="/admin/comunicacao/feedback"
                    icon="reviews"
                    titulo="Feedback"
                    descricao="Questionários para um recorte da base, com convite por e-mail e resultados anônimos."
                />
            </div>
        </AppShell>
    );
}
