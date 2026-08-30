import { useState, useEffect } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth.jsx';
import { getConversasNaoVistas } from '../lib/chat.js';
import SupportFooter from './SupportFooter.jsx';
import ChatWidget from './ChatWidget.jsx';
import AvisoCard from './AvisoCard.jsx';
import FeedbackCard from './FeedbackCard.jsx';
import SeletorEdicao from './SeletorEdicao.jsx';
import { abasPermitidas } from '../lib/abasAdmin.js';

function navClass({ isActive }) {
    return (
        'flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-colors ' +
        (isActive
            ? 'bg-primary-container text-on-primary'
            : 'text-on-surface-variant hover:bg-surface-variant')
    );
}

// Badge numérico (ex.: conversas não vistas) ao final de um item do menu.
function NavBadge({ count }) {
    if (!count) return null;
    return (
        <span className="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-error text-on-error text-xs font-bold inline-flex items-center justify-center">
            {count > 99 ? '99+' : count}
        </span>
    );
}


// Links de navegação por papel. onNavigate fecha o menu mobile ao clicar num link.
function NavLinks({ role, abas, onNavigate, suporteBadge = 0 }) {
    if (role === 'admin') {
        return (
            <>
                {/* A Home nunca é filtrada: ela só reúne atalhos para as abas
                    que a pessoa já pode abrir. */}
                <NavLink to="/admin" end className={navClass} onClick={onNavigate}>
                    <span className="material-symbols-outlined">home</span>
                    Início
                </NavLink>
                {abasPermitidas(abas).map((a) => (
                    <NavLink key={a.to} to={a.to} className={navClass} onClick={onNavigate}>
                        <span className="material-symbols-outlined">{a.icon}</span>
                        {a.label}
                        {a.badge && <NavBadge count={suporteBadge} />}
                    </NavLink>
                ))}
            </>
        );
    }
    if (role === 'avaliador') {
        return (
            <>
                <NavLink to="/avaliador" end className={navClass} onClick={onNavigate}>
                    <span className="material-symbols-outlined">fact_check</span>
                    Avaliações
                </NavLink>
                <NavLink to="/avaliador/perfil" className={navClass} onClick={onNavigate}>
                    <span className="material-symbols-outlined">account_circle</span>
                    Perfil
                </NavLink>
            </>
        );
    }
    return (
        <>
            <NavLink to="/projetos" className={navClass} onClick={onNavigate}>
                <span className="material-symbols-outlined">folder_shared</span>
                Meus Projetos
            </NavLink>
            {/* A aba fica sempre visível: fora do período de ajustes ela abre
                explicando que está fechada. */}
            <NavLink to="/ajustes" className={navClass} onClick={onNavigate}>
                <span className="material-symbols-outlined">rule_settings</span>
                Ajustes
            </NavLink>
            <NavLink to="/perfil" className={navClass} onClick={onNavigate}>
                <span className="material-symbols-outlined">account_circle</span>
                Perfil
            </NavLink>
        </>
    );
}

function LogoutButton({ onClick }) {
    return (
        <button
            onClick={onClick}
            className="flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold text-red-900 hover:bg-surface-variant transition-colors"
        >
            <span className="material-symbols-outlined">logout</span>
            Sair
        </button>
    );
}

