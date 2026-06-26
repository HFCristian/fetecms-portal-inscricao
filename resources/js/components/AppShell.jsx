import { useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth.jsx';
import SupportFooter from './SupportFooter.jsx';
import ChatWidget from './ChatWidget.jsx';

function navClass({ isActive }) {
    return (
        'flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-colors ' +
        (isActive
            ? 'bg-primary-container text-on-primary'
            : 'text-on-surface-variant hover:bg-surface-variant')
    );
}

// Links de navegação por papel. onNavigate fecha o menu mobile ao clicar num link.
function NavLinks({ role, onNavigate }) {
    if (role === 'admin') {
        return (
            <>
                <NavLink to="/admin" end className={navClass} onClick={onNavigate}>
                    <span className="material-symbols-outlined">dashboard</span>
                    Dashboard
                </NavLink>
                <NavLink to="/admin/parametrizacao" className={navClass} onClick={onNavigate}>
                    <span className="material-symbols-outlined">tune</span>
                    Parametrização
                </NavLink>
                <NavLink to="/admin/suporte" className={navClass} onClick={onNavigate}>
                    <span className="material-symbols-outlined">forum</span>
                    Suporte
                </NavLink>
                <NavLink to="/admin/gerir-admins" className={navClass} onClick={onNavigate}>
                    <span className="material-symbols-outlined">people</span>
                    Administradores
                </NavLink>
            </>
        );
    }
    if (role === 'avaliador') {
        return (
            <NavLink to="/avaliador" className={navClass} onClick={onNavigate}>
                <span className="material-symbols-outlined">fact_check</span>
                Avaliações
            </NavLink>
        );
    }
    return (
        <>
            <NavLink to="/projetos" className={navClass} onClick={onNavigate}>
                <span className="material-symbols-outlined">folder_shared</span>
                Meus Projetos
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
                    <NavLinks role={user?.role} />
                    <div className="flex flex-col gap-4 mt-auto mb-4">
                        <LogoutButton onClick={handleLogout} />
                        <SupportFooter className="pb-2" />
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
                        <NavLinks role={user?.role} onNavigate={() => setMenuOpen(false)} />
                    </div>
                    <div className="p-4 border-t border-outline-variant/30 flex flex-col gap-3 shrink-0">
                        <LogoutButton onClick={handleLogout} />
                        <SupportFooter />
                    </div>
                </div>
            )}

            {/* Conteúdo */}
            <div className="md:ml-64">
                <div className="max-w-5xl px-4 md:px-8 py-6">
                    <p className="text-sm text-on-surface-variant mb-4">
                        Olá, <strong className="text-on-surface">{user?.name}</strong>
                    </p>
                    {children}
                    <SupportFooter className="mt-10 pb-2 md:hidden" />
                </div>
            </div>

            {/* Chat de suporte: disponível para orientador e avaliador (o admin é o suporte). */}
            {(user?.role === 'orientador' || user?.role === 'avaliador') && <ChatWidget />}
        </div>
    );
}
