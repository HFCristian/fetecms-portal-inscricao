import { useMemo, useState } from 'react';
import { Alert, Button, Field, Input } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { alocarNoMapa } from '../lib/mapaEvento.js';

const TURNOS = [
    { value: 'A', label: 'Turno A (matutino)' },
    { value: 'B', label: 'Turno B (vespertino)' },
];

const rotulo = (n) => String(n).padStart(3, '0');

const rotuloTurno = (t) => TURNOS.find((o) => o.value === t)?.label ?? t;

/** "Turno A (matutino) · Estande 042", ou só o que houver. */
export function lugarDe(turno, numero) {
    if (!turno) return 'sem turno';
    return numero ? `${rotuloTurno(turno)} · Estande ${rotulo(numero)}` : rotuloTurno(turno);
}

/**
 * Para onde vai um projeto: **turno + estande** — o diálogo das duas telas do
 * Mapa do Evento (Turnos e planta), para as regras serem as mesmas nas duas.
 *
 * Com o estande ocupado, o admin escolhe o que acontece com quem está lá:
 * **trocar** os dois de lugar (só para quem já tem lugar), **empurrar** a fila
 * um estande até o primeiro vazio, ou mandar o atual para o **fim** do turno.
 * Antes de os estandes serem distribuídos a escolha é só de turno.
 *
 * `dados` é o painel de remanejamento (números da planta, ocupação e
 * capacidade); quem decide é o servidor, que recusa o que não couber.
 */