export default function AppShell({ children }) {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const [menuOpen, setMenuOpen] = useState(false);
    const [suporteBadge, setSuporteBadge] = useState(0);

    // Só o admin COM a aba Suporte no escopo: número de conversas não
    // visualizadas ao lado do item. Checagem leve em segundo plano (~60s) +
    // reatualização imediata quando o painel de suporte sinaliza mudança
    // (abrir/responder/arquivar uma conversa).
    const veSuporte = user?.role === 'admin'
        && (!Array.isArray(user?.abas) || user.abas.includes('suporte'));

    useEffect(() => {
        if (!veSuporte) return undefined;
        let cancelado = false;
        const checar = () =>
            getConversasNaoVistas()
                .then((r) => { if (!cancelado) setSuporteBadge(r.total ?? 0); })
                .catch(() => {});
        checar();
        const id = setInterval(checar, 60000);
        window.addEventListener('suporte:atualizar', checar);
        return () => {
            cancelado = true;
            clearInterval(id);
            window.removeEventListener('suporte:atualizar', checar);
        };
    }, [veSuporte]);

    async function handleLogout() {
        setMenuOpen(false);
        await logout();
        navigate('/login', { replace: true });
    }

    return (
        <div className="min-h-screen bg-background">
            {/* Sidebar desktop */}
            <nav className="hidden md:flex fixed left-0 top-0 h-full w-64 z-40 p-3 flex-col bg-surface-container-low border-r border-outline-variant/30">
                <div className="mb-6 pb-4 border-b border-outline-variant/30">
                    <img src="/img/logo2026.webp" alt="XVI FETECMS" className="max-h-28 mx-auto w-auto mb-2" />
                    <h1 className="font-display text-lg text-primary font-bold">Portal do Orientador</h1>
                    <p className="text-sm text-on-surface-variant">XVI FETECMS</p>
                </div>
                <div className="flex-1 flex flex-col gap-1">
                    <SeletorEdicao />
                    <NavLinks role={user?.role} abas={user?.abas} suporteBadge={suporteBadge} />
                    <div className="flex flex-col gap-1 mt-auto mb-4">
                        <NavLink to="/acesso" className={navClass}>
                            <span className="material-symbols-outlined">lock</span>
                            Acesso
                        </NavLink>
                        <LogoutButton onClick={handleLogout} />
                        <SupportFooter className="pb-2 mt-3" />
                    </div>
                </div>
            </nav>

            {/* Header mobile: botão de menu abre o menu lateral em tela cheia */}
            <header className="md:hidden sticky top-0 z-40 flex items-center justify-between bg-surface border-b border-outline-variant/30 px-4 h-20">
                <img src="/img/logo2026.webp" alt="XVI FETECMS" className="max-h-16" />
                <div className='text-center'>
                    <h1 className="font-display text-lg text-primary font-bold">Portal do Orientador</h1>
                    <p className="text-sm text-on-surface-variant">XVI FETECMS</p>
                </div>
                <button onClick={() => setMenuOpen(true)} aria-label="Abrir menu" className="text-on-surface-variant p-2">
                    <span className="material-symbols-outlined text-[28px]">menu</span>
                </button>
            </header>

            {/* Menu lateral em tela cheia (mobile) */}
            {menuOpen && (
                <div className="md:hidden fixed inset-0 z-50 bg-surface-container-low flex flex-col" role="dialog" aria-modal="true">
                    <div className="flex items-center justify-between px-4 h-20 border-b border-outline-variant/30 shrink-0">
                        <div className="flex items-center gap-3">
                            <img src="/img/logo2026.webp" alt="XVI FETECMS" className="max-h-16" />
                            <div>
                                <h1 className="font-display text-lg text-primary font-bold leading-tight">Portal</h1>
                                <p className="text-xs text-on-surface-variant">XVI FETECMS</p>
                            </div>
                        </div>
                        <button onClick={() => setMenuOpen(false)} aria-label="Fechar menu" className="text-on-surface-variant p-2">
                            <span className="material-symbols-outlined text-[28px]">close</span>
                        </button>
                    </div>
                    <div className="flex-1 overflow-y-auto p-4 flex flex-col gap-1">
                        <p className="text-sm text-on-surface-variant mb-3 px-1">
                            Olá, <strong className="text-on-surface">{user?.name}</strong>
                        </p>
                        <SeletorEdicao />
                        <NavLinks role={user?.role} abas={user?.abas} onNavigate={() => setMenuOpen(false)} suporteBadge={suporteBadge} />
                    </div>
                    <div className="p-4 border-t border-outline-variant/30 flex flex-col gap-1 shrink-0">
                        <NavLink to="/acesso" className={navClass} onClick={() => setMenuOpen(false)}>
                            <span className="material-symbols-outlined">lock</span>
                            Acesso
                        </NavLink>
                        <LogoutButton onClick={handleLogout} />
                        <SupportFooter className="mt-3" />
                    </div>
                </div>
            )}

            {/* Conteúdo */}
            <div className="md:ml-64">
                <div className="max-w-5xl px-4 md:px-8 py-6">
                    <p className="text-sm text-on-surface-variant mb-4">
                        Olá, <strong className="text-on-surface">{user?.name}</strong>
                    </p>
                    {/* Aviso do admin: o público é escolhido na publicação, então
                        orientador e avaliador consultam — o backend decide se há
                        card para esta pessoa. */}
                    <AvisoCard ativo={user?.role === 'orientador' || user?.role === 'avaliador'} />
                    {/* Convite de feedback: o público de cada pedido decide quem
                        vê, então basta estar autenticado — o backend responde
                        `null` para quem nenhum pedido alcança. */}
                    <FeedbackCard ativo={!!user} />
                    {children}
                    <SupportFooter className="mt-10 pb-2 md:hidden" />
                </div>
            </div>

            {/* Chat de suporte: disponível para orientador e avaliador (o admin é o suporte). */}
            {(user?.role === 'orientador' || user?.role === 'avaliador') && <ChatWidget />}
        </div>
    );
}
