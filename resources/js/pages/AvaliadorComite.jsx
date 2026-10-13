import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import BuscaCombobox from '../components/BuscaCombobox.jsx';
import { PilulaTipo, InformacoesAdicionais } from '../components/CartaoCredencial.jsx';
import StatusRecomendacao from '../components/StatusRecomendacao.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getComiteAvaliador, recomendarProjeto, retirarRecomendacao } from '../lib/avaliador.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

/** Uma credencial: o que é, o campo para recomendar e o que ele já recomendou para ela. */
function CartaoRecomendacao({ credencial, candidatos, minhas, ocupado, onRecomendar, onRetirar }) {
    const [projeto, setProjeto] = useState(null);
    const [justificativa, setJustificativa] = useState('');

    const jaRecomendados = new Set(minhas.map((r) => r.projeto_id));
    const opcoes = candidatos
        .filter((c) => !jaRecomendados.has(c.id))
        .map((c) => ({ id: c.id, nome: c.titulo, detalhe: [c.categoria, c.area].filter(Boolean).join(' · ') }));

    async function enviar() {
        const ok = await onRecomendar(credencial, projeto, justificativa);
        if (ok) { setProjeto(null); setJustificativa(''); }
    }

    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
            <PilulaTipo tipo={credencial.tipo} label={credencial.tipo_label} />
            <p className="font-semibold text-on-surface mt-1">
                {credencial.nome}
                {credencial.orgao && <span className="text-on-surface-variant font-normal"> · {credencial.orgao}</span>}
            </p>
            {credencial.descricao && <p className="text-xs text-on-surface-variant mt-0.5">{credencial.descricao}</p>}
            <p className="text-xs text-on-surface-variant">
                {credencial.vagas === null ? 'Sem limite de vagas' : `${credencial.vagas} vaga(s)`}
            </p>

            <InformacoesAdicionais credencial={credencial} />

            <div className="mt-3 space-y-2">
                <BuscaCombobox
                    options={opcoes}
                    value={projeto}
                    onChange={setProjeto}
                    placeholder="Buscar entre os finalistas…"
                    vazio="Nenhum finalista disponível"
                />
                <textarea
                    rows={2}
                    maxLength={2000}
                    className={campoClass}
                    aria-label={`Por que recomendar para ${credencial.nome} (opcional)`}
                    placeholder="Por que este projeto? (opcional)"
                    value={justificativa}
                    onChange={(e) => setJustificativa(e.target.value)}
                />
                <div className="flex justify-end">
                    <Button type="button" disabled={!projeto || ocupado} onClick={enviar}>
                        <span className="material-symbols-outlined text-[20px]">thumb_up</span>
                        Recomendar
                    </Button>
                </div>
            </div>

            {minhas.length > 0 && (
                <div className="mt-3">
                    <p className="text-xs font-semibold text-on-surface mb-1">Suas recomendações</p>
                    <ul className="divide-y divide-outline-variant/30">
                        {minhas.map((r) => (
                            <li key={r.id} className="py-2 flex flex-wrap items-start gap-2">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm text-on-surface">{r.projeto}</p>
                                    <p className="text-xs text-on-surface-variant">{[r.categoria, r.area].filter(Boolean).join(' · ')}</p>
                                    {r.justificativa && (
                                        <p className="text-xs text-on-surface-variant whitespace-pre-line mt-0.5">{r.justificativa}</p>
                                    )}
                                </div>
                                <StatusRecomendacao status={r.status} label={r.status_label} />
                                {r.status === 'pendente' && (
                                    <Button type="button" variant="outline" disabled={ocupado} onClick={() => onRetirar(r)}>
                                        Retirar
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </li>
    );
}

/**
 * Aba **Comitê especial** do avaliador (Sprint 193) — só para quem a
 * organização marcou como comissão especial.
 *
 * Ele recomenda finalistas para as credenciais e os prêmios da feira, quantos
 * quiser, com uma justificativa opcional. A organização aprova ou rejeita, e a
 * situação de cada recomendação aparece aqui; enquanto ninguém decidiu, ele
 * pode retirá-la.
 */
export default function AvaliadorComite() {
    const [dados, setDados] = useState(null);
    const [ocupado, setOcupado] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback(() => getComiteAvaliador()
        .then(setDados)
        .catch((e) => { setDados({ credenciais: [], candidatos: [], recomendacoes: [] }); setAlerta(extractErrors(e).message || 'Não foi possível carregar a aba do comitê.'); }), []);

    useEffect(() => { carregar(); }, [carregar]);

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

    const credenciais = dados?.credenciais ?? [];
    const candidatos = dados?.candidatos ?? [];
    const recomendacoes = dados?.recomendacoes ?? [];

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Comitê especial</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Recomende projetos finalistas para as credenciais e os prêmios da feira — quantos quiser. A
                organização aprova ou rejeita cada recomendação, e a decisão aparece aqui.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : credenciais.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    A organização ainda não cadastrou credenciais ou prêmios para recomendar.
                </div>
            ) : (
                <>
                    {candidatos.length === 0 && (
                        <div className="mb-4 max-w-3xl">
                            <Alert type="info">A lista final ainda não foi publicada: sem finalistas, não há projeto para recomendar.</Alert>
                        </div>
                    )}
                    <ul className="space-y-3 max-w-3xl">
                        {credenciais.map((c) => (
                            <CartaoRecomendacao
                                key={c.id}
                                credencial={c}
                                candidatos={candidatos}
                                minhas={recomendacoes.filter((r) => r.credencial_id === c.id)}
                                ocupado={ocupado}
                                onRecomendar={(cred, projeto, justificativa) => acao(
                                    () => recomendarProjeto(cred.id, projeto.id, justificativa.trim() || null),
                                    `${projeto.nome} recomendado para ${cred.nome}.`,
                                )}
                                onRetirar={(r) => acao(() => retirarRecomendacao(r.id), 'Recomendação retirada.')}
                            />
                        ))}
                    </ul>
                </>
            )}
        </AppShell>
    );
}
