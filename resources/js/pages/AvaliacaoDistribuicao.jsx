import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import RegrasDistribuicaoCard from '../components/RegrasDistribuicaoCard.jsx';
import { Button, Alert, Toggle, useConfirm } from '../components/ui.jsx';
import {
    getAvaliacaoConfig, getDistribuicaoConfig, distribuirAvaliacoes,
    redistribuirAvaliacoes, definirDistribuicaoAoCadastrar,
    getProgressoDistribuicao, getUltimaDistribuicao, definirPisoFila,
} from '../lib/admin.js';

/** De quanto em quanto tempo a tela pergunta como vai a rodada. */
const INTERVALO_POLLING = 1500;

/**
 * A barra da rodada em andamento (ou da última concluída).
 *
 * O denominador só é conhecido quando o job começa e conta os projetos da
 * rodada, então antes disso a barra fica indeterminada em vez de mentir um 0%
 * que não quer dizer nada.
 */
function BarraProgresso({ rodada }) {
    const indeterminada = !rodada.finalizada && rodada.total === 0;

    return (
        <div className="mt-4">
            <div className="flex justify-between items-baseline gap-2 mb-1">
                <span className="text-sm font-semibold text-on-surface">
                    {rodada.etapa ?? rodada.status_label}
                </span>
                <span className="text-sm text-on-surface-variant tabular-nums">
                    {indeterminada
                        ? 'Preparando…'
                        : `${rodada.processados} de ${rodada.total} · ${rodada.percentual}%`}
                </span>
            </div>
            <div
                role="progressbar"
                aria-label="Progresso da distribuição"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={indeterminada ? undefined : rodada.percentual}
                className="h-2 w-full rounded-full bg-surface-variant overflow-hidden"
            >
                <div
                    className={`h-full rounded-full transition-[width] duration-300 ${
                        rodada.status === 'falha' ? 'bg-error' : 'bg-primary-container'
                    } ${indeterminada ? 'animate-pulse w-1/3' : ''}`}
                    style={indeterminada ? undefined : { width: `${rodada.percentual}%` }}
                />
            </div>
        </div>
    );
}

