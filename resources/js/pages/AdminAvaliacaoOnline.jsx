import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getAvaliacaoConfig } from '../lib/admin.js';

// Resumo das datas do período — quem muda é a Parametrização.
function JanelaAvaliacao({ config }) {
    const estado = !config ? null
        : config.encerrada ? `Período encerrado em ${config.encerrada_em_label}.`
            : config.liberada
                ? `Avaliação liberada${config.encerrada_em_label ? ` — encerra em ${config.encerrada_em_label}` : ''}.`
                : config.liberada_em_label ? `Libera em ${config.liberada_em_label}.` : 'Sem data de liberação definida.';

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl flex items-center gap-3 flex-wrap">
            <span className="material-symbols-outlined text-primary-container">event</span>
            <p className="text-sm text-on-surface flex-1 min-w-0">{estado ?? 'Carregando as datas…'}</p>
            <Link
                to="/admin/parametrizacao/avaliacao"
                className="text-sm font-semibold text-primary-container hover:text-primary shrink-0"
            >
                Alterar datas
            </Link>
        </div>
    );
}

// Card de acesso a uma tela da avaliação online.
function CardAvaliacao({ to, icon, titulo, descricao }) {
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

export default function AdminAvaliacaoOnline() {
    const [janela, setJanela] = useState(null);

    useEffect(() => {
        getAvaliacaoConfig().then(setJanela).catch(() => setJanela(null));
    }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação online</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Configure como os projetos chegam aos avaliadores e acompanhe a distribuição por área do
                conhecimento.
            </p>

            <JanelaAvaliacao config={janela} />

            {/* As regras do algoritmo e as duas ações de distribuição moram numa tela só. */}
            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl flex items-center gap-4 flex-wrap">
                <div className="min-w-0 flex-1">
                    <h2 className="font-display text-lg font-semibold text-on-surface">Algoritmo de distribuição</h2>
                    <p className="text-sm text-on-surface-variant mt-1">
                        Quais projetos entram na distribuição automática, em que faixa de avaliações, e as
                        ações de <strong>distribuir</strong> e <strong>redistribuir</strong>.
                    </p>
                </div>
                <Link
                    to="/admin/avaliacao/distribuicao"
                    className="inline-flex items-center justify-center gap-2 rounded-lg px-5 py-2.5 font-semibold transition-colors bg-primary-container text-on-primary hover:bg-primary shrink-0"
                >
                    <span className="material-symbols-outlined text-[18px]">tune</span>
                    Abrir configurações
                </Link>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardAvaliacao
                    to="/admin/avaliacao/avaliadores"
                    icon="groups"
                    titulo="Avaliadores Online"
                    descricao="Panorama dos avaliadores (totais e distribuição por área) e o progresso de cada um: em avaliação, já avaliados e quantos faltam."
                />
                <CardAvaliacao
                    to="/admin/avaliacao/projetos"
                    icon="fact_check"
                    titulo="Projetos submetidos"
                    descricao="Projetos submetidos por área, quantas avaliações cada um recebeu e designação manual."
                />
                <CardAvaliacao
                    to="/admin/avaliacao/designacoes"
                    icon="assignment_ind"
                    titulo="Designações"
                    descricao="Tudo que está na mão de cada avaliador e há quanto tempo. Retire uma designação parada e o projeto vai para outro avaliador na hora."
                />
                <CardAvaliacao
                    to="/admin/avaliacao/reclassificacoes"
                    icon="rule"
                    titulo="Reclassificações sugeridas"
                    descricao="Projetos em que avaliadores apontaram área ou subárea incorreta, com o consenso das sugestões."
                />
                <CardAvaliacao
                    to="/admin/avaliacao/ranking"
                    icon="trophy"
                    titulo="Ranking dos projetos"
                    descricao="Projetos já avaliados, ordenados pela média das notas finais, com as médias de cada seção da rubrica."
                />
                <CardAvaliacao
                    to="/admin/avaliacao/ranking-avaliadores"
                    icon="social_leaderboard"
                    titulo="Ranking dos avaliadores"
                    descricao="Quem mais avaliou: nome, área, avaliações concluídas, projetos em avaliação e de onde a pessoa é."
                />
            </div>
        </AppShell>
    );
}
