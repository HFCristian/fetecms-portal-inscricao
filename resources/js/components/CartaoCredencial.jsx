import { useState } from 'react';
import { Button } from './ui.jsx';
import BuscaCombobox from './BuscaCombobox.jsx';

/**
 * Credencial ou prêmio? A pílula não é decoração: no cerimonial, a
 * **credencial** é objeto a separar na mesa e entra no card de credenciais a
 * separar; o **prêmio** faz o projeto ser premiado (e render medalha) sem
 * entrar naquela pilha.
 */
export function PilulaTipo({ tipo, label }) {
    const premio = tipo === 'premio';
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold shrink-0 ${
                premio
                    ? 'bg-tertiary-container/60 text-on-surface'
                    : 'bg-primary-fixed text-primary-container'
            }`}
        >
            <span className="material-symbols-outlined text-[14px]">
                {premio ? 'emoji_events' : 'badge'}
            </span>
            {label}
        </span>
    );
}

/** Quantas vagas ainda cabem — "—" quando a credencial não tem teto. */
export function Vagas({ credencial }) {
    if (credencial.vagas === null) {
        return (
            <span className="text-xs text-on-surface-variant">
                {credencial.usadas} atribuída(s) · sem limite de vagas
            </span>
        );
    }

    const esgotada = credencial.disponiveis === 0;

    return (
        <span className={`text-xs font-semibold ${esgotada ? 'text-error' : 'text-on-surface-variant'}`}>
            {credencial.usadas} de {credencial.vagas} vaga(s)
            {esgotada ? ' · esgotada' : ` · ${credencial.disponiveis} disponível(is)`}
        </span>
    );
}

/**
 * As informações adicionais da credencial (Sprint 192): o texto longo da
 * organização — critérios, regras do órgão —, que o comitê lê ao recomendar.
 * Quem passa `onSalvar` (a Avaliação presencial, que cadastra) também edita.
 */
export function InformacoesAdicionais({ credencial, ocupado = false, onSalvar = null }) {
    const [editando, setEditando] = useState(false);
    const [texto, setTexto] = useState(credencial.informacoes ?? '');

    if (editando) {
        return (
            <div className="mt-3">
                <label className="block text-xs font-semibold text-on-surface mb-1" htmlFor={`informacoes-${credencial.id}`}>
                    Informações adicionais
                </label>
                <textarea
                    id={`informacoes-${credencial.id}`}
                    rows={4}
                    maxLength={5000}
                    value={texto}
                    onChange={(e) => setTexto(e.target.value)}
                    className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                />
                <div className="flex justify-end gap-2 mt-2">
                    <Button type="button" variant="outline" disabled={ocupado} onClick={() => { setTexto(credencial.informacoes ?? ''); setEditando(false); }}>
                        Cancelar
                    </Button>
                    <Button type="button" disabled={ocupado} onClick={async () => { await onSalvar(credencial, texto); setEditando(false); }}>
                        Salvar informações
                    </Button>
                </div>
            </div>
        );
    }

    if (!credencial.informacoes && !onSalvar) return null;

    return (
        <div className="mt-3 rounded-lg bg-surface-container-low px-3 py-2">
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-semibold text-on-surface">Informações adicionais</p>
                {onSalvar && (
                    <button
                        type="button"
                        className="text-xs text-primary hover:underline disabled:opacity-50"
                        disabled={ocupado}
                        onClick={() => { setTexto(credencial.informacoes ?? ''); setEditando(true); }}
                    >
                        {credencial.informacoes ? 'Editar' : 'Adicionar'}
                    </button>
                )}
            </div>
            {credencial.informacoes
                ? <p className="text-sm text-on-surface-variant whitespace-pre-line mt-1">{credencial.informacoes}</p>
                : <p className="text-xs text-on-surface-variant mt-1">Nenhuma informação adicional.</p>}
        </div>
    );
}

/**
 * Uma credencial: o que é, quem já recebeu e o campo para anexar mais um.
 *
 * Serve às duas telas que mexem na mesma base (`credencial_projeto`): a de
 * Avaliação presencial, que também **cadastra** — e por isso passa
 * `onAlternar`/`onExcluir` —, e a do Comitê especial (Sprint 190), que só
 * **atribui**: sem os dois, os botões de cadastro não aparecem.
 */
export default function CartaoCredencial({
    credencial, candidatos, ocupado, onAtribuir, onRetirar, onAlternar = null, onExcluir = null,
    onSalvarInformacoes = null, rotuloAnexar = 'Anexar',
}) {
    const [projeto, setProjeto] = useState(null);

    const opcoes = candidatos
        .filter((c) => !credencial.projetos.some((p) => p.id === c.id))
        .map((c) => ({
            id: c.id,
            nome: c.titulo,
            detalhe: [c.categoria, c.area, c.credenciais.length ? `já tem: ${c.credenciais.join(', ')}` : null]
                .filter(Boolean).join(' · '),
        }));

    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <PilulaTipo tipo={credencial.tipo} label={credencial.tipo_label} />
                    </div>
                    <p className={`font-semibold mt-1 ${credencial.ativa ? 'text-on-surface' : 'text-on-surface-variant line-through'}`}>
                        {credencial.nome}
                        {credencial.orgao && <span className="text-on-surface-variant font-normal"> · {credencial.orgao}</span>}
                    </p>
                    {credencial.descricao && (
                        <p className="text-xs text-on-surface-variant mt-0.5">{credencial.descricao}</p>
                    )}
                    <Vagas credencial={credencial} />
                </div>
                <div className="flex gap-2 shrink-0">
                    {onAlternar && (
                        <Button type="button" variant="outline" disabled={ocupado} onClick={() => onAlternar(credencial)}>
                            {credencial.ativa ? 'Desativar' : 'Reativar'}
                        </Button>
                    )}
                    {onExcluir && credencial.usadas === 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            className="text-error border-error/40 hover:bg-error-container/40"
                            disabled={ocupado}
                            onClick={() => onExcluir(credencial)}
                        >
                            Excluir
                        </Button>
                    )}
                </div>
            </div>

            <InformacoesAdicionais credencial={credencial} ocupado={ocupado} onSalvar={onSalvarInformacoes} />

            {credencial.projetos.length > 0 && (
                <ul className="mt-3 divide-y divide-outline-variant/30">
                    {credencial.projetos.map((p) => (
                        <li key={p.id} className="py-2 flex flex-wrap items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm text-on-surface truncate">{p.titulo}</p>
                                <p className="text-xs text-on-surface-variant">
                                    {[p.categoria, p.area].filter(Boolean).join(' · ')}
                                    {p.observacao ? ` · ${p.observacao}` : ''}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={ocupado}
                                onClick={() => onRetirar(credencial, p)}
                            >
                                Retirar
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {credencial.ativa && (
                <div className="mt-3 flex flex-col sm:flex-row gap-2 sm:items-center">
                    <div className="flex-1">
                        <BuscaCombobox
                            options={opcoes}
                            value={projeto}
                            onChange={setProjeto}
                            placeholder="Buscar entre os finalistas…"
                            vazio="Nenhum finalista disponível"
                        />
                    </div>
                    <Button
                        type="button"
                        disabled={!projeto || ocupado}
                        onClick={() => { onAtribuir(credencial, projeto); setProjeto(null); }}
                    >
                        <span className="material-symbols-outlined text-[20px]">workspace_premium</span>
                        {rotuloAnexar}
                    </Button>
                </div>
            )}
        </li>
    );
}
