import { Navigate, Outlet } from 'react-router-dom';
import { useAuth, homeFor } from '../lib/auth.jsx';

function Spinner() {
    return (
        <div className="min-h-screen flex items-center justify-center text-on-surface-variant">
            <span className="material-symbols-outlined animate-spin">progress_activity</span>
        </div>
    );
}

/** Exige autenticação e (opcionalmente) que o papel esteja em `allow`. */
export function RoleRoute({ allow }) {
    const { user, loading } = useAuth();
    if (loading) return <Spinner />;
    if (!user) return <Navigate to="/login" replace />;
    if (allow && !allow.includes(user.role)) return <Navigate to={homeFor(user.role)} replace />;
    return <Outlet />;
}

/** Redireciona a raiz "/" para a home do papel (ou login). */
export function RoleRedirect() {
    const { user, loading } = useAuth();
    if (loading) return <Spinner />;
    return <Navigate to={user ? homeFor(user.role) : '/login'} replace />;
}
