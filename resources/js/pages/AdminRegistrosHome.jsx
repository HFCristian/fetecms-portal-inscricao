import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';

// Card de acesso a uma seção da trilha de registros.
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

export default function AdminRegistrosHome() {
    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Registros</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                A trilha de auditoria do sistema: quem fez o quê e quando. Cada seção tem seus próprios
                filtros por tipo, período e busca, e exporta em CSV o mesmo recorte que está na tela.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardSecao
                    to="/admin/registros/inscricoes"
                    icon="app_registration"
                    titulo="Inscrições"
                    descricao="Submissões, cancelamentos, exclusões de projeto e trocas de e-mail das contas."
                />
                <CardSecao
                    to="/admin/registros/projetos"
                    icon="edit_note"
                    titulo="Projetos"
                    descricao="Correções do admin em projetos submetidos: categoria, área, subárea e vídeo, com a justificativa."
                />
                <CardSecao
                    to="/admin/registros/rascunhos"
                    icon="drafts"
                    titulo="Rascunhos"
                    descricao="O admin terminando e submetendo a inscrição de alguém depois do prazo: cada campo alterado e a justificativa do envio."
                />
                <CardSecao
                    to="/admin/registros/lista-final"
                    icon="fact_check"
                    titulo="Lista final"
                    descricao="A lista oficial da feira: publicação e cada projeto incluído ou retirado, com a justificativa."
                />
                <CardSecao
                    to="/admin/registros/credenciamento"
                    icon="how_to_reg"
                    titulo="Credenciamento"
                    descricao="Quem credenciou cada finalista, o horário, quem faltou e cada retirada de kit."
                />
                <CardSecao
                    to="/admin/registros/avaliacao"
                    icon="grading"
                    titulo="Avaliação Online"
                    descricao="Mudanças de parâmetro do período: início, fim e os mínimos de avaliações."
                />
            </div>
        </AppShell>
    );
}
