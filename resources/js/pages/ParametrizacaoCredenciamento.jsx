import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, Select, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getParametrizacaoCredenciamento, definirItensCredenciamento,
    criarDocumentoCredenciamento, atualizarDocumentoCredenciamento, excluirDocumentoCredenciamento,
} from '../lib/credenciamento.js';

/**
 * Parametrização → Credenciamento: quando o balcão abre e o que ele confere.
 *
 * A **janela do evento** mudou-se para Parametrização → Datas e períodos, com
 * as demais datas da edição. Aqui ficam os **documentos** — catálogo do portal,
 * um conjunto por papel — e os **itens a entregar**, o lembrete que a tela do
 * balcão mostra ao concluir cada credenciamento.
 */
export default function ParametrizacaoCredenciamento() {
    const [dados, setDados] = useState(null);
    const [novo, setNovo] = useState({ tipo_pessoa: 'aluno', nome: '' });
    const [itens, setItens] = useState([]);
    const [novoItem, setNovoItem] = useState('');
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => {
        getParametrizacaoCredenciamento()
            .then((d) => {
                setDados(d);
                setItens(d.config.itens ?? []);
            })
            .catch(() => setDados(null));
    }, []);

    function aplicar(novoEstado, mensagem) {
        setDados(novoEstado);
        setSucesso(mensagem);
        setAlerta('');
        setErrors({});
    }

    function falhar(e) {
        const { message, fields } = extractErrors(e);
        setErrors(fields);
        setAlerta(message || 'Não foi possível concluir.');
        setSucesso('');
    }

    async function salvarItens(lista) {
        setSalvando(true);
        try {
            const novoEstado = await definirItensCredenciamento(lista);
            setItens(novoEstado.config.itens ?? []);
            aplicar(novoEstado, 'Itens salvos.');
        } catch (e) {
            falhar(e);
        } finally {
            setSalvando(false);
        }
    }

    function adicionarItem(ev) {
        ev.preventDefault();
        const texto = novoItem.trim();
        if (texto === '' || itens.includes(texto)) return;
        setNovoItem('');
        salvarItens([...itens, texto]);
    }

    async function criar(ev) {
        ev.preventDefault();
        setSalvando(true);
        try {
            aplicar(await criarDocumentoCredenciamento({ ...novo, nome: novo.nome.trim() }), 'Documento adicionado.');
            setNovo((n) => ({ ...n, nome: '' }));
        } catch (e) {
            falhar(e);
        } finally {
            setSalvando(false);
        }
    }

    async function alternarAtivo(doc) {
        try {
            aplicar(
                await atualizarDocumentoCredenciamento(doc.id, { ativo: !doc.ativo }),
                doc.ativo ? 'Documento desativado.' : 'Documento reativado.',
            );
        } catch (e) {
            falhar(e);
        }
    }

    async function excluir(doc) {
        const ok = await confirm({
            title: 'Excluir documento',
            message: `Excluir "${doc.nome}" da lista? Se ele já foi conferido em algum credenciamento, desative em vez de excluir.`,
            confirmLabel: 'Excluir',
            danger: true,
        });
        if (!ok) return;
        try {
            aplicar(await excluirDocumentoCredenciamento(doc.id), 'Documento excluído.');
        } catch (e) {
            falhar(e);
        }
    }

    const tipos = dados?.tipos ?? [];
    const documentos = dados?.documentos ?? [];

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Credenciamento</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                O que o balcão do evento entrega e quais documentos ele confere de cada papel. O{' '}
                <strong>período do evento</strong> mudou-se para{' '}
                <Link to="/admin/parametrizacao/datas" className="underline">Datas e períodos</Link> — e,
                sem a data de início, o credenciamento continua <strong>fechado</strong>: ele é um ato
                presencial, ninguém credencia por padrão.
            </p>

            {alerta && <div className="mb-4"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            {/* Itens entregues no balcão */}
            <form onSubmit={adicionarItem} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
                <h2 className="font-display text-primary font-semibold mb-1">Itens entregues aos finalistas</h2>
                <p className="text-xs text-on-surface-variant mb-4">
                    Ao concluir cada credenciamento, a tela lembra o atendente de entregar estes itens.
                </p>
                <div className="flex gap-2">
                    <div className="flex-1">
                        <Input
                            value={novoItem}
                            onChange={(e) => setNovoItem(e.target.value)}
                            placeholder="Camiseta, crachá, kit do participante…"
                            aria-label="Novo item a entregar"
                        />
                    </div>
                    <Button type="submit" loading={salvando} disabled={!novoItem.trim()}>
                        <span className="material-symbols-outlined text-[20px]">add</span>
                        Adicionar
                    </Button>
                </div>
                {itens.length > 0 && (
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {itens.map((item) => (
                            <li key={item} className="inline-flex items-center gap-1 rounded-full bg-primary-fixed text-primary-container px-3 py-1 text-sm">
                                {item}
                                <button
                                    type="button"
                                    aria-label={`Remover ${item}`}
                                    onClick={() => salvarItens(itens.filter((i) => i !== item))}
                                    className="hover:opacity-70"
                                >
                                    <span className="material-symbols-outlined text-[16px]">close</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </form>

            {/* Documentos */}
            <form onSubmit={criar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
                <h2 className="font-display text-primary font-semibold mb-1">Documentos exigidos</h2>
                <p className="text-xs text-on-surface-variant mb-4">
                    Cada papel tem a sua lista. No balcão, cada item é marcado como presente, ausente
                    ou não necessário.
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <Select value={novo.tipo_pessoa} onChange={(e) => setNovo((n) => ({ ...n, tipo_pessoa: e.target.value }))} aria-label="Papel">
                        {tipos.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </Select>
                    <div className="sm:col-span-2 flex gap-2">
                        <div className="flex-1">
                            <Input
                                value={novo.nome}
                                onChange={(e) => setNovo((n) => ({ ...n, nome: e.target.value }))}
                                placeholder="RG, CPF, autorização de menor…"
                                aria-label="Nome do documento"
                                error={errors.nome}
                            />
                        </div>
                        <Button type="submit" loading={salvando} disabled={!novo.nome.trim()}>
                            <span className="material-symbols-outlined text-[20px]">add</span>
                            Adicionar
                        </Button>
                    </div>
                </div>
                {errors.nome && <div className="mt-2"><Alert>{errors.nome}</Alert></div>}
            </form>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="space-y-4 max-w-3xl">
                    {tipos.map((tipo) => {
                        const doTipo = documentos.filter((d) => d.tipo_pessoa === tipo.value);
                        return (
                            <section key={tipo.value} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                                <h3 className="font-display text-primary font-semibold mb-3">{tipo.label}</h3>
                                {doTipo.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">Nenhum documento exigido deste papel.</p>
                                ) : (
                                    <ul className="divide-y divide-outline-variant/40">
                                        {doTipo.map((d) => (
                                            <li key={d.id} className="py-2 flex items-center gap-3">
                                                <span className={`text-sm flex-1 min-w-0 ${d.ativo ? 'text-on-surface' : 'text-on-surface-variant line-through'}`}>
                                                    {d.nome}
                                                </span>
                                                <Button type="button" variant="outline" onClick={() => alternarAtivo(d)}>
                                                    {d.ativo ? 'Desativar' : 'Reativar'}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="text-error border-error/40 hover:bg-error-container/40"
                                                    onClick={() => excluir(d)}
                                                >
                                                    <span className="material-symbols-outlined text-[20px]">delete</span>
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        );
                    })}
                </div>
            )}
            {confirmDialog}
        </AppShell>
    );
}
