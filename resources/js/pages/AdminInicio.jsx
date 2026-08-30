import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { useAuth } from '../lib/auth.jsx';
import { abasPermitidas } from '../lib/abasAdmin.js';

/**
 * Home do administrador (`/admin`).
 *
 * Um índice do que esta pessoa pode abrir: um botão por aba liberada pelos
 * escopos dela (o RBAC), na mesma ordem do menu lateral. A Home em si não é uma
 * aba — ela nunca é bloqueada, porque só oferece atalhos para telas que já
 * passam pelo `aba:` do backend.
 */
function BotaoAba({ aba }) {
    return (
        <Link
            to={aba.to}
            className="group flex items-start gap-4 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 hover:ring-2 hover:ring-primary-container/40 transition"
        >
            <span className="material-symbols-outlined text-primary-container text-3xl shrink-0">{aba.icon}</span>
            <div className="min-w-0">
                <h2 className="font-display text-lg font-semibold text-on-surface group-hover:text-primary transition-colors">
                    {aba.label}
                </h2>
                <p className="text-sm text-on-surface-variant mt-1">{aba.descricao}</p>
            </div>
        </Link>
    );
}

export default function AdminInicio() {
    const { user } = useAuth();
    const abas = abasPermitidas(user?.abas);
    const primeiroNome = (user?.name ?? '').trim().split(/\s+/)[0];

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">
                {primeiroNome ? `Olá, ${primeiroNome}` : 'Painel do Administrador'}
            </h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Por onde você quer começar? Abaixo estão as áreas do portal liberadas para o seu acesso
                nesta edição.
            </p>

            {abas.length === 0 ? (
                <div className="max-w-3xl bg-surface-container-lowest rounded-xl fetec-card-shadow p-6">
                    <span className="material-symbols-outlined text-primary-container text-3xl">lock</span>
                    <h2 className="font-display text-lg font-semibold text-on-surface mt-2">
                        Nenhuma área liberada nesta edição
                    </h2>
                    <p className="text-sm text-on-surface-variant mt-1">
                        Os escopos de acesso da sua conta não abrem nenhuma aba na edição em curso. Peça a
                        outro administrador para revisar o seu acesso em <strong>Administradores</strong>.
                    </p>
                </div>
            ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {abas.map((aba) => <BotaoAba key={aba.aba} aba={aba} />)}
                </div>
            )}
        </AppShell>
    );
}
