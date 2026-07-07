import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, extractErrors, homeFor } from '../lib/auth.jsx';
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
            const user = await login(email, password);
            navigate(homeFor(user.role), { replace: true });
        } catch (err) {
            setError(extractErrors(err).message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <AuthCard>
            <div className="flex flex-col grow justify-center px-6 sm:px-10 py-10 w-full max-w-lg mx-auto">
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

                    <div className="flex justify-end -mt-2">
                        <Link
                            to="/esqueci-senha"
                            className="text-sm font-semibold text-primary-container hover:underline"
                        >
                            Esqueci minha senha
                        </Link>
                    </div>

                    <Button type="submit" loading={loading} className="w-full">
                        <span className="material-symbols-outlined text-[20px]">login</span>
                        ENTRAR
                    </Button>
                </form>

                <div className="mt-8 pt-6 border-t border-outline-variant/30">
                    <p className="text-sm text-on-surface-variant text-center mb-4">
                        Ainda não tem conta? Cadastre-se como:
                    </p>
                    <div className="mt-4 mx-8 flex flex-col gap-4 sm:flex-row justify-between">
                        <Link
                            to="/cadastro"
                            className="inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 font-semibold transition-colors border-2 border-primary-container/50 text-primary-container bg-primary-fixed-dim/20 hover:bg-primary-fixed-dim"
                        >
                            <span className="material-symbols-outlined text-[20px]">school</span>
                            Orientador
                        </Link>
                        <Link
                            to="/cadastro/avaliador"
                            className="inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 font-semibold transition-colors border-2 border-secondary/50 text-secondary bg-secondary-fixed/20 hover:bg-secondary-container/70"
                        >
                            <span className="material-symbols-outlined text-[20px]">rate_review</span>
                            Avaliador
                        </Link>
                    </div>
                </div>
            </div>
        </AuthCard>
    );
}
