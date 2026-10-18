import { useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { excluirAvaliacaoPresencial } from '../lib/presencial.js';

// Três casas, como a rubrica presencial: os pesos foram escolhidos para desempatar.
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(3).replace('.', ',');

const peso = (valor) => String(valor ?? '').replace('.', ',');

const PILL = {
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

/** "Comissão especial · peso 2" ou "Vaga comum". */
const vagaTexto = (a) => (a.vaga === 'comite' ? `${a.vaga_label} · peso ${peso(a.peso)}` : a.vaga_label);

/**
 * O cartão de uma avaliação: quem avaliou, em que vaga, quando, o parecer — e a
 * exclusão, com justificativa.
 *
 * Excluir apaga de vez (a vaga volta a abrir no estande, ninguém é designado no
 * lugar), então a justificativa é a única memória do que ela era: vai para
 * Registros → Notas junto da nota.
 */
function CartaoAvaliacao({ avaliacao: a, teste, onExcluida }) {
    const [abrindo, setAbrindo] = useState(false);
    const [justificativa, setJustificativa] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');

    async function excluir() {
        setSalvando(true);
        setErro('');
        try {
            onExcluida(await excluirAvaliacaoPresencial(a.avaliacao_id, justificativa.trim(), teste));
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível excluir a avaliação.');
            setSalvando(false);
        }
    }

    return (
        <li className="rounded-lg border border-outline-variant/40 p-3 text-xs space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-semibold text-sm text-on-surface">{a.avaliador}</p>
                <span className={`font-semibold px-2 py-0.5 rounded-full ${PILL[a.status] ?? 'bg-surface-variant text-on-surface-variant'}`}>
                    {a.status_label}
                </span>
                <span className={a.vaga === 'comite' ? 'font-semibold text-secondary' : 'text-on-surface-variant'}>
                    {vagaTexto(a)}
                </span>
                {a.status === 'concluida' && (
                    <span className="ml-auto text-sm font-bold text-primary-container">nota {nota(a.nota)}</span>
                )}
            </div>

            <p className="text-on-surface-variant">
                {[
                    a.ocorrencia_label,
                    a.origem_label,
                    a.concluida_em_label ? `enviada em ${a.concluida_em_label}` : null,
                    !a.concluida_em_label && a.iniciada_em_label ? `iniciada em ${a.iniciada_em_label}` : null,
                ].filter(Boolean).join(' · ')}
            </p>

            {a.rubrica_anterior && (
                <p className="text-on-surface-variant">
                    Enviada com a rubrica anterior ao documento de 2026: a nota vale, os pontos por seção não existem.
                </p>
            )}

            {a.status === 'concluida' && (
                <p className="text-on-surface-variant">
                    {a.comentario
                        ? <><strong>Parecer:</strong> <span className="whitespace-pre-line">{a.comentario}</span></>
                        : 'Não escreveu parecer.'}
                </p>
            )}

            {erro && <Alert>{erro}</Alert>}

            {abrindo ? (
                <div className="space-y-2">
                    <p className="text-on-surface">
                        A avaliação é apagada e a vaga volta a abrir no estande. Ninguém é designado no lugar.
                    </p>
                    <label className="block">
                        <span className="block font-semibold text-on-surface mb-1">Por que excluir esta avaliação?</span>
                        <textarea
                            className="fetec-input w-full text-xs"
                            rows={2}
                            maxLength={1000}
                            value={justificativa}
                            onChange={(e) => setJustificativa(e.target.value)}
                            placeholder="A justificativa fica em Registros → Notas."
                        />
                    </label>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => { setAbrindo(false); setErro(''); }}>
                            Cancelar
                        </Button>
                        <Button type="button" loading={salvando} disabled={justificativa.trim().length < 5} onClick={excluir}>
                            Excluir avaliação
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={() => setAbrindo(true)}
                        aria-label={`Excluir a avaliação de ${a.avaliador}`}
                        className="inline-flex items-center gap-1 font-semibold text-error hover:underline"
                    >
                        <span className="material-symbols-outlined text-[16px]">delete</span>
                        Excluir avaliação
                    </button>
                </div>
            )}
        </li>
    );
}

/**
 * Ranking presencial → **Ver avaliações** (Sprint 237): as avaliações do estande
 * de um projeto, com quem avaliou.
 *
 * O ranking mostra as notas sem nome; aqui elas aparecem lado a lado, seção por
 * seção da rubrica, e cada uma pode ser excluída. Entram as enviadas e as em
 * andamento — a só designada sai pela Distribuição presencial. Abrir fica
 * registrado em Registros → Notas, pelo servidor.
 */
export default function DialogoAvaliacoesPresenciais({ dados, carregando, erro, teste = false, onFechar, onExcluida }) {
    const avaliacoes = dados?.avaliacoes ?? [];
    // Só quem enviou com a rubrica de agora tem pontos por seção.
    const colunas = avaliacoes.filter((a) => a.secoes);
    const secoes = dados?.secoes ?? [];
    const projeto = dados?.projeto;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true"
            aria-labelledby="dialogo-avaliacoes-presenciais">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-4xl max-h-[90vh] overflow-y-auto p-6 space-y-4">
                <h3 id="dialogo-avaliacoes-presenciais" className="font-display text-lg font-semibold text-on-surface">
                    Avaliações presenciais
                </h3>

                {erro && <Alert>{erro}</Alert>}

                {carregando && !dados && (
                    <div className="text-center py-8 text-on-surface-variant">
                        <span className="inline-block w-6 h-6 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                )}

                {dados && (
                    <>
                        <div>
                            <p className="text-sm font-semibold text-on-surface">
                                {projeto.codigo && <span className="text-primary-container mr-1">{projeto.codigo}</span>}
                                {projeto.titulo}
                            </p>
                            <p className="text-xs text-on-surface-variant">
                                {projeto.area ?? 'Sem área'}
                                {projeto.categoria ? ` · ${projeto.categoria}` : ''}
                                {projeto.estande ? ` · Estande ${String(projeto.estande).padStart(3, '0')}` : ''}
                                {projeto.turno_label ? ` · ${projeto.turno_label}` : ''}
                                {dados.media !== null ? ` · média ponderada ${nota(dados.media)}` : ''}
                            </p>
                        </div>

                        {avaliacoes.length === 0 ? (
                            <p className="text-sm text-on-surface-variant">
                                Nenhuma avaliação enviada ou em andamento neste projeto.
                            </p>
                        ) : (
                            <>
                                {colunas.length > 0 && (
                                    // A tabela é o único elemento que pode passar da largura da tela: ela rola sozinha.
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm border-collapse">
                                            <caption className="sr-only">Pontuação de cada seção da rubrica por avaliador</caption>
                                            <thead>
                                                <tr className="border-b border-outline-variant/60">
                                                    <th scope="col" className="text-left font-semibold text-on-surface-variant py-2 pr-3">Seção</th>
                                                    {colunas.map((a) => (
                                                        <th key={a.avaliacao_id} scope="col" className="text-right font-semibold text-on-surface py-2 pl-3 whitespace-nowrap">
                                                            {a.avaliador}
                                                            <span className={`block text-[11px] font-normal ${a.vaga === 'comite' ? 'text-secondary' : 'text-on-surface-variant'}`}>
                                                                {vagaTexto(a)}
                                                            </span>
                                                        </th>
                                                    ))}
                                                </tr>
                                                <tr className="border-b-2 border-outline-variant">
                                                    <th scope="row" className="text-left py-2 pr-3 font-semibold text-on-surface">
                                                        Nota final
                                                        <span className="block text-[11px] font-normal text-on-surface-variant">de {nota(dados.nota_maxima)}</span>
                                                    </th>
                                                    {colunas.map((a) => (
                                                        <td key={a.avaliacao_id} className="text-right py-2 pl-3 text-lg font-bold whitespace-nowrap text-primary-container">
                                                            {nota(a.nota)}
                                                        </td>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {secoes.map((s) => (
                                                    <tr key={s.chave} className="border-b border-outline-variant/30">
                                                        <th scope="row" className="text-left py-1.5 pr-3 font-normal text-on-surface">
                                                            {s.titulo}
                                                            <span className="block text-[11px] text-on-surface-variant">de {nota(s.maximo)}</span>
                                                        </th>
                                                        {colunas.map((a) => (
                                                            <td key={a.avaliacao_id} className="text-right py-1.5 pl-3 whitespace-nowrap text-on-surface">
                                                                {nota(a.secoes[s.chave])}
                                                            </td>
                                                        ))}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                <div className="space-y-2">
                                    <h4 className="font-semibold text-sm text-on-surface">Cada avaliação</h4>
                                    <ul className="space-y-2">
                                        {avaliacoes.map((a) => (
                                            <CartaoAvaliacao key={a.avaliacao_id} avaliacao={a} teste={teste} onExcluida={onExcluida} />
                                        ))}
                                    </ul>
                                </div>
                            </>
                        )}
                    </>
                )}

                <div className="flex justify-end">
                    <Button type="button" onClick={onFechar}>Fechar</Button>
                </div>
            </div>
        </div>
    );
}
