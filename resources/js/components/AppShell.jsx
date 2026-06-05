import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth.jsx';

function navClass({ isActive }) {
    return (
        'flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-colors ' +
        (isActive
            ? 'bg-primary-container text-on-primary'
            : 'text-on-surface-variant hover:bg-surface-variant')
    );
}

export default function AppShell({ children }) {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    async function handleLogout() {
        await logout();
        navigate('/login', { replace: true });
    }

    return (
        <div className="min-h-screen bg-background">
            {/* Sidebar desktop */}
            <nav className="hidden md:flex fixed left-0 top-0 h-full w-64 z-40 p-3 flex-col bg-surface-container-low border-r border-outline-variant/30">
                <div className="mb-6 pb-4 border-b border-outline-variant/30">
                    <h1 className="font-display text-lg text-primary font-bold">Portal do Orientador</h1>
                    <p className="text-sm text-on-surface-variant">XVI FETECMS</p>
                </div>
                <div className="flex-1 flex flex-col gap-1">
                    {user?.role === 'avaliador' ? (
                        <NavLink to="/avaliador" className={navClass}>
                            <span className="material-symbols-outlined">fact_check</span>
                            Avaliações
                        </NavLink>
                    ) : (
                        <>
                            <NavLink to="/projetos" className={navClass}>
                                <span className="material-symbols-outlined">folder_shared</span>
                                Meus Projetos
                            </NavLink>
                            <NavLink to="/perfil" className={navClass}>
                                <span className="material-symbols-outlined">account_circle</span>
                                Perfil
                            </NavLink>
                        </>
                    )}
                    <button
                        onClick={handleLogout}
                        className="mt-auto flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold text-on-surface-variant hover:bg-surface-variant transition-colors"
                    >
                        <span className="material-symbols-outlined">logout</span>
                        Sair
                    </button>
                </div>
            </nav>

            {/* Header mobile */}
            <header className="md:hidden sticky top-0 z-40 flex items-center justify-between bg-surface border-b border-outline-variant/30 px-4 h-16">
                <span className="font-display text-primary font-bold">FETECMS</span>
                <button onClick={handleLogout} className="text-on-surface-variant p-2">
                    <span className="material-symbols-outlined">logout</span>
                </button>
            </header>

            {/* Conteúdo */}
            <div className="md:ml-64">
                <div className="max-w-5xl px-4 md:px-8 py-6">
                    <p className="text-sm text-on-surface-variant mb-4">
                        Olá, <strong className="text-on-surface">{user?.name}</strong>
                    </p>
                    {children}
                </div>
            </div>
        </div>
    );
}
