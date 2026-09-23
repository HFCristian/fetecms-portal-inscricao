import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    definirAtualizacao, getDetalheCard, getVisaoGeral,
} from '../lib/cerimonial.js';
import { useModoTeste } from '../lib/modoTeste.js';

/** As opções do seletor de atualização automática. `null` desliga. */
const INTERVALOS = [
    { valor: null, label: 'Desligada' },
    { valor: 10, label: '10 segundos' },
    { valor: 30, label: '30 segundos' },
    { valor: 60, label: '1 minuto' },
    { valor: 300, label: '5 minutos' },
];

/**
 * Um card do painel.
 *
 * Todo card abre a lista nominal — é a pergunta que a organização faz o tempo
 * todo no dia ("quem falta?"), e um número sozinho não a responde. Alguns
 * levam a uma tela própria (os premiados), e então o clique navega em vez de
 * abrir a lista.
 */
function Card({ icon, titulo, numero, de, rodape, detalhe, onAbrir, acao }) {
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 flex flex-col">
            <div className="flex items-start gap-3">
                <span className="w-10 h-10 rounded-xl bg-primary-fixed text-primary-container flex items-center justify-center shrink-0">
                    <span className="material-symbols-outlined text-[22px]">{icon}</span>
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="font-display font-semibold text-on-surface">{titulo}</h2>
                    <p className="text-3xl font-bold text-primary-container leading-tight">
                        {numero}
                        {de !== undefined && (
                            <span className="text-base font-normal text-on-surface-variant"> de {de}</span>
                        )}
                    </p>
                    {rodape && <p className="text-xs text-on-surface-variant mt-1">{rodape}</p>}
                    {detalhe && <p className="text-xs text-on-surface-variant mt-1">{detalhe}</p>}
                </div>
            </div>
            <div className="mt-3 pt-3 border-t border-outline-variant/30 flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={onAbrir}
                    className="text-sm font-semibold text-primary hover:underline inline-flex items-center gap-1"
                >
                    <span className="material-symbols-outlined text-[18px]">list</span>
                    Ver quem chegou e quem falta
                </button>
                {acao}
            </div>
        </div>
    );
}

