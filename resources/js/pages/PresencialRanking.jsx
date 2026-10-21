import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import DialogoAvaliacoesPresenciais from '../components/DialogoAvaliacoesPresenciais.jsx';
import ListaPresencialDialog from '../components/ListaPresencialDialog.jsx';
import { Alert, Button, Toggle } from '../components/ui.jsx';
import { extractErrors, useAuth } from '../lib/auth.jsx';
import { exportarRankingPresencial, getAvaliacoesRankingPresencial, getRankingPresencial } from '../lib/presencial.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

// Medalha para o pódio; do 4º em diante, só o número.
const MEDALHA = { 1: '🥇', 2: '🥈', 3: '🥉' };

// Três casas, como a rubrica presencial: os pesos foram escolhidos para desempatar.
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(3).replace('.', ',');

const peso = (valor) => String(valor ?? '').replace('.', ',');

const rotulo = (n) => String(n).padStart(3, '0');

// Os quatro formatos do ranking online (Sprint 153), na mesma ordem.
const FORMATOS = [
    { formato: 'csv', rotulo: 'CSV' },
    { formato: 'xlsx', rotulo: 'Excel' },
    { formato: 'pdf', rotulo: 'PDF' },
    { formato: 'txt', rotulo: 'TXT' },
];

function Posicao({ n }) {
    return (
        <span className="w-10 shrink-0 text-center" aria-label={`${n}º lugar`}>
            {MEDALHA[n]
                ? <span className="text-2xl leading-none">{MEDALHA[n]}</span>
                : <span className="text-lg font-bold text-on-surface-variant">{n}º</span>}
        </span>
    );
}

function Linha({ p, onVerAvaliacoes }) {
    return (
        <li className="px-4 py-3 flex items-center gap-3 flex-wrap">
            <Posicao n={p.posicao} />

            <div className="flex-1 min-w-0">
                <p className="text-sm text-on-surface truncate">
                    {p.codigo && <span className="font-semibold text-primary-container mr-1">{p.codigo}</span>}
                    {p.titulo}
                </p>
                <p className="text-xs text-on-surface-variant truncate">
                    {p.area ?? 'Sem área'}{p.categoria ? ` · ${p.categoria}` : ''}
                    {p.estande ? ` · Estande ${rotulo(p.estande)}` : ''}{p.turno_label ? ` · ${p.turno_label}` : ''}
                </p>
                {/* As notas que formaram a média, sem quem avaliou: a da comissão em destaque. */}
                <p className="text-xs text-on-surface-variant mt-0.5">
                    {p.notas.map((n, i) => (
                        <span key={i} className={`mr-2 ${n.vaga === 'comite' ? 'font-semibold text-secondary' : ''}`}
                            title={n.vaga === 'comite' ? `Comissão especial · peso ${peso(n.peso)}` : 'Vaga comum · peso 1'}>
                            {nota(n.nota)}{n.vaga === 'comite' ? ` (comissão ×${peso(n.peso)})` : ''}
                        </span>
                    ))}
                </p>
            </div>

            <div className="text-right shrink-0 w-28">
                <div className="text-xl font-bold text-secondary">
                    {nota(p.media)}
                    <span className="text-xs font-normal text-on-surface-variant">/10</span>
                </div>
                <div className="text-[10px] text-on-surface-variant leading-tight">
                    {p.comuns} comum · {p.comite} comissão
                    {p.parcial && <span className="block text-error font-semibold">parcial</span>}
                </div>
            </div>

            {onVerAvaliacoes && (
                <button
                    type="button"
                    onClick={() => onVerAvaliacoes(p)}
                    aria-label={`Ver avaliações de ${p.titulo}`}
                    className="shrink-0 inline-flex items-center gap-1 rounded-lg border border-primary-container px-3 py-1.5 text-xs font-semibold text-primary-container hover:bg-primary-fixed"
                >
                    <span className="material-symbols-outlined text-[16px]">fact_check</span>
                    Ver avaliações
                </button>
            )}
        </li>
    );
}

/**
 * Avaliação presencial → **Ranking presencial** (Sprint 222).
 *
 * A média de cada finalista nas avaliações do estande, ponderada: a avaliação
 * da comissão especial pesa mais (o peso é da edição, em Distribuição
 * presencial). Quem calcula é o servidor; a tela só mostra e filtra. Parcial
 * é quem ainda não teve todas as vagas preenchidas.
 *
 * Cada linha tem **Ver avaliações** (Sprint 237): quem avaliou, a nota seção por
 * seção, a exclusão e a transferência para o projeto certo (Sprint 249), com
 * justificativa. É da organização — a conta temporária do voluntário não vê o
 * botão, e o servidor recusa.
 */
