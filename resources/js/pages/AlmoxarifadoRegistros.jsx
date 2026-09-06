import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input, Select } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getAlmoxarifadoRegistros,
    retirarDoAlmoxarifado,
    editarRegistroAlmoxarifado,
    excluirRegistroAlmoxarifado,
} from '../lib/almoxarifado.js';
import { useModoTeste } from '../lib/modoTeste.js';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

/** Diálogo das quatro ações da linha: mesma moldura, conteúdo diferente. */
function Dialogo({ titulo, descricao, children, acao, rotulo, ocupado, desabilitado, onFechar }) {
    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <h3 className="font-display text-lg font-semibold text-on-surface">{titulo}</h3>
                {descricao && <p className="text-sm text-on-surface-variant">{descricao}</p>}
                {children}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onFechar}>Voltar</Button>
                    <Button type="button" loading={ocupado} disabled={desabilitado} onClick={acao}>{rotulo}</Button>
                </div>
            </div>
        </div>
    );
}

/** Escolha de quem está retirando: sempre alguém do projeto. */
function EscolhaResponsavel({ pessoas, valor, onChange }) {
    return (
        <label className="block">
            <span className="text-sm font-semibold text-on-surface">Quem está retirando</span>
            <Select value={valor} aria-label="Quem está retirando" onChange={(e) => onChange(e.target.value)}>
                <option value="">Escolha uma pessoa do projeto</option>
                {(pessoas ?? []).map((p) => (
                    <option key={`${p.tipo}:${p.id}`} value={`${p.tipo}:${p.id}`}>
                        {p.nome} · {p.tipo_label}
                    </option>
                ))}
            </Select>
        </label>
    );
}

const SITUACAO = {
    guardado: { label: 'Guardado', classe: 'bg-primary-fixed text-primary-container' },
    parcial: { label: 'Retirada parcial', classe: 'bg-surface-variant text-on-surface-variant' },
    retirado: { label: 'Retirado', classe: 'bg-secondary-container text-on-secondary-container' },
};

/**
 * A tabela do almoxarifado: um registro por atendimento.
 *
 * Cada linha diz de qual projeto é o material, quem o deixou, quantos volumes
 * entraram, quando, e — quando já saiu — quem levou e a que horas. Enquanto a
 * retirada é parcial, a linha mostra quantos ainda estão no balcão: é a
 * pergunta que a equipe faz ao voltar.
 */
