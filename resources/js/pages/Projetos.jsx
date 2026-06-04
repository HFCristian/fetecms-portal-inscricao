import AppShell from '../components/AppShell.jsx';

export default function Projetos() {
    return (
        <AppShell>
            <div className="flex items-end justify-between gap-4 mb-6">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-primary mb-1">Meus Projetos</h1>
                    <p className="text-on-surface-variant">Projetos inscritos por você como orientador.</p>
                </div>
                <button
                    disabled
                    title="Disponível na Sprint 2"
                    className="inline-flex items-center gap-2 rounded-lg px-5 py-2.5 font-semibold bg-primary-container text-on-primary opacity-60 cursor-not-allowed"
                >
                    <span className="material-symbols-outlined text-[20px]">add</span>
                    NOVA INSCRIÇÃO
                </button>
            </div>

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center">
                <span className="material-symbols-outlined text-[48px] text-primary-container">folder_open</span>
                <p className="text-on-surface mt-3 font-semibold">Cadastro de projetos chega na Sprint 2</p>
                <p className="text-on-surface-variant text-sm mt-1">
                    A autenticação está pronta — o CRUD de projetos (rascunho/submissão) é a próxima entrega.
                </p>
            </div>
        </AppShell>
    );
}
