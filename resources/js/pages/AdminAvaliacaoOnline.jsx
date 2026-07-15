import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, useConfirm } from '../components/ui.jsx';
import { getAvaliacaoConfig, definirLiberacaoAvaliacao, distribuirAvaliacoes } from '../lib/admin.js';

function LiberacaoConfig() {
    const [config, setConfig] = useState(null);
    const [valor, setValor] = useState(''); // "AAAA-MM-DDTHH:mm" (hora de parede local do app)
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    useEffect(() => {
        getAvaliacaoConfig()
            .then((c) => { setConfig(c); setValor(c.liberada_em_input || ''); })
            .catch(() => setConfig({ liberada: false, liberada_em_input: null, liberada_em_label: null }));
    }, []);

    // Envia a hora de parede como está (sem converter para UTC no navegador).
    async function salvar(liberadaEm) {
        setSalvando(true); setMsg(''); setErro('');
        try {
            const c = await definirLiberacaoAvaliacao(liberadaEm);
            setConfig(c); setValor(c.liberada_em_input || '');
            setMsg(liberadaEm ? 'Data de liberação salva.' : 'Liberação removida.');
        } catch {
            setErro('Não foi possível salvar. Tente novamente.');
        } finally {
            setSalvando(false);
        }
    }

    const status = !config ? null
        : config.liberada ? { txt: 'Avaliação liberada', cor: 'bg-secondary text-on-secondary' }
            : config.liberada_em_label ? { txt: `Libera em ${config.liberada_em_label}`, cor: 'bg-primary-fixed text-primary-container' }
                : { txt: 'Sem data definida', cor: 'bg-surface-variant text-on-surface-variant' };

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
            <div className="flex items-center gap-2 flex-wrap mb-3">
                <h2 className="font-display text-primary font-semibold">Liberação da avaliação</h2>
                {status && <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${status.cor}`}>{status.txt}</span>}
            </div>
            <p className="text-sm text-on-surface-variant mb-3">
                Data/hora (horário de Campo Grande) a partir da qual os avaliadores acessam os projetos.
                Deixe em branco para não liberar.
            </p>
            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
            <div className="flex items-end gap-2 flex-wrap">
                <input
                    type="datetime-local"
                    value={valor}
                    onChange={(e) => setValor(e.target.value)}
                    className="bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                />
                <Button type="button" loading={salvando} disabled={!valor} onClick={() => salvar(valor || null)}>
                    Salvar data
                </Button>
                {config?.liberada_em_input && (
                    <Button type="button" variant="outline" onClick={() => salvar(null)}>Remover</Button>
                )}
            </div>
        </div>
    );
}

// Distribuição automática (idempotente) + relatório de sub-cobertura.
function DistribuicaoCard() {
    const [confirm, dialogo] = useConfirm();
    const [rodando, setRodando] = useState(false);
    const [relatorio, setRelatorio] = useState(null);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    async function distribuir() {
        const ok = await confirm({
            title: 'Distribuir avaliações', confirmLabel: 'Distribuir',
            message: 'Vou completar cada projeto submetido até 3 avaliadores (por subárea/área), respeitando os limites e ignorando avaliadores demo. É seguro rodar mais de uma vez. Continuar?',
        });
        if (!ok) return;
        setRodando(true); setMsg(''); setErro(''); setRelatorio(null);
        try {
            const resp = await distribuirAvaliacoes();
            setRelatorio(resp.data);
            setMsg(resp.meta?.message || 'Distribuição concluída.');
        } catch {
            setErro('Não foi possível distribuir. Tente novamente.');
        } finally {
            setRodando(false);
        }
    }

    const subs = relatorio?.sub_cobertos ?? [];

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
            <h2 className="font-display text-primary font-semibold mb-1">Distribuição automática</h2>
            <p className="text-sm text-on-surface-variant mb-3">
                Completa cada projeto submetido até 3 avaliadores, casando por subárea (preferencial) ou
                área. Idempotente: pode rodar quantas vezes quiser — só completa o que falta.
            </p>
            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
            <Button type="button" loading={rodando} onClick={distribuir}>
                <span className="material-symbols-outlined text-[18px]">shuffle</span>
                Distribuir avaliações
            </Button>

            {relatorio && subs.length === 0 && (
                <p className="mt-3 text-sm text-secondary font-semibold">
                    Todos os projetos submetidos têm ao menos 3 avaliadores.
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
    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação online</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Acompanhe a distribuição das avaliações por área do conhecimento. O algoritmo de
                distribuição ainda será implementado — por enquanto os números refletem o que já
                estiver registrado.
            </p>

            <LiberacaoConfig />
            <DistribuicaoCard />

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <CardAvaliacao
                    to="/admin/avaliacao/avaliadores"
                    icon="groups"
                    titulo="Avaliadores por área"
                    descricao="Veja os avaliadores de cada área e o progresso de cada um: em avaliação, já avaliados e quantos faltam."
                />
                <CardAvaliacao
                    to="/admin/avaliacao/projetos"
                    icon="fact_check"
                    titulo="Projetos submetidos"
                    descricao="Projetos submetidos por área, quantas avaliações cada um recebeu e designação manual."
                />
            </div>
        </AppShell>
    );
}