export default function AlmoxarifadoRegistros() {
    const navigate = useNavigate();
    const [teste] = useModoTeste();
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [filtros, setFiltros] = useState({ busca: '', situacao: '' });
    const [pagina, setPagina] = useState(1);

    // A ação em curso: { tipo, registro } + os campos que cada uma pede.
    const [acao, setAcao] = useState(null);
    const [responsavel, setResponsavel] = useState('');
    const [escolhidos, setEscolhidos] = useState([]);
    const [rascunho, setRascunho] = useState([]);
    const [justificativa, setJustificativa] = useState('');
    const [ocupado, setOcupado] = useState(false);

    const carregar = useCallback(() => {
        const params = { page: pagina };
        if (filtros.busca.trim()) params.busca = filtros.busca.trim();
        if (filtros.situacao) params.situacao = filtros.situacao;

        getAlmoxarifadoRegistros(params, teste)
            .then((r) => { setDados(r); setErro(''); })
            .catch(() => setErro('Não foi possível carregar os registros.'));
    }, [filtros, pagina, teste]);

    useEffect(() => { carregar(); }, [carregar]);

    function alterarFiltro(campo, valor) {
        setPagina(1);
        setFiltros((f) => ({ ...f, [campo]: valor }));
    }

    /**
     * Abre uma das quatro ações da linha, já com os campos preenchidos com o
     * que o registro tem hoje — a correção parte do estado atual, não do zero.
     */
    function abrir(tipo, registro) {
        setAcao({ tipo, registro });
        setResponsavel('');
        setEscolhidos([]);
        setJustificativa('');
        setRascunho(registro.itens.map((i) => ({ ...i })));
    }

    function fechar() {
        setAcao(null);
        setOcupado(false);
    }

    const pessoas = acao?.registro?.pessoas ?? [];
    const pendentes = (acao?.registro?.itens ?? []).filter((i) => !i.retirado);

    /** "tipo:id" → o par que a API espera. */
    function responsavelEmPartes() {
        const [tipo, id] = responsavel.split(':');
        return { responsavel_tipo: tipo, responsavel_id: id === 'null' ? null : Number(id) };
    }

    async function executar(chamada) {
        setOcupado(true);
        setErro('');
        try {
            await chamada();
            fechar();
            carregar();
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível concluir a ação.');
            setOcupado(false);
        }
    }

    const retirar = (itens) => executar(() => retirarDoAlmoxarifado(
        acao.registro.id,
        { ...responsavelEmPartes(), ...(itens ? { itens } : {}) },
        teste,
    ));

    const salvarEdicao = () => executar(() => editarRegistroAlmoxarifado(acao.registro.id, {
        ...responsavelEmPartes(),
        itens: rascunho
            .filter((i) => i.descricao.trim() !== '')
            .map((i) => ({ id: i.id ?? null, descricao: i.descricao.trim() })),
        justificativa: justificativa.trim(),
    }, teste));

    const excluir = () => executar(
        () => excluirRegistroAlmoxarifado(acao.registro.id, justificativa.trim(), teste),
    );

    const itens = dados?.data ?? [];
    const meta = dados?.meta;
    const config = meta?.config;
    const resumo = meta?.resumo;

    return (
        <AppShell>
            <Link to="/admin/almoxarifado" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Almoxarifado
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-primary mb-1">Registros do almoxarifado</h1>
                    <p className="text-sm text-on-surface-variant max-w-3xl">
                        Tudo o que passou pelo balcão: o que entrou, o que ainda está guardado e quem
                        levou de volta.
                    </p>
                </div>
                <Button type="button" disabled={config && !config.aberto} onClick={() => navigate('/admin/almoxarifado/novo')}>
                    <span className="material-symbols-outlined text-[20px]">add</span>
                    Guardar material
                </Button>
            </div>

            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

            {config?.lista?.demo && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        <strong>Modo demo</strong> — registros de ensaio, separados dos de verdade.
                    </Alert>
                </div>
            )}

            {config && !config.aberto && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        O almoxarifado está fechado agora — dá para consultar os registros, mas não
                        para guardar material novo.
                    </Alert>
                </div>
            )}

            {resumo && (
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5 max-w-3xl">
                    {[
                        { valor: resumo.registros, rotulo: 'registros' },
                        { valor: resumo.itens_guardados, rotulo: 'itens no balcão' },
                        { valor: resumo.retirados, rotulo: 'já devolvidos' },
                    ].map((c) => (
                        <div key={c.rotulo} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                            <p className="text-2xl font-display font-semibold text-primary-container leading-none">{c.valor}</p>
                            <p className="text-xs text-on-surface-variant font-semibold mt-1">{c.rotulo}</p>
                        </div>
                    ))}
                </div>
            )}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Input
                    placeholder="Buscar por projeto ou quem deixou"
                    aria-label="Buscar registro"
                    value={filtros.busca}
                    onChange={(e) => alterarFiltro('busca', e.target.value)}
                />
                <Select
                    value={filtros.situacao}
                    aria-label="Situação"
                    onChange={(e) => alterarFiltro('situacao', e.target.value)}
                >
                    <option value="">Todas as situações</option>
                    <option value="guardados">Com material no balcão</option>
                    <option value="retirados">Tudo retirado</option>
                </Select>
            </div>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : itens.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
                    Nenhum registro com esses filtros.
                </div>
            ) : (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-x-auto">
                    <table className="w-full text-sm min-w-[52rem]">
                        <thead className="bg-surface-variant/60 text-on-surface-variant">
                            <tr>
                                <th className="text-left font-semibold px-3 py-2">Projeto</th>
                                <th className="text-left font-semibold px-3 py-2">Quem deixou</th>
                                <th className="text-right font-semibold px-3 py-2">Itens</th>
                                <th className="text-left font-semibold px-3 py-2">Registro</th>
                                <th className="text-left font-semibold px-3 py-2">Retirada</th>
                                <th className="text-right font-semibold px-3 py-2">Ações</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-outline-variant/40">
                            {itens.map((r) => (
                                <tr key={r.id}>
                                    <td className="px-3 py-2">
                                        <span className="block text-on-surface font-semibold">{r.projeto}</span>
                                        <span className="block text-xs text-on-surface-variant">{r.escola || '—'}</span>
                                    </td>
                                    <td className="px-3 py-2 text-on-surface-variant">{r.responsavel}</td>
                                    <td className="px-3 py-2 text-right text-on-surface-variant">
                                        {r.itens_total}
                                        {r.itens_pendentes > 0 && r.itens_retirados > 0 && (
                                            <span className="block text-xs text-error font-semibold">
                                                {r.itens_pendentes} no balcão
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-on-surface-variant">
                                        {dataHora(r.registrado_em)}
                                        <span className="block text-xs">{r.registrado_por || '—'}</span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className={`inline-block text-xs font-semibold px-2 py-0.5 rounded-full ${SITUACAO[r.situacao]?.classe ?? ''}`}>
                                            {SITUACAO[r.situacao]?.label ?? r.situacao}
                                        </span>
                                        {r.retirado_por && (
                                            <span className="block text-xs text-on-surface-variant mt-0.5">
                                                {r.retirado_por} · {dataHora(r.retirado_em)}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex flex-wrap justify-end gap-1">
                                            {[
                                                { tipo: 'completa', rotulo: 'Retirada completa', icone: 'done_all', some: r.itens_pendentes === 0 },
                                                { tipo: 'parcial', rotulo: 'Retirada parcial', icone: 'outbox', some: r.itens_pendentes === 0 },
                                                { tipo: 'editar', rotulo: 'Editar', icone: 'edit' },
                                                { tipo: 'excluir', rotulo: 'Excluir', icone: 'delete' },
                                            ].filter((b) => !b.some).map((b) => (
                                                <button
                                                    key={b.tipo}
                                                    type="button"
                                                    title={b.rotulo}
                                                    aria-label={`${b.rotulo} — ${r.projeto}`}
                                                    disabled={config && !config.aberto}
                                                    onClick={() => abrir(b.tipo, r)}
                                                    className="w-9 h-9 rounded-lg border border-outline-variant text-on-surface-variant hover:bg-surface-variant disabled:opacity-40 transition-colors"
                                                >
                                                    <span className="material-symbols-outlined text-[18px] align-middle">{b.icone}</span>
                                                </button>
                                            ))}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {meta && meta.ultima_pagina > 1 && (
                        <div className="flex items-center justify-between gap-3 p-3 border-t border-outline-variant">
                            <span className="text-xs text-on-surface-variant">
                                Página {meta.pagina_atual} de {meta.ultima_pagina}
                            </span>
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" disabled={meta.pagina_atual <= 1} onClick={() => setPagina((p) => Math.max(1, p - 1))}>Anterior</Button>
                                <Button type="button" variant="outline" disabled={meta.pagina_atual >= meta.ultima_pagina} onClick={() => setPagina((p) => p + 1)}>Próxima</Button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {acao?.tipo === 'completa' && (
                <Dialogo
                    titulo="Retirada completa"
                    descricao={`Sai tudo o que ainda está guardado de “${acao.registro.projeto}” (${acao.registro.itens_pendentes} ${acao.registro.itens_pendentes === 1 ? 'item' : 'itens'}). A data e a hora são as de agora.`}
                    rotulo="Registrar retirada"
                    ocupado={ocupado}
                    desabilitado={responsavel === ''}
                    acao={() => retirar(null)}
                    onFechar={fechar}
                >
                    <ul className="list-disc pl-5 text-sm text-on-surface">
                        {pendentes.map((i) => <li key={i.id}>{i.descricao}</li>)}
                    </ul>
                    <EscolhaResponsavel pessoas={pessoas} valor={responsavel} onChange={setResponsavel} />
                </Dialogo>
            )}

            {acao?.tipo === 'parcial' && (
                <Dialogo
                    titulo="Retirada parcial"
                    descricao="Marque só o que está saindo agora. O resto continua no balcão e pode ser retirado depois, por outra pessoa."
                    rotulo="Registrar retirada"
                    ocupado={ocupado}
                    desabilitado={responsavel === '' || escolhidos.length === 0}
                    acao={() => retirar(escolhidos)}
                    onFechar={fechar}
                >
                    <ul className="space-y-1">
                        {pendentes.map((i) => (
                            <li key={i.id}>
                                <label className="flex items-center gap-2 text-sm text-on-surface">
                                    <input
                                        type="checkbox"
                                        className="accent-primary-container"
                                        aria-label={i.descricao}
                                        checked={escolhidos.includes(i.id)}
                                        onChange={() => setEscolhidos((a) => (
                                            a.includes(i.id) ? a.filter((x) => x !== i.id) : [...a, i.id]
                                        ))}
                                    />
                                    {i.descricao}
                                </label>
                            </li>
                        ))}
                    </ul>
                    <EscolhaResponsavel pessoas={pessoas} valor={responsavel} onChange={setResponsavel} />
                </Dialogo>
            )}

            {acao?.tipo === 'editar' && (
                <Dialogo
                    titulo="Corrigir registro"
                    descricao="Dá para trocar quem deixou o material e ajustar a lista de itens. O que já foi retirado não pode ser apagado — a entrega já aconteceu."
                    rotulo="Salvar correção"
                    ocupado={ocupado}
                    desabilitado={responsavel === '' || justificativa.trim().length < 5}
                    acao={salvarEdicao}
                    onFechar={fechar}
                >
                    <label className="block">
                        <span className="text-sm font-semibold text-on-surface">Quem deixou o material</span>
                        <Select
                            value={responsavel}
                            aria-label="Quem deixou o material"
                            onChange={(e) => setResponsavel(e.target.value)}
                        >
                            <option value="">Escolha uma pessoa do projeto</option>
                            {pessoas.map((p) => (
                                <option key={`${p.tipo}:${p.id}`} value={`${p.tipo}:${p.id}`}>
                                    {p.nome} · {p.tipo_label}
                                </option>
                            ))}
                        </Select>
                    </label>

                    <div className="space-y-2">
                        <span className="text-sm font-semibold text-on-surface">Itens</span>
                        {rascunho.map((item, i) => (
                            <div key={item.id ?? `novo-${i}`} className="flex items-center gap-2">
                                <Input
                                    value={item.descricao}
                                    aria-label={`Item ${i + 1}`}
                                    disabled={item.retirado}
                                    onChange={(e) => setRascunho((atual) => atual.map((x, j) => (
                                        j === i ? { ...x, descricao: e.target.value } : x
                                    )))}
                                />
                                <button
                                    type="button"
                                    aria-label={`Remover item ${i + 1}`}
                                    disabled={item.retirado}
                                    onClick={() => setRascunho((atual) => atual.filter((_, j) => j !== i))}
                                    className="text-on-surface-variant hover:text-error disabled:opacity-40"
                                    title={item.retirado ? 'Já retirado: não pode ser removido' : 'Remover'}
                                >
                                    <span className="material-symbols-outlined text-[18px]">close</span>
                                </button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setRascunho((atual) => [...atual, { descricao: '' }])}
                        >
                            <span className="material-symbols-outlined text-[18px]">add</span>
                            Acrescentar item
                        </Button>
                    </div>

                    <label className="block">
                        <span className="text-sm font-semibold text-on-surface">Justificativa</span>
                        <textarea
                            rows={2}
                            maxLength={500}
                            aria-label="Justificativa da correção"
                            value={justificativa}
                            onChange={(e) => setJustificativa(e.target.value)}
                            placeholder="Ex.: quem deixou foi a orientadora, e faltou lançar a caixa."
                            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                        />
                    </label>
                </Dialogo>
            )}

            {acao?.tipo === 'excluir' && (
                <Dialogo
                    titulo="Excluir registro"
                    descricao={`O registro de “${acao.registro.projeto}” sai da lista. A justificativa entra em Registros → Almoxarifado, com o que estava guardado.`}
                    rotulo="Excluir"
                    ocupado={ocupado}
                    desabilitado={justificativa.trim().length < 5}
                    acao={excluir}
                    onFechar={fechar}
                >
                    <label className="block">
                        <span className="text-sm font-semibold text-on-surface">Justificativa</span>
                        <textarea
                            rows={2}
                            maxLength={500}
                            aria-label="Justificativa da exclusão"
                            value={justificativa}
                            onChange={(e) => setJustificativa(e.target.value)}
                            placeholder="Ex.: lançado em duplicidade no balcão."
                            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                        />
                    </label>
                </Dialogo>
            )}
        </AppShell>
    );
}
