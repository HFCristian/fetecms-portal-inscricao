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
                As datas que abrem e fecham cada etapa da feira e os catálogos do sistema: padronize
                nomes, mescle duplicatas (reatribuindo todas as referências) e remova itens sem uso.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardParametrizacao
                    to="/admin/parametrizacao/edicoes"
                    icon="event_repeat"
                    titulo="Edições"
                    descricao="As edições da feira: crie a do próximo ano, escolha a padrão e reaproveite o portal."
                />
                <CardParametrizacao
                    to="/admin/parametrizacao/escopos"
                    icon="admin_panel_settings"
                    titulo="Escopos de admin"
                    descricao="Perfis de acesso: quais abas do menu cada administrador abre, edição por edição."
                />
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
                <CardParametrizacao
                    to="/admin/parametrizacao/inscricoes"
                    icon="app_registration"
                    titulo="Inscrições"
                    descricao="Quando as inscrições abrem e até quando os orientadores podem submeter os projetos."
                />
                <CardParametrizacao
                    to="/admin/parametrizacao/avaliacao"
                    icon="grading"
                    titulo="Avaliação Online"
                    descricao="Quando os avaliadores passam a acessar os projetos e quando o período se encerra."
                />
            </div>
        </AppShell>
    );
}
