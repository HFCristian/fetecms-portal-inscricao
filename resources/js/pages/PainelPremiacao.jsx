import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { PilulaTipo } from '../components/CartaoCredencial.jsx';
import { getPainelPremiacao } from '../lib/recomendacoes.js';
import { getPainelComite } from '../lib/avaliador.js';

/** A tela fica aberta (às vezes projetada) durante a deliberação: recarrega sozinha. */
const ATUALIZAR_MS = 30_000;

const ORIGENS = {
    admin: { carregar: getPainelPremiacao, voltar: '/admin/comite/premiacao', rotuloVoltar: 'Premiação' },
    avaliador: { carregar: getPainelComite, voltar: '/avaliador/comite', rotuloVoltar: 'Comitê especial' },
};

const hora = (iso) => (iso ? new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '');

function Barra({ valor, total, cor = 'bg-primary-container' }) {
    const pct = total > 0 ? Math.min(100, Math.round((valor / total) * 100)) : 0;

    return (
        <div className="h-2 rounded-full bg-surface-variant overflow-hidden" role="progressbar" aria-valuenow={valor} aria-valuemin={0} aria-valuemax={total}>
            <div className={`h-full rounded-full ${cor}`} style={{ width: `${pct}%` }} />
        </div>
    );
}

function Numero({ rotulo, valor, detalhe, destaque = false, children }) {
    return (
        <div className={`rounded-xl fetec-card-shadow p-4 ${destaque ? 'bg-primary-container text-on-primary' : 'bg-surface-container-lowest'}`}>
            <p className={`text-xs font-semibold uppercase tracking-wide ${destaque ? 'text-on-primary/80' : 'text-on-surface-variant'}`}>{rotulo}</p>
            <p className="font-display text-3xl font-semibold mt-1">{valor}</p>
            {detalhe && <p className={`text-xs mt-1 ${destaque ? 'text-on-primary/80' : 'text-on-surface-variant'}`}>{detalhe}</p>}
            {children}
        </div>
    );
}

