import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { useAuth } from '../lib/auth.jsx';
import { abasDaHome } from '../lib/abasAvaliador.js';

/**
 * Home do avaliador (`/avaliador`, para onde o login manda) — Sprint 246.
 *
 * Como a do admin: um botão por aba que ele vê, na ordem do menu. Durante o
 * evento, quem vai ao estande encontra a **Avaliação presencial** em primeiro
 * lugar e destacada — antes a porta de entrada desviava direto para ela
 * (Sprint 236), e o avaliador não via as outras abas.
 */
function BotaoAba({ aba }) {
    return (
        <Link
            to={aba.to}
            className={`group flex items-start gap-4 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 hover:ring-2 hover:ring-primary-container/40 transition ${
                aba.destaque ? 'ring-2 ring-primary-container' : ''
            }`}
        >
            <span className="material-symbols-outlined text-primary-container text-3xl shrink-0">{aba.icon}</span>
            <div className="min-w-0">
                <h2 className="font-display text-lg font-semibold text-on-surface group-hover:text-primary transition-colors">
                    {aba.label}
                    {aba.destaque && (
                        <span className="ml-2 align-middle text-xs font-semibold rounded-full px-2 py-0.5 bg-secondary-container text-on-secondary-container">
                            Evento em andamento
                        </span>
                    )}
                </h2>
                <p className="text-sm text-on-surface-variant mt-1">{aba.descricao}</p>
            </div>
        </Link>
    );
}

export default function AvaliadorInicio() {
    const { user } = useAuth();
    const abas = abasDaHome(user);
    const primeiroNome = (user?.name ?? '').trim().split(/\s+/)[0];

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">
                {primeiroNome ? `Olá, ${primeiroNome}` : 'Painel do Avaliador'}
            </h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Por onde você quer começar? Abaixo estão as áreas do portal abertas para você nesta edição.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {abas.map((aba) => <BotaoAba key={aba.chave} aba={aba} />)}
            </div>
        </AppShell>
    );
}
