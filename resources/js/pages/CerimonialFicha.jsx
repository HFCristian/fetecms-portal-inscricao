import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { desfazerCheckin, getFichaCerimonial, registrarCheckin } from '../lib/cerimonial.js';
import { useModoTeste } from '../lib/modoTeste.js';

/** O busto genérico: verde para quem entrou, cinza para quem ainda não. */
export function IconePessoa({ presente, tamanho = 40 }) {
    return (
        <span
            className={`material-symbols-outlined shrink-0 ${presente ? 'text-green-700' : 'text-on-surface-variant/50'}`}
            style={{ fontSize: `${tamanho}px`, lineHeight: 1 }}
            aria-hidden="true"
        >
            account_circle
        </span>
    );
}

/**
 * Cerimonial → ficha de um projeto.
 *
 * A equipe chega junta, então a unidade da tela é o **projeto** e o ato é
 * marcar de uma vez quem está ali. O check-in continua sendo **por pessoa**:
 * é gente que entra na sala e é gente que recebe medalha.
 *
 * Desfazer pede **justificativa** porque o número de check-ins decide quantas
 * medalhas e quantas credenciais saem da mesa — apagar um é mexer no que vai
 * ser entregue no palco.
 *
 * O credenciamento aparece como **informação**: a cerimônia é outro momento, e
 * quem não passou pelo balcão entra do mesmo jeito.
 */
