import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getDadosDemo,
    definirContaDemo,
    excluirContaDemo,
    excluirProjetoDemo,
    excluirListaDemo,
    excluirGuardaDemo,
    limparDadosDemo,
} from '../lib/dadosDemo.js';

/**
 * Parametrização → **Dados de demonstração**.
 *
 * O portal acumula ensaio: contas de treinamento, o projeto-exemplo da aba
 * Ajustes, a lista final de demonstração do balcão, as guardas do almoxarifado.
 * Cada um some de uma tela diferente, e ninguém conseguia responder "o que
 * ainda é de mentira aqui dentro?" — que é a pergunta de quem vai abrir a feira
 * de verdade. Esta tela responde, e deixa limpar.
 *
 * Só aparece (e só se apaga) o que está **marcado** como demonstração: conta com
 * o interruptor ligado, projeto de uma dessas contas, lista e guarda marcadas.
 */

/** Data curta, do jeito que o resto do painel mostra. */
function data(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/** Cartão de contagem do topo — o resumo do que existe de ensaio. */
function CardResumo({ icone, titulo, total }) {
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex items-center gap-3">
            <span className="w-10 h-10 rounded-lg bg-primary-fixed text-primary-container flex items-center justify-center shrink-0">
                <span className="material-symbols-outlined text-[22px]">{icone}</span>
            </span>
            <div className="min-w-0">
                <p className="font-display text-2xl font-semibold text-on-surface leading-none">{total}</p>
                <p className="text-xs text-on-surface-variant mt-1 truncate">{titulo}</p>
            </div>
        </div>
    );
}

/**
 * Uma seção do panorama. Recolhida por padrão: a tela existe para dar a visão
 * geral primeiro, e abrir oito tabelas de uma vez esconderia justamente isso.
 */
function Secao({ titulo, descricao, grupo, children }) {
    const [aberta, setAberta] = useState(false);
    const total = grupo?.total ?? 0;
    const mostrados = grupo?.itens?.length ?? 0;

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
            <button
                type="button"
                onClick={() => setAberta((a) => !a)}
                aria-expanded={aberta}
                className="w-full flex items-center gap-3 p-4 text-left hover:bg-surface-container-low transition-colors"
            >
                <span className="material-symbols-outlined text-on-surface-variant">
                    {aberta ? 'expand_more' : 'chevron_right'}
                </span>
                <span className="min-w-0">
                    <span className="font-display font-semibold text-on-surface">{titulo}</span>
                    <span className="block text-xs text-on-surface-variant">{descricao}</span>
                </span>
                <span className="ml-auto shrink-0 text-sm font-semibold text-primary-container">{total}</span>
            </button>

            {aberta && (
                <div className="border-t border-outline-variant/40 p-4 overflow-x-auto">
                    {total === 0 ? (
                        <p className="text-sm text-on-surface-variant">Nada de demonstração aqui.</p>
                    ) : (
                        <>
                            {children}
                            {mostrados < total && (
                                <p className="text-xs text-on-surface-variant mt-3">
                                    Mostrando {mostrados} de {total} — apague em lote para ver o resto.
                                </p>
                            )}
                        </>
                    )}
                </div>
            )}
        </section>
    );
}

/** Cabeçalho de tabela padrão das listagens do painel. */
function Th({ children, className = '' }) {
    return (
        <th className={`text-left text-xs font-semibold text-on-surface-variant uppercase tracking-wide pb-2 pr-4 ${className}`}>
            {children}
        </th>
    );
}

function Td({ children, className = '' }) {
    return <td className={`py-2 pr-4 text-sm text-on-surface align-top ${className}`}>{children}</td>;
}

