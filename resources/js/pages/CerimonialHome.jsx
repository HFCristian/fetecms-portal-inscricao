import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Toggle } from '../components/ui.jsx';
import { getCerimonialConfig } from '../lib/cerimonial.js';
import { useModoTeste } from '../lib/modoTeste.js';

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

/**
 * Aba **Cerimonial**: a porta da cerimônia de premiação.
 *
 * É outro balcão que não o do credenciamento — outro momento, outra sala,
 * outra fila —, e a pergunta aqui é só uma: quem já está dentro? Por isso o
 * check-in não exige que o projeto tenha passado pelo credenciamento.
 *
 * A conta temporária do setor enxerga **apenas o Check-in**: quem atende a
 * porta não precisa saber quantas medalhas estão na mesa, e não gere as contas
 * dos colegas. O servidor recusa as outras duas seções de qualquer jeito —
 * esconder o card é cortesia, não a trava.
 */
export default function CerimonialHome() {
    const [teste, setTeste] = useModoTeste();
    const [config, setConfig] = useState(null);

    useEffect(() => {
        getCerimonialConfig(teste).then(setConfig).catch(() => setConfig(null));
    }, [teste]);

    const podeGerir = config?.pode_gerir !== false;

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Cerimonial</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                A porta da <strong>cerimônia de premiação</strong>. Os finalistas fazem check-in ao
                entrar — pelo crachá ou pelo nome — e o painel diz, a qualquer momento, quem já
                chegou, quantas medalhas e quantas credenciais separar.
            </p>

            {config && !config.lista && (
                <div className="mb-4 max-w-3xl">
                    <Alert>
                        {config.modo_teste
                            ? 'Nenhuma lista de demonstração foi criada nesta edição. Peça à equipe técnica para rodar “php artisan demo:credenciamento”.'
                            : 'Nenhuma lista final oficial foi publicada nesta edição — sem ela não há finalistas para receber.'}
                    </Alert>
                </div>
            )}

            {config?.lista?.demo && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        Você está no <strong>modo de teste</strong>, com a lista{' '}
                        <strong>{config.lista.nome}</strong>. Os projetos abaixo são fictícios e os
                        check-ins do ensaio não entram na contagem da cerimônia de verdade.
                    </Alert>
                </div>
            )}

            {config && !config.aberto && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        {config.encerrado
                            ? `O evento foi encerrado em ${config.fim_label}. O cerimonial está só para consulta.`
                            : config.inicio_label
                                ? `O cerimonial abre em ${config.inicio_label}.`
                                : 'O período do evento ainda não foi definido em Parametrização → Datas e períodos.'}
                    </Alert>
                </div>
            )}

            {config?.pode_testar && (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                    <Toggle
                        checked={teste}
                        onChange={setTeste}
                        label="Modo demo (teste do cerimonial)"
                        description="Ignora as datas do evento e troca a lista oficial pela de demonstração. Só vale para a sua conta — nenhum finalista de verdade entra na contagem."
                    />
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardSecao
                    to="/admin/cerimonial/checkin"
                    icon="how_to_reg"
                    titulo="Check-in"
                    descricao="Leia o crachá ou busque pelo nome ou CPF para marcar quem chegou."
                />
                {podeGerir && (
                    <>
                        <CardSecao
                            to="/admin/cerimonial/visao-geral"
                            icon="monitoring"
                            titulo="Visão Geral"
                            descricao="Pessoas, projetos, premiados, medalhas e credenciais — com quem já chegou e quem falta."
                        />
                        <CardSecao
                            to="/admin/cerimonial/contas"
                            icon="badge"
                            titulo="Contas temporárias"
                            descricao="Acesso de prazo curto para quem atende a porta, restrito ao check-in."
                        />
                    </>
                )}
            </div>
        </AppShell>
    );
}
