import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import CampoDataCard from '../components/CampoDataCard.jsx';
import { getAvaliacaoConfig, definirLiberacaoAvaliacao, definirEncerramentoAvaliacao } from '../lib/admin.js';

const PILL = {
    aberta: 'bg-secondary-container text-on-secondary-container',
    futura: 'bg-primary-fixed text-primary-container',
    encerrada: 'bg-error-container text-on-error-container',
    vazia: 'bg-surface-variant text-on-surface-variant',
};

export default function ParametrizacaoAvaliacao() {
    const [config, setConfig] = useState(null);

    useEffect(() => {
        getAvaliacaoConfig()
            .then(setConfig)
            .catch(() => setConfig({
                liberada: false, encerrada: false,
                liberada_em_input: null, liberada_em_label: null,
                encerrada_em_input: null, encerrada_em_label: null,
            }));
    }, []);

    const statusInicio = !config ? null
        : config.liberada ? { txt: 'Avaliação liberada', cor: PILL.aberta }
            : config.liberada_em_label ? { txt: `Libera em ${config.liberada_em_label}`, cor: PILL.futura }
                : { txt: 'Sem data definida', cor: PILL.vazia };

    const statusFim = !config ? null
        : config.encerrada ? { txt: 'Avaliação encerrada', cor: PILL.encerrada }
            : config.encerrada_em_label ? { txt: `Encerra em ${config.encerrada_em_label}`, cor: PILL.futura }
                : { txt: 'Sem encerramento', cor: PILL.vazia };

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação Online</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                A janela em que os avaliadores trabalham. A distribuição dos projetos e o acompanhamento
                continuam na aba <strong>Avaliação online</strong>; aqui ficam só as datas.
            </p>

            {config === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    <CampoDataCard
                        titulo="Início das avaliações"
                        status={statusInicio}
                        valor={config.liberada_em_input}
                        ariaLabel="Data de liberação da avaliação"
                        salvarLabel="Salvar início"
                        descricao={
                            <>
                                Data/hora (horário de Campo Grande) a partir da qual os avaliadores acessam os
                                projetos designados. A partir dela o orientador também <strong>não consegue mais
                                cancelar a submissão</strong>, e o avaliador não troca mais a própria área.
                                Deixe em branco para não liberar.
                            </>
                        }
                        onSalvar={definirLiberacaoAvaliacao}
                        onSalvo={setConfig}
                    />

                    <CampoDataCard
                        titulo="Fim das avaliações"
                        status={statusFim}
                        valor={config.encerrada_em_input}
                        ariaLabel="Data de encerramento da avaliação"
                        salvarLabel="Salvar fim"
                        descricao={
                            <>
                                Data/hora em que o período se encerra. Depois dela o avaliador ainda
                                <strong> consulta</strong> os projetos e o que respondeu, mas não inicia, não
                                salva rascunho e não envia avaliação. Deixe em branco para manter aberto.
                            </>
                        }
                        onSalvar={definirEncerramentoAvaliacao}
                        onSalvo={setConfig}
                    />
                </>
            )}
        </AppShell>
    );
}
