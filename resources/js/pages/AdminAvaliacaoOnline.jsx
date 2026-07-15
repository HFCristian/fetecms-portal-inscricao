import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert } from '../components/ui.jsx';
import { getAvaliacaoConfig, definirLiberacaoAvaliacao } from '../lib/admin.js';

// ISO -> valor de <input type="datetime-local"> (hora local).
function isoParaInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

function LiberacaoConfig() {
    const [config, setConfig] = useState(null);
    const [valor, setValor] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    useEffect(() => {
        getAvaliacaoConfig()
            .then((c) => { setConfig(c); setValor(isoParaInput(c.liberada_em)); })
            .catch(() => setConfig({ liberada: false, liberada_em: null }));
    }, []);

    async function salvar(liberadaEm) {
        setSalvando(true); setMsg(''); setErro('');
        try {
            const c = await definirLiberacaoAvaliacao(liberadaEm);
            setConfig(c); setValor(isoParaInput(c.liberada_em));
            setMsg(liberadaEm ? 'Data de liberação salva.' : 'Liberação removida.');
        } catch {
            setErro('Não foi possível salvar. Tente novamente.');
        } finally {
            setSalvando(false);
        }
    }

    const status = !config ? null
        : config.liberada ? { txt: 'Avaliação liberada', cor: 'bg-secondary text-on-secondary' }
            : config.liberada_em ? { txt: `Libera em ${new Date(config.liberada_em).toLocaleString('pt-BR')}`, cor: 'bg-primary-fixed text-primary-container' }
                : { txt: 'Sem data definida', cor: 'bg-surface-variant text-on-surface-variant' };

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
            <div className="flex items-center gap-2 flex-wrap mb-3">
                <h2 className="font-display text-primary font-semibold">Liberação da avaliação</h2>
                {status && <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${status.cor}`}>{status.txt}</span>}
            </div>
            <p className="text-sm text-on-surface-variant mb-3">
                Antes desta data/hora os avaliadores não acessam os projetos. Deixe em branco para não liberar.
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
                <Button type="button" loading={salvando} disabled={!valor} onClick={() => salvar(valor ? new Date(valor).toISOString() : null)}>
                    Salvar data
                </Button>
                {config?.liberada_em && (
                    <Button type="button" variant="outline" onClick={() => salvar(null)}>Remover</Button>
                )}
            </div>
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
