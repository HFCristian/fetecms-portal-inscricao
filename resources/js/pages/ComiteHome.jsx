import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';

function CardSecao({ to, icon, titulo, descricao }) {
    return (
        <Link
            to={to}
            className="group bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 flex items-start gap-4 hover:ring-2 hover:ring-primary-container/30 transition-all"
        >
            <span className="w-12 h-12 rounded-xl bg-primary-fixed text-primary-container flex items-center justify-center shrink-0">
                <span className="material-symbols-outlined text-[26px]">{icon}</span>
            </span>
            <div className="min-w-0">
                <h2 className="font-display text-lg font-semibold text-on-surface group-hover:text-primary transition-colors">{titulo}</h2>
                <p className="text-sm text-on-surface-variant mt-1">{descricao}</p>
            </div>
            <span className="material-symbols-outlined text-on-surface-variant ml-auto self-center group-hover:translate-x-0.5 transition-transform">chevron_right</span>
        </Link>
    );
}

/** Aba Comitê especial: o transporte de quem está a caminho e o mapa geral. */
export default function ComiteHome() {
    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Comitê especial</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O deslocamento das equipes durante a feira: quem está conduzindo um grupo liga o
                localizador do próprio aparelho, e a organização acompanha todo mundo num mapa só.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardSecao
                    to="/admin/comite/transporte"
                    icon="directions_bus"
                    titulo="Transporte de comitê"
                    descricao="Quem está com o localizador ligado, com o que cada um configurou — e o botão para habilitar o seu."
                />
                <CardSecao
                    to="/admin/comite/mapa"
                    icon="map"
                    titulo="Mapa do comitê"
                    descricao="Em tempo real: todos os grupos a caminho. Clique num ponto para ver quem está lá e quanto falta."
                />
            </div>
        </AppShell>
    );
}
