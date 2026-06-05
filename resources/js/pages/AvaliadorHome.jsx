import AppShell from '../components/AppShell.jsx';
import { useAuth } from '../lib/auth.jsx';

export default function AvaliadorHome() {
    const { user } = useAuth();
    const sub = user?.avaliador_profile?.subarea;
    const area = user?.avaliador_profile?.area;

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Painel do Avaliador</h1>
            <p className="text-on-surface-variant mb-6">
                {area ? <>Sua área: <strong>{area}</strong>{sub ? <> · subárea <strong>{sub}</strong></> : null}.</> : 'Bem-vindo(a).'}
            </p>

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center">
                <span className="material-symbols-outlined text-[48px] text-primary-container">event_upcoming</span>
                <p className="text-on-surface mt-3 font-semibold">As avaliações ainda não foram liberadas</p>
                <p className="text-on-surface-variant text-sm mt-1 max-w-md mx-auto">
                    A partir da data definida pela organização, os projetos designados para você aparecerão aqui
                    para leitura e atribuição de nota (1 a 10). A distribuição automática por subárea/área será
                    entregue em uma sprint futura.
                </p>
            </div>
        </AppShell>
    );
}
