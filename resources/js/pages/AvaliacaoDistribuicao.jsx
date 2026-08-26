import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import RegrasDistribuicaoCard from '../components/RegrasDistribuicaoCard.jsx';
import { Button, Alert, Toggle, useConfirm } from '../components/ui.jsx';
import {
    getAvaliacaoConfig, getDistribuicaoConfig, distribuirAvaliacoes,
    redistribuirAvaliacoes, definirDistribuicaoAoCadastrar,
} from '../lib/admin.js';

// Distribuição automática (idempotente) e rodízio + relatório de sub-cobertura.
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
            <h2 className="font-display text-primary font-semibold mb-1">Distribuição automática</h2>
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

/**
 * Algoritmo de distribuição: os limiares que o admin ajusta e as duas ações que
 * põem projeto na mão do avaliador. Mora fora da landing de "Avaliação online"
 * para não misturar configuração com acompanhamento.
 */
export default function AvaliacaoDistribuicao() {
    const [janela, setJanela] = useState(null);
    const [distribuicao, setDistribuicao] = useState(null);

    useEffect(() => {
        getAvaliacaoConfig().then(setJanela).catch(() => setJanela(null));
        getDistribuicaoConfig().then(setDistribuicao).catch(() => setDistribuicao(null));
    }, []);

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Algoritmo de distribuição</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Os limiares que o algoritmo respeita ao designar projetos automaticamente. O alvo por
                projeto e a capacidade de cada avaliador continuam em{' '}
                <Link to="/admin/parametrizacao/avaliacao" className="font-semibold text-primary-container hover:text-primary">
                    Parametrização → Avaliação Online
                </Link>.
            </p>

            {distribuicao === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    <RegrasDistribuicaoCard config={distribuicao} onSalvo={setDistribuicao} />
                    <DesignacaoAoCadastrarCard config={distribuicao} onSalvo={setDistribuicao} />
                    <DistribuicaoCard minPorProjeto={janela?.min_por_projeto} />
                </>
            )}
        </AppShell>
    );
}
