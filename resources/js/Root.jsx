import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './lib/auth.jsx';
import ProtectedRoute from './components/ProtectedRoute.jsx';
import Login from './pages/Login.jsx';
import Cadastro from './pages/Cadastro.jsx';
import EmBreve from './pages/EmBreve.jsx';
import Projetos from './pages/Projetos.jsx';
import Perfil from './pages/Perfil.jsx';

function Spinner() {
    return (
        <div className="min-h-screen flex items-center justify-center text-on-surface-variant">
            <span className="material-symbols-outlined animate-spin">progress_activity</span>
        </div>
    );
}

// Impede usuário logado de ver login/cadastro.
function GuestRoute({ children }) {
    const { user, loading } = useAuth();
    if (loading) return <Spinner />;
    if (user) return <Navigate to="/projetos" replace />;
    return children;
}

export default function Root() {
    return (
        <BrowserRouter>
            <AuthProvider>
                <Routes>
                    <Route path="/" element={<Navigate to="/projetos" replace />} />
                    <Route path="/login" element={<GuestRoute><Login /></GuestRoute>} />
                    <Route path="/cadastro" element={<GuestRoute><Cadastro /></GuestRoute>} />
                    <Route path="/cadastro/avaliador" element={<EmBreve />} />

                    <Route element={<ProtectedRoute />}>
                        <Route path="/projetos" element={<Projetos />} />
                        <Route path="/perfil" element={<Perfil />} />
                    </Route>

                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </AuthProvider>
        </BrowserRouter>
    );
}
