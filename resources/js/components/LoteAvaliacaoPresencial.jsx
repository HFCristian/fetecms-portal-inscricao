import { useRef, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { baixarPlanilhaLote, enviarLotePresencial, previaLotePresencial } from '../lib/avaliacaoPresencial.js';

// Três casas, como a rubrica presencial.
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(3).replace('.', ',');

const estande = (n) => (n ? `Estande ${String(n).padStart(3, '0')}` : 'Estande ?');

const SITUACAO = {
    enviada: { rotulo: 'Enviada', classe: 'bg-secondary text-on-secondary' },
    rascunho: { rotulo: 'Rascunho', classe: 'bg-[#fff4cc] text-[#5c4a00]' },
    erro: { rotulo: 'Não enviada', classe: 'bg-error-container text-on-error-container' },
};

/**
 * A prévia (antes de enviar) ou o resultado (depois), linha a linha. Cada erro
 * vem do servidor já por extenso — a tela só os lista.
 */
function DialogoLote({ previa, resultado, enviando, erro, onEnviar, onFechar }) {
    const validas = (previa?.linhas ?? []).filter((l) => l.erros.length === 0);
    const comErro = (previa?.linhas ?? []).filter((l) => l.erros.length > 0);
    const envio = previa?.envio;
    // Fora do horário, o comitê guarda rascunho; o comum não tem o que fazer.
    const soRascunho = envio && !envio.aberto && envio.comite;
    const bloqueado = envio && !envio.aberto && !envio.comite;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true"
            aria-label="Avaliações pela planilha">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-3xl max-h-[90vh] overflow-y-auto p-6 space-y-4">
                <h3 className="font-display text-lg font-semibold text-on-surface">Avaliações pela planilha</h3>

                {erro && <Alert>{erro}</Alert>}

                {resultado ? (
                    <>
                        <ul className="divide-y divide-outline-variant/30">
                            {resultado.linhas.map((l, i) => (
                                <li key={`${l.projeto_id}-${i}`} className="py-2 flex flex-wrap items-start gap-2 text-sm">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-on-surface">
                                            {l.linha ? <span className="text-on-surface-variant">Linha {l.linha} · </span> : null}
                                            {l.titulo ?? `Projeto ${l.projeto_id}`}
                                        </p>
                                        <p className="text-xs text-on-surface-variant">{l.mensagem}</p>
                                    </div>
                                    <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${SITUACAO[l.situacao]?.classe ?? ''}`}>
                                        {SITUACAO[l.situacao]?.rotulo ?? l.situacao}
                                        {l.situacao === 'enviada' ? ` · ${nota(l.nota)}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="flex justify-end">
                            <Button type="button" onClick={onFechar}>Fechar</Button>
                        </div>
                    </>
                ) : previa && (
                    <>
                        <p className="text-sm text-on-surface-variant">
                            {previa.resumo.validas} linha(s) pronta(s) para enviar · {previa.resumo.com_erro} com erro
                            {previa.resumo.em_branco > 0 ? ` · ${previa.resumo.em_branco} em branco (ignorada[s])` : ''}.
                            Nada foi gravado ainda.
                        </p>
                        {envio?.mensagem && <Alert type={bloqueado ? 'error' : 'warning'}>{envio.mensagem}</Alert>}

                        {comErro.length > 0 && (
                            <section aria-label="Linhas com erro">
                                <h4 className="font-semibold text-sm text-error mb-1">Com erro — corrija na planilha e suba de novo</h4>
                                <ul className="space-y-2">
                                    {comErro.map((l) => (
                                        <li key={l.linha} className="rounded-lg border border-error/30 bg-error/5 p-3 text-sm">
                                            <p className="font-semibold text-on-surface">
                                                Linha {l.linha}{l.titulo ? ` · ${l.titulo}` : ''}{l.estande ? ` · ${estande(l.estande)}` : ''}
                                            </p>
                                            <ul className="list-disc pl-5 text-on-surface">
                                                {l.erros.map((e) => <li key={e}>{e}</li>)}
                                            </ul>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        {validas.length > 0 && (
                            <section aria-label="Linhas prontas">
                                <h4 className="font-semibold text-sm text-on-surface mb-1">Prontas</h4>
                                <ul className="divide-y divide-outline-variant/30">
                                    {validas.map((l) => (
                                        <li key={l.linha} className="py-2 flex flex-wrap items-center gap-2 text-sm">
                                            <span className="text-on-surface-variant">Linha {l.linha}</span>
                                            <span className="min-w-0 flex-1 text-on-surface truncate">
                                                {l.codigo ? `${l.codigo} · ` : ''}{l.titulo} · {estande(l.estande)}
                                            </span>
                                            <span className="font-semibold text-secondary">nota {nota(l.nota)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <div className="flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                            <Button
                                type="button"
                                variant="success"
                                loading={enviando}
                                disabled={validas.length === 0 || bloqueado}
                                onClick={() => onEnviar(validas)}
                            >
                                {soRascunho
                                    ? `Salvar ${validas.length} como rascunho`
                                    : `Enviar ${validas.length} avaliação(ões)`}
                            </Button>
                        </div>
                        {!soRascunho && validas.length > 0 && (
                            <p className="text-xs text-on-surface-variant text-right">
                                Depois de enviadas, as avaliações não podem ser alteradas.
                            </p>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

/**
 * Avaliação **em lote** dos projetos designados (Sprint 239): baixar a
 * planilha, preencher fora do portal e subir. A prévia diz o que cada linha
 * vira — ou por que não vira — antes de qualquer envio.
 */
export default function LoteAvaliacaoPresencial({ teste = false, onEnviado }) {
    const arquivo = useRef(null);
    const [baixando, setBaixando] = useState(false);
    const [lendo, setLendo] = useState(false);
    const [enviando, setEnviando] = useState(false);
    const [aberto, setAberto] = useState(false);
    const [previa, setPrevia] = useState(null);
    const [resultado, setResultado] = useState(null);
    const [erro, setErro] = useState('');
    const [erroFora, setErroFora] = useState('');

    const mensagem = (e, padrao) => {
        const { message, fields } = extractErrors(e);
        return Object.values(fields ?? {})[0] || message || padrao;
    };

    async function baixar() {
        setBaixando(true);
        setErroFora('');
        try {
            await baixarPlanilhaLote(teste);
        } catch (e) {
            setErroFora(mensagem(e, 'Não foi possível baixar a planilha.'));
        } finally {
            setBaixando(false);
        }
    }

    async function ler(evento) {
        const escolhido = evento.target.files?.[0];
        evento.target.value = '';
        if (!escolhido) return;

        setLendo(true);
        setErroFora('');
        try {
            setPrevia(await previaLotePresencial(escolhido, teste));
            setResultado(null);
            setErro('');
            setAberto(true);
        } catch (e) {
            // O cabeçalho errado recusa o arquivo inteiro, com o motivo.
            setErroFora(mensagem(e, 'Não foi possível ler a planilha.'));
        } finally {
            setLendo(false);
        }
    }

    async function enviar(validas) {
        setEnviando(true);
        setErro('');
        try {
            const r = await enviarLotePresencial(validas.map((l) => ({
                linha: l.linha, projeto_id: l.projeto_id, respostas: l.respostas, comentario: l.comentario,
            })), teste);
            setResultado(r.data);
            await onEnviado?.(r.meta?.message);
        } catch (e) {
            setErro(mensagem(e, 'Não foi possível enviar as avaliações.'));
        } finally {
            setEnviando(false);
        }
    }

    return (
        <div className="mb-3 rounded-lg bg-surface-variant/40 p-3 space-y-2">
            <p className="text-xs text-on-surface-variant">
                <strong className="text-on-surface">Várias de uma vez:</strong> baixe a planilha com os seus designados, preencha
                uma linha por projeto (S/N, ou 0, 2, 4, 6, 8 e 10 na escala) e suba. As perguntas condicionais só se
                preenchem quando a de origem for S.
            </p>
            <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" className="!px-3 !py-1.5 text-xs" loading={baixando} onClick={baixar}>
                    <span className="material-symbols-outlined text-[16px]" aria-hidden="true">download</span>
                    Baixar planilha
                </Button>
                <Button type="button" variant="outline" className="!px-3 !py-1.5 text-xs" loading={lendo} onClick={() => arquivo.current?.click()}>
                    <span className="material-symbols-outlined text-[16px]" aria-hidden="true">upload</span>
                    Enviar planilha preenchida
                </Button>
                <input
                    ref={arquivo}
                    type="file"
                    accept=".xlsx,.csv"
                    className="hidden"
                    aria-label="Planilha preenchida"
                    onChange={ler}
                />
            </div>
            {erroFora && <Alert>{erroFora}</Alert>}

            {aberto && (
                <DialogoLote
                    previa={previa}
                    resultado={resultado}
                    enviando={enviando}
                    erro={erro}
                    onEnviar={enviar}
                    onFechar={() => { setAberto(false); setPrevia(null); setResultado(null); }}
                />
            )}
        </div>
    );
}
