import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import CampoDataCard from '../components/CampoDataCard.jsx';
import { getInscricoesConfig, definirInicioInscricoes, definirPrazoInscricoes } from '../lib/admin.js';

const PILL = {
    aberta: 'bg-secondary-container text-on-secondary-container',
    futura: 'bg-primary-fixed text-primary-container',
    encerrada: 'bg-error-container text-on-error-container',
    vazia: 'bg-surface-variant text-on-surface-variant',
};

export default function ParametrizacaoInscricoes() {
    const [config, setConfig] = useState(null);

    useEffect(() => {
        getInscricoesConfig()
            .then(setConfig)
            .catch(() => setConfig({
                abertas: true, encerradas: false, nao_iniciadas: false,
                inicio_input: null, inicio_label: null, prazo_input: null, prazo_label: null,
            }));
    }, []);

    const statusInicio = !config ? null
        : config.nao_iniciadas ? { txt: `Abre em ${config.inicio_label}`, cor: PILL.futura }
            : config.inicio_label ? { txt: 'Já abriu', cor: PILL.aberta }
                : { txt: 'Sem data — já abertas', cor: PILL.vazia };

    const statusPrazo = !config ? null
        : config.encerradas ? { txt: 'Inscrições encerradas', cor: PILL.encerrada }
            : config.prazo_label ? { txt: `Encerra em ${config.prazo_label}`, cor: PILL.futura }
                : { txt: 'Sem prazo definido', cor: PILL.vazia };

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Inscrições</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                A janela em que o orientador pode inscrever projetos. Fora dela a área dele fica
                <strong> só de leitura</strong> — e você, como admin, continua podendo agir por fora
                das datas. Deixe um campo em branco para manter aquela ponta aberta.
            </p>

            {config === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    <CampoDataCard
                        titulo="Abertura das inscrições"
                        status={statusInicio}
                        valor={config.inicio_input}
                        ariaLabel="Data de abertura das inscrições"
                        salvarLabel="Salvar abertura"
                        descricao={
                            <>
                                Data/hora (horário de Campo Grande) em que as inscrições abrem. Antes dela
                                ninguém cadastra projeto e o <strong>cadastro de orientador fica fechado</strong> —
                                o cadastro de avaliador continua liberado.
                            </>
                        }
                        onSalvar={definirInicioInscricoes}
                        onSalvo={setConfig}
                    />

                    <CampoDataCard
                        titulo="Prazo de submissão"
                        status={statusPrazo}
                        valor={config.prazo_input}
                        ariaLabel="Data-limite de submissão"
                        salvarLabel="Salvar prazo"
                        descricao={
                            <>
                                Data/hora em que as inscrições se encerram. Passado o prazo, ninguém cria,
                                edita, submete ou cancela projeto — nem quem tinha cancelado o envio para
                                editar. Criar conta continua permitido.
                            </>
                        }
                        onSalvar={definirPrazoInscricoes}
                        onSalvo={setConfig}
                    />
                </>
            )}
        </AppShell>
    );
}