/** A lista nominal de um card: duas abas, quem chegou e quem falta. */
function DialogoDetalhe({ card, titulo, teste, onFechar }) {
    const [aba, setAba] = useState('faltantes');
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [busca, setBusca] = useState('');

    useEffect(() => {
        setDados(null);
        getDetalheCard(card, teste)
            .then(setDados)
            .catch((e) => {
                const { message } = extractErrors(e);
                setErro(message || 'Não foi possível carregar a lista.');
                setDados({ presentes: [], faltantes: [] });
            });
    }, [card, teste]);

    const porPessoa = card === 'pessoas' || card === 'medalhas';
    const lista = (dados?.[aba] ?? []).filter((item) => {
        const alvo = porPessoa
            ? `${item.nome} ${item.projeto_titulo ?? ''}`
            : `${item.titulo} ${item.escola ?? ''}`;
        return alvo.toLowerCase().includes(busca.trim().toLowerCase());
    });

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-2xl max-h-[85vh] flex flex-col">
                <div className="p-5 pb-3 border-b border-outline-variant/30">
                    <div className="flex items-start justify-between gap-3">
                        <h3 className="font-display text-lg font-semibold text-on-surface">{titulo}</h3>
                        <button type="button" onClick={onFechar} aria-label="Fechar" className="text-on-surface-variant p-1">
                            <span className="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <div className="flex gap-2 mt-3">
                        {['faltantes', 'presentes'].map((chave) => (
                            <button
                                key={chave}
                                type="button"
                                onClick={() => setAba(chave)}
                                className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
                                    aba === chave
                                        ? 'bg-primary-container text-on-primary'
                                        : 'text-on-surface-variant hover:bg-surface-variant'
                                }`}
                            >
                                {chave === 'faltantes' ? 'Faltam' : 'Já chegaram'}
                                {dados && ` (${dados[chave].length})`}
                            </button>
                        ))}
                    </div>
                    <input
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        placeholder="Filtrar nesta lista…"
                        aria-label="Filtrar nesta lista"
                        className="w-full mt-3 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                    />
                </div>

                <div className="p-5 pt-3 overflow-y-auto fetec-scroll">
                    {erro && <Alert>{erro}</Alert>}
                    {dados === null && (
                        <p className="text-sm text-on-surface-variant text-center py-6">Carregando…</p>
                    )}
                    {dados !== null && lista.length === 0 && (
                        <p className="text-sm text-on-surface-variant text-center py-6">
                            {busca.trim() ? 'Nada encontrado com esse filtro.' : 'Nenhum nome nesta lista.'}
                        </p>
                    )}
                    <ul className="divide-y divide-outline-variant/30">
                        {lista.map((item) => (
                            <li key={porPessoa ? `${item.projeto_id}-${item.nome}-${item.papel}` : item.id} className="py-2">
                                {porPessoa ? (
                                    <>
                                        <p className="text-sm text-on-surface">
                                            {item.nome}{' '}
                                            <span className="text-on-surface-variant">· {item.papel_label}</span>
                                        </p>
                                        <p className="text-xs text-on-surface-variant truncate">
                                            {item.projeto_titulo}
                                            {item.checkin_em ? ` · chegou em ${item.checkin_em}` : ''}
                                        </p>
                                    </>
                                ) : (
                                    <>
                                        <p className="text-sm text-on-surface">{item.titulo}</p>
                                        <p className="text-xs text-on-surface-variant truncate">
                                            {[item.categoria, item.escola].filter(Boolean).join(' · ')}
                                        </p>
                                        <p className="text-xs text-on-surface-variant">
                                            {item.presentes} de {item.total} presente(s)
                                            {item.completo ? ' · equipe completa' : ''}
                                            {item.credenciais ? ` · ${item.credenciais} credencial(is)` : ''}
                                            {item.premiacoes?.length ? ` · ${item.premiacoes.join(', ')}` : ''}
                                        </p>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}

/**
 * Cerimonial → **Visão Geral**.
 *
 * O painel do dia: quantas pessoas entraram, quantos projetos estão na sala
 * (parcial e completo são números diferentes — o palco chama a equipe inteira,
 * a porta conta quem entrou), quantos premiados chegaram e quanto material a
 * mesa precisa separar.
 *
 * **Medalha segue a pessoa** e **credencial segue o projeto**: cada premiado
 * presente rende uma medalha, e cada projeto premiado com alguém na sala tira
 * da mesa as credenciais dele — só as credenciais, porque prêmio se anuncia e
 * não se entrega em mãos.
 *
 * A tela se atualiza sozinha (polling; não há WebSocket no projeto) num
 * intervalo **da edição**, que o admin escolhe aqui mesmo: ela costuma ficar
 * aberta num tablet enquanto vários balcões registram em paralelo.
 *
 * A conta temporária não chega aqui: o servidor recusa, e o card nem aparece na
 * Home.
 */
export default function CerimonialVisaoGeral() {
    const [teste] = useModoTeste();
    const navigate = useNavigate();
    const [dados, setDados] = useState(null);
    const [config, setConfig] = useState(null);
    const [erro, setErro] = useState('');
    const [detalhe, setDetalhe] = useState(null);
    const [intervalo, setIntervalo] = useState(null);
    const [salvandoIntervalo, setSalvandoIntervalo] = useState(false);
    const primeira = useRef(true);

    const carregar = useCallback(() => getVisaoGeral(teste)
        .then((r) => {
            setDados(r.data);
            setConfig(r.meta?.config ?? null);
            // A edição manda no intervalo; a tela só o reflete até alguém mudar.
            if (primeira.current) {
                setIntervalo(r.meta?.config?.atualizacao_segundos ?? null);
                primeira.current = false;
            }
            setErro('');
        })
        .catch((e) => {
            const { message } = extractErrors(e);
            setErro(message || 'Não foi possível carregar o painel.');
        }), [teste]);

    useEffect(() => { carregar(); }, [carregar]);

    useEffect(() => {
        if (!intervalo) return undefined;
        const id = setInterval(carregar, intervalo * 1000);
        return () => clearInterval(id);
    }, [intervalo, carregar]);

    async function trocarIntervalo(valor) {
        setIntervalo(valor);
        setSalvandoIntervalo(true);
        try {
            await definirAtualizacao(valor);
        } catch {
            // Falhar aqui não pode derrubar o painel: o intervalo vale nesta
            // sessão de qualquer jeito, só não fica guardado na edição.
        } finally {
            setSalvandoIntervalo(false);
        }
    }

    const abrir = (card, titulo) => setDetalhe({ card, titulo });

    return (
        <AppShell>
            <Link to="/admin/cerimonial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Cerimonial
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-3 mb-1">
                <h1 className="font-display text-2xl font-semibold text-primary">Visão Geral</h1>
                <div className="flex flex-wrap items-center gap-2">
                    <label className="text-xs text-on-surface-variant" htmlFor="cerimonial-intervalo">
                        Atualização automática
                    </label>
                    <select
                        id="cerimonial-intervalo"
                        value={intervalo ?? ''}
                        disabled={salvandoIntervalo}
                        onChange={(e) => trocarIntervalo(e.target.value === '' ? null : Number(e.target.value))}
                        className="bg-surface border border-outline-variant rounded-lg px-2 py-1.5 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                    >
                        {INTERVALOS.map((i) => (
                            <option key={String(i.valor)} value={i.valor ?? ''}>{i.label}</option>
                        ))}
                    </select>
                    <Button type="button" variant="outline" onClick={carregar}>
                        <span className="material-symbols-outlined text-[20px]">refresh</span>
                        Atualizar
                    </Button>
                </div>
            </div>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Quem já entrou na cerimônia e o que a mesa precisa separar. Todo card abre a lista
                nominal de quem chegou e de quem falta.
                {dados?.atualizado_em && (
                    <span className="block text-xs mt-1">Atualizado às {dados.atualizado_em}.</span>
                )}
            </p>

            {config?.lista?.demo && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        <strong>Modo de teste</strong>: os números abaixo são da lista de
                        demonstração.
                    </Alert>
                </div>
            )}

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            {config && !config.lista && (
                <Alert>
                    Nenhuma lista final {config.modo_teste ? 'de demonstração' : 'oficial'} está
                    vigente nesta edição — sem ela não há cerimônia a acompanhar.
                </Alert>
            )}

            {dados && (
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <Card
                        icon="groups"
                        titulo="Pessoas com check-in"
                        numero={dados.pessoas.presentes}
                        de={dados.pessoas.total}
                        rodape={`Faltam ${dados.pessoas.faltam}.`}
                        detalhe={`${dados.pessoas.por_papel.alunos} aluno(s) · ${dados.pessoas.por_papel.orientadores} orientador(es) · ${dados.pessoas.por_papel.coorientadores} coorientador(es)`}
                        onAbrir={() => abrir('pessoas', 'Pessoas com check-in')}
                    />
                    <Card
                        icon="science"
                        titulo="Projetos na sala"
                        numero={dados.projetos.presentes}
                        de={dados.projetos.total}
                        rodape={`Faltam ${dados.projetos.faltam}.`}
                        detalhe={`${dados.projetos.completos} com a equipe completa · ${dados.projetos.parciais} parcialmente presente(s)`}
                        onAbrir={() => abrir('projetos', 'Projetos na sala')}
                    />
                    <Card
                        icon="workspace_premium"
                        titulo="Premiados"
                        numero={dados.premiados.presentes}
                        de={dados.premiados.total}
                        rodape={`Faltam ${dados.premiados.faltam}.`}
                        detalhe={`${dados.premiados.completos} com a equipe completa`}
                        onAbrir={() => abrir('premiados', 'Projetos premiados')}
                        acao={(
                            <button
                                type="button"
                                onClick={() => navigate('/admin/cerimonial/premiados')}
                                className="text-sm font-semibold text-primary hover:underline inline-flex items-center gap-1"
                            >
                                <span className="material-symbols-outlined text-[18px]">grid_view</span>
                                Ver os cartões
                            </button>
                        )}
                    />
                    <Card
                        icon="military_tech"
                        titulo="Medalhas a separar"
                        numero={dados.medalhas.separar}
                        de={dados.medalhas.total}
                        rodape="Uma por participante premiado que já chegou."
                        detalhe={`${dados.medalhas.por_papel.alunos} aluno(s) · ${dados.medalhas.por_papel.orientadores} orientador(es) · ${dados.medalhas.por_papel.coorientadores} coorientador(es)`}
                        onAbrir={() => abrir('medalhas', 'Medalhas — participantes premiados')}
                    />
                    <Card
                        icon="badge"
                        titulo="Credenciais a separar"
                        numero={dados.credenciais.separar}
                        de={dados.credenciais.total}
                        rodape="As credenciais dos projetos premiados que já têm alguém na sala."
                        detalhe="Prêmios não entram: eles se anunciam, não se entregam em mãos."
                        onAbrir={() => abrir('credenciais', 'Credenciais por projeto')}
                    />
                </div>
            )}

            {detalhe && (
                <DialogoDetalhe
                    card={detalhe.card}
                    titulo={detalhe.titulo}
                    teste={teste}
                    onFechar={() => setDetalhe(null)}
                />
            )}
        </AppShell>
    );
}
