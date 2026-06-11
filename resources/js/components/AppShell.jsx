import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth.jsx';
import SupportFooter from './SupportFooter.jsx';

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
                    <img src="/img/logo2026.png" alt="XVI FETECMS" className="max-h-28 mx-auto w-auto mb-2" />
                    <h1 className="font-display text-lg text-primary font-bold">Portal do Orientador</h1>
                    <p className="text-sm text-on-surface-variant">XVI FETECMS</p>
                </div>
                <div className="flex-1 flex flex-col gap-1">
                    {user?.role === 'admin' ? (
                        <>
                            <NavLink to="/admin" end className={navClass}>
                                <span className="material-symbols-outlined">dashboard</span>
                                Dashboard
                            </NavLink>
                            <NavLink to="/admin/parametrizacao" className={navClass}>
                                <span className="material-symbols-outlined">tune</span>
                                Parametrização
                            </NavLink>
                            <NavLink to="/admin/gerir-admins" className={navClass}>
                                <span className="material-symbols-outlined">people</span>
                                Administradores
                            </NavLink>
                        </>
                    ) : user?.role === 'avaliador' ? (
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
                    <div className="flex flex-col gap-4 mt-auto mb-4">
                        <button
                            onClick={handleLogout}
                            className="flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold text-red-900 hover:bg-surface-variant transition-colors"
                        >
                            <span className="material-symbols-outlined">logout</span>
                            Sair
                        </button>
                        <SupportFooter className="pb-2" />
                    </div>
                </div>
            </nav>

            {/* Header mobile */}
            <header className="md:hidden sticky top-0 z-40 flex items-center justify-between bg-surface border-b border-outline-variant/30 px-4 h-20">
                <img src="/img/logo2026.png" alt="XVI FETECMS" className="max-h-16" />
                <div className='text-center'>
                    <h1 className="font-display text-lg text-primary font-bold">Portal do Orientador</h1>
                    <p className="text-sm text-on-surface-variant">XVI FETECMS</p>
                </div>
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
                    <SupportFooter className="mt-10 pb-2 md:hidden" />
                </div>
            </div>
        </div>
    );
}
