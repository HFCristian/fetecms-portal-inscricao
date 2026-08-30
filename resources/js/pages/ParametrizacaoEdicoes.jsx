import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Input, Field, Alert, Toggle, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getEdicoesAdmin, criarEdicao, atualizarEdicao, definirEdicaoPadrao, excluirEdicao,
} from '../lib/edicoes.js';

const FORM_VAZIO = { nome: '', ano: new Date().getFullYear() + 1, copiar: true };

/**
 * Parametrização → Edições.
 *
 * É o que torna o portal reutilizável no ano seguinte: cria-se a edição nova
 * (herdando a parametrização da atual, se quiser), marca-se como padrão e todo
 * mundo passa a trabalhar nela. A anterior continua ali, consultável pelo
 * seletor de edição no menu.
 */
export default function ParametrizacaoEdicoes() {
    const [edicoes, setEdicoes] = useState(null);
    const [form, setForm] = useState(FORM_VAZIO);
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [editando, setEditando] = useState(null); // { id, nome, ano }
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => {
        getEdicoesAdmin().then(setEdicoes).catch(() => setEdicoes([]));
    }, []);

    function aplicar(lista, mensagem) {
        setEdicoes(lista);
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

    const padraoAtual = (edicoes ?? []).find((e) => e.padrao);

    async function criar(ev) {
        ev.preventDefault();
        setSalvando(true);
        try {
            const lista = await criarEdicao({
                nome: form.nome.trim(),
                ano: Number(form.ano),
                copiar_de: form.copiar && padraoAtual ? padraoAtual.id : null,
            });
            aplicar(lista, 'Edição criada.');
            setForm(FORM_VAZIO);
        } catch (e) {
            falhar(e);
        } finally {
            setSalvando(false);
        }
    }

    async function salvarEdicao(ev) {
        ev.preventDefault();
        setSalvando(true);
        try {
            const lista = await atualizarEdicao(editando.id, {
                nome: editando.nome.trim(),
                ano: Number(editando.ano),
            });
            aplicar(lista, 'Edição atualizada.');
            setEditando(null);
        } catch (e) {
            falhar(e);
        } finally {
            setSalvando(false);
        }
    }

    async function tornarPadrao(edicao) {
        const ok = await confirm({
            title: 'Trocar a edição padrão',
            message: `"${edicao.nome}" passa a ser a edição de todo o portal: é ela que vale para `
                + 'quem não escolheu nenhuma, para o cadastro público e para os e-mails automáticos. '
                + 'Quem já tinha escolhido outra continua nela.',
            confirmLabel: 'Tornar padrão',
        });
        if (!ok) return;
        try {
            aplicar(await definirEdicaoPadrao(edicao.id), `"${edicao.nome}" agora é a edição padrão.`);
        } catch (e) {
            falhar(e);
        }
    }

    async function excluir(edicao) {
        const ok = await confirm({
            title: 'Excluir edição',
            message: `Excluir "${edicao.nome}"? Ela não tem nenhum projeto. Esta ação não pode ser desfeita.`,
            confirmLabel: 'Excluir',
            danger: true,
        });
        if (!ok) return;
        try {
            aplicar(await excluirEdicao(edicao.id), 'Edição excluída.');
        } catch (e) {
            falhar(e);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Edições</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Cada projeto pertence a uma edição da feira. A <strong>edição padrão</strong> é a que
                vale para quem não escolheu nenhuma — inclusive o cadastro público e os e-mails
                automáticos. Qualquer usuário pode trocar a sua edição pelo seletor no topo do menu,
                e isso troca de uma vez os projetos, os prazos e os limites que ele enxerga.
            </p>

            {alerta && <div className="mb-4"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            {/* Nova edição */}
            <form onSubmit={criar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
                <h2 className="font-display text-primary font-semibold mb-4">Nova edição</h2>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="sm:col-span-2">
                        <Field label="Nome" required error={errors.nome}>
                            <Input
                                value={form.nome}
                                onChange={(e) => setForm((f) => ({ ...f, nome: e.target.value }))}
                                placeholder="XVII FETECMS"
                                error={errors.nome}
                            />
                        </Field>
                    </div>
                    <Field label="Ano" required error={errors.ano}>
                        <Input
                            type="number"
                            value={form.ano}
                            onChange={(e) => setForm((f) => ({ ...f, ano: e.target.value }))}
                            error={errors.ano}
                        />
                    </Field>
                </div>
                {padraoAtual && (
                    <div className="mt-3">
                        <Toggle
                            checked={form.copiar}
                            onChange={(v) => setForm((f) => ({ ...f, copiar: v }))}
                            label={`Copiar a parametrização de "${padraoAtual.nome}"`}
                            description="Limites de avaliação, regras de distribuição e designação ao cadastrar. As datas não são copiadas — cada edição tem seu próprio calendário."
                        />
                    </div>
                )}
                <div className="flex justify-end mt-4">
                    <Button type="submit" loading={salvando} disabled={!form.nome.trim()}>
                        <span className="material-symbols-outlined text-[20px]">add</span>
                        Criar edição
                    </Button>
                </div>
            </form>

            {/* Lista */}
            {edicoes === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40 max-w-3xl">
                    {edicoes.map((e) => (
                        <li key={e.id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                            {editando?.id === e.id ? (
                                <form onSubmit={salvarEdicao} className="flex-1 flex flex-col sm:flex-row gap-2 sm:items-center">
                                    <Input
                                        value={editando.nome}
                                        onChange={(ev) => setEditando((d) => ({ ...d, nome: ev.target.value }))}
                                        error={errors.nome}
                                    />
                                    <div className="sm:w-28">
                                        <Input
                                            type="number"
                                            value={editando.ano}
                                            onChange={(ev) => setEditando((d) => ({ ...d, ano: ev.target.value }))}
                                            error={errors.ano}
                                        />
                                    </div>
                                    <Button type="submit" loading={salvando}>Salvar</Button>
                                    <Button type="button" variant="outline" onClick={() => { setEditando(null); setErrors({}); }}>
                                        Cancelar
                                    </Button>
                                </form>
                            ) : (
                                <>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-on-surface">
                                            {e.nome} <span className="text-on-surface-variant font-normal">({e.ano})</span>
                                            {e.padrao && (
                                                <span className="ml-2 text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">
                                                    padrão
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-xs text-on-surface-variant">
                                            {e.projetos} {e.projetos === 1 ? 'projeto' : 'projetos'}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button type="button" variant="outline" onClick={() => { setEditando({ id: e.id, nome: e.nome, ano: e.ano }); setErrors({}); }}>
                                            <span className="material-symbols-outlined text-[20px]">edit</span>
                                            Renomear
                                        </Button>
                                        {!e.padrao && (
                                            <Button type="button" variant="outline" onClick={() => tornarPadrao(e)}>
                                                <span className="material-symbols-outlined text-[20px]">star</span>
                                                Tornar padrão
                                            </Button>
                                        )}
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
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {confirmDialog}
        </AppShell>
    );
}