export default function DialogoDestinoMapa({ projeto, dados, turnoInicial, numeroInicial, titulo, onConcluido, onFechar }) {
    const [turno, setTurno] = useState(turnoInicial ?? projeto.turno ?? 'A');
    const [numero, setNumero] = useState(numeroInicial ? String(numeroInicial) : '');
    const [modo, setModo] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState('');

    const comEstande = Boolean(dados?.estandes_gerados);
    const ocupacao = dados?.ocupacao ?? {};
    const numeros = dados?.numeros ?? [];
    const n = Number(numero) || null;

    const temLugar = Boolean(projeto.turno) && (!comEstande || Boolean(projeto.numero));
    const ocupanteBruto = n ? ocupacao[n]?.[turno] ?? null : null;
    const ocupante = ocupanteBruto && ocupanteBruto.projeto_id !== projeto.projeto_id ? ocupanteBruto : null;
    const naPlanta = !n || numeros.includes(n);

    const livres = useMemo(
        () => numeros.filter((x) => {
            const o = ocupacao[x]?.[turno];
            return !o || o.projeto_id === projeto.projeto_id;
        }),
        [numeros, ocupacao, turno, projeto.projeto_id],
    );

    const capacidade = dados?.capacidade?.[turno];
    const ocupados = dados?.ocupacao_turnos?.[turno] ?? 0;
    const mesmoLugar = projeto.turno === turno && (!comEstande || projeto.numero === n);

    const modos = ocupante ? [
        {
            value: 'trocar',
            label: 'Trocar os dois de lugar',
            descricao: temLugar
                ? `“${ocupante.titulo}” vai para o lugar atual deste projeto (${lugarDe(projeto.turno, projeto.numero)}).`
                : 'Este projeto ainda não tem lugar no mapa: não há o que trocar.',
            desabilitado: !temLugar,
        },
        {
            value: 'empurrar',
            label: 'Empurrar a fila',
            descricao: `Este projeto fica no ${rotulo(n)}; quem está lá e os seguintes andam um estande, até o primeiro vazio.`,
        },
        {
            value: 'final',
            label: 'Mandar o atual para o fim',
            descricao: `“${ocupante.titulo}” vai para o próximo estande livre depois do último ocupado do turno.`,
        },
    ] : [];

    const podeEnviar = !mesmoLugar && (!comEstande || (n && naPlanta)) && (!ocupante || modo);

    async function enviar() {
        setEnviando(true);
        setErro('');
        try {
            const resp = await alocarNoMapa({
                projetoId: projeto.projeto_id,
                turno,
                numero: comEstande ? n : null,
                modo: ocupante ? modo : null,
            });
            onConcluido?.(resp);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
        } finally {
            setEnviando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label={titulo ?? 'Escolher o lugar do projeto'}>
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <div>
                    <h3 className="font-display text-xl font-semibold text-on-surface">{titulo ?? 'Escolher o lugar do projeto'}</h3>
                    <p className="text-sm text-on-surface-variant mt-1">
                        <strong className="text-on-surface">{projeto.titulo}</strong>
                        {projeto.codigo ? ` · ${projeto.codigo}` : ''}
                    </p>
                    <p className="text-xs text-on-surface-variant">Hoje: {lugarDe(projeto.turno, projeto.numero)}</p>
                </div>

                {erro && <Alert>{erro}</Alert>}

                <div role="radiogroup" aria-label="Turno" className="grid grid-cols-2 gap-2">
                    {TURNOS.map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            role="radio"
                            aria-checked={turno === t.value}
                            onClick={() => { setTurno(t.value); setModo(''); }}
                            className={`rounded-lg border-2 px-3 py-2 text-sm font-semibold text-left ${
                                turno === t.value
                                    ? 'border-primary-container bg-primary-fixed/60 text-primary'
                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                            }`}
                        >
                            {t.label}
                            {dados?.capacidade && (
                                <span className="block text-xs font-normal">
                                    {dados.ocupacao_turnos?.[t.value] ?? 0} de {dados.capacidade[t.value] ?? 0} ocupados
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {capacidade !== undefined && projeto.turno !== turno && ocupados >= capacidade && !(ocupante && modo === 'trocar') && (
                    <Alert type="warning">
                        O {rotuloTurno(turno)} está com a capacidade cheia. Aumente a capacidade na configuração
                        dos turnos (não regera nada) ou troque este projeto com um de lá.
                    </Alert>
                )}

                {comEstande && (
                    <>
                        <Field
                            label="Estande"
                            error={!naPlanta ? 'Este número não existe na planta em vigor.' : undefined}
                            hint={livres.length > 0 ? `${livres.length} estande(s) livre(s) neste turno.` : 'Nenhum estande livre neste turno.'}
                        >
                            <Input
                                type="number"
                                min="1"
                                aria-label="Número do estande"
                                value={numero}
                                onChange={(e) => { setNumero(e.target.value); setModo(''); }}
                                placeholder="Ex.: 42"
                            />
                        </Field>

                        {livres.length > 0 && (
                            <div>
                                <p className="text-xs font-semibold text-on-surface-variant mb-1">Livres neste turno</p>
                                <div className="flex flex-wrap gap-1 max-h-24 overflow-y-auto">
                                    {livres.slice(0, 40).map((x) => (
                                        <button
                                            key={x}
                                            type="button"
                                            onClick={() => { setNumero(String(x)); setModo(''); }}
                                            className={`px-2 py-0.5 rounded-full text-xs border tabular-nums ${
                                                n === x
                                                    ? 'bg-primary-container text-on-primary border-primary-container'
                                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                            }`}
                                        >
                                            {rotulo(x)}
                                        </button>
                                    ))}
                                    {livres.length > 40 && (
                                        <span className="text-xs text-on-surface-variant self-center">+{livres.length - 40}</span>
                                    )}
                                </div>
                            </div>
                        )}

                        {n && naPlanta && !ocupante && !mesmoLugar && (
                            <p className="text-sm text-secondary">Estande {rotulo(n)} livre no {rotuloTurno(turno)}.</p>
                        )}

                        {ocupante && (
                            <div className="rounded-lg border border-outline-variant/60 p-3 space-y-2">
                                <p className="text-sm text-on-surface">
                                    O estande {rotulo(n)} do {rotuloTurno(turno)} já é de <strong>“{ocupante.titulo}”</strong>.
                                    O que acontece com ele?
                                </p>
                                <div role="radiogroup" aria-label="O que fazer com o projeto atual" className="space-y-2">
                                    {modos.map((m) => (
                                        <label
                                            key={m.value}
                                            className={`flex items-start gap-2 rounded-lg border px-3 py-2 cursor-pointer ${
                                                modo === m.value ? 'border-primary-container bg-primary-fixed/40' : 'border-outline-variant/60'
                                            } ${m.desabilitado ? 'opacity-50 cursor-not-allowed' : ''}`}
                                        >
                                            <input
                                                type="radio"
                                                name="modo-remanejamento"
                                                value={m.value}
                                                checked={modo === m.value}
                                                disabled={m.desabilitado}
                                                onChange={() => setModo(m.value)}
                                                className="mt-1"
                                            />
                                            <span>
                                                <span className="text-sm font-semibold text-on-surface block">{m.label}</span>
                                                <span className="text-xs text-on-surface-variant">{m.descricao}</span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )}
                    </>
                )}

                {mesmoLugar && <p className="text-sm text-on-surface-variant">O projeto já está neste lugar.</p>}

                <div className="flex justify-end gap-3 pt-1">
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button type="button" onClick={enviar} loading={enviando} disabled={!podeEnviar}>
                        Confirmar
                    </Button>
                </div>
            </div>
        </div>
    );
}
