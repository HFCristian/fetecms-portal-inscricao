import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import BuscaCombobox from '../components/BuscaCombobox.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getDesignacoes,
    retirarDesignacoes,
    getOpcoesAvaliadores,
    getOpcoesDesignacao,
    designarEmMassa,
    verNotasDaDesignacao,
} from '../lib/admin.js';

/**
 * Avaliação online → Designações: tudo que está na mão de cada avaliador, com
 * há quanto tempo, e a retirada em lote.
 *
 * O que está apenas *designada* sai sem perda; o que está *em avaliação* sai
 * descartando o rascunho do avaliador — por isso a confirmação separa os dois.
 * *Concluída* aparece na tabela (é histórico) mas não pode ser marcada.
 */
const COLUNAS = [
    { key: 'projeto', label: 'Projeto', alinhamento: 'text-left' },
    { key: 'area', label: 'Área', alinhamento: 'text-left' },
    { key: 'avaliador', label: 'Avaliador', alinhamento: 'text-left' },
    { key: 'situacao', label: 'Situação', alinhamento: 'text-left' },
    { key: 'designado_em', label: 'Designado há', alinhamento: 'text-left' },
];

const CORES_SITUACAO = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary-container text-on-secondary-container',
};

/** Nota com vírgula e duas casas, como o resto do portal a mostra. */
const nota = (valor) => (valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ','));

const selectClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