// Distribuição automática (idempotente) e rodízio + relatório de sub-cobertura.
//
// As duas ações vão para a FILA: o POST volta na hora com o registro da rodada e
// a tela acompanha por polling até `finalizada`. Antes era uma requisição só,
// que segurava a tela até o fim — uma espera cega e um bom candidato a timeout.
function DistribuicaoCard({ minPorProjeto }) {
    const [confirm, dialogo] = useConfirm();
    const [rodada, setRodada] = useState(null);
    const [enviando, setEnviando] = useState('');
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');
    const timer = useRef(null);

    const alvo = minPorProjeto ?? 3;
    const emAndamento = rodada !== null && !rodada.finalizada;

    // Consulta em laço enquanto a rodada não termina. O timeout é reagendado a
    // cada resposta (e não um setInterval) para duas consultas nunca se
    // atropelarem se a API demorar.
    const acompanhar = useCallback((id) => {
        clearTimeout(timer.current);
        timer.current = setTimeout(async () => {
            try {
                const atual = await getProgressoDistribuicao(id);
                setRodada(atual);
                if (!atual.finalizada) {
                    acompanhar(id);
                } else if (atual.status === 'falha') {
                    setErro(atual.erro || 'A distribuição falhou.');
                } else {
                    setMsg(`${atual.relatorio?.designadas_criadas ?? 0} designação(ões) criada(s).`);
                }
            } catch {
                setErro('Perdi o contato com a distribuição. Recarregue a página para ver como ficou.');
            }
        }, INTERVALO_POLLING);
    }, []);

    // Ao abrir, retoma o acompanhamento: a rodada roda no servidor, então
    // fechar a aba no meio não a interrompe nem perde o relatório.
    useEffect(() => {
        let vivo = true;
        getUltimaDistribuicao()
            .then((ultima) => {
                if (!vivo || !ultima) return;
                setRodada(ultima);
                if (!ultima.finalizada) acompanhar(ultima.id);
            })
            .catch(() => {});

        return () => { vivo = false; clearTimeout(timer.current); };
    }, [acompanhar]);

    async function acionar(tipo, chamada, dialogo_) {
        const ok = await confirm(dialogo_);
        if (!ok) return;

        setEnviando(tipo); setMsg(''); setErro(''); setRodada(null);
        try {
            const resp = await chamada();
            setRodada(resp.data);
            acompanhar(resp.data.id);
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível iniciar. Tente novamente.');
        } finally {
            setEnviando('');
        }
    }

    const distribuir = () => acionar('distribuir', distribuirAvaliacoes, {
        title: 'Distribuir avaliações', confirmLabel: 'Distribuir',
        message: `Vou completar até ${alvo} avaliadores por projeto (por subárea/área), respeitando as regras acima, os limites de cada avaliador e ignorando avaliadores demo. É seguro rodar mais de uma vez. Continuar?`,
    });

    const redistribuir = () => acionar('redistribuir', redistribuirAvaliacoes, {
        title: 'Redistribuir avaliações', confirmLabel: 'Redistribuir', danger: true,
        message: 'Todo projeto apenas designado volta para o bolo e cada avaliador recebe outros no lugar, pelas regras acima. O que já está em avaliação, o que foi concluído e o que você designou à mão não se mexem. Continuar?',
    });

    const relatorio = rodada?.finalizada ? rodada.relatorio : null;
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
                <Button
                    type="button"
                    loading={enviando === 'distribuir'}
                    disabled={enviando !== '' || emAndamento}
                    onClick={distribuir}
                >
                    <span className="material-symbols-outlined text-[18px]">shuffle</span>
                    Distribuir avaliações
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    loading={enviando === 'redistribuir'}
                    disabled={enviando !== '' || emAndamento}
                    onClick={redistribuir}
                >
                    <span className="material-symbols-outlined text-[18px]">autorenew</span>
                    Redistribuir avaliações
                </Button>
            </div>
            <p className="mt-2 text-xs text-on-surface-variant">
                Redistribuir troca o que ainda não foi aberto: em avaliação, concluído e designação
                manual do admin ficam como estão.
            </p>

            {rodada && <BarraProgresso rodada={rodada} />}

            {emAndamento && (
                <p className="mt-2 text-xs text-on-surface-variant">
                    Pode fechar esta página: a distribuição continua rodando no servidor.
                </p>
            )}

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

/**
 * O **piso da fila**: a rede de segurança das regras acima.
 *
 * Uma regra restritiva ("só FUNDECT com 0 avaliações") pode deixar tão pouco
 * projeto elegível que o avaliador fica sem trabalho. O piso não afrouxa a
 * regra — ela continua escolhendo quem entra primeiro; ele só garante que
 * ninguém termine a distribuição com a fila quase vazia.
 */
function PisoFilaCard({ config, onSalvo }) {
    const [valor, setValor] = useState(config.piso_fila ?? '');
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    async function salvar(novo) {
        setSalvando(true); setMsg(''); setErro('');
        try {
            const resp = await definirPisoFila(novo);
            onSalvo?.(resp.data);
            setValor(resp.data.piso_fila ?? '');
            setMsg(resp.meta?.message || 'Piso salvo.');
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível salvar. Tente novamente.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-4 max-w-3xl">
            <h2 className="font-display text-primary font-semibold mb-1">Piso da fila do avaliador</h2>
            <p className="text-sm text-on-surface-variant mb-3">
                Quantos projetos, no mínimo, cada avaliador precisa ter na fila. As regras acima valem
                primeiro; <strong>só quando elas deixam alguém abaixo deste número</strong> a distribuição
                completa a fila dele ignorando-as. Vale também no botão{' '}
                <em>Sortear outros projetos</em> do avaliador. Em branco, não há piso: a regra manda
                sozinha, mesmo que deixe gente parada.
            </p>
            <p className="text-sm text-on-surface-variant mb-3">
                Acima de tudo isso vale a <strong>cota justa</strong>: quando a área não tem projeto
                suficiente para todo mundo, a fila de cada avaliador encolhe para o que a área
                comporta dividido pelos avaliadores dela. É o que evita terminar a feira com alguns
                em {config.piso_fila ?? 6} projetos e outros em nenhum.
            </p>
            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
            <div className="flex items-end gap-2 flex-wrap">
                <input
                    type="number"
                    min="1"
                    max={config.piso_maximo ?? 50}
                    aria-label="Piso da fila do avaliador"
                    value={valor}
                    onChange={(e) => setValor(e.target.value)}
                    placeholder="sem piso"
                    className="w-32 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                />
                <Button type="button" loading={salvando} onClick={() => salvar(valor === '' ? null : Number(valor))}>
                    Salvar piso
                </Button>
                {config.piso_fila !== null && (
                    <Button type="button" variant="outline" disabled={salvando} onClick={() => salvar(null)}>
                        Remover piso
                    </Button>
                )}
            </div>
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
                    <PisoFilaCard config={distribuicao} onSalvo={setDistribuicao} />
                    <DesignacaoAoCadastrarCard config={distribuicao} onSalvo={setDistribuicao} />
                    <DistribuicaoCard minPorProjeto={janela?.min_por_projeto} />
                </>
            )}
        </AppShell>
    );
}
