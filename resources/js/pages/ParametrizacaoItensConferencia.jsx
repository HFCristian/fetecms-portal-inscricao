import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getItensConferencia, criarItemConferencia, atualizarItemConferencia, excluirItemConferencia,
} from '../lib/itensConferencia.js';

const VAZIO = {
    nome: '', descricao: '', abrangencia: 'pessoa', papeis: ['aluno'],
    no_credenciamento: true, na_checagem: false, receber_antes: false,
};

const pill = 'text-[11px] font-semibold px-2 py-0.5 rounded-full';

/** Caixa de marcação com rótulo — o formulário tem muitas. */
function Marca({ checked, onChange, disabled, children }) {
    return (
        <label className={`flex items-center gap-2 text-sm ${disabled ? 'opacity-60' : 'cursor-pointer'}`}>
            <input type="checkbox" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
            {children}
        </label>
    );
}

/**
 * O formulário de um item, para criar e para editar. Item do sistema (os dois
 * termos) não muda de abrangência nem deixa de ser recebido antes — o portal
 * depende disso —, então esses campos ficam travados.
 */
function FormItem({ inicial, papeis, sistema = false, salvando, onSalvar, onCancelar, rotulo }) {
    const [form, setForm] = useState(inicial);
    const campo = (k, v) => setForm((f) => ({ ...f, [k]: v }));
    const alternarPapel = (p) => campo('papeis', form.papeis.includes(p) ? form.papeis.filter((x) => x !== p) : [...form.papeis, p]);
    const pessoa = form.abrangencia === 'pessoa';
    const valido = form.nome.trim() !== ''
        && (form.no_credenciamento || form.na_checagem)
        && (!pessoa || form.papeis.length > 0);

    return (
        <form
            className="space-y-3"
            onSubmit={(e) => {
                e.preventDefault();
                onSalvar({ ...form, nome: form.nome.trim(), descricao: form.descricao.trim() || null, papeis: pessoa ? form.papeis : [] });
            }}
        >
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Input value={form.nome} onChange={(e) => campo('nome', e.target.value)} placeholder="Nome do item (ex.: Documento com foto)" aria-label="Nome do item" maxLength={120} />
                <Input value={form.descricao} onChange={(e) => campo('descricao', e.target.value)} placeholder="Descrição (opcional)" aria-label="Descrição do item" maxLength={255} />
            </div>

            <fieldset className="space-y-1">
                <legend className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">De quem é</legend>
                <div className="flex flex-wrap gap-4 text-sm">
                    {[['pessoa', 'De cada pessoa'], ['projeto', 'Do projeto']].map(([valor, texto]) => (
                        <label key={valor} className={`flex items-center gap-2 ${sistema ? 'opacity-60' : 'cursor-pointer'}`}>
                            <input type="radio" name={`abrangencia-${rotulo}`} value={valor} disabled={sistema}
                                checked={form.abrangencia === valor} onChange={() => campo('abrangencia', valor)} />
                            {texto}
                        </label>
                    ))}
                </div>
                {pessoa && (
                    <div className="flex flex-wrap gap-4 pl-1 pt-1">
                        {papeis.map((p) => (
                            <Marca key={p.value} checked={form.papeis.includes(p.value)} disabled={sistema} onChange={() => alternarPapel(p.value)}>
                                {p.label}
                            </Marca>
                        ))}
                    </div>
                )}
            </fieldset>

            <fieldset className="space-y-1">
                <legend className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">Onde é conferido</legend>
                <div className="flex flex-wrap gap-4">
                    <Marca checked={form.no_credenciamento} onChange={(v) => campo('no_credenciamento', v)}>No credenciamento</Marca>
                    <Marca checked={form.na_checagem} onChange={(v) => campo('na_checagem', v)}>Na checagem do estande</Marca>
                </div>
            </fieldset>

            <Marca checked={form.receber_antes} disabled={sistema} onChange={(v) => campo('receber_antes', v)}>
                Receber antes do orientador — ele envia pela aba Documentos, e o item já chega marcado no balcão
            </Marca>

            <div className="flex gap-2">
                <Button type="submit" loading={salvando} disabled={!valido}>{onCancelar ? 'Salvar' : 'Adicionar item'}</Button>
                {onCancelar && <Button type="button" variant="outline" disabled={salvando} onClick={onCancelar}>Cancelar</Button>}
            </div>
        </form>
    );
}

