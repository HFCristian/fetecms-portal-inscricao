import { useEffect, useState } from 'react';
import BuscaCombobox from './BuscaCombobox.jsx';
import { Alert, Button } from './ui.jsx';
import { avaliarNoLugarDaNota, desconsiderarNota, getOpcoesDesignacao, reconsiderarNota } from '../lib/admin.js';
import { extractErrors } from '../lib/auth.jsx';

/** Nota em pt_BR com duas casas (6,74). */
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

/**
 * O cartão de um avaliador: o parecer que ele escreveu e o botão que tira (ou
 * devolve) a nota dele da classificação.
 *
 * A nota desconsiderada **não some**: ela fica aqui, riscada e com o motivo à
 * vista. Apagar destruiria a prova justamente no caso em que alguém contesta —
 * e o admin precisa poder voltar atrás.
 */
function CartaoAvaliador({ avaliador, onAtualizar, onAvaliarAgora }) {
    const [abrindo, setAbrindo] = useState(false);
    const [justificativa, setJustificativa] = useState('');
    const [substituicao, setSubstituicao] = useState('nenhuma');
    const [candidatos, setCandidatos] = useState([]);
    const [escolhido, setEscolhido] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');

    const desconsiderada = avaliador.desconsiderada;
    // Uma avaliação da organização já aberta no lugar desta nota, ainda não
    // enviada. É a única porta de volta ao formulário: o wizard da organização
    // só se abre por aqui.
    const substituindo = avaliador.substituicao_em_aberto;

    // A lista de avaliadores só é buscada quando ela vai ser usada: abrir o
    // diálogo de notas não precisa pagar por ela.
    useEffect(() => {
        if (substituicao !== 'avaliador' || candidatos.length > 0) return;
        getOpcoesDesignacao({ limite: 30 })
            .then((o) => setCandidatos(o.avaliadores ?? []))
            .catch(() => setCandidatos([]));
    }, [substituicao, candidatos.length]);

    async function confirmar() {
        setSalvando(true);
        setErro('');
        try {
            if (desconsiderada) {
                onAtualizar(await reconsiderarNota(avaliador.avaliacao_id, justificativa));
            } else if (substituicao === 'admin') {
                // A rubrica abre **antes**: a nota antiga só sai da
                // classificação quando esta avaliação for enviada. Desistir no
                // meio não deixa o projeto com um parecer a menos.
                const dados = await avaliarNoLugarDaNota(avaliador.avaliacao_id, justificativa);
                onAtualizar(dados);
                onAvaliarAgora?.(dados.substituicao.avaliacao_id, avaliador);
            } else {
                onAtualizar(await desconsiderarNota(avaliador.avaliacao_id, justificativa, {
                    tipo: substituicao,
                    ...(substituicao === 'avaliador' ? { avaliador_id: escolhido?.id } : {}),
                }));
            }
            setAbrindo(false);
            setJustificativa('');
            setSubstituicao('nenhuma');
            setEscolhido(null);
        } catch (err) {
            const { message, fields } = extractErrors(err);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div
            className={`rounded-lg border p-3 text-xs space-y-2 ${
                desconsiderada
                    ? 'border-dashed border-error/50 bg-error/5'
                    : 'border-outline-variant/40'
            }`}
        >
            <div className="flex flex-wrap items-baseline gap-2">
                <p className={`font-semibold ${desconsiderada ? 'text-on-surface-variant line-through' : 'text-on-surface'}`}>
                    {avaliador.avaliador} · nota {nota(avaliador.nota)}
                </p>
                {desconsiderada && (
                    <span className="rounded-full bg-error/10 text-error border border-error/30 px-2 py-0.5 text-[10px] font-semibold">
                        não conta para a classificação
                    </span>
                )}
            </div>

            {desconsiderada && (
                <p className="text-on-surface-variant">
                    Desconsiderada por {avaliador.desconsiderada_por ?? 'um administrador'}
                    {avaliador.desconsiderada_em_label ? ` em ${avaliador.desconsiderada_em_label}` : ''}:{' '}
                    <em>{avaliador.desconsiderada_motivo}</em>
                </p>
            )}

            {substituindo && !desconsiderada && (
                <div className="rounded-lg border border-primary/30 bg-primary/5 p-2 space-y-1">
                    <p className="text-on-surface">
                        {substituindo.minha
                            ? 'Você abriu uma avaliação no lugar desta nota — ela continua contando até você enviar a sua.'
                            : `${substituindo.por} abriu uma avaliação no lugar desta nota — ela continua contando até o envio.`}
                    </p>
                    {substituindo.minha && (
                        <button
                            type="button"
                            onClick={() => onAvaliarAgora?.(substituindo.avaliacao_id, avaliador)}
                            className="font-semibold text-primary hover:underline"
                        >
                            Continuar a avaliação
                        </button>
                    )}
                </div>
            )}

            {avaliador.recomendacao_video && (
                <p className="text-on-surface-variant">
                    <strong>Sobre o vídeo:</strong> {avaliador.recomendacao_video}
                </p>
            )}
            {avaliador.recomendacao_projeto && (
                <p className="text-on-surface-variant">
                    <strong>Sobre o projeto:</strong> {avaliador.recomendacao_projeto}
                </p>
            )}
            {!avaliador.recomendacao_video && !avaliador.recomendacao_projeto && (
                <p className="text-on-surface-variant">Não escreveu recomendações.</p>
            )}

            {erro && <Alert>{erro}</Alert>}

            {abrindo ? (
                <div className="space-y-2">
                    <label className="block">
                        <span className="block font-semibold text-on-surface mb-1">
                            Por que {desconsiderada ? 'voltar a considerar' : 'desconsiderar'} esta nota?
                        </span>
                        <textarea
                            className="fetec-input w-full text-xs"
                            rows={2}
                            value={justificativa}
                            onChange={(e) => setJustificativa(e.target.value)}
                            placeholder="A justificativa fica na trilha de Registros."
                        />
                    </label>

                    {/* Tirar a nota abre um buraco na cobertura do projeto. O
                        admin decide na hora como fechá-lo — ou não fechar, que
                        também é resposta quando há pareceres de sobra. */}
                    {!desconsiderada && (
                        <fieldset className="space-y-1">
                            <legend className="font-semibold text-on-surface mb-1">
                                E no lugar desta nota?
                            </legend>
                            {[
                                ['nenhuma', 'Apenas desconsiderar', 'O projeto fica com um parecer a menos.'],
                                ['avaliador', 'Designar outro avaliador', 'Ele recebe o projeto e o aviso por e-mail.'],
                                ['admin', 'Eu mesmo avalio agora', 'Abre a rubrica na hora; esta nota sai da classificação quando você enviar a sua.'],
                            ].map(([valor, rotulo, ajuda]) => (
                                <label key={valor} className="flex items-start gap-2 cursor-pointer">
                                    <input
                                        type="radio"
                                        className="mt-0.5 accent-primary-container"
                                        name={`substituicao-${avaliador.avaliacao_id}`}
                                        checked={substituicao === valor}
                                        onChange={() => setSubstituicao(valor)}
                                    />
                                    <span className="min-w-0">
                                        <span className="block text-on-surface">{rotulo}</span>
                                        <span className="block text-on-surface-variant">{ajuda}</span>
                                    </span>
                                </label>
                            ))}

                            {substituicao === 'avaliador' && (
                                <div className="pt-1">
                                    <BuscaCombobox
                                        options={candidatos.map((a) => ({
                                            id: a.id,
                                            nome: a.nome,
                                            detalhe: `${a.area ?? 'Sem área'} · ${a.na_fila} na fila`,
                                        }))}
                                        value={escolhido}
                                        onChange={setEscolhido}
                                        placeholder="Procure o avaliador pelo nome"
                                        vazio="Nenhum avaliador encontrado"
                                    />
                                </div>
                            )}
                        </fieldset>
                    )}

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => setAbrindo(false)}>
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            loading={salvando}
                            disabled={!desconsiderada && substituicao === 'avaliador' && !escolhido}
                            onClick={confirmar}
                        >
                            {desconsiderada
                                ? 'Voltar a considerar'
                                : substituicao === 'admin' ? 'Avaliar agora' : 'Desconsiderar'}
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={() => { setAbrindo(true); setErro(''); }}
                        className="font-semibold text-primary hover:underline"
                    >
                        {desconsiderada ? 'Voltar a considerar esta nota' : 'Desconsiderar esta nota'}
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * As notas de **todos** os avaliadores de um projeto, lado a lado.
 *
 * A lista de disparidade diz que a maior e a menor nota estão longe; esta tela
 * diz **onde** elas se afastaram — a rubrica é a mesma para todos, então
 * comparar seção por seção mostra se a briga foi na metodologia ou no vídeo — e
 * **quem** deu cada nota, que é com quem o admin vai falar depois.
 *
 * A seção em que os avaliadores mais discordaram fica destacada: é a primeira
 * coisa que se procura ao abrir, e caçá-la à mão numa tabela de dez linhas é
 * trabalho que a tela pode fazer.
 */
export default function DialogoNotasProjeto({ dados, carregando, erro, onFechar, onAtualizar, onAvaliarAgora }) {
    const avaliadores = dados?.avaliadores ?? [];
    const secoes = dados?.secoes ?? [];

    // Amplitude de cada seção, para marcar a que mais separou os avaliadores.
    const amplitudes = {};
    const contam = avaliadores.filter((a) => !a.desconsiderada);
    secoes.forEach((s) => {
        const pontos = contam.map((a) => a.secoes?.[s.chave] ?? 0);
        amplitudes[s.chave] = pontos.length < 2 ? 0 : Math.max(...pontos) - Math.min(...pontos);
    });
    const maiorAmplitude = Math.max(0, ...Object.values(amplitudes));

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-4xl max-h-[90vh] overflow-y-auto p-6 space-y-4">
                <h3 className="font-display text-lg font-semibold text-on-surface">
                    Notas por avaliador
                </h3>

                {erro && <Alert>{erro}</Alert>}

                {carregando && (
                    <div className="text-center py-8 text-on-surface-variant">
                        <span className="inline-block w-6 h-6 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                )}

                {dados && (
                    <>
                        <div>
                            <p className="text-sm font-semibold text-on-surface">{dados.projeto.titulo}</p>
                            <p className="text-xs text-on-surface-variant">
                                {dados.projeto.area ?? 'Sem área'}
                                {dados.projeto.categoria ? ` · ${dados.projeto.categoria}` : ''}
                                {dados.media !== null ? ` · média ${nota(dados.media)}` : ''}
                                {dados.amplitude !== null ? ` · ${nota(dados.amplitude)} de diferença` : ''}
                            </p>
                            {dados.desconsideradas > 0 && (
                                <p className="text-xs text-error mt-1">
                                    {dados.desconsideradas}{' '}
                                    {dados.desconsideradas === 1 ? 'nota desconsiderada' : 'notas desconsideradas'} —
                                    a média acima já é só do que conta.
                                </p>
                            )}
                        </div>

                        {avaliadores.length === 0 ? (
                            <p className="text-sm text-on-surface-variant">
                                Nenhuma avaliação concluída neste projeto — só há nota depois que o
                                avaliador envia.
                            </p>
                        ) : (
                            <>
                                {/* A tabela é o único elemento que pode passar da
                                    largura da tela: ela rola sozinha. */}
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm border-collapse">
                                        <caption className="sr-only">
                                            Pontuação de cada seção da rubrica por avaliador
                                        </caption>
                                        <thead>
                                            <tr className="border-b border-outline-variant/60">
                                                <th scope="col" className="text-left font-semibold text-on-surface-variant py-2 pr-3">
                                                    Seção
                                                </th>
                                                {avaliadores.map((a) => (
                                                    <th
                                                        key={a.avaliacao_id}
                                                        scope="col"
                                                        className={`text-right font-semibold py-2 pl-3 whitespace-nowrap ${
                                                            a.desconsiderada ? 'text-on-surface-variant' : 'text-on-surface'
                                                        }`}
                                                    >
                                                        <span className={a.desconsiderada ? 'line-through' : ''}>
                                                            {a.avaliador}
                                                        </span>
                                                        <span className="block text-[11px] font-normal text-on-surface-variant">
                                                            {a.desconsiderada ? 'desconsiderada' : (a.concluida_em_label ?? '—')}
                                                        </span>
                                                    </th>
                                                ))}
                                            </tr>
                                            <tr className="border-b-2 border-outline-variant">
                                                <th scope="row" className="text-left py-2 pr-3 font-semibold text-on-surface">
                                                    Nota final
                                                    <span className="block text-[11px] font-normal text-on-surface-variant">
                                                        de {nota(dados.nota_maxima)}
                                                    </span>
                                                </th>
                                                {avaliadores.map((a) => (
                                                    <td
                                                        key={a.avaliacao_id}
                                                        className={`text-right py-2 pl-3 text-lg font-bold whitespace-nowrap ${
                                                            a.desconsiderada
                                                                ? 'text-on-surface-variant line-through decoration-error'
                                                                : 'text-primary-container'
                                                        }`}
                                                    >
                                                        {nota(a.nota)}
                                                    </td>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {secoes.map((s) => {
                                                const destaque = maiorAmplitude > 0 && amplitudes[s.chave] === maiorAmplitude;

                                                return (
                                                    <tr
                                                        key={s.chave}
                                                        className={`border-b border-outline-variant/30 ${destaque ? 'bg-error/5' : ''}`}
                                                    >
                                                        <th scope="row" className="text-left py-1.5 pr-3 font-normal text-on-surface">
                                                            {s.titulo}
                                                            <span className="block text-[11px] text-on-surface-variant">
                                                                de {nota(s.maximo)}
                                                                {destaque ? ' · maior discordância' : ''}
                                                            </span>
                                                        </th>
                                                        {avaliadores.map((a) => (
                                                            <td
                                                                key={a.avaliacao_id}
                                                                className={`text-right py-1.5 pl-3 whitespace-nowrap ${
                                                                    a.desconsiderada
                                                                        ? 'text-on-surface-variant/60 line-through'
                                                                        : 'text-on-surface'
                                                                }`}
                                                            >
                                                                {nota(a.secoes?.[s.chave])}
                                                            </td>
                                                        ))}
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="space-y-3">
                                    <h4 className="font-semibold text-sm text-on-surface">
                                        Parecer de cada avaliador
                                    </h4>
                                    {avaliadores.map((a) => (
                                        <CartaoAvaliador
                                            key={a.avaliacao_id}
                                            avaliador={a}
                                            onAtualizar={onAtualizar}
                                            onAvaliarAgora={onAvaliarAgora}
                                        />
                                    ))}
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