function Cabecalho({ coluna, ordenar, direcao, onOrdenar }) {
    const ativo = ordenar === coluna.key;

    return (
        <th scope="col" className={`px-3 py-2 text-xs font-semibold text-on-surface-variant ${coluna.alinhamento}`}>
            <button
                type="button"
                onClick={() => onOrdenar(coluna.key)}
                aria-label={`Ordenar por ${coluna.label}`}
                className={`inline-flex items-center gap-0.5 hover:text-primary transition-colors ${ativo ? 'text-primary' : ''}`}
            >
                {coluna.label}
                <span className="material-symbols-outlined text-[16px]">
                    {ativo ? (direcao === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more'}
                </span>
            </button>
        </th>
    );
}

function ConfirmarRetirada({ linhas, salvando, erro, onConfirmar, onFechar }) {
    const emAndamento = linhas.filter((l) => l.situacao === 'em_andamento');

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4">
                <h3 className="font-display text-lg font-semibold text-on-surface">
                    Retirar {linhas.length} designação{linhas.length === 1 ? '' : 'ões'}
                </h3>

                {erro && <Alert>{erro}</Alert>}

                <p className="text-sm text-on-surface-variant">
                    Cada projeto volta ao bolo e é designado na hora para outro avaliador, pelas
                    prioridades do edital. Se não houver ninguém elegível, o projeto fica sem
                    designação e a tela avisa.
                </p>

                {emAndamento.length > 0 && (
                    <Alert>
                        {emAndamento.length} {emAndamento.length === 1 ? 'avaliação já foi iniciada' : 'avaliações já foram iniciadas'}
                        {' '}pelo avaliador. Retirar <strong>descarta o rascunho</strong> dela — não há como recuperar.
                    </Alert>
                )}

                <ul className="max-h-56 overflow-y-auto rounded-lg border border-outline-variant/40 divide-y divide-outline-variant/30">
                    {linhas.map((l) => (
                        <li key={l.id} className="px-3 py-2 text-sm">
                            <p className="text-on-surface truncate">{l.projeto}</p>
                            <p className="text-xs text-on-surface-variant truncate">
                                {l.avaliador} · {l.situacao_label} · {l.tempo_label}
                            </p>
                        </li>
                    ))}
                </ul>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button type="button" loading={salvando} onClick={onConfirmar}>Retirar e redesignar</Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Uma das duas colunas do diálogo de designação: busca no servidor + marcação.
 *
 * A lista vem filtrada e limitada de propósito — a base tem centenas de
 * projetos e de avaliadores, e mandar tudo para a tela a cada abertura seria
 * pagar caro por uma lista que ninguém lê inteira.
 */
function Escolha({ titulo, itens, marcados, onAlternar, busca, onBuscar, rotuloBusca, render }) {
    return (
        <div className="flex-1 min-w-0">
            <h4 className="text-sm font-semibold text-on-surface mb-2">
                {titulo} <span className="text-on-surface-variant">({marcados.length} marcado(s))</span>
            </h4>
            <input
                type="text"
                aria-label={rotuloBusca}
                placeholder={`${rotuloBusca}…`}
                value={busca}
                onChange={(e) => onBuscar(e.target.value)}
                className={selectClass}
            />
            <ul className="mt-2 max-h-64 overflow-y-auto rounded-lg border border-outline-variant/40 divide-y divide-outline-variant/30">
                {itens.length === 0 && (
                    <li className="px-3 py-2 text-sm text-on-surface-variant">Nada encontrado.</li>
                )}
                {itens.map((item) => (
                    <li key={item.id}>
                        <label className="flex items-start gap-2 px-3 py-2 cursor-pointer hover:bg-surface-container-low">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={marcados.includes(item.id)}
                                onChange={() => onAlternar(item.id)}
                            />
                            <span className="min-w-0">{render(item)}</span>
                        </label>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * O diálogo de **designação em massa**: um ou mais projetos para um ou mais
 * avaliadores, no cruzamento de tudo com tudo.
 *
 * Quem já avaliou um projeto não o recebe de novo — o par é pulado e o diálogo
 * mostra quais foram, em vez de sumir com eles em silêncio.
 */
function DialogoDesignar({ onFechar, onConcluido }) {
    const [opcoes, setOpcoes] = useState({ projetos: [], avaliadores: [] });
    const [buscaProjeto, setBuscaProjeto] = useState('');
    const [buscaAvaliador, setBuscaAvaliador] = useState('');
    const [projetos, setProjetos] = useState([]);
    const [avaliadores, setAvaliadores] = useState([]);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [resultado, setResultado] = useState(null);

    useEffect(() => {
        const t = setTimeout(() => {
            getOpcoesDesignacao({
                ...(buscaProjeto.trim() ? { projeto: buscaProjeto.trim() } : {}),
                ...(buscaAvaliador.trim() ? { avaliador: buscaAvaliador.trim() } : {}),
            })
                .then(setOpcoes)
                .catch(() => setErro('Não foi possível carregar projetos e avaliadores.'));
        }, 300);

        return () => clearTimeout(t);
    }, [buscaProjeto, buscaAvaliador]);

    const alternar = (lista, set) => (id) =>
        set(lista.includes(id) ? lista.filter((x) => x !== id) : [...lista, id]);

    const total = projetos.length * avaliadores.length;

    async function designar() {
        setSalvando(true);
        setErro('');
        try {
            const resp = await designarEmMassa(projetos, avaliadores);
            setResultado(resp.data);
            onConcluido(resp.meta?.message ?? 'Designações realizadas.');
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível designar.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-3xl p-6 space-y-4">
                <h3 className="font-display text-lg font-semibold text-on-surface">Designar projetos</h3>

                {erro && <Alert>{erro}</Alert>}

                {resultado ? (
                    <>
                        <Alert type="info">{resultado.resumo}</Alert>

                        {resultado.avaliadores.length > 0 && (
                            <ul className="max-h-40 overflow-y-auto rounded-lg border border-outline-variant/40 divide-y divide-outline-variant/30">
                                {resultado.avaliadores.map((a) => (
                                    <li key={a.id} className="px-3 py-2 text-sm">
                                        <p className="text-on-surface">{a.nome}</p>
                                        <p className="text-xs text-on-surface-variant">{a.projetos.join(' · ')}</p>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {resultado.ignoradas.length > 0 ? (
                            <Alert type="warning">
                                <p className="font-semibold mb-1">
                                    {resultado.ignoradas.length} designação(ões) não foram feitas:
                                </p>
                                <ul className="list-disc pl-5 text-sm max-h-32 overflow-y-auto">
                                    {resultado.ignoradas.map((i, n) => (
                                        <li key={n}>{i.projeto} → {i.avaliador}: {i.motivo}</li>
                                    ))}
                                </ul>
                            </Alert>
                        ) : (
                            <p className="text-sm text-on-surface-variant">
                                Todas as designações foram realizadas, sem nenhum problema. Cada
                                avaliador recebeu um e-mail com a lista do que chegou para ele.
                            </p>
                        )}

                        <div className="flex justify-end">
                            <Button type="button" onClick={onFechar}>Fechar</Button>
                        </div>
                    </>
                ) : (
                    <>
                        <p className="text-sm text-on-surface-variant">
                            Cada avaliador marcado recebe <strong>todos</strong> os projetos marcados.
                            Quem já avaliou um projeto não o recebe de novo — o portal avisa quais
                            foram e designa o resto.
                        </p>

                        <div className="flex flex-col md:flex-row gap-4">
                            <Escolha
                                titulo="Projetos"
                                rotuloBusca="Buscar projeto"
                                busca={buscaProjeto}
                                onBuscar={setBuscaProjeto}
                                itens={opcoes.projetos}
                                marcados={projetos}
                                onAlternar={alternar(projetos, setProjetos)}
                                render={(p) => (
                                    <>
                                        <span className="block text-sm text-on-surface truncate">{p.titulo}</span>
                                        <span className="block text-xs text-on-surface-variant truncate">
                                            {[p.categoria, p.area].filter(Boolean).join(' · ')} · {p.concluidas} avaliação(ões)
                                        </span>
                                    </>
                                )}
                            />
                            <Escolha
                                titulo="Avaliadores"
                                rotuloBusca="Buscar avaliador"
                                busca={buscaAvaliador}
                                onBuscar={setBuscaAvaliador}
                                itens={opcoes.avaliadores}
                                marcados={avaliadores}
                                onAlternar={alternar(avaliadores, setAvaliadores)}
                                render={(a) => (
                                    <>
                                        <span className="block text-sm text-on-surface truncate">{a.nome}</span>
                                        <span className="block text-xs text-on-surface-variant truncate">
                                            {[a.area, `${a.na_fila} na fila`].filter(Boolean).join(' · ')}
                                        </span>
                                    </>
                                )}
                            />
                        </div>

                        <div className="flex flex-wrap items-center justify-end gap-3">
                            <span className="text-sm text-on-surface-variant mr-auto">
                                {projetos.length} projeto(s) × {avaliadores.length} avaliador(es) ={' '}
                                <strong>{total} designação(ões)</strong>
                            </span>
                            <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                            <Button type="button" loading={salvando} disabled={total === 0} onClick={designar}>
                                Designar
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

/**
 * O detalhe da nota de uma avaliação concluída: **seção por seção**, com o que
 * o avaliador respondeu em cada pergunta e a soma geral.
 *
 * Mostra o rótulo da escala ("Bom"), e não só o número, porque é assim que a
 * pergunta aparece para quem avaliou — o admin precisa ler a mesma coisa que
 * ele leu. Pergunta não respondida é dita como tal, em vez de virar um zero
 * silencioso.
 *
 * Abrir esta caixa **fica registrado** (Registros → Notas): a nota decide a
 * lista final e o parecer é anônimo para o orientador, então quem o consulta
 * precisa ficar rastreável.
 */
function DialogoNotas({ dados, carregando, erro, onFechar }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-2xl p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <h3 className="font-display text-lg font-semibold text-on-surface">Notas da avaliação</h3>

                {erro && <Alert>{erro}</Alert>}
                {carregando && <p className="text-sm text-on-surface-variant">Carregando…</p>}

                {dados && (
                    <>
                        <div className="text-sm">
                            <p className="font-medium text-on-surface">{dados.projeto.titulo}</p>
                            <p className="text-xs text-on-surface-variant">
                                {[dados.projeto.categoria, dados.projeto.area].filter(Boolean).join(' · ')}
                            </p>
                            <p className="text-xs text-on-surface-variant">
                                Avaliador: {dados.avaliador}
                                {dados.concluida_em_label && ` · concluída em ${dados.concluida_em_label}`}
                            </p>
                        </div>

                        {/* A soma geral em cima: é o número que decide o ranking. */}
                        <div className="bg-primary-fixed rounded-xl p-4 flex items-baseline gap-2">
                            <span className="font-display text-3xl font-semibold text-primary-container">
                                {nota(dados.nota)}
                            </span>
                            <span className="text-sm text-on-surface-variant">
                                de {nota(dados.nota_maxima)} — soma de todas as seções
                            </span>
                        </div>

                        {dados.nota_calculada !== dados.nota && (
                            <Alert type="warning">
                                A nota gravada ({nota(dados.nota)}) difere do recálculo pela rubrica de
                                hoje ({nota(dados.nota_calculada)}). Vale a gravada, que foi a que entrou
                                no ranking.
                            </Alert>
                        )}

                        <ul className="space-y-3">
                            {dados.secoes.map((secao) => (
                                <li key={secao.chave} className="border border-outline-variant/50 rounded-lg p-3">
                                    <div className="flex items-baseline gap-2">
                                        <h4 className="font-semibold text-sm text-on-surface mr-auto">{secao.titulo}</h4>
                                        <span className="text-sm font-semibold text-primary-container whitespace-nowrap">
                                            {nota(secao.pontos)} / {nota(secao.maximo)}
                                        </span>
                                    </div>

                                    <ul className="mt-2 divide-y divide-outline-variant/30">
                                        {secao.perguntas.map((p) => (
                                            <li key={p.chave} className="py-1.5 flex items-start gap-3 text-xs">
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-on-surface">{p.rotulo}</span>
                                                    <span className="block text-on-surface-variant">
                                                        {p.respondida ? p.resposta : 'Não respondida'}
                                                    </span>
                                                </span>
                                                <span className="text-on-surface-variant whitespace-nowrap">
                                                    {nota(p.pontos)} / {nota(p.peso)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </li>
                            ))}
                        </ul>

                        {(dados.recomendacao_video || dados.recomendacao_projeto) && (
                            <div className="text-sm space-y-2">
                                <h4 className="font-semibold text-on-surface">Parecer escrito</h4>
                                {dados.recomendacao_video && (
                                    <p className="text-on-surface-variant">
                                        <strong>Sobre o vídeo:</strong> {dados.recomendacao_video}
                                    </p>
                                )}
                                {dados.recomendacao_projeto && (
                                    <p className="text-on-surface-variant">
                                        <strong>Sobre o projeto:</strong> {dados.recomendacao_projeto}
                                    </p>
                                )}
                            </div>
                        )}
                    </>
                )}

                <div className="flex justify-end">
                    <Button type="button" onClick={onFechar}>Fechar</Button>
                </div>
            </div>
        </div>
    );
}

export default function AvaliacaoDesignacoes() {
    const [busca, setBusca] = useState('');
    const [filtros, setFiltros] = useState({
        q: '', areaId: '', categoria: '', situacao: '', avaliadorId: '',
        ordenar: 'designado_em', direcao: 'asc',
    });
    const [page, setPage] = useState(1);
    const [lista, setLista] = useState(null);
    const [meta, setMeta] = useState(null);
    const [avaliadores, setAvaliadores] = useState([]);
    const [avaliadorSel, setAvaliadorSel] = useState(null);
    const [marcadas, setMarcadas] = useState([]);
    const [confirmando, setConfirmando] = useState(false);
    const [designando, setDesignando] = useState(false);
    const [notas, setNotas] = useState(null);        // { carregando, dados, erro }
    const [salvando, setSalvando] = useState(false);
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');

    useEffect(() => {
        const t = setTimeout(() => {
            setFiltros((f) => (f.q === busca.trim() ? f : { ...f, q: busca.trim() }));
            setPage(1);
        }, 300);
        return () => clearTimeout(t);
    }, [busca]);

    const carregar = useCallback(() => {
        setAlert('');
        return getDesignacoes({ ...filtros, page })
            .then((resp) => { setLista(resp.data); setMeta(resp.meta); })
            .catch((e) => { setLista([]); setAlert(extractErrors(e).message); });
    }, [filtros, page]);

    useEffect(() => { carregar(); }, [carregar]);

    useEffect(() => {
        getOpcoesAvaliadores()
            .then((opcoes) => setAvaliadores(opcoes.map((a) => ({ id: a.id, nome: a.nome, detalhe: a.area }))))
            .catch(() => setAvaliadores([]));
    }, []);

    function ordenarPor(coluna) {
        setFiltros((f) => ({
            ...f,
            ordenar: coluna,
            direcao: f.ordenar === coluna && f.direcao === 'asc' ? 'desc' : 'asc',
        }));
        setPage(1);
    }

    function trocarFiltro(campo, valor) {
        setFiltros((f) => ({ ...f, [campo]: valor }));
        setPage(1);
    }

    const linhas = lista ?? [];
    const retiraveis = useMemo(() => linhas.filter((l) => l.pode_retirar), [linhas]);
    const selecionadas = useMemo(
        () => linhas.filter((l) => marcadas.includes(l.id)),
        [linhas, marcadas],
    );
    const todasMarcadas = retiraveis.length > 0 && retiraveis.every((l) => marcadas.includes(l.id));

    function alternar(id) {
        setMarcadas((m) => (m.includes(id) ? m.filter((x) => x !== id) : [...m, id]));
    }

    function alternarTodas() {
        const ids = retiraveis.map((l) => l.id);
        setMarcadas((m) => (todasMarcadas ? m.filter((x) => !ids.includes(x)) : [...new Set([...m, ...ids])]));
    }

    /**
     * Abre a nota de uma avaliação concluída. A chamada é o que grava o registro
     * da consulta, então ela só acontece no clique — nunca ao montar a tabela.
     */
    async function verNotas(linha) {
        setNotas({ carregando: true, dados: null, erro: '' });
        try {
            setNotas({ carregando: false, dados: await verNotasDaDesignacao(linha.id), erro: '' });
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setNotas({
                carregando: false,
                dados: null,
                erro: Object.values(fields ?? {})[0] || message || 'Não foi possível abrir as notas.',
            });
        }
    }

    async function retirar() {
        setSalvando(true); setAlert(''); setSuccess('');
        try {
            const resp = await retirarDesignacoes(selecionadas.map((l) => l.id));
            setSuccess(resp.meta?.message || 'Designações retiradas.');
            setMarcadas([]);
            setConfirmando(false);
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        } finally {
            setSalvando(false);
        }
    }

    const resumo = meta?.resumo ?? null;
    const areasFiltro = meta?.areas ?? [];
    const categorias = meta?.categorias ?? [];
    const situacoes = meta?.situacoes ?? [];

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <div className="flex flex-wrap items-center gap-3 mb-1">
                <h1 className="font-display text-2xl font-semibold text-primary mr-auto">Designações</h1>
                <Button type="button" onClick={() => setDesignando(true)}>Designar</Button>
            </div>
            <p className="text-on-surface-variant mb-4 max-w-4xl">
                Todos os projetos designados, com <strong>há quanto tempo</strong> cada um está com o
                avaliador. Marque as designações que quer tirar de alguém: elas voltam ao bolo e são
                redesignadas na hora para outro avaliador. Avaliação concluída fica no histórico e
                não sai.
            </p>

            {resumo && (
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 max-w-4xl">
                    {[
                        { label: 'Designações', valor: resumo.total, cor: 'text-primary' },
                        { label: 'Não abertas', valor: resumo.designada, cor: 'text-on-surface' },
                        { label: 'Em avaliação', valor: resumo.em_andamento, cor: 'text-primary-container' },
                        { label: 'Concluídas', valor: resumo.concluida, cor: 'text-secondary' },
                    ].map((card) => (
                        <div key={card.label} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                            <p className={`font-display text-2xl font-semibold ${card.cor}`}>{card.valor}</p>
                            <p className="text-xs text-on-surface-variant">{card.label}</p>
                        </div>
                    ))}
                </div>
            )}

            {alert && <div className="mb-4 max-w-4xl"><Alert>{alert}</Alert></div>}
            {success && <div className="mb-4 max-w-4xl"><Alert type="info">{success}</Alert></div>}

            <div className="max-w-4xl">
                <div className="flex flex-col md:flex-row gap-2 mb-3">
                    <div className="relative flex-1">
                        <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">search</span>
                        <input
                            type="text"
                            aria-label="Buscar por projeto ou avaliador"
                            placeholder="Buscar por projeto ou avaliador…"
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            className={`${selectClass} pl-10`}
                        />
                    </div>
                    <select
                        aria-label="Filtrar por área"
                        className={`${selectClass} md:w-56`}
                        value={filtros.areaId}
                        onChange={(e) => trocarFiltro('areaId', e.target.value)}
                    >
                        <option value="">Todas as áreas</option>
                        {areasFiltro.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                    </select>
                    <select
                        aria-label="Filtrar por categoria"
                        className={`${selectClass} md:w-48`}
                        value={filtros.categoria}
                        onChange={(e) => trocarFiltro('categoria', e.target.value)}
                    >
                        <option value="">Todas as categorias</option>
                        {categorias.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                    </select>
                    <select
                        aria-label="Filtrar por situação"
                        className={`${selectClass} md:w-44`}
                        value={filtros.situacao}
                        onChange={(e) => trocarFiltro('situacao', e.target.value)}
                    >
                        <option value="">Todas as situações</option>
                        {situacoes.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                </div>

                <div className="mb-3 md:w-72">
                    <BuscaCombobox
                        options={avaliadores}
                        value={avaliadorSel}
                        onChange={(a) => { setAvaliadorSel(a); trocarFiltro('avaliadorId', a?.id ?? ''); }}
                        placeholder="Filtrar por avaliador…"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-2 mb-3">
                    <Button
                        type="button"
                        disabled={selecionadas.length === 0}
                        onClick={() => setConfirmando(true)}
                    >
                        <span className="material-symbols-outlined text-[20px]">swap_horiz</span>
                        Retirar {selecionadas.length > 0 ? `(${selecionadas.length})` : 'selecionadas'}
                    </Button>
                    {selecionadas.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setMarcadas([])}
                            className="text-sm text-on-surface-variant hover:text-primary"
                        >
                            Limpar seleção
                        </button>
                    )}
                </div>

                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-surface-variant/40">
                                <tr>
                                    <th scope="col" className="px-3 py-2 w-10">
                                        <input
                                            type="checkbox"
                                            aria-label="Marcar todas as designações retiráveis desta página"
                                            checked={todasMarcadas}
                                            disabled={retiraveis.length === 0}
                                            onChange={alternarTodas}
                                            className="accent-primary-container"
                                        />
                                    </th>
                                    {COLUNAS.map((coluna) => (
                                        <Cabecalho
                                            key={coluna.key}
                                            coluna={coluna}
                                            ordenar={filtros.ordenar}
                                            direcao={filtros.direcao}
                                            onOrdenar={ordenarPor}
                                        />
                                    ))}
                                    <th scope="col" className="px-3 py-2 text-xs font-semibold text-on-surface-variant text-right">
                                        Notas
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-outline-variant/30">
                                {lista === null ? (
                                    <tr><td colSpan={COLUNAS.length + 2} className="px-3 py-8 text-center text-on-surface-variant">Carregando…</td></tr>
                                ) : linhas.length === 0 ? (
                                    <tr><td colSpan={COLUNAS.length + 2} className="px-3 py-8 text-center text-on-surface-variant">Nenhuma designação neste recorte.</td></tr>
                                ) : linhas.map((l) => (
                                    <tr key={l.id} className="hover:bg-surface-variant/20">
                                        <td className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label={`Retirar ${l.projeto} de ${l.avaliador}`}
                                                checked={marcadas.includes(l.id)}
                                                disabled={!l.pode_retirar}
                                                onChange={() => alternar(l.id)}
                                                className="accent-primary-container disabled:opacity-30"
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <p className="text-on-surface truncate max-w-[16rem]" title={l.projeto}>{l.projeto}</p>
                                            {l.categoria_label && <p className="text-xs text-on-surface-variant">{l.categoria_label}</p>}
                                        </td>
                                        <td className="px-3 py-2 text-on-surface-variant">{l.area ?? 'Sem área'}</td>
                                        <td className="px-3 py-2">
                                            <p className="text-on-surface truncate max-w-[12rem]" title={l.avaliador}>{l.avaliador}</p>
                                            {l.designacao_manual && <p className="text-xs text-primary-container">designação manual</p>}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${CORES_SITUACAO[l.situacao] ?? ''}`}>
                                                {l.situacao_label}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-on-surface-variant whitespace-nowrap">
                                            {l.tempo_label}
                                            <span className="block text-xs">{l.designado_em_label}</span>
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {/* Só avaliação concluída tem nota: no resto o botão
                                                fica desabilitado dizendo o porquê, em vez de sumir
                                                e deixar a coluna irregular. */}
                                            <button
                                                type="button"
                                                disabled={l.situacao !== 'concluida'}
                                                onClick={() => verNotas(l)}
                                                title={l.situacao === 'concluida'
                                                    ? 'Ver a nota seção por seção'
                                                    : 'A avaliação ainda não foi enviada'}
                                                aria-label={`Ver notas de ${l.projeto} por ${l.avaliador}`}
                                                className="inline-flex items-center gap-1 text-sm text-primary hover:underline disabled:text-on-surface-variant disabled:no-underline disabled:opacity-50"
                                            >
                                                <span className="material-symbols-outlined text-[18px]">scoreboard</span>
                                                Ver notas
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {meta && meta.ultima_pagina > 1 && (
                    <div className="flex items-center justify-between gap-2 mt-3">
                        <span className="text-xs text-on-surface-variant">Página {meta.pagina_atual} de {meta.ultima_pagina}</span>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" disabled={meta.pagina_atual <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Anterior</Button>
                            <Button type="button" variant="outline" disabled={meta.pagina_atual >= meta.ultima_pagina} onClick={() => setPage((p) => p + 1)}>Próxima</Button>
                        </div>
                    </div>
                )}
            </div>

            {designando && (
                <DialogoDesignar
                    onFechar={() => { setDesignando(false); carregar(); }}
                    onConcluido={(mensagem) => { setSuccess(mensagem); setAlert(''); }}
                />
            )}

            {notas && (
                <DialogoNotas
                    dados={notas.dados}
                    carregando={notas.carregando}
                    erro={notas.erro}
                    onFechar={() => setNotas(null)}
                />
            )}

            {confirmando && (
                <ConfirmarRetirada
                    linhas={selecionadas}
                    salvando={salvando}
                    erro={alert}
                    onConfirmar={retirar}
                    onFechar={() => setConfirmando(false)}
                />
            )}
        </AppShell>
    );
}
