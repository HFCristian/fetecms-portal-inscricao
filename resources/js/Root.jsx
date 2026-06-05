import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth, homeFor } from './lib/auth.jsx';
import ErrorBoundary from './components/ErrorBoundary.jsx';
import { RoleRoute, RoleRedirect } from './components/RoleRoute.jsx';
import Login from './pages/Login.jsx';
import Cadastro from './pages/Cadastro.jsx';
import CadastroAvaliador from './pages/CadastroAvaliador.jsx';
import Projetos from './pages/Projetos.jsx';
import ProjetoForm from './pages/ProjetoForm.jsx';
import Integrantes from './pages/Integrantes.jsx';
import Resumo from './pages/Resumo.jsx';
import Perfil from './pages/Perfil.jsx';
import AvaliadorHome from './pages/AvaliadorHome.jsx';

function Spinner() {
    return (
        <div className="min-h-screen flex items-center justify-center text-on-surface-variant">
            <span className="material-symbols-outlined animate-spin">progress_activity</span>
        </div>
    );
}

// Impede usuário logado de ver login/cadastro (manda para a home do papel).
function GuestRoute({ children }) {
    const { user, loading } = useAuth();
    if (loading) return <Spinner />;
    if (user) return <Navigate to={homeFor(user.role)} replace />;
    return children;
}

export default function Root() {
    return (
        <BrowserRouter>
            <AuthProvider>
                <ErrorBoundary>
                    <Routes>
                        <Route path="/" element={<RoleRedirect />} />
                        <Route path="/login" element={<GuestRoute><Login /></GuestRoute>} />
                        <Route path="/cadastro" element={<GuestRoute><Cadastro /></GuestRoute>} />
                        <Route path="/cadastro/avaliador" element={<GuestRoute><CadastroAvaliador /></GuestRoute>} />

                        {/* Área do orientador */}
                        <Route element={<RoleRoute allow={['orientador']} />}>
                            <Route path="/projetos" element={<Projetos />} />
                            <Route path="/projetos/novo" element={<ProjetoForm />} />
                            <Route path="/projetos/:id/editar" element={<ProjetoForm />} />
                            <Route path="/projetos/:id/integrantes" element={<Integrantes />} />
                            <Route path="/projetos/:id/resumo" element={<Resumo />} />
                            <Route path="/perfil" element={<Perfil />} />
                        </Route>

                        {/* Área do avaliador */}
                        <Route element={<RoleRoute allow={['avaliador']} />}>
                            <Route path="/avaliador" element={<AvaliadorHome />} />
                        </Route>

                        <Route path="*" element={<Navigate to="/" replace />} />
                    </Routes>
                </ErrorBoundary>
            </AuthProvider>
        </BrowserRouter>
    );
}
