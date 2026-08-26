import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import RegrasDistribuicaoCard from '../components/RegrasDistribuicaoCard.jsx';
import { Button, Alert, Toggle, useConfirm } from '../components/ui.jsx';
import {
    getAvaliacaoConfig, getDistribuicaoConfig, distribuirAvaliacoes,
    redistribuirAvaliacoes, definirDistribuicaoAoCadastrar,
} from '../lib/admin.js';

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

// Distribuição automática (idempotente) + relatório de sub-cobertura.
function DistribuicaoCard({ minPorProjeto }) {
    const [confirm, dialogo] = useConfirm();
    const [rodando, setRodando] = useState('');
    const [relatorio, setRelatorio] = useState(null);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    const alvo = minPorProjeto ?? 3;

    async function distribuir() {
        const ok = await confirm({
            title: 'Distribuir avaliações', confirmLabel: 'Distribuir',
            message: `Vou completar até ${alvo} avaliadores por projeto (por subárea/área), respeitando as regras acima, os limites de cada avaliador e ignorando avaliadores demo. É seguro rodar mais de uma vez. Continuar?`,
        });
        if (!ok) return;
        setRodando('distribuir'); setMsg(''); setErro(''); setRelatorio(null);
        try {
            const resp = await distribuirAvaliacoes();
            setRelatorio(resp.data);
            setMsg(resp.meta?.message || 'Distribuição concluída.');
        } catch {
            setErro('Não foi possível distribuir. Tente novamente.');
        } finally {
            setRodando('');
        }
    }

    async function redistribuir() {
        const ok = await confirm({
            title: 'Redistribuir avaliações', confirmLabel: 'Redistribuir', danger: true,
            message: 'Todo projeto apenas designado volta para o bolo e cada avaliador recebe outros no lugar, pelas regras acima. O que já está em avaliação, o que foi concluído e o que você designou à mão não se mexem. Continuar?',
        });
        if (!ok) return;
        setRodando('redistribuir'); setMsg(''); setErro(''); setRelatorio(null);
        try {
            const resp = await redistribuirAvaliacoes();
            setRelatorio(resp.data);
            setMsg(resp.meta?.message || 'Redistribuição concluída.');
        } catch {
            setErro('Não foi possível redistribuir. Tente novamente.');
        } finally {
            setRodando('');
        }
    }

    const subs = relatorio?.sub_cobertos ?? [];
    const ignorados = relatorio?.ignorados_pela_regra ?? 0;

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-4 max-w-3xl">
            <h3 className="font-display text-primary font-semibold mb-1">Distribuição automática</h3>
            <p className="text-sm text-on-surface-variant mb-3">
                Completa cada projeto elegível até {alvo} avaliadores, casando por subárea (preferencial)
                ou área. Idempotente: pode rodar quantas vezes quiser — só completa o que falta, sem
                mexer no que já foi designado.
            </p>
            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
            <div className="flex items-center gap-2 flex-wrap">
                <Button type="button" loading={rodando === 'distribuir'} disabled={rodando !== ''} onClick={distribuir}>
                    <span className="material-symbols-outlined text-[18px]">shuffle</span>
                    Distribuir avaliações
                </Button>
                <Button type="button" variant="outline" loading={rodando === 'redistribuir'} disabled={rodando !== ''} onClick={redistribuir}>
                    <span className="material-symbols-outlined text-[18px]">autorenew</span>
                    Redistribuir avaliações
                </Button>
            </div>
            <p className="mt-2 text-xs text-on-surface-variant">
                Redistribuir troca o que ainda não foi aberto: em avaliação, concluído e designação
                manual do admin ficam como estão.
            </p>

            {relatorio?.devolvidas > 0 && (
                <p className="mt-3 text-sm text-on-surface">
                    {relatorio.devolvidas} designação(ões) devolvidas ao bolo e {relatorio.recebidas} nova(s) no lugar.
                </p>
            )}
            {ignorados > 0 && (
                <p className="mt-3 text-sm text-on-surface-variant">
                    {ignorados} projeto(s) ficaram de fora pelas regras por categoria.
                </p>
            )}
            {relatorio && subs.length === 0 && (
                <p className="mt-3 text-sm text-secondary font-semibold">
                    Todos os projetos elegíveis têm ao menos {alvo} avaliadores.
                </p>
            )}
            {subs.length > 0 && (
                <div className="mt-4">
                    <p className="text-sm font-semibold text-on-surface mb-2">
                        {subs.length} projeto(s) ainda precisam de avaliadores — complete manualmente na tela de projetos:
                    </p>
                    <ul className="text-sm text-on-surface-variant space-y-1 max-h-56 overflow-auto">
                        {subs.map((s) => (
                            <li key={s.projeto_id} className="flex justify-between gap-2">
                                <span className="truncate">{s.titulo}{s.area ? ` — ${s.area}` : ''}</span>
                                <span className="shrink-0 text-error font-semibold">faltam {s.faltam}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {dialogo}
        </div>
    );
}

// Toggle: quem se cadastra como avaliador já sai com a fila cheia.
function DesignacaoAoCadastrarCard({ config, onSalvo }) {
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    async function alternar(valor) {
        setSalvando(true); setMsg(''); setErro('');
        try {
            const resp = await definirDistribuicaoAoCadastrar(valor);
            onSalvo?.(resp.data);
            setMsg(resp.meta?.message || 'Configuração salva.');
        } catch {
            setErro('Não foi possível salvar. Tente novamente.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-4 max-w-3xl">
            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
            <fieldset disabled={salvando}>
                <Toggle
                    checked={config.ao_cadastrar}
                    onChange={alternar}
                    label="Designar projetos ao cadastrar um avaliador"
                    description="Ligado, quem termina o cadastro de avaliador já encontra projetos na fila — pelas mesmas regras acima, sem esperar a próxima distribuição."
                />
            </fieldset>
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
    const [distribuicao, setDistribuicao] = useState(null);

    useEffect(() => {
        getAvaliacaoConfig().then(setJanela).catch(() => setJanela(null));
        getDistribuicaoConfig().then(setDistribuicao).catch(() => setDistribuicao(null));
    }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação online</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Configure como os projetos chegam aos avaliadores e acompanhe a distribuição por área do
                conhecimento.
            </p>

            <JanelaAvaliacao config={janela} />

            <section className="mb-8">
                <h2 className="font-display text-xl font-semibold text-primary mb-1">Algoritmo de distribuição</h2>
                <p className="text-sm text-on-surface-variant mb-4 max-w-3xl">
                    Os limiares que o algoritmo respeita ao designar projetos automaticamente. O alvo por
                    projeto e a capacidade de cada avaliador continuam em{' '}
                    <Link to="/admin/parametrizacao/avaliacao" className="font-semibold text-primary-container hover:text-primary">
                        Parametrização → Avaliação Online
                    </Link>.
                </p>

                {distribuicao && <RegrasDistribuicaoCard config={distribuicao} onSalvo={setDistribuicao} />}
                {distribuicao && <DesignacaoAoCadastrarCard config={distribuicao} onSalvo={setDistribuicao} />}
                <DistribuicaoCard minPorProjeto={janela?.min_por_projeto} />
            </section>

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