/** Uma credencial: quanto falta, em barra, e quem já levou. */
function CartaoSituacao({ c }) {
    const situacao = c.vagas === null
        ? { texto: `${c.usadas} atribuída(s) · sem limite`, classe: 'text-on-surface-variant' }
        : c.esgotada
            ? { texto: 'Esgotada', classe: 'text-error' }
            : { texto: `Falta(m) ${c.faltam} de ${c.vagas}`, classe: 'text-secondary' };

    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex flex-col">
            <div className="flex items-start justify-between gap-2">
                <PilulaTipo tipo={c.tipo} label={c.tipo_label} />
                <span className={`text-sm font-semibold ${situacao.classe}`}>{situacao.texto}</span>
            </div>
            <p className="font-semibold text-on-surface mt-2">{c.nome}</p>
            {c.orgao && <p className="text-xs text-on-surface-variant">{c.orgao}</p>}
            {c.vagas !== null && (
                <div className="mt-2">
                    <Barra valor={c.usadas} total={c.vagas} cor={c.esgotada ? 'bg-error' : 'bg-secondary'} />
                    <p className="text-xs text-on-surface-variant mt-1">{c.usadas} de {c.vagas} vaga(s) preenchida(s)</p>
                </div>
            )}
            {c.aprovadas_aguardando > 0 && (
                <p className="text-xs font-semibold text-yellow-800 mt-2">
                    {c.aprovadas_aguardando} recomendação(ões) aprovada(s) aguardando o anexo
                </p>
            )}
            {c.premiados.length > 0 ? (
                <ul className="mt-2 space-y-0.5">
                    {c.premiados.map((p) => (
                        <li key={p.id} className="text-sm text-on-surface flex gap-1.5">
                            <span className="material-symbols-outlined text-[16px] text-secondary mt-0.5">check_circle</span>
                            <span>{p.titulo}{p.area && <span className="text-xs text-on-surface-variant"> · {p.area}</span>}</span>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-on-surface-variant mt-2">Ainda não atribuída.</p>
            )}
        </li>
    );
}

const MEDALHA = { 1: 'bg-yellow-400 text-yellow-950', 2: 'bg-gray-300 text-gray-900', 3: 'bg-orange-300 text-orange-950' };

/** Uma área: os premiados do que mais recebeu para o que menos. */
function RankingArea({ grupo }) {
    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4" aria-label={`Ranking de ${grupo.area}`}>
            <div className="flex items-baseline justify-between gap-2 mb-2">
                <h3 className="font-display text-base font-semibold text-on-surface">{grupo.area}</h3>
                <span className="text-xs text-on-surface-variant">{grupo.premiacoes} premiação(ões)</span>
            </div>
            <ol className="space-y-2">
                {grupo.projetos.map((p) => (
                    <li key={p.id} className="flex items-start gap-3">
                        <span className={`w-8 h-8 shrink-0 rounded-full flex items-center justify-center text-sm font-bold ${MEDALHA[p.posicao] ?? 'bg-surface-variant text-on-surface'}`}>
                            {p.posicao}º
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-on-surface">
                                {p.titulo}
                                <span className="ml-2 text-xs font-normal text-on-surface-variant">{p.total} premiação(ões)</span>
                            </p>
                            <p className="text-xs text-on-surface-variant">{[p.categoria, p.escola].filter(Boolean).join(' · ')}</p>
                            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                                {p.premiacoes.map((pr) => (
                                    <span key={pr.nome} className="inline-flex items-center gap-1 text-xs text-on-surface">
                                        <PilulaTipo tipo={pr.tipo} label={pr.tipo_label} />
                                        {pr.nome}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    );
}

/**
 * **Painel da premiação** (Sprint 196): a mesma base de Credenciais e Prêmios
 * lida de longe — quantas vagas ainda faltam, credencial a credencial, e o
 * ranking dos projetos premiados por área do conhecimento.
 *
 * Abre pelo botão da Premiação do comitê (admin) e da aba Comitê especial
 * (comissão), só leitura. Feita para ficar aberta na mesa da deliberação: tem
 * tela cheia e recarrega sozinha a cada 30 segundos.
 */
export default function PainelPremiacao({ origem = 'admin' }) {
    const config = ORIGENS[origem] ?? ORIGENS.admin;
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [telaCheia, setTelaCheia] = useState(false);
    const palco = useRef(null);

    const carregar = useCallback(() => config.carregar()
        .then((d) => { setDados(d); setErro(''); })
        .catch(() => setErro('Não foi possível carregar o painel da premiação.')), [config]);

    useEffect(() => {
        carregar();
        const id = setInterval(carregar, ATUALIZAR_MS);
        return () => clearInterval(id);
    }, [carregar]);

    useEffect(() => {
        const aoTrocar = () => setTelaCheia(Boolean(document.fullscreenElement));
        document.addEventListener('fullscreenchange', aoTrocar);
        return () => document.removeEventListener('fullscreenchange', aoTrocar);
    }, []);

    async function alternarTelaCheia() {
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else await palco.current?.requestFullscreen?.();
        } catch {
            setErro('Este navegador não permitiu abrir o painel em tela cheia.');
        }
    }

    const resumo = dados?.resumo;

    return (
        <AppShell>
            <Link to={config.voltar} className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> {config.rotuloVoltar}
            </Link>

            <div ref={palco} className={telaCheia ? 'bg-background overflow-y-auto p-6 h-full' : ''}>
                <div className="flex flex-wrap items-start justify-between gap-3 mb-1 max-w-6xl">
                    <div>
                        <h1 className="font-display text-2xl font-semibold text-primary">Painel da premiação</h1>
                        <p className="text-on-surface-variant text-sm">
                            Quanto ainda falta dar e quem já levou o quê.
                            {dados && ` Atualizado às ${hora(dados.atualizado_em)} — recarrega sozinho.`}
                        </p>
                    </div>
                    <Button type="button" variant="outline" onClick={alternarTelaCheia}>
                        <span className="material-symbols-outlined text-[20px]">{telaCheia ? 'fullscreen_exit' : 'fullscreen'}</span>
                        {telaCheia ? 'Sair da tela cheia' : 'Tela cheia'}
                    </Button>
                </div>

                {erro && <div className="my-4 max-w-6xl"><Alert>{erro}</Alert></div>}

                {dados === null ? (
                    !erro && (
                        <div className="text-center py-10 text-on-surface-variant">
                            <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                        </div>
                    )
                ) : (
                    <div className="max-w-6xl space-y-8 mt-4">
                        <section aria-label="Resumo" className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <Numero
                                rotulo="Vagas que faltam"
                                valor={resumo.faltam}
                                destaque
                                detalhe={`em ${resumo.credenciais_com_vaga} credencial(is) com vaga aberta`}
                            />
                            <Numero
                                rotulo="Vagas preenchidas"
                                valor={`${resumo.vagas_preenchidas} de ${resumo.vagas_total}`}
                                detalhe={`${resumo.esgotadas} esgotada(s)${resumo.sem_limite ? ` · ${resumo.sem_limite} sem limite de vagas` : ''}`}
                            >
                                <div className="mt-2"><Barra valor={resumo.vagas_preenchidas} total={resumo.vagas_total} /></div>
                            </Numero>
                            <Numero
                                rotulo="Projetos premiados"
                                valor={resumo.projetos_premiados}
                                detalhe={`${resumo.premiacoes} premiação(ões) atribuída(s)`}
                            />
                            <Numero
                                rotulo="Aprovadas aguardando"
                                valor={resumo.aprovadas_aguardando}
                                detalhe="recomendações do comitê aprovadas e ainda sem a credencial"
                            />
                        </section>

                        <section aria-label="Credenciais e prêmios">
                            <h2 className="font-display text-lg font-semibold text-on-surface mb-3">Credenciais e prêmios</h2>
                            {dados.credenciais.length === 0 ? (
                                <p className="text-sm text-on-surface-variant">Nenhuma credencial ou prêmio ativo nesta edição.</p>
                            ) : (
                                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {dados.credenciais.map((c) => <CartaoSituacao key={c.id} c={c} />)}
                                </ul>
                            )}
                        </section>

                        <section aria-label="Ranking por área">
                            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Ranking por área do conhecimento</h2>
                            <p className="text-sm text-on-surface-variant mb-3">
                                Os projetos premiados de cada área, do que mais recebeu credenciais e prêmios para o que menos.
                                Mesma quantidade, mesma posição.
                            </p>
                            {dados.ranking_por_area.length === 0 ? (
                                <p className="text-sm text-on-surface-variant">Nenhum projeto premiado ainda.</p>
                            ) : (
                                <div className="grid gap-4 lg:grid-cols-2">
                                    {dados.ranking_por_area.map((g) => <RankingArea key={g.area} grupo={g} />)}
                                </div>
                            )}
                        </section>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
