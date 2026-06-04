import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, extractErrors } from '../lib/auth.jsx';
import AuthCard from '../components/AuthCard.jsx';
import { Field, Input, Button, Alert } from '../components/ui.jsx';

export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [showPass, setShowPass] = useState(false);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    async function onSubmit(e) {
        e.preventDefault();
        setError('');
        setLoading(true);
        try {
            await login(email, password);
            navigate('/projetos', { replace: true });
        } catch (err) {
            setError(extractErrors(err).message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <AuthCard>
            <div className="flex-grow flex flex-col justify-center px-6 sm:px-10 py-10 w-full max-w-md mx-auto">
                <h2 className="font-display text-2xl font-semibold text-on-surface mb-1">Bem-vindo de volta</h2>
                <p className="text-sm text-on-surface-variant mb-8">
                    Informe seu e-mail e senha para acessar o portal.
                </p>

                <div className="mb-4">
                    <Alert>{error}</Alert>
                </div>

                <form onSubmit={onSubmit} className="space-y-5">
                    <Field label="E-mail" required>
                        <Input
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            placeholder="seu@email.com"
                            autoComplete="email"
                            required
                        />
                    </Field>

                    <Field label="Senha" required>
                        <div className="relative">
                            <Input
                                type={showPass ? 'text' : 'password'}
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                placeholder="••••••••"
                                autoComplete="current-password"
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShowPass((v) => !v)}
                                className="absolute right-3 top-1/2 -translate-y-1/2 text-outline hover:text-primary-container"
                            >
                                <span className="material-symbols-outlined text-[20px]">
                                    {showPass ? 'visibility' : 'visibility_off'}
                                </span>
                            </button>
                        </div>
                    </Field>

                    <Button type="submit" loading={loading} className="w-full">
                        <span className="material-symbols-outlined text-[20px]">login</span>
                        ENTRAR
                    </Button>
                </form>

                <div className="mt-8 pt-6 border-t border-outline-variant/30 space-y-3 text-center">
                    <p className="text-sm text-on-surface-variant">
                        Novo por aqui?{' '}
                        <Link to="/cadastro" className="font-semibold text-primary-container hover:underline">
                            Criar conta de orientador
                        </Link>
                    </p>
                    <p className="text-sm text-on-surface-variant">
                        É avaliador?{' '}
                        <Link
                            to="/cadastro/avaliador"
                            className="font-semibold text-secondary hover:underline"
                        >
                            Cadastre-se como avaliador
                        </Link>
                    </p>
                </div>
            </div>
        </AuthCard>
    );
}
