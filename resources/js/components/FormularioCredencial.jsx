import { useState } from 'react';
import { Alert, Button, Field, Input } from './ui.jsx';

export const CREDENCIAL_VAZIA = { tipo: 'credencial', nome: '', orgao: '', vagas: '', descricao: '', informacoes: '' };

/** Os campos do formulário a partir do cartão que a API devolve. */
export function camposDaCredencial(c) {
    return {
        tipo: c.tipo,
        nome: c.nome ?? '',
        orgao: c.orgao ?? '',
        vagas: c.vagas === null || c.vagas === undefined ? '' : String(c.vagas),
        descricao: c.descricao ?? '',
        informacoes: c.informacoes ?? '',
    };
}

const classeCaixa = 'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

/**
 * O formulário de uma credencial ou prêmio: o mesmo no cadastro e na edição.
 *
 * Na edição, `usadas` é quantos projetos já receberam o item. É o piso das
 * vagas — abaixo dele uma premiação dada sumiria da conta (o servidor confere
 * de novo) —, e é o que faz a troca de tipo pedir atenção: credencial conta no
 * card de credenciais a separar do Cerimonial, prêmio não.
 */
export default function FormularioCredencial({
    inicial = CREDENCIAL_VAZIA, tipos, erros = {}, ocupado = false,
    titulo, rotuloSalvar, usadas = 0, tipoOriginal = null, onSalvar, onCancelar,
}) {
    const [form, setForm] = useState(inicial);
    const campo = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    const minimo = Math.max(1, usadas);
    const vagasAbaixo = form.vagas !== '' && Number(form.vagas) < usadas;
    const erroVagas = vagasAbaixo
        ? `As vagas não podem ser menos que ${usadas}: ${usadas} projeto(s) já receberam este item.`
        : erros.vagas;
    const trocouTipo = tipoOriginal !== null && usadas > 0 && form.tipo !== tipoOriginal;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (vagasAbaixo) return;
                onSalvar({
                    tipo: form.tipo,
                    nome: form.nome.trim(),
                    orgao: form.orgao.trim() || null,
                    descricao: form.descricao.trim() || null,
                    informacoes: form.informacoes.trim() || null,
                    vagas: form.vagas === '' ? null : Number(form.vagas),
                });
            }}
        >
            {titulo && <h2 className="font-display text-primary font-semibold mb-3">{titulo}</h2>}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field
                    label="Tipo"
                    error={erros.tipo}
                    hint={tipos.find((t) => t.value === form.tipo)?.descricao}
                >
                    <select aria-label="Tipo" value={form.tipo} onChange={campo('tipo')} className={classeCaixa}>
                        {tipos.map((t) => (
                            <option key={t.value} value={t.value}>{t.label}</option>
                        ))}
                    </select>
                </Field>
                <Field label="Nome" error={erros.nome}>
                    <Input aria-label="Nome" value={form.nome} onChange={campo('nome')} error={erros.nome} placeholder="MOSTRATEC 2027" />
                </Field>
                <Field label="Órgão" error={erros.orgao}>
                    <Input aria-label="Órgão" value={form.orgao} onChange={campo('orgao')} error={erros.orgao} placeholder="FUNDECT" />
                </Field>
                <Field
                    label="Vagas"
                    error={erroVagas}
                    hint={usadas > 0
                        ? `Em branco, sem limite. Mínimo de ${usadas}: os projetos que já receberam.`
                        : 'Em branco, sem limite.'}
                >
                    <Input type="number" min={minimo} max="999" aria-label="Vagas" value={form.vagas} onChange={campo('vagas')} error={erroVagas} />
                </Field>
                <Field label="Descrição" error={erros.descricao}>
                    <Input aria-label="Descrição" value={form.descricao} onChange={campo('descricao')} error={erros.descricao} />
                </Field>
                <div className="sm:col-span-2">
                    <Field
                        label="Informações adicionais"
                        error={erros.informacoes}
                        hint="Critérios, regras do órgão, o que a vaga cobre. Os avaliadores do comitê leem ao recomendar."
                    >
                        <textarea
                            aria-label="Informações adicionais"
                            rows={4}
                            maxLength={5000}
                            value={form.informacoes}
                            onChange={campo('informacoes')}
                            className={classeCaixa}
                        />
                    </Field>
                </div>
            </div>
            {trocouTipo && (
                <div className="mt-4">
                    <Alert type="warning">
                        Este item já foi dado a {usadas} projeto(s).{' '}
                        {form.tipo === 'premio'
                            ? 'Como prêmio, ele sai do card de credenciais a separar do Cerimonial; os projetos continuam premiados.'
                            : 'Como credencial, ele passa a contar no card de credenciais a separar do Cerimonial.'}
                    </Alert>
                </div>
            )}
            <div className="flex justify-end gap-2 mt-4">
                <Button type="button" variant="outline" disabled={ocupado} onClick={onCancelar}>
                    Cancelar
                </Button>
                <Button type="submit" loading={ocupado} disabled={form.nome.trim() === '' || vagasAbaixo}>
                    {rotuloSalvar}
                </Button>
            </div>
        </form>
    );
}