export default function PresencialRanking() {
    const { user } = useAuth();
    const navigate = useNavigate();
    // Gerar lista (Sprint 251): o assistente de cotas, que abre o rascunho.
    const [gerandoLista, setGerandoLista] = useState(false);
    const podeVerAvaliacoes = !user?.conta_temporaria;
    const [dados, setDados] = useState(null);
    const [areaId, setAreaId] = useState('');
    const [categoria, setCategoria] = useState('');
    const [modoTeste, setModoTeste] = useState(false);
    const [erro, setErro] = useState('');
    const [aviso, setAviso] = useState('');
    // O diálogo de Ver avaliações: { dados, carregando, erro, aviso }.
    const [avaliacoes, setAvaliacoes] = useState(null);
    const [baixando, setBaixando] = useState('');

    const carregar = useCallback(() => {
        setErro('');
        return getRankingPresencial({ area_id: areaId, categoria }, modoTeste)
            .then(setDados)
            .catch((e) => {
                setDados((d) => d ?? { projetos: [], opcoes: { areas: [], categorias: [] } });
                setErro(extractErrors(e).message || 'Não foi possível carregar o ranking presencial.');
            });
    }, [areaId, categoria, modoTeste]);

    useEffect(() => { carregar(); }, [carregar]);

    function verAvaliacoes(p) {
        setAviso('');
        setAvaliacoes({ dados: null, carregando: true, erro: '' });
        getAvaliacoesRankingPresencial(p.projeto_id, modoTeste)
            .then((d) => setAvaliacoes({ dados: d, carregando: false, erro: '' }))
            .catch((e) => setAvaliacoes({
                dados: null, carregando: false,
                erro: extractErrors(e).message || 'Não foi possível carregar as avaliações.',
            }));
    }

    // A exclusão devolve o projeto sem a avaliação; o ranking é recalculado no servidor.
    function aoExcluir(resposta) {
        setAvaliacoes({ dados: resposta.data, carregando: false, erro: '' });
        setAviso(resposta.meta?.message ?? 'Avaliação excluída.');
        carregar();
    }

    // A transferência (Sprint 249) também devolve o projeto de origem sem ela;
    // o aviso de teto, se houver, fica no próprio diálogo.
    function aoTransferir(resposta) {
        const texto = [resposta.meta?.message ?? 'Avaliação transferida.', resposta.meta?.aviso].filter(Boolean).join(' ');
        setAvaliacoes({ dados: resposta.data, carregando: false, erro: '', aviso: texto });
        setAviso(texto);
        carregar();
    }

    // O arquivo sai no recorte da tela (Sprint 250), como o do ranking online.
    async function baixar(formato) {
        setErro('');
        setBaixando(formato);
        try {
            await exportarRankingPresencial(formato, { area_id: areaId, categoria }, modoTeste);
        } catch {
            setErro('Não foi possível gerar o arquivo.');
        } finally {
            setBaixando('');
        }
    }

    const projetos = dados?.projetos ?? [];
    const parciais = projetos.filter((p) => p.parcial).length;
    const vagas = dados?.vagas;

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <div className="flex items-start justify-between gap-3 flex-wrap max-w-3xl">
                <h1 className="font-display text-2xl font-semibold text-primary mb-1">Ranking presencial</h1>
                {/* As listas da premiação (Sprint 251) são da organização. */}
                {podeVerAvaliacoes && (
                    <div className="flex flex-wrap gap-2 shrink-0 mb-2">
                        <Link
                            to="/admin/presencial/listas"
                            className="inline-flex items-center gap-2 rounded-lg border border-outline-variant px-4 py-2.5 text-sm font-semibold text-on-surface hover:bg-surface-variant transition-colors"
                        >
                            <span className="material-symbols-outlined text-[18px]">fact_check</span>
                            Listas preliminares e finais
                        </Link>
                        <Button type="button" onClick={() => setGerandoLista(true)}>
                            <span className="material-symbols-outlined text-[18px]">list_alt</span>
                            Gerar lista
                        </Button>
                    </div>
                )}
            </div>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Finalistas com ao menos uma avaliação presencial enviada, pela média ponderada das notas do
                estande
                {vagas ? (
                    <>
                        {' '}— cada projeto tem {vagas.comuns} vaga(s) comum(ns) e {vagas.comite} da comissão especial, e a
                        nota da comissão pesa <strong>{peso(vagas.peso_comite)}</strong>
                    </>
                ) : null}
                . Empate na média divide a posição.
            </p>

            {dados?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Conta demo: lê a lista final de demonstração."
                    />
                </div>
            )}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="ranking-presencial-area">
                            Área do conhecimento
                        </label>
                        <select id="ranking-presencial-area" className={campoClass} value={areaId} onChange={(e) => setAreaId(e.target.value)}>
                            <option value="">Todas as áreas</option>
                            {(dados?.opcoes?.areas ?? []).map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="ranking-presencial-categoria">
                            Categoria
                        </label>
                        <select id="ranking-presencial-categoria" className={campoClass} value={categoria} onChange={(e) => setCategoria(e.target.value)}>
                            <option value="">Todas as categorias</option>
                            {(dados?.opcoes?.categorias ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                        </select>
                    </div>
                </div>
            </div>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}
            {aviso && <div className="mb-4 max-w-3xl"><Alert type="info">{aviso}</Alert></div>}

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : !dados.lista && !erro ? (
                <div className="max-w-3xl"><Alert type="info">A lista final ainda não foi gerada: sem finalistas, não há ranking.</Alert></div>
            ) : projetos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhum projeto com avaliação presencial enviada{areaId || categoria ? ' neste recorte' : ''}.
                </div>
            ) : (
                <div className="max-w-3xl">
                    {parciais > 0 && (
                        <div className="mb-4">
                            <Alert type="info">
                                {parciais} {parciais === 1 ? 'projeto ainda não teve' : 'projetos ainda não tiveram'} todas as
                                vagas preenchidas — a média é <strong>parcial</strong> e a posição pode mudar.
                            </Alert>
                        </div>
                    )}
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                        <div className="px-4 py-3 bg-surface-variant/40 flex items-center justify-between gap-2 flex-wrap">
                            <h2 className="font-display font-semibold text-on-surface">Classificação</h2>
                            <div className="flex items-center gap-2 flex-wrap">
                                <span className="text-xs text-on-surface-variant shrink-0">
                                    {projetos.length} {projetos.length === 1 ? 'projeto' : 'projetos'}
                                </span>
                                {/* Baixar é da organização, como o Ver avaliações. */}
                                {podeVerAvaliacoes && (
                                    <>
                                        <span className="text-xs text-on-surface-variant">· Baixar:</span>
                                        {FORMATOS.map((f) => (
                                            <button
                                                key={f.formato}
                                                type="button"
                                                aria-label={`Baixar ${f.rotulo}`}
                                                disabled={baixando !== ''}
                                                onClick={() => baixar(f.formato)}
                                                className="inline-flex items-center gap-1 rounded-lg border border-outline-variant px-2.5 py-1 text-xs font-semibold text-on-surface hover:bg-surface-variant transition-colors disabled:opacity-40"
                                            >
                                                <span className="material-symbols-outlined text-[16px]">
                                                    {baixando === f.formato ? 'hourglass_top' : 'download'}
                                                </span>
                                                {f.rotulo}
                                            </button>
                                        ))}
                                    </>
                                )}
                            </div>
                        </div>
                        <ul className="divide-y divide-outline-variant/30">
                            {projetos.map((p) => (
                                <Linha key={p.projeto_id} p={p} onVerAvaliacoes={podeVerAvaliacoes ? verAvaliacoes : null} />
                            ))}
                        </ul>
                    </div>
                </div>
            )}

            <ListaPresencialDialog
                open={gerandoLista}
                teste={modoTeste}
                onClose={(id) => {
                    setGerandoLista(false);
                    if (id) navigate(`/admin/presencial/listas/${id}`);
                }}
            />

            {avaliacoes && (
                <DialogoAvaliacoesPresenciais
                    dados={avaliacoes.dados}
                    carregando={avaliacoes.carregando}
                    erro={avaliacoes.erro}
                    aviso={avaliacoes.aviso}
                    teste={modoTeste}
                    onExcluida={aoExcluir}
                    onTransferida={aoTransferir}
                    onFechar={() => setAvaliacoes(null)}
                />
            )}
        </AppShell>
    );
}
