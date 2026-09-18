import { Alert, Button } from './ui.jsx';

/** Nota em pt_BR com duas casas (6,74). */
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

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
export default function DialogoNotasProjeto({ dados, carregando, erro, onFechar }) {
    const avaliadores = dados?.avaliadores ?? [];
    const secoes = dados?.secoes ?? [];

    // Amplitude de cada seção, para marcar a que mais separou os avaliadores.
    const amplitudes = {};
    secoes.forEach((s) => {
        const pontos = avaliadores.map((a) => a.secoes?.[s.chave] ?? 0);
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
                                                    <th key={a.avaliacao_id} scope="col" className="text-right font-semibold text-on-surface py-2 pl-3 whitespace-nowrap">
                                                        {a.avaliador}
                                                        <span className="block text-[11px] font-normal text-on-surface-variant">
                                                            {a.concluida_em_label ?? '—'}
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
                                                    <td key={a.avaliacao_id} className="text-right py-2 pl-3 text-lg font-bold text-primary-container whitespace-nowrap">
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
                                                            <td key={a.avaliacao_id} className="text-right py-1.5 pl-3 text-on-surface whitespace-nowrap">
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
                                    <h4 className="font-semibold text-sm text-on-surface">Parecer escrito</h4>
                                    {avaliadores.map((a) => (
                                        <div key={a.avaliacao_id} className="rounded-lg border border-outline-variant/40 p-3 text-xs space-y-1">
                                            <p className="font-semibold text-on-surface">
                                                {a.avaliador} · nota {nota(a.nota)}
                                            </p>
                                            {a.recomendacao_video && (
                                                <p className="text-on-surface-variant">
                                                    <strong>Sobre o vídeo:</strong> {a.recomendacao_video}
                                                </p>
                                            )}
                                            {a.recomendacao_projeto && (
                                                <p className="text-on-surface-variant">
                                                    <strong>Sobre o projeto:</strong> {a.recomendacao_projeto}
                                                </p>
                                            )}
                                            {!a.recomendacao_video && !a.recomendacao_projeto && (
                                                <p className="text-on-surface-variant">Não escreveu recomendações.</p>
                                            )}
                                        </div>
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
