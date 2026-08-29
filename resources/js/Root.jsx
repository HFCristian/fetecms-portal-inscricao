import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth, homeFor } from './lib/auth.jsx';
import ErrorBoundary from './components/ErrorBoundary.jsx';
import { RoleRoute, RoleRedirect } from './components/RoleRoute.jsx';
import Login from './pages/Login.jsx';
import EsqueciSenha from './pages/EsqueciSenha.jsx';
import RedefinirSenha from './pages/RedefinirSenha.jsx';
import Cadastro from './pages/Cadastro.jsx';
import CadastroAvaliador from './pages/CadastroAvaliador.jsx';
import Projetos from './pages/Projetos.jsx';
import ProjetoForm from './pages/ProjetoForm.jsx';
import Integrantes from './pages/Integrantes.jsx';
import Resumo from './pages/Resumo.jsx';
import Perfil from './pages/Perfil.jsx';
import AvaliadorHome from './pages/AvaliadorHome.jsx';
import AvaliadorPerfil from './pages/AvaliadorPerfil.jsx';
import AdminAvisoDetalhe from './pages/AdminAvisoDetalhe.jsx';
import AdminAvisos from './pages/AdminAvisos.jsx';
import AdminModelosEmail from './pages/AdminModelosEmail.jsx';
import AdminComunicacao from './pages/AdminComunicacao.jsx';
import AdminHome from './pages/AdminHome.jsx';
import AdminProjetosPorArea from './pages/AdminProjetosPorArea.jsx';
import AdminProjetosPorEstado from './pages/AdminProjetosPorEstado.jsx';
import AdminProjetosPorCidade from './pages/AdminProjetosPorCidade.jsx';
import AdminProjetosPorEscola from './pages/AdminProjetosPorEscola.jsx';
import Parametrizacao from './pages/Parametrizacao.jsx';
import ParametrizacaoAreas from './pages/ParametrizacaoAreas.jsx';
import ParametrizacaoEscolas from './pages/ParametrizacaoEscolas.jsx';
import ParametrizacaoInscricoes from './pages/ParametrizacaoInscricoes.jsx';
import ParametrizacaoAvaliacao from './pages/ParametrizacaoAvaliacao.jsx';
import AdminManager from './pages/AdminManager.jsx';
import AdminSuporte from './pages/AdminSuporte.jsx';
import AdminAvaliacaoOnline from './pages/AdminAvaliacaoOnline.jsx';
import AvaliacaoAvaliadores from './pages/AvaliacaoAvaliadores.jsx';
import AvaliacaoDistribuicao from './pages/AvaliacaoDistribuicao.jsx';
import AvaliacaoProjetos from './pages/AvaliacaoProjetos.jsx';
import AvaliacaoReclassificacoes from './pages/AvaliacaoReclassificacoes.jsx';
import AvaliacaoRanking from './pages/AvaliacaoRanking.jsx';
import AvaliacaoRankingAvaliadores from './pages/AvaliacaoRankingAvaliadores.jsx';
import Acesso from './pages/Acesso.jsx';
import AdminRegistros from './pages/AdminRegistros.jsx';
import AdminRegistrosHome from './pages/AdminRegistrosHome.jsx';
import AdminMalaDireta from './pages/AdminMalaDireta.jsx';
import AdminMalaDiretaForm from './pages/AdminMalaDiretaForm.jsx';
import AdminMalaDiretaDetalhe from './pages/AdminMalaDiretaDetalhe.jsx';

