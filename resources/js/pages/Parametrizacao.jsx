import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';

// Card de acesso a uma área de parametrização (áreas/subáreas e escolas).
function CardParametrizacao({ to, icon, titulo, descricao }) {
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

export default function Parametrizacao() {
    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Parametrização</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Mantenha os catálogos limpos: padronize nomes, mescle duplicatas (reatribuindo todas as
                referências) e remova itens sem uso.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardParametrizacao
                    to="/admin/parametrizacao/areas"
                    icon="category"
                    titulo="Áreas e subáreas"
                    descricao="Renomeie, mescle e exclua áreas do conhecimento e suas subáreas."
                />
                <CardParametrizacao
                    to="/admin/parametrizacao/escolas"
                    icon="school"
                    titulo="Escolas"
                    descricao="Renomeie, mescle e exclua instituições de ensino — limpe as cadastradas em duplicidade."
                />
            </div>
        </AppShell>
    );
}