/** As etiquetas de um item: de quem é, onde é conferido e se vem antes. */
function Etiquetas({ item, papeis }) {
    const rotuloPapel = Object.fromEntries(papeis.map((p) => [p.value, p.label]));

    return (
        <div className="flex flex-wrap gap-1 mt-1">
            <span className={`${pill} bg-surface-variant text-on-surface-variant`}>
                {item.abrangencia === 'projeto' ? 'Do projeto' : item.papeis.map((p) => rotuloPapel[p] ?? p).join(', ')}
            </span>
            {item.no_credenciamento && <span className={`${pill} bg-primary-fixed text-primary-container`}>Credenciamento</span>}
            {item.na_checagem && <span className={`${pill} bg-primary-fixed text-primary-container`}>Checagem</span>}
            {item.receber_antes && <span className={`${pill} bg-secondary-container text-on-secondary-container`}>Recebido antes</span>}
            {item.chave && <span className={`${pill} border border-outline-variant text-on-surface-variant`}>do sistema</span>}
        </div>
    );
}

/**
 * Parametrização → **Itens do credenciamento e da checagem** (Sprint 181).
 *
 * Uma lista só para os dois balcões. Cada item diz de quem é — de cada pessoa
 * (com os papéis) ou do projeto —, onde é conferido — no credenciamento, na
 * checagem do estande ou nos dois — e se a organização quer recebê-lo antes,
 * enviado pelo orientador na aba Documentos. Item já conferido ou com arquivo
 * enviado não é excluído: desative-o.
 */
export default function ParametrizacaoItensConferencia() {
    const [dados, setDados] = useState(null);
    const [editando, setEditando] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [chaveNovo, setChaveNovo] = useState(0);
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => {
        getItensConferencia().then(setDados).catch(() => setAlerta('Não foi possível carregar os itens.'));
    }, []);

    async function executar(fn, depois) {
        setSalvando(true); setAlerta(''); setSucesso('');
        try {
            const resp = await fn();
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? '');
            depois?.();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
        } finally {
            setSalvando(false);
        }
    }

    async function excluir(item) {
        const ok = await confirm({
            title: 'Excluir item',
            message: `Excluir "${item.nome}" da lista?`,
            confirmLabel: 'Excluir',
            danger: true,
        });
        if (ok) executar(() => excluirItemConferencia(item.id));
    }

    const itens = dados?.itens ?? [];
    const papeis = dados?.papeis ?? [];

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Itens do credenciamento e da checagem</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Uma lista só para o balcão do credenciamento e para a checagem dos estandes. Cada item diz
                de quem é (de cada pessoa ou do projeto), onde é conferido — num lugar ou nos dois — e se a
                organização quer <strong>recebê-lo antes</strong>: o orientador envia pela aba Documentos e o
                item já chega marcado, para o atendente só conferir.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
                <h2 className="font-display text-primary font-semibold mb-3">Novo item</h2>
                <FormItem
                    key={chaveNovo}
                    rotulo="novo"
                    inicial={VAZIO}
                    papeis={papeis}
                    salvando={salvando}
                    onSalvar={(form) => executar(() => criarItemConferencia(form), () => setChaveNovo((k) => k + 1))}
                />
            </section>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : itens.length === 0 ? (
                <p className="text-sm text-on-surface-variant max-w-3xl">Nenhum item cadastrado ainda.</p>
            ) : (
                <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40 max-w-3xl">
                    {itens.map((item) => (
                        <li key={item.id} className="p-4">
                            {editando === item.id ? (
                                <FormItem
                                    rotulo={`item-${item.id}`}
                                    inicial={{ ...VAZIO, ...item, descricao: item.descricao ?? '', papeis: item.papeis ?? [] }}
                                    papeis={papeis}
                                    sistema={Boolean(item.chave)}
                                    salvando={salvando}
                                    onSalvar={(form) => executar(() => atualizarItemConferencia(item.id, form), () => setEditando(null))}
                                    onCancelar={() => setEditando(null)}
                                />
                            ) : (
                                <div className="flex flex-col sm:flex-row sm:items-start gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className={`text-sm font-semibold ${item.ativo ? 'text-on-surface' : 'text-on-surface-variant line-through'}`}>
                                            {item.nome}
                                        </p>
                                        {item.descricao && <p className="text-xs text-on-surface-variant">{item.descricao}</p>}
                                        <Etiquetas item={item} papeis={papeis} />
                                    </div>
                                    <div className="flex flex-wrap gap-2 shrink-0">
                                        <Button type="button" variant="outline" disabled={salvando} onClick={() => setEditando(item.id)} aria-label={`Editar ${item.nome}`}>
                                            <span className="material-symbols-outlined text-[20px]">edit</span>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={salvando}
                                            onClick={() => executar(() => atualizarItemConferencia(item.id, { ativo: !item.ativo }))}
                                        >
                                            {item.ativo ? 'Desativar' : 'Reativar'}
                                        </Button>
                                        {!item.chave && !item.em_uso && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="text-error border-error/40 hover:bg-error-container/40"
                                                disabled={salvando}
                                                onClick={() => excluir(item)}
                                                aria-label={`Excluir ${item.nome}`}
                                            >
                                                <span className="material-symbols-outlined text-[20px]">delete</span>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {confirmDialog}
        </AppShell>
    );
}