export default function CerimonialFicha() {
    const { id } = useParams();
    const [teste] = useModoTeste();
    const [ficha, setFicha] = useState(null);
    const [config, setConfig] = useState(null);
    const [marcadas, setMarcadas] = useState([]);
    const [ocupado, setOcupado] = useState(false);
    const [erro, setErro] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [desfazendo, setDesfazendo] = useState(null);
    const [justificativa, setJustificativa] = useState('');

    const carregar = useCallback(() => getFichaCerimonial(id, teste)
        .then((r) => { setFicha(r.data); setConfig(r.meta?.config ?? null); })
        .catch((e) => {
            const { message } = extractErrors(e);
            setErro(message || 'Não foi possível abrir este projeto.');
            setFicha(false);
        }), [id, teste]);

    useEffect(() => { carregar(); }, [carregar]);

    const aberto = config?.aberto !== false;
    const ausentes = (ficha?.pessoas ?? []).filter((p) => !p.presente);

    function alternar(chave) {
        setMarcadas((m) => (m.includes(chave) ? m.filter((c) => c !== chave) : [...m, chave]));
    }

    async function confirmar() {
        if (marcadas.length === 0) return;
        setOcupado(true); setErro(''); setSucesso('');
        try {
            const atualizada = await registrarCheckin(id, marcadas, teste);
            setFicha(atualizada);
            setSucesso(
                marcadas.length === 1
                    ? 'Check-in registrado.'
                    : `${marcadas.length} check-ins registrados.`,
            );
            setMarcadas([]);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível registrar o check-in.');
        } finally {
            setOcupado(false);
        }
    }

    async function confirmarDesfazer() {
        setOcupado(true); setErro(''); setSucesso('');
        try {
            const atualizada = await desfazerCheckin(id, desfazendo.chave, justificativa, teste);
            setFicha(atualizada);
            setSucesso(`Check-in de ${desfazendo.nome} desfeito.`);
            setDesfazendo(null);
            setJustificativa('');
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível desfazer.');
        } finally {
            setOcupado(false);
        }
    }

    if (ficha === null) {
        return (
            <AppShell>
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            </AppShell>
        );
    }

    if (ficha === false) {
        return (
            <AppShell>
                <Link to="/admin/cerimonial/checkin" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                    <span className="material-symbols-outlined text-[18px]">arrow_back</span> Check-in
                </Link>
                <Alert>{erro}</Alert>
            </AppShell>
        );
    }

    return (
        <AppShell>
            <Link to="/admin/cerimonial/checkin" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Check-in
            </Link>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">{ficha.projeto.titulo}</h1>
            <p className="text-on-surface-variant mb-1">
                {[ficha.projeto.categoria, ficha.projeto.area].filter(Boolean).join(' · ')}
            </p>
            <p className="text-sm text-on-surface-variant mb-4">{ficha.projeto.escola}</p>

            <div className="flex flex-wrap items-center gap-2 mb-4">
                <span className="text-sm font-semibold text-on-surface">
                    {ficha.presentes} de {ficha.total} presente(s)
                </span>
                {ficha.premiacoes.length > 0 && ficha.premiacoes.map((p) => (
                    <span
                        key={p.id}
                        className="inline-flex items-center gap-1 rounded-full bg-primary-fixed text-primary-container px-2 py-0.5 text-[11px] font-semibold"
                    >
                        <span className="material-symbols-outlined text-[14px]">
                            {p.tipo === 'premio' ? 'emoji_events' : 'badge'}
                        </span>
                        {p.nome}
                    </span>
                ))}
            </div>

            {!ficha.credenciado && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        Este projeto <strong>ainda não passou pelo credenciamento</strong>. A
                        cerimônia é outro momento, então isso não impede o check-in — é só para
                        você saber.
                    </Alert>
                </div>
            )}

            {!aberto && (
                <div className="mb-4 max-w-3xl">
                    <Alert>
                        Fora do período do evento a ficha abre só para consulta: nada é registrado
                        nem desfeito.
                    </Alert>
                </div>
            )}

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            <ul className="space-y-2 max-w-3xl">
                {ficha.pessoas.map((p) => (
                    <li
                        key={p.chave}
                        className={`bg-surface-container-lowest rounded-xl fetec-card-shadow p-3 flex flex-wrap items-center gap-3 ${
                            marcadas.includes(p.chave) ? 'ring-2 ring-primary-container/40' : ''
                        }`}
                    >
                        <IconePessoa presente={p.presente} />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-on-surface">
                                {p.nome} <span className="font-normal text-on-surface-variant">· {p.papel_label}</span>
                            </p>
                            {p.presente ? (
                                <p className="text-xs text-green-800">
                                    Check-in em {p.checkin_em}
                                    {p.registrado_por ? ` · por ${p.registrado_por}` : ''}
                                </p>
                            ) : (
                                <p className="text-xs text-on-surface-variant">Ainda não chegou</p>
                            )}
                        </div>

                        {p.presente ? (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={!aberto || ocupado}
                                onClick={() => { setDesfazendo(p); setJustificativa(''); }}
                            >
                                Desfazer
                            </Button>
                        ) : (
                            <label className="flex items-center gap-2 text-sm font-semibold text-on-surface cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    className="w-5 h-5 accent-[color:var(--color-primary-container,#43157a)]"
                                    checked={marcadas.includes(p.chave)}
                                    disabled={!aberto || ocupado}
                                    onChange={() => alternar(p.chave)}
                                    aria-label={`Marcar check-in de ${p.nome}`}
                                />
                                Chegou
                            </label>
                        )}
                    </li>
                ))}
            </ul>

            {aberto && ausentes.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 mt-4 max-w-3xl">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={ocupado}
                        onClick={() => setMarcadas(
                            marcadas.length === ausentes.length ? [] : ausentes.map((p) => p.chave),
                        )}
                    >
                        {marcadas.length === ausentes.length ? 'Limpar seleção' : 'Marcar a equipe toda'}
                    </Button>
                    <Button type="button" loading={ocupado} disabled={marcadas.length === 0} onClick={confirmar}>
                        <span className="material-symbols-outlined text-[20px]">how_to_reg</span>
                        Registrar check-in ({marcadas.length})
                    </Button>
                </div>
            )}

            {desfazendo && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                        <h3 className="font-display text-lg font-semibold text-on-surface">Desfazer o check-in</h3>
                        <p className="text-sm text-on-surface-variant">
                            <strong>{desfazendo.nome}</strong> deixa de constar como presente. Isso muda a
                            contagem de medalhas e de credenciais a separar, então a justificativa entra em
                            Registros → Cerimonial.
                        </p>
                        <label className="block">
                            <span className="text-sm font-semibold text-on-surface">Justificativa</span>
                            <textarea
                                aria-label="Justificativa para desfazer o check-in"
                                value={justificativa}
                                onChange={(e) => setJustificativa(e.target.value)}
                                rows={3}
                                maxLength={500}
                                placeholder="Ex.: crachá lido por engano, era o colega de equipe."
                                className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                            />
                        </label>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => { setDesfazendo(null); setJustificativa(''); }}
                            >
                                Voltar
                            </Button>
                            <Button
                                type="button"
                                loading={ocupado}
                                disabled={justificativa.trim().length < 5}
                                onClick={confirmarDesfazer}
                            >
                                Desfazer check-in
                            </Button>
                        </div>
                    </div>
                </div>
            )}

        </AppShell>
    );
}
