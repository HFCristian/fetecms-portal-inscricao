import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Toggle } from '../components/ui.jsx';
import { getAlmoxarifadoRegistros } from '../lib/almoxarifado.js';
import { useModoTeste } from '../lib/modoTeste.js';
import { useAuth } from '../lib/auth.jsx';

function CardSecao({ to, icon, titulo, descricao, numero, rotulo }) {
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
                {numero !== undefined && (
                    <p className="text-2xl font-bold text-primary-container mt-2">
                        {numero} <span className="text-sm font-normal text-on-surface-variant">{rotulo}</span>
                    </p>
                )}
            </div>
            <span className="material-symbols-outlined text-on-surface-variant ml-auto self-center group-hover:translate-x-0.5 transition-transform">chevron_right</span>
        </Link>
    );
}

/**
 * Aba Almoxarifado: a guarda de volumes dos finalistas durante a feira.
 *
 * Quem pode deixar material é quem está na **lista final vigente** — sem lista
 * oficial não há a quem atender. O balcão só abre dentro da janela do evento;
 * fora dela a aba continua consultável, apenas não registra entrada nova.
 *
 * O **modo demo** (só para conta demo) ignora as datas, usa a lista de
 * demonstração e isola os registros do ensaio, então treinar aqui nunca devolve
 * o material de um finalista de verdade.
 */
export default function AlmoxarifadoHome() {
    const [teste, setTeste] = useModoTeste();
    const [dados, setDados] = useState(null);
    const { user } = useAuth();

    useEffect(() => {
        getAlmoxarifadoRegistros({}, teste).then(setDados).catch(() => setDados(null));
    }, [teste]);

    const config = dados?.meta?.config;
    const resumo = dados?.meta?.resumo ?? { registros: 0, guardados: 0, itens_guardados: 0 };

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Almoxarifado</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O balcão de guarda do evento: a equipe de um projeto finalista deixa maquete,
                ferramenta e mochila enquanto circula pela feira. Cada registro guarda quem deixou,
                o que ficou e, depois, quem levou de volta — que quase nunca é a mesma pessoa.
            </p>

            {config && !config.lista && (
                <div className="mb-4 max-w-3xl">
                    <Alert>
                        {config.modo_teste
                            ? 'Nenhuma lista de demonstração foi criada nesta edição. Peça à equipe técnica para rodar “php artisan demo:credenciamento”.'
                            : 'Nenhuma lista final oficial foi publicada nesta edição — sem ela não há finalistas para atender.'}
                    </Alert>
                </div>
            )}

            {config?.lista?.demo && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        Você está no <strong>modo de teste</strong>, com a lista{' '}
                        <strong>{config.lista.nome}</strong>. Os projetos são fictícios e os
                        registros ficam separados dos de verdade.
                    </Alert>
                </div>
            )}

            {config && !config.aberto && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        {config.encerrado
                            ? `O evento foi encerrado em ${config.fim_label}. O almoxarifado está só para consulta.`
                            : config.inicio_label
                                ? `O almoxarifado abre em ${config.inicio_label}.`
                                : 'O período do evento ainda não foi definido em Parametrização → Datas e períodos.'}
                    </Alert>
                </div>
            )}

            {config?.pode_testar && (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                    <Toggle
                        checked={teste}
                        onChange={setTeste}
                        label="Modo demo (teste do almoxarifado)"
                        description="Ignora as datas do evento, usa a lista de demonstração e separa os registros do ensaio. Só vale para a sua conta."
                    />
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardSecao
                    to="/admin/almoxarifado/novo"
                    icon="add_box"
                    titulo="Guardar material"
                    descricao="O passo a passo: projeto, quem está deixando, os itens e a conferência final."
                />
                <CardSecao
                    to="/admin/almoxarifado/registros"
                    icon="inventory_2"
                    titulo="Registros"
                    descricao="Tudo o que passou pelo balcão, com o que ainda está guardado e o que já saiu."
                    numero={resumo.itens_guardados}
                    rotulo={resumo.itens_guardados === 1 ? 'item guardado' : 'itens guardados'}
                />
                {/* Contas de prazo curto para quem atende este balcão. Lista
                    própria: a do credenciamento não abre o almoxarifado. Quem É
                    uma conta temporária não as gere — o backend recusa. */}
                {!user?.conta_temporaria && (
                    <CardSecao
                        to="/admin/almoxarifado/contas"
                        icon="badge"
                        titulo="Contas temporárias"
                        descricao="Acesso de prazo curto para quem atende o almoxarifado, restrito a esta aba."
                    />
                )}
            </div>
        </AppShell>
    );
}