function Spinner() {
    return (
        <div className="min-h-screen flex items-center justify-center text-on-surface-variant">
            <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
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
                        <Route path="/esqueci-senha" element={<GuestRoute><EsqueciSenha /></GuestRoute>} />
                        {/* Redefinição via link do e-mail: acessível mesmo se houver sessão aberta. */}
                        <Route path="/redefinir-senha" element={<RedefinirSenha />} />
                        <Route path="/cadastro" element={<GuestRoute><Cadastro /></GuestRoute>} />
                        <Route path="/cadastro/avaliador" element={<GuestRoute><CadastroAvaliador /></GuestRoute>} />

                        {/* Disponível a qualquer usuário autenticado */}
                        <Route element={<RoleRoute />}>
                            <Route path="/acesso" element={<Acesso />} />
                            {/* URLs antigas (links salvos, e-mails já enviados) caem na tela nova. */}
                            <Route path="/alterar-senha" element={<Navigate to="/acesso" replace />} />
                            <Route path="/alterar-email" element={<Navigate to="/acesso" replace />} />
                        </Route>

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
                            <Route path="/avaliador/perfil" element={<AvaliadorPerfil />} />
                        </Route>

                        {/* Área do admin */}
                        <Route element={<RoleRoute allow={['admin']} />}>
                            <Route path="/admin" element={<AdminHome />} />
                            <Route path="/admin/avaliacao" element={<AdminAvaliacaoOnline />} />
                            <Route path="/admin/avaliacao/distribuicao" element={<AvaliacaoDistribuicao />} />
                            <Route path="/admin/avaliacao/avaliadores" element={<AvaliacaoAvaliadores />} />
                            <Route path="/admin/avaliacao/projetos" element={<AvaliacaoProjetos />} />
                            <Route path="/admin/avaliacao/reclassificacoes" element={<AvaliacaoReclassificacoes />} />
                            <Route path="/admin/avaliacao/ranking" element={<AvaliacaoRanking />} />
                            <Route path="/admin/avaliacao/ranking-avaliadores" element={<AvaliacaoRankingAvaliadores />} />
                            <Route path="/admin/projetos-por-area" element={<AdminProjetosPorArea />} />
                            <Route path="/admin/projetos-por-estado" element={<AdminProjetosPorEstado />} />
                            <Route path="/admin/projetos-por-cidade" element={<AdminProjetosPorCidade />} />
                            <Route path="/admin/projetos-por-escola" element={<AdminProjetosPorEscola />} />
                            <Route path="/admin/parametrizacao" element={<Parametrizacao />} />
                            <Route path="/admin/parametrizacao/areas" element={<ParametrizacaoAreas />} />
                            <Route path="/admin/parametrizacao/escolas" element={<ParametrizacaoEscolas />} />
                            <Route path="/admin/parametrizacao/inscricoes" element={<ParametrizacaoInscricoes />} />
                            <Route path="/admin/parametrizacao/avaliacao" element={<ParametrizacaoAvaliacao />} />
                            <Route path="/admin/registros" element={<AdminRegistrosHome />} />
                            <Route path="/admin/registros/inscricoes" element={<AdminRegistros secao="inscricoes" />} />
                            <Route path="/admin/registros/avaliacao" element={<AdminRegistros secao="avaliacao" />} />
                            <Route path="/admin/comunicacao" element={<AdminComunicacao />} />
                            <Route path="/admin/comunicacao/avisos" element={<AdminAvisos />} />
                            <Route path="/admin/comunicacao/modelos" element={<AdminModelosEmail />} />
                            <Route path="/admin/comunicacao/avisos/:id" element={<AdminAvisoDetalhe />} />
                            <Route path="/admin/mala-direta" element={<AdminMalaDireta />} />
                            <Route path="/admin/mala-direta/nova" element={<AdminMalaDiretaForm />} />
                            <Route path="/admin/mala-direta/:id" element={<AdminMalaDiretaDetalhe />} />
                            <Route path="/admin/gerir-admins" element={<AdminManager />} />
                            <Route path="/admin/suporte" element={<AdminSuporte />} />
                        </Route>

                        <Route path="*" element={<Navigate to="/" replace />} />
                    </Routes>
                </ErrorBoundary>
            </AuthProvider>
        </BrowserRouter>
    );
}
