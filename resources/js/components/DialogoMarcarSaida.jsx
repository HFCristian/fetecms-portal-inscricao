import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Button, Field, Input, Select, useConfirm } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getSituacaoPlanta, marcarSaida, desfazerSaida } from '../lib/mapaEvento.js';

const doisDigitos = (n) => String(n).padStart(2, '0');

/** "aaaa-mm-dd" de hoje, no relógio do aparelho (o do balcão, em Campo Grande). */
const hoje = () => {
    const d = new Date();
    return `${d.getFullYear()}-${doisDigitos(d.getMonth() + 1)}-${doisDigitos(d.getDate())}`;
};

const agora = () => {
    const d = new Date();
    return `${doisDigitos(d.getHours())}:${doisDigitos(d.getMinutes())}`;
};

const semAcento = (t) => (t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * Mapa do Evento → **Marcar saída** (Sprint 243): a equipe que chegou e foi
 * embora antes do fim do turno.
 *
 * Lista os projetos **credenciados** do turno, hoje — só sai quem chegou —, e
 * cada um pode ser marcado com a hora (agora, por padrão) e uma observação. A
 * marcação vale para o dia: amanhã a equipe pode voltar. Desfazer serve para a
 * equipe que voltou ou para o projeto marcado por engano.
 */
export default function DialogoMarcarSaida({ turnos, turnoInicial = 'A', onAlterado, onFechar }) {
    const [turno, setTurno] = useState(turnoInicial);
    const [linhas, setLinhas] = useState(null);
    const [termo, setTermo] = useState('');
    const [aberto, setAberto] = useState(null);     // projeto_id com o formulário aberto
    const [hora, setHora] = useState(agora());
    const [observacao, setObservacao] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState('');
    const [confirm, dialogo] = useConfirm();

    const carregar = useCallback(() => {
        getSituacaoPlanta({ turno })
            .then((r) => { setLinhas(Object.values(r.estandes ?? {})); setErro(''); })
            .catch(() => setErro('Não foi possível carregar os projetos do turno.'));
    }, [turno]);

    useEffect(() => { setLinhas(null); carregar(); }, [carregar]);

    const credenciados = useMemo(() => (linhas ?? [])
        .filter((l) => l.projeto_id && l.credenciado)
        .filter((l) => semAcento(`${l.estande} ${l.titulo} ${l.area ?? ''} ${l.escola ?? ''}`).includes(semAcento(termo.trim())))
        .sort((a, b) => a.numero - b.numero), [linhas, termo]);

    const naoCredenciados = (linhas ?? []).filter((l) => l.projeto_id && !l.credenciado).length;

    function abrir(linha) {
        setAberto(linha.projeto_id);
        setHora(agora());
        setObservacao('');
        setErro('');
    }

    async function confirmar(linha) {
        setEnviando(true);
        setErro('');
        try {
            const r = await marcarSaida({ projetoId: linha.projeto_id, saiuEm: `${hoje()}T${hora}`, observacao });
            setAberto(null);
            carregar();
            onAlterado?.(`Estande ${linha.estande} · ${linha.titulo}: ${r.meta?.message ?? 'saída registrada.'}`);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0]?.[0] || message || 'Não foi possível registrar a saída.');
        } finally {
            setEnviando(false);
        }
    }

    async function desfazer(linha) {
        const ok = await confirm({
            title: 'Desfazer saída',
            message: `“${linha.titulo}” volta a aparecer no estande ${linha.estande}. Use quando a equipe voltou ou quando o projeto foi marcado por engano.`,
            confirmLabel: 'Desfazer',
        });
        if (!ok) return;

        setEnviando(true);
        setErro('');
        try {
            await desfazerSaida(linha.saida.id);
            carregar();
            onAlterado?.(`Estande ${linha.estande} · ${linha.titulo}: saída desfeita.`);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível desfazer a saída.');
        } finally {
            setEnviando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Marcar saída">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-2xl p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <div>
                    <h3 className="font-display text-xl font-semibold text-on-surface">Marcar saída</h3>
                    <p className="text-sm text-on-surface-variant mt-1">
                        A equipe chegou e foi embora antes do fim do turno. O estande fica cinza no mapa
                        hoje — amanhã a equipe pode voltar — e a marcação vai para Registros → Mapa do evento.
                    </p>
                </div>

                {erro && <Alert>{erro}</Alert>}

                <div className="flex flex-wrap items-end gap-3">
                    <Field label="Turno">
                        <Select aria-label="Turno da saída" value={turno} onChange={(e) => { setTurno(e.target.value); setAberto(null); }}>
                            {turnos.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </Select>
                    </Field>
                    <div className="flex-1 min-w-[12rem]">
                        <Input
                            value={termo}
                            onChange={(e) => setTermo(e.target.value)}
                            placeholder="Buscar por estande, título, área ou escola…"
                            aria-label="Buscar projeto"
                        />
                    </div>
                </div>

                {linhas === null ? (
                    <p className="text-sm text-on-surface-variant">Carregando…</p>
                ) : (
                    <>
                        <ul className="divide-y divide-outline-variant/30 border border-outline-variant/50 rounded-lg">
                            {credenciados.map((l) => (
                                <li key={l.projeto_id} className="px-3 py-2 space-y-2">
                                    <div className="flex items-center gap-3">
                                        <span className="min-w-0 flex-1">
                                            <span className="text-sm font-medium text-on-surface block">
                                                Estande {l.estande} · {l.titulo}
                                            </span>
                                            <span className="text-xs text-on-surface-variant block">
                                                {l.saida
                                                    ? `Saiu às ${l.saida.hora}${l.saida.observacao ? ` · ${l.saida.observacao}` : ''}${l.saida.registrado_por ? ` · marcado por ${l.saida.registrado_por}` : ''}`
                                                    : [l.categoria, l.area].filter(Boolean).join(' · ')}
                                            </span>
                                        </span>
                                        {l.saida ? (
                                            <Button variant="outline" disabled={enviando} onClick={() => desfazer(l)}>
                                                Desfazer
                                            </Button>
                                        ) : aberto !== l.projeto_id && (
                                            <Button variant="outline" disabled={enviando} onClick={() => abrir(l)}
                                                aria-label={`Marcar saída de ${l.titulo}`}>
                                                <span className="material-symbols-outlined text-[18px]">logout</span>
                                                Marcar saída
                                            </Button>
                                        )}
                                    </div>

                                    {aberto === l.projeto_id && (
                                        <div className="flex flex-wrap items-end gap-3 rounded-lg bg-surface-variant/40 p-3">
                                            <Field label="Saiu às">
                                                <Input type="time" aria-label="Horário da saída" value={hora}
                                                    onChange={(e) => setHora(e.target.value)} />
                                            </Field>
                                            <div className="flex-1 min-w-[12rem]">
                                                <Field label="Observação (opcional)">
                                                    <Input value={observacao} maxLength={500} aria-label="Observação da saída"
                                                        onChange={(e) => setObservacao(e.target.value)}
                                                        placeholder="Ex.: aluno passou mal, ônibus da escola" />
                                                </Field>
                                            </div>
                                            <Button loading={enviando} disabled={!hora} onClick={() => confirmar(l)}>Confirmar saída</Button>
                                            <Button variant="outline" disabled={enviando} onClick={() => setAberto(null)}>Cancelar</Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                            {credenciados.length === 0 && (
                                <li className="px-3 py-2 text-sm text-on-surface-variant">
                                    {termo.trim() ? 'Nenhum projeto encontrado.' : 'Nenhum projeto credenciado neste turno.'}
                                </li>
                            )}
                        </ul>
                        {naoCredenciados > 0 && (
                            <p className="text-xs text-on-surface-variant">
                                {naoCredenciados} projeto(s) do turno ainda não credenciado(s) não aparecem aqui: só sai quem chegou.
                            </p>
                        )}
                    </>
                )}

                <div className="flex justify-end">
                    <Button type="button" variant="outline" onClick={onFechar}>Fechar</Button>
                </div>
            </div>
            {dialogo}
        </div>
    );
}
