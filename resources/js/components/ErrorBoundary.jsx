import { Component } from 'react';

/**
 * Captura erros de renderização da SPA e mostra uma tela legível (em vez de
 * tela branca), exibindo a mensagem/stack para facilitar o diagnóstico.
 */
export default class ErrorBoundary extends Component {
    state = { error: null };

    static getDerivedStateFromError(error) {
        return { error };
    }

    componentDidCatch(error, info) {
        // eslint-disable-next-line no-console
        console.error('Erro na SPA:', error, info);
    }

    render() {
        if (this.state.error) {
            return (
                <div className="min-h-screen bg-background flex items-center justify-center p-6">
                    <div className="max-w-2xl w-full bg-surface-container-lowest rounded-xl fetec-card-shadow p-8">
                        <div className="flex items-center gap-2 text-error mb-2">
                            <span className="material-symbols-outlined">error</span>
                            <h1 className="font-display text-xl font-semibold">Algo deu errado nesta tela</h1>
                        </div>
                        <p className="text-on-surface-variant text-sm mb-4">
                            Copie a mensagem abaixo para reportar o problema.
                        </p>
                        <pre className="bg-surface-container-low text-on-surface text-xs rounded-lg p-3 overflow-auto whitespace-pre-wrap">
                            {String(this.state.error?.stack || this.state.error?.message || this.state.error)}
                        </pre>
                        <button
                            onClick={() => window.location.assign('/projetos')}
                            className="mt-4 inline-flex items-center gap-2 rounded-lg px-5 py-2.5 font-semibold bg-primary-container text-on-primary"
                        >
                            Voltar aos projetos
                        </button>
                    </div>
                </div>
            );
        }
        return this.props.children;
    }
}
