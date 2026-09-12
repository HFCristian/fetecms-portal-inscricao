import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';

/**
 * Aba **Mapa do Evento** — a landing das três seções.
 *
 * Elas são etapas de uma coisa só, e a ordem importa: os **turnos** dizem quem
 * apresenta de manhã e quem à tarde; os **estandes** dizem em que número cada um
 * fica; a **planta** mostra o resultado no desenho do ginásio. Por isso os cards
 * saem nessa ordem, e não em ordem alfabética.
 */
function CardSecao({ to, icon, titulo, descricao, passo }) {
    return (
        <Link
            to={to}
            className="group bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 flex items-start gap-4 hover:ring-2 hover:ring-primary-container/30 transition-all"
        >
            <span className="w-12 h-12 rounded-xl bg-primary-fixed text-primary-container flex items-center justify-center shrink-0">
                <span className="material-symbols-outlined text-[26px]">{icon}</span>
            </span>
            <div className="min-w-0">
                <span className="text-xs font-semibold text-primary-container uppercase tracking-wide">{passo}</span>
                <h2 className="font-display text-lg font-semibold text-on-surface group-hover:text-primary transition-colors">{titulo}</h2>
                <p className="text-sm text-on-surface-variant mt-1">{descricao}</p>
            </div>
            <span className="material-symbols-outlined text-on-surface-variant ml-auto self-center group-hover:translate-x-0.5 transition-transform">chevron_right</span>
        </Link>
    );
}

export default function MapaEventoHome() {
    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Mapa do Evento</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                A ocupação do ginásio, em três etapas: primeiro quem apresenta em cada turno,
                depois em que estande cada projeto fica e, por fim, a planta do evento com tudo
                no lugar. Tudo parte da <strong>lista final oficial vigente</strong>.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardSecao
                    to="/admin/mapa/turnos"
                    icon="schedule"
                    passo="Etapa 1"
                    titulo="Turnos de Apresentação"
                    descricao="Divide os finalistas entre o turno A (matutino) e o B (vespertino), pelas regras que você escolher."
                />
                <CardSecao
                    to="/admin/mapa/estandes"
                    icon="grid_view"
                    passo="Etapa 2"
                    titulo="Estandes dos Projetos"
                    descricao="Distribui os projetos de cada turno pelos números de estande, respeitando as faixas por categoria."
                />
                <CardSecao
                    to="/admin/mapa/planta"
                    icon="map"
                    passo="Etapa 3"
                    titulo="Mapa do Evento"
                    descricao="A planta do ginásio: clique num estande e veja quem apresenta nele de manhã e à tarde."
                />
            </div>
        </AppShell>
    );
}
