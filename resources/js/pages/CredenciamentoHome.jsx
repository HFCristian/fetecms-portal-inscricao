import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Toggle } from '../components/ui.jsx';
import { getFinalistas } from '../lib/credenciamento.js';
import { useModoTeste } from '../lib/modoTeste.js';

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
                <p className="text-2xl font-bold text-primary-container mt-2">
                    {numero} <span className="text-sm font-normal text-on-surface-variant">{rotulo}</span>
                </p>
            </div>
            <span className="material-symbols-outlined text-on-surface-variant ml-auto self-center group-hover:translate-x-0.5 transition-transform">chevron_right</span>
        </Link>
    );
}

/**
 * Aba Credenciamento: as duas seções do balcão do evento.
 *
 * Quem é finalista sai da **lista final vigente** — sem lista oficial não há
 * ninguém para credenciar. O balcão só abre dentro da janela do evento; fora
 * dela a aba continua consultável, apenas não credencia.
 */
export default function CredenciamentoHome() {
    const [teste, setTeste] = useModoTeste();
    const [dados, setDados] = useState(null);

    useEffect(() => {
        getFinalistas({}, teste).then(setDados).catch(() => setDados(null));
    }, [teste]);

    const config = dados?.meta?.config;
    const resumo = dados?.meta?.resumo ?? { finalistas: 0, credenciados: 0, pendentes: 0 };

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Credenciamento</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O balcão do evento: os <strong>finalistas</strong> da lista final vigente passam para
                conferir a documentação de alunos, orientador e coorientador. Cada credenciamento
                registra quem atendeu e o horário.
            </p>

            {config && !config.lista && (
                <div className="mb-4 max-w-3xl">
                    <Alert>
                        Nenhuma lista final oficial foi publicada nesta edição — sem ela não há
                        finalistas para credenciar.
                    </Alert>
                </div>
            )}

            {config && !config.aberto && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        {config.encerrado
                            ? `O evento foi encerrado em ${config.fim_label}. O credenciamento está só para consulta.`
                            : config.inicio_label
                                ? `O credenciamento abre em ${config.inicio_label}.`
                                : 'O período do evento ainda não foi definido em Parametrização → Credenciamento.'}
                    </Alert>
                </div>
            )}

            {/* Só o admin demo enxerga o modo de teste. */}
            {config?.pode_testar && (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                    <Toggle
                        checked={teste}
                        onChange={setTeste}
                        label="Modo de teste (conta demo)"
                        description="Abre o credenciamento antes do evento, ignorando as datas. Só vale para a sua conta."
                    />
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardSecao
                    to="/admin/credenciamento/credenciar"
                    icon="how_to_reg"
                    titulo="Credenciar"
                    descricao="Os finalistas que ainda não passaram pelo balcão."
                    numero={resumo.pendentes}
                    rotulo={resumo.pendentes === 1 ? 'a credenciar' : 'a credenciar'}
                />
                <CardSecao
                    to="/admin/credenciamento/credenciados"
                    icon="fact_check"
                    titulo="Credenciados"
                    descricao="Quem já foi credenciado, com busca e filtros por área e categoria."
                    numero={resumo.credenciados}
                    rotulo={resumo.credenciados === 1 ? 'credenciado' : 'credenciados'}
                />
            </div>
        </AppShell>
    );
}