export default function ParametrizacaoDemo() {
    const [dados, setDados] = useState(null);
    const [carregando, setCarregando] = useState(true);
    const [ocupado, setOcupado] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => {
        getDadosDemo()
            .then((r) => setDados(r.data))
            .catch(() => setAlerta('Não foi possível carregar os dados de demonstração.'))
            .finally(() => setCarregando(false));
    }, []);

    /** Toda ação devolve o panorama inteiro: apagar uma conta mexe em vários grupos. */
    async function acao(fn, confirmacao) {
        if (confirmacao && !(await confirm(confirmacao))) return;

        setOcupado(true);
        setAlerta('');
        setSucesso('');
        try {
            const r = await fn();
            if (r?.data?.contas) setDados(r.data);
            setSucesso(r?.meta?.message ?? 'Pronto.');
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(fields?.demo ?? message ?? 'Não foi possível concluir.');
        } finally {
            setOcupado(false);
        }
    }

    /** O interruptor de uma conta: devolve só a linha, então recarregamos o todo. */
    async function alternarConta(conta) {
        await acao(
            async () => {
                await definirContaDemo(conta.id, !conta.is_demo);
                return getDadosDemo();
            },
            conta.is_demo
                ? {
                    title: 'Tirar a marca de demonstração',
                    message: `"${conta.name}" deixa de ser conta de ensaio. Os projetos dela voltam a contar no painel, no ranking e na lista final.`,
                    confirmLabel: 'Tirar a marca',
                    danger: true,
                }
                : null,
        );
    }

    const g = dados ?? {};
    const vazio = dados && Object.values(g).every((grupo) => (grupo?.total ?? 0) === 0);

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/parametrizacao" className="hover:text-primary">Parametrização</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Dados de demonstração</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Dados de demonstração</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Tudo que existe no portal para ensaio: contas de treinamento, os projetos delas, as
                avaliações, listas finais e registros que esse ensaio gerou. Nada disso entra nos
                números da feira, no ranking, na lista final oficial nem chega a um avaliador de
                verdade — e daqui dá para apagar o que já não serve.
            </p>

            {alerta && <div className="mb-4"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            {carregando && <p className="text-on-surface-variant">Carregando…</p>}

            {dados && (
                <>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                        <CardResumo icone="badge" titulo="Contas" total={g.contas?.total ?? 0} />
                        <CardResumo icone="science" titulo="Projetos" total={g.projetos?.total ?? 0} />
                        <CardResumo icone="grading" titulo="Avaliações" total={g.avaliacoes?.total ?? 0} />
                        <CardResumo icone="history" titulo="Registros" total={g.registros?.total ?? 0} />
                    </div>

                    {vazio ? (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6">
                            <p className="text-on-surface-variant">
                                Não há nada de demonstração no portal. O que estiver nas telas é dado real.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            <Secao
                                titulo="Contas"
                                descricao="Orientadores, avaliadores e administradores marcados como demonstração."
                                grupo={g.contas}
                            >
                                <table className="w-full min-w-[42rem]">
                                    <thead><tr>
                                        <Th>Conta</Th><Th>Papel</Th><Th>Projetos</Th>
                                        <Th>Demonstração</Th><Th className="text-right">Ações</Th>
                                    </tr></thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {g.contas.itens.map((c) => (
                                            <tr key={c.id}>
                                                <Td>
                                                    <span className="font-medium">{c.name}</span>
                                                    <span className="block text-xs text-on-surface-variant">{c.email}</span>
                                                </Td>
                                                <Td>{c.papel}</Td>
                                                <Td>{c.projetos}</Td>
                                                <Td>
                                                    <Toggle
                                                        checked={c.is_demo}
                                                        onChange={() => alternarConta(c)}
                                                        label=""
                                                    />
                                                </Td>
                                                <Td className="text-right">
                                                    <Button
                                                        variant="outline"
                                                        disabled={ocupado}
                                                        onClick={() => acao(() => excluirContaDemo(c.id), {
                                                            title: 'Excluir conta de demonstração',
                                                            message: `Apagar "${c.name}" e tudo que nasceu dela: ${c.projetos} projeto(s), as avaliações, credenciamentos e registros. Não há como desfazer.`,
                                                            confirmLabel: 'Excluir tudo',
                                                            danger: true,
                                                        })}
                                                    >
                                                        Excluir
                                                    </Button>
                                                </Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Secao>

                            <Secao
                                titulo="Projetos"
                                descricao="De todas as edições — inclusive os que ficaram para trás em anos anteriores."
                                grupo={g.projetos}
                            >
                                <table className="w-full min-w-[46rem]">
                                    <thead><tr>
                                        <Th>Projeto</Th><Th>Situação</Th><Th>Orientador</Th>
                                        <Th>Edição</Th><Th>Avaliações</Th><Th className="text-right">Ações</Th>
                                    </tr></thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {g.projetos.itens.map((p) => (
                                            <tr key={p.id}>
                                                <Td>
                                                    <span className="font-medium">{p.titulo}</span>
                                                    <span className="block text-xs text-on-surface-variant">
                                                        #{p.id} · {p.categoria_label ?? 'sem categoria'} · {p.area ?? 'sem área'}
                                                    </span>
                                                </Td>
                                                <Td>
                                                    {p.status_label ?? '—'}
                                                    {p.excluido && <span className="block text-xs text-error">na lixeira</span>}
                                                </Td>
                                                <Td>{p.orientador}</Td>
                                                <Td>{p.edicao ?? '—'}</Td>
                                                <Td>{p.avaliacoes}</Td>
                                                <Td className="text-right">
                                                    <Button
                                                        variant="outline"
                                                        disabled={ocupado}
                                                        onClick={() => acao(() => excluirProjetoDemo(p.id), {
                                                            title: 'Excluir projeto de demonstração',
                                                            message: `Apagar "${p.titulo}" com as avaliações, o credenciamento e as guardas dele. A conta do orientador continua.`,
                                                            confirmLabel: 'Excluir projeto',
                                                            danger: true,
                                                        })}
                                                    >
                                                        Excluir
                                                    </Button>
                                                </Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Secao>

                            <Secao
                                titulo="Avaliações"
                                descricao="De projeto de ensaio ou feitas por avaliador de ensaio — as devolvidas incluídas."
                                grupo={g.avaliacoes}
                            >
                                <table className="w-full min-w-[40rem]">
                                    <thead><tr>
                                        <Th>Projeto</Th><Th>Avaliador</Th><Th>Situação</Th><Th>Nota</Th><Th>Motivo</Th>
                                    </tr></thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {g.avaliacoes.itens.map((a) => (
                                            <tr key={a.id}>
                                                <Td>#{a.projeto_id}</Td>
                                                <Td>{a.avaliador ?? '—'}</Td>
                                                <Td>
                                                    {a.status_label ?? '—'}
                                                    {a.devolvida && <span className="block text-xs text-on-surface-variant">devolvida</span>}
                                                </Td>
                                                <Td>{a.nota ?? '—'}</Td>
                                                <Td>{a.motivo}</Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Secao>

                            <Secao
                                titulo="Listas finais"
                                descricao="A trilha paralela de demonstração usada pelo modo de teste do balcão."
                                grupo={g.listas}
                            >
                                <table className="w-full min-w-[36rem]">
                                    <thead><tr>
                                        <Th>Lista</Th><Th>Versão</Th><Th>Projetos</Th>
                                        <Th>Gerada em</Th><Th className="text-right">Ações</Th>
                                    </tr></thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {g.listas.itens.map((l) => (
                                            <tr key={l.id}>
                                                <Td>
                                                    <span className="font-medium">{l.nome}</span>
                                                    {l.vigente && <span className="block text-xs text-primary-container">vigente</span>}
                                                </Td>
                                                <Td>v{l.versao}</Td>
                                                <Td>{l.projetos}</Td>
                                                <Td>{data(l.gerada_em)}</Td>
                                                <Td className="text-right">
                                                    <Button
                                                        variant="outline"
                                                        disabled={ocupado}
                                                        onClick={() => acao(() => excluirListaDemo(l.id), {
                                                            title: 'Excluir lista de demonstração',
                                                            message: `Apagar "${l.nome}". Os projetos dela continuam; some só a lista.`,
                                                            confirmLabel: 'Excluir lista',
                                                            danger: true,
                                                        })}
                                                    >
                                                        Excluir
                                                    </Button>
                                                </Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Secao>

                            <Secao
                                titulo="Credenciamentos"
                                descricao="Atendimentos de balcão feitos sobre projetos de ensaio."
                                grupo={g.credenciamentos}
                            >
                                <table className="w-full min-w-[32rem]">
                                    <thead><tr><Th>Projeto</Th><Th>Atendente</Th><Th>Situação</Th></tr></thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {g.credenciamentos.itens.map((c) => (
                                            <tr key={c.id}>
                                                <Td>#{c.projeto_id}</Td>
                                                <Td>{c.autor ?? '—'}</Td>
                                                <Td>{c.rascunho ? 'Em rascunho' : `Concluído em ${data(c.finalizado_em)}`}</Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Secao>

                            <Secao
                                titulo="Almoxarifado"
                                descricao="Guardas registradas no modo de ensaio do balcão."
                                grupo={g.guardas}
                            >
                                <table className="w-full min-w-[36rem]">
                                    <thead><tr>
                                        <Th>Projeto</Th><Th>Quem deixou</Th><Th>Itens</Th>
                                        <Th>Data</Th><Th className="text-right">Ações</Th>
                                    </tr></thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {g.guardas.itens.map((gu) => (
                                            <tr key={gu.id}>
                                                <Td>#{gu.projeto_id}</Td>
                                                <Td>{gu.responsavel}</Td>
                                                <Td>{gu.itens}</Td>
                                                <Td>{data(gu.registrado_em)}</Td>
                                                <Td className="text-right">
                                                    <Button
                                                        variant="outline"
                                                        disabled={ocupado}
                                                        onClick={() => acao(() => excluirGuardaDemo(gu.id), {
                                                            title: 'Excluir guarda de demonstração',
                                                            message: 'Apagar este registro de guarda do ensaio.',
                                                            confirmLabel: 'Excluir guarda',
                                                            danger: true,
                                                        })}
                                                    >
                                                        Excluir
                                                    </Button>
                                                </Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Secao>

                            <Secao
                                titulo="Ajustes de área/subárea"
                                descricao="As sugestões que o orientador de ensaio vê na aba Ajustes."
                                grupo={g.ajustes}
                            >
                                <table className="w-full min-w-[30rem]">
                                    <thead><tr><Th>Projeto</Th><Th>Tipo</Th><Th>Situação</Th></tr></thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {g.ajustes.itens.map((a) => (
                                            <tr key={a.id}>
                                                <Td>#{a.projeto_id}</Td>
                                                <Td>{a.tipo}</Td>
                                                <Td>{a.aceito ? `Aceito em ${data(a.decidido_em)}` : 'Sugerido'}</Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Secao>

                            <Secao
                                titulo="Registros"
                                descricao="A trilha de auditoria que o ensaio gerou — ela some junto com o dado."
                                grupo={g.registros}
                            >
                                <table className="w-full min-w-[36rem]">
                                    <thead><tr><Th>Quando</Th><Th>Tipo</Th><Th>Autor</Th><Th>Projeto</Th></tr></thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {g.registros.itens.map((r) => (
                                            <tr key={r.id}>
                                                <Td>{data(r.criado_em)}</Td>
                                                <Td>{r.tipo_label ?? r.tipo}</Td>
                                                <Td>{r.autor ?? '—'}</Td>
                                                <Td>{r.projeto ?? '—'}</Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Secao>

                            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex flex-wrap items-center gap-3 mt-2">
                                <span className="material-symbols-outlined text-error">delete_forever</span>
                                <p className="text-sm text-on-surface-variant flex-1 min-w-[16rem]">
                                    Apagar de uma vez todas as contas, projetos, listas e guardas de
                                    demonstração. O que é dado real não é tocado.
                                </p>
                                <Button
                                    variant="outline"
                                    disabled={ocupado}
                                    onClick={() => acao(limparDadosDemo, {
                                        title: 'Apagar toda a demonstração',
                                        message: `Serão apagadas ${g.contas?.total ?? 0} conta(s), ${g.projetos?.total ?? 0} projeto(s), ${g.listas?.total ?? 0} lista(s) e ${g.guardas?.total ?? 0} guarda(s), com tudo que veio delas. Não há como desfazer.`,
                                        confirmLabel: 'Apagar tudo',
                                        danger: true,
                                    })}
                                >
                                    Apagar tudo
                                </Button>
                            </div>
                        </div>
                    )}
                </>
            )}

            {confirmDialog}
        </AppShell>
    );
}
