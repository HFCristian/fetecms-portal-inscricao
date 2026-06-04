import { Link } from 'react-router-dom';
import AuthCard from '../components/AuthCard.jsx';

export default function EmBreve() {
    return (
        <AuthCard>
            <div className="flex-grow flex flex-col justify-center px-6 sm:px-10 py-12 text-center max-w-md mx-auto">
                <span className="material-symbols-outlined text-[48px] text-secondary mx-auto">verified_user</span>
                <h2 className="font-display text-2xl font-semibold text-on-surface mt-3">Cadastro de Avaliador</h2>
                <p className="text-on-surface-variant mt-2">
                    O cadastro e o fluxo do avaliador entram na Sprint 4. Um avaliador não pode
                    estar cadastrado como orientador (exclusão mútua) — essa regra será aplicada aqui.
                </p>
                <Link to="/login" className="mt-6 font-semibold text-primary-container hover:underline">
                    Voltar ao login
                </Link>
            </div>
        </AuthCard>
    );
}
