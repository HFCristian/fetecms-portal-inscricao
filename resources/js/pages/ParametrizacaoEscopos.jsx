import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Input, Field, Alert, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getEscopos, criarEscopo, atualizarEscopo, excluirEscopo } from '../lib/admin.js';

/**
 * Parametrização → Escopos de admin.
 *
 * Um escopo é um nome e as abas do menu que ele abre. Quem recebe o escopo é
 * cada administrador, **por edição**, na aba Administradores — aqui só se
 * desenham os perfis.
 */
export default function ParametrizacaoEscopos() {
    const [dados, setDados] = useState(null);
    const [form, setForm] = useState({ nome: '', abas: [] });
    const [editando, setEditando] = useState(null); // { id, nome, abas }
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => { getEscopos().then(setDados).catch(() => setDados(null)); }, []);

    function aplicar(novo, mensagem) {
        setDados(novo);
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

    const abas = dados?.abas ?? [];
    const escopos = dados?.escopos ?? [];

    // O alvo da edição de abas é o escopo aberto ou o formulário de criação.
    const alvo = editando ?? form;
    const setAlvo = editando ? setEditando : setForm;

    function alternarAba(valor) {
        setAlvo((a) => ({
            ...a,
            abas: a.abas.includes(valor) ? a.abas.filter((x) => x !== valor) : [...a.abas, valor],
        }));
    }

    async function salvar(ev) {
        ev.preventDefault();
        setSalvando(true);
        try {
            if (editando) {
                aplicar(
                    await atualizarEscopo(editando.id, { nome: editando.nome.trim(), abas: editando.abas }),
                    'Escopo atualizado.',
                );
                setEditando(null);
            } else {
                aplicar(await criarEscopo({ nome: form.nome.trim(), abas: form.abas }), 'Escopo criado.');
                setForm({ nome: '', abas: [] });
            }
        } catch (e) {
            falhar(e);
        } finally {
            setSalvando(false);
        }
    }

    async function excluir(escopo) {
        const ok = await confirm({
            title: 'Excluir escopo',
            message: `Excluir "${escopo.nome}"? Nenhum administrador o utiliza.`,
            confirmLabel: 'Excluir',
            danger: true,
        });
        if (!ok) return;
        try {
            aplicar(await excluirEscopo(escopo.id), 'Escopo excluído.');
        } catch (e) {
            falhar(e);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Escopos de admin</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Um escopo diz <strong>quais abas do menu</strong> um administrador abre. A atribuição é
                feita em <Link to="/admin/gerir-admins" className="underline">Administradores</Link> e vale
                <strong> por edição</strong>. Quem não recebeu escopo na edição em curso continua com
                acesso total.
            </p>

            {alerta && <div className="mb-4"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            {/* Formulário (criação ou edição) */}
            <form onSubmit={salvar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
                <h2 className="font-display text-primary font-semibold mb-4">
                    {editando ? `Editar "${escopos.find((e) => e.id === editando.id)?.nome ?? ''}"` : 'Novo escopo'}
                </h2>

                <Field label="Nome" required error={errors.nome}>
                    <Input
                        value={alvo.nome}
                        onChange={(e) => setAlvo((a) => ({ ...a, nome: e.target.value }))}
                        placeholder="Comunicação e suporte"
                        error={errors.nome}
                    />
                </Field>

                <p className="text-sm font-semibold text-on-surface mt-4 mb-2">
                    Abas liberadas <span className="text-error">*</span>
                </p>
                {errors.abas && <div className="mb-2"><Alert>{errors.abas}</Alert></div>}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    {abas.map((a) => (
                        <label key={a.value} className="flex items-start gap-3 cursor-pointer rounded-lg border border-outline-variant p-3 hover:bg-surface-variant/40">
                            <input
                                type="checkbox"
                                checked={alvo.abas.includes(a.value)}
                                onChange={() => alternarAba(a.value)}
                                className="mt-0.5 w-5 h-5 rounded text-primary-container"
                            />
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold text-on-surface">{a.label}</span>
                                <span className="block text-xs text-on-surface-variant">{a.descricao}</span>
                            </span>
                        </label>
                    ))}
                </div>

                <div className="flex justify-end gap-3 mt-4">
                    {editando && (
                        <Button type="button" variant="outline" onClick={() => { setEditando(null); setErrors({}); }}>
                            Cancelar
                        </Button>
                    )}
                    <Button type="submit" loading={salvando} disabled={!alvo.nome.trim() || alvo.abas.length === 0}>
                        <span className="material-symbols-outlined text-[20px]">{editando ? 'save' : 'add'}</span>
                        {editando ? 'Salvar escopo' : 'Criar escopo'}
                    </Button>
                </div>
            </form>

            {/* Lista */}
            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : escopos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm max-w-3xl">
                    Nenhum escopo criado ainda — todos os administradores têm acesso total.
                </div>
            ) : (
                <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40 max-w-3xl">
                    {escopos.map((e) => (
                        <li key={e.id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-on-surface">{e.nome}</p>
                                <p className="text-xs text-on-surface-variant">
                                    {e.abas
                                        .map((v) => abas.find((a) => a.value === v)?.label ?? v)
                                        .join(' · ')}
                                </p>
                                <p className="text-xs text-on-surface-variant">
                                    {e.admins} {e.admins === 1 ? 'administrador' : 'administradores'}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" onClick={() => { setEditando({ id: e.id, nome: e.nome, abas: [...e.abas] }); setErrors({}); }}>
                                    <span className="material-symbols-outlined text-[20px]">edit</span>
                                    Editar
                                </Button>
                                {e.pode_excluir && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="text-error border-error/40 hover:bg-error-container/40"
                                        onClick={() => excluir(e)}
                                    >
                                        <span className="material-symbols-outlined text-[20px]">delete</span>
                                        Excluir
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {confirmDialog}
        </AppShell>
    );
}
