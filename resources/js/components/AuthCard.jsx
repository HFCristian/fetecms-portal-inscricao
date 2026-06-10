export default function AuthCard({ children }) {
    return (
        <main className="fetec-gradient-bg min-h-screen flex items-center justify-center p-4 sm:p-6 md:p-10">
            <div className="w-full max-w-4xl bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden flex flex-col lg:flex-row">
                {/* Painel lateral de marca (desktop) */}
                <aside className="fetec-auth-card-gradient-bg hidden lg:flex lg:w-5/12 flex-col justify-between p-10 relative overflow-hidden border-r border-outline-variant/30">
                    <div className="relative z-10">
                        <span className="text-sm font-semibold tracking-wider uppercase text-primary-container">
                            XVI FETECMS
                        </span>
                        <h1 className="font-display text-5xl font-bold text-on-surface leading-tight mt-2">
                            Portal da FETEC
                        </h1>
                        <img
                            src="/img/logo2026slogan.png"
                            alt="Logo XVI FETECMS"
                            className="my-6 mx-auto w-full max-w-[260px] h-auto object-contain drop-shadow-md"
                        />
                    </div>
                    <div className="relative z-10 mt-auto">
                        <p className="font-display text-lg font-semibold text-primary-container border-l-4 border-secondary pl-4 py-1">
                            A CIÊNCIA É A PONTE PARA O FUTURO.
                        </p>
                        <p className="text-sm text-on-surface-variant mt-3 max-w-[90%]">
                            Acesse sua conta para gerenciar projetos, integrantes e inscrições.
                        </p>
                    </div>
                </aside>

                {/* Área de conteúdo (formulário) */}
                <section className="w-full lg:w-7/12 flex flex-col bg-surface-container-lowest">
                    {children}
                </section>
            </div>
        </main>
    );
}
