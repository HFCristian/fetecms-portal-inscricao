import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, extractErrors, homeFor } from '../lib/auth.jsx';
import AuthCard from '../components/AuthCard.jsx';
import { Field, Input, Button, Alert, useContagemRegressiva, formatarMmSs } from '../components/ui.jsx';

/**
 * Aviso de bloqueio por excesso de tentativas: mostra o motivo, quanto falta
 * para liberar e uma contagem regressiva até 00:00 (com barra de progresso).
 */
function BloqueioTentativas({ mensagem, restante, total }) {
    const pct = total > 0 ? Math.min(100, (restante / total) * 100) : 0;

    return (
        <div className="rounded-lg bg-error-container text-on-error-container p-4" role="alert">
            <div className="flex items-start gap-2">
                <span className="material-symbols-outlined text-[20px] shrink-0">lock_clock</span>
                <div className="text-sm">
                    <p className="font-semibold">Acesso bloqueado temporariamente</p>
                    <p className="mt-0.5">{mensagem}</p>
                </div>
            </div>

            <div className="mt-3 flex items-baseline justify-center gap-2">
                <span className="text-xs uppercase tracking-wide">Tente novamente em</span>
                <span role="timer" aria-label={`Aguarde ${restante} segundos`} className="text-2xl font-bold tabular-nums">
                    {formatarMmSs(restante)}
                </span>
            </div>

            <div className="mt-2 h-1.5 rounded-full bg-on-error-container/15 overflow-hidden">
                <div
                    className="h-full bg-on-error-container/60 transition-[width] duration-500 ease-linear"
                    style={{ width: `${pct}%` }}
                />
            </div>
        </div>
    );
}

export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [showPass, setShowPass] = useState(false);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    // { ate: timestamp ms, total: segundos } enquanto houver bloqueio (429).
    const [bloqueio, setBloqueio] = useState(null);

    const restante = useContagemRegressiva(bloqueio?.ate ?? null);
    const bloqueado = restante > 0;
    const liberado = !!bloqueio && restante === 0;

    async function onSubmit(e) {
        e.preventDefault();
        if (bloqueado) return;

        setError('');
        setBloqueio(null);
        setLoading(true);
        try {
            const user = await login(email, password);
            navigate(homeFor(user.role), { replace: true });
        } catch (err) {
            const { message, retryAfter } = extractErrors(err);
            setError(message);
            if (retryAfter) setBloqueio({ ate: Date.now() + retryAfter * 1000, total: retryAfter });
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
                    {bloqueado ? (
                        <BloqueioTentativas mensagem={error} restante={restante} total={bloqueio.total} />
                    ) : liberado ? (
                        <Alert type="info">A espera terminou. Você já pode tentar entrar novamente.</Alert>
                    ) : (
                        <Alert>{error}</Alert>
                    )}
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

                    <Button type="submit" loading={loading} disabled={bloqueado} className="w-full">
                        <span className="material-symbols-outlined text-[20px]">
                            {bloqueado ? 'lock_clock' : 'login'}
                        </span>
                        {bloqueado ? `AGUARDE ${formatarMmSs(restante)}` : 'ENTRAR'}
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
                    <p className="mt-4 text-xs text-on-surface-variant text-center">
                        Para avaliar basta estar cursando pós-graduação — especialização, mestrado ou
                        doutorado em andamento já habilita.
                    </p>
                </div>
            </div>
        </AuthCard>
    );
}
