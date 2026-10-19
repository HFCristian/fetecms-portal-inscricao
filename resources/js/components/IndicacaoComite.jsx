import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert, Button, Select } from './ui.jsx';
import BuscaCombobox from './BuscaCombobox.jsx';
import { comCodigo } from './CartaoCredencial.jsx';
import StatusRecomendacao from './StatusRecomendacao.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getComiteAvaliador, recomendarProjeto, retirarRecomendacao } from '../lib/avaliador.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

/**
 * **Indicar para premiação**, no topo da Avaliação presencial (Sprint 246) —
 * só para a comissão especial.
 *
 * É a aba Comitê especial (Sprint 193) em forma compacta: quem está no
 * corredor avaliando indica o finalista na hora, sem trocar de tela. Uma
 * credencial, um finalista, a justificativa opcional; embaixo, o que ele já
 * indicou, com a decisão da organização. A visão completa (todas as
 * credenciais com as informações adicionais e o painel) continua na aba.
 */
export default function IndicacaoComite() {
    const [dados, setDados] = useState(null);
    const [credencialId, setCredencialId] = useState('');
    const [projeto, setProjeto] = useState(null);
    const [justificativa, setJustificativa] = useState('');
    const [ocupado, setOcupado] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback(() => getComiteAvaliador()
        .then(setDados)
        .catch((e) => {
            setDados({ credenciais: [], candidatos: [], recomendacoes: [] });
            setAlerta(extractErrors(e).message || 'Não foi possível carregar as indicações.');
        }), []);

    useEffect(() => { carregar(); }, [carregar]);

    const credenciais = dados?.credenciais ?? [];
    const candidatos = dados?.candidatos ?? [];
    const recomendacoes = dados?.recomendacoes ?? [];
    const credencial = credenciais.find((c) => String(c.id) === String(credencialId)) ?? null;
    const nomeDaCredencial = (id) => credenciais.find((c) => c.id === id)?.nome ?? '—';

    // O que ele já indicou para esta credencial não volta como opção.
    const jaIndicados = new Set(recomendacoes.filter((r) => r.credencial_id === credencial?.id).map((r) => r.projeto_id));
    const opcoes = candidatos
        .filter((c) => !jaIndicados.has(c.id))
        .map((c) => ({ id: c.id, nome: comCodigo(c.codigo, c.titulo), detalhe: [c.categoria, c.area].filter(Boolean).join(' · ') }));

    async function acao(fn, mensagem) {
        setOcupado(true); setAlerta(''); setSucesso('');
        try {
            const resp = await fn();
            setDados(resp.data);
            setSucesso(mensagem);
            return true;
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
            return false;
        } finally {
            setOcupado(false);
        }
    }

    async function indicar() {
        const ok = await acao(
            () => recomendarProjeto(credencial.id, projeto.id, justificativa.trim() || null),
            `${projeto.nome} indicado para ${credencial.nome}.`,
        );
        if (ok) { setProjeto(null); setJustificativa(''); }
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 border-l-4 border-primary-container" aria-label="Indicar para premiação">
            <div className="flex flex-wrap items-start justify-between gap-2 mb-1">
                <h2 className="font-display text-base font-semibold text-on-surface">
                    <span className="material-symbols-outlined text-[20px] align-[-0.2em] text-primary-container mr-1">workspace_premium</span>
                    Indicar para premiação
                </h2>
                <Link to="/avaliador/comite" className="text-sm font-semibold text-primary hover:underline">
                    Ver todas as credenciais
                </Link>
            </div>
            <p className="text-xs text-on-surface-variant mb-3">
                Você é da <strong>comissão especial</strong>: se quiser, indique finalistas para as credenciais
                e os prêmios da feira. A organização aprova ou rejeita cada indicação.
            </p>

            <div className="space-y-2">
                <Alert>{alerta}</Alert>
                <Alert type="info">{sucesso}</Alert>
            </div>

            {dados === null ? (
                <p className="text-sm text-on-surface-variant">Carregando…</p>
            ) : credenciais.length === 0 ? (
                <p className="text-sm text-on-surface-variant">
                    A organização ainda não cadastrou credenciais ou prêmios para indicar.
                </p>
            ) : candidatos.length === 0 ? (
                <p className="text-sm text-on-surface-variant">
                    A lista final ainda não foi publicada: sem finalistas, não há projeto para indicar.
                </p>
            ) : (
                <div className="grid gap-2 md:grid-cols-2">
                    <Select
                        aria-label="Credencial ou prêmio"
                        value={credencialId}
                        onChange={(e) => { setCredencialId(e.target.value); setProjeto(null); }}
                    >
                        <option value="">Escolha a credencial ou o prêmio…</option>
                        {credenciais.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.nome}{c.orgao ? ` · ${c.orgao}` : ''}
                            </option>
                        ))}
                    </Select>
                    <BuscaCombobox
                        options={opcoes}
                        value={projeto}
                        onChange={setProjeto}
                        disabled={!credencial}
                        placeholder="Buscar finalista pelo código ou título…"
                        vazio="Nenhum finalista disponível"
                    />
                    <textarea
                        rows={2}
                        maxLength={2000}
                        className={`${campoClass} md:col-span-2`}
                        aria-label="Por que indicar este projeto (opcional)"
                        placeholder="Por que este projeto? (opcional)"
                        value={justificativa}
                        onChange={(e) => setJustificativa(e.target.value)}
                    />
                    <div className="md:col-span-2 flex justify-end">
                        <Button type="button" disabled={!credencial || !projeto || ocupado} onClick={indicar}>
                            <span className="material-symbols-outlined text-[20px]">thumb_up</span>
                            Indicar
                        </Button>
                    </div>
                </div>
            )}

            {recomendacoes.length > 0 && (
                <details className="mt-3">
                    <summary className="text-sm font-semibold text-on-surface cursor-pointer">
                        Suas indicações ({recomendacoes.length})
                    </summary>
                    <ul className="mt-1 divide-y divide-outline-variant/30">
                        {recomendacoes.map((r) => (
                            <li key={r.id} className="py-2 flex flex-wrap items-center gap-2">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm text-on-surface">
                                        {r.codigo && <code className="text-xs text-primary-container mr-1">{r.codigo}</code>}
                                        {r.projeto}
                                    </p>
                                    <p className="text-xs text-on-surface-variant">Para {nomeDaCredencial(r.credencial_id)}</p>
                                </div>
                                <StatusRecomendacao status={r.status} label={r.status_label} />
                                {r.status === 'pendente' && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={ocupado}
                                        onClick={() => acao(() => retirarRecomendacao(r.id), 'Indicação retirada.')}
                                    >
                                        Retirar
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}
