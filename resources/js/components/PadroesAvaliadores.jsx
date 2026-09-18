import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Field, Input } from './ui.jsx';
import { getPadroesDeAvaliacao } from '../lib/admin.js';
import { extractErrors } from '../lib/auth.jsx';

/** Nota em pt_BR com duas casas (6,74). */
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

/** Vírgula → ponto: o admin digita 1,50 e a API recebe 1.5. */
const numero = (texto) => Number(String(texto).replace(',', '.'));

/** Cada sinal tem a sua cor: o grave em vermelho, o resto em âmbar. */
const COR = {
    fora_da_curva: 'bg-error/10 text-error border-error/30',
    notas_infladas: 'bg-tertiary-container/40 text-on-surface border-outline-variant',
    respostas_repetidas: 'bg-tertiary-container/40 text-on-surface border-outline-variant',
    relampago: 'bg-tertiary-container/40 text-on-surface border-outline-variant',
};

/** Um avaliador que disparou algum sinal, com os números que o sustentam. */
function Linha({ linha, onVerNotas }) {
    const [aberta, setAberta] = useState(false);

    return (
        <li className="p-4">
            <div className="flex flex-col sm:flex-row sm:items-start gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-on-surface truncate">{linha.avaliador}</p>
                    <p className="text-xs text-on-surface-variant truncate">
                        {linha.area ?? 'Sem área'}
                        {linha.email ? ` · ${linha.email}` : ''}
                        {' · '}{linha.concluidas} {linha.concluidas === 1 ? 'avaliação' : 'avaliações'}
                    </p>

                    <ul className="mt-2 flex flex-wrap gap-2">
                        {linha.padroes.map((p) => (
                            <li
                                key={p.chave}
                                className={`rounded-lg border px-2 py-1 text-xs ${COR[p.chave] ?? COR.notas_infladas}`}
                            >
                                <strong>{p.titulo}:</strong> {p.detalhe}
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="flex items-center gap-4 shrink-0">
                    <div className="text-center w-20">
                        <div className="text-xl font-bold text-on-surface">{nota(linha.media)}</div>
                        <div className="text-[10px] text-on-surface-variant leading-tight">
                            média
                            <span className="block">
                                {linha.desvio_medio === null
                                    ? 'sem comparação'
                                    : `${linha.desvio_medio > 0 ? '+' : ''}${nota(linha.desvio_medio)} vs. colegas`}
                            </span>
                        </div>
                    </div>
                    <Button type="button" variant="outline" onClick={() => setAberta((v) => !v)}>
                        {aberta ? 'Recolher' : 'Ver avaliações'}
                    </Button>
                </div>
            </div>

            {aberta && (
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full text-xs border-collapse">
                        <thead>
                            <tr className="border-b border-outline-variant/60 text-on-surface-variant">
                                <th scope="col" className="text-left font-semibold py-1.5 pr-3">Projeto</th>
                                <th scope="col" className="text-right font-semibold py-1.5 px-2">Nota dele</th>
                                <th scope="col" className="text-right font-semibold py-1.5 px-2">Colegas</th>
                                <th scope="col" className="text-right font-semibold py-1.5 px-2">Diferença</th>
                                <th scope="col" className="text-right font-semibold py-1.5 px-2">Tempo</th>
                                <th scope="col" className="py-1.5 pl-2"><span className="sr-only">Notas</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {linha.itens.map((i) => (
                                <tr key={i.avaliacao_id} className="border-b border-outline-variant/30">
                                    <td className="py-1.5 pr-3 text-on-surface">
                                        {i.projeto ?? '—'}
                                        {i.uniforme && (
                                            <span className="block text-[10px] text-error">
                                                mesma resposta em todas as perguntas
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-1.5 px-2 text-right text-on-surface whitespace-nowrap">{nota(i.nota)}</td>
                                    <td className="py-1.5 px-2 text-right text-on-surface-variant whitespace-nowrap">
                                        {nota(i.media_outros)}
                                    </td>
                                    <td className={`py-1.5 px-2 text-right whitespace-nowrap ${i.desvio !== null && i.desvio < 0 ? 'text-error font-semibold' : 'text-on-surface-variant'}`}>
                                        {i.desvio === null ? '—' : `${i.desvio > 0 ? '+' : ''}${nota(i.desvio)}`}
                                    </td>
                                    <td className="py-1.5 px-2 text-right text-on-surface-variant whitespace-nowrap">
                                        {/* Sem registro de abertura é diferente de rápido:
                                            a avaliação é anterior à marcação do relógio. */}
                                        {i.minutos === null ? 'sem registro' : `${i.minutos} min`}
                                    </td>
                                    <td className="py-1.5 pl-2 text-right">
                                        <button
                                            type="button"
                                            onClick={() => onVerNotas(i)}
                                            className="text-primary font-semibold hover:underline whitespace-nowrap"
                                        >
                                            Ver notas
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </li>
    );
}

/**
 * Verificar disparidade → aba **Identificação de padrões**.
 *
 * A primeira aba olha para o projeto: onde as notas se afastaram. Esta olha
 * para o **avaliador** — quem deu nota máxima em tudo (o certificado fácil),
 * quem afundou projetos que os colegas aprovaram, quem respondeu a rubrica
 * inteira com o mesmo número e quem enviou minutos depois de abrir.
 *
 * Nada aqui é veredito: a tela diz "olhe para este" e mostra os números que
 * sustentam a suspeita. Os limiares são do admin, porque o que é muito numa
 * edição é pouco em outra.
 */
export default function PadroesAvaliadores({ onVerNotas }) {
    const [limiares, setLimiares] = useState({
        media_alta: '9,50',
        desvio_abaixo: '2,00',
        minutos_relampago: '10',
        min_avaliacoes: '3',
    });
    const [dados, setDados] = useState(null);
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState('');

    const analisar = useCallback(async (valores) => {
        setCarregando(true);
        setErro('');
        try {
            setDados(await getPadroesDeAvaliacao({
                media_alta: numero(valores.media_alta),
                desvio_abaixo: numero(valores.desvio_abaixo),
                minutos_relampago: Number(valores.minutos_relampago),
                min_avaliacoes: Number(valores.min_avaliacoes),
            }));
        } catch (err) {
            const { message, fields } = extractErrors(err);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível analisar.');
        } finally {
            setCarregando(false);
        }
    }, []);

    // A aba já abre com a análise pelos limiares padrão: chegar nela e ter de
    // clicar em "analisar" para ver qualquer coisa seria um passo à toa.
    useEffect(() => { analisar(limiares); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const campo = (chave, valor) => setLimiares((l) => ({ ...l, [chave]: valor }));

    return (
        <div className="max-w-5xl">
            <p className="text-sm text-on-surface-variant mb-4">
                Avaliadores que avaliaram de um jeito que <strong>não parece avaliar</strong>: nota
                máxima em tudo, nota sistematicamente muito abaixo da dos colegas nos mesmos
                projetos, a mesma resposta em todas as perguntas ou o envio poucos minutos depois de
                abrir. Nada aqui é veredito — são os números para você olhar.
            </p>

            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

            <form
                className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6"
                onSubmit={(e) => { e.preventDefault(); analisar(limiares); }}
            >
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label="Média alta a partir de" hint="Notas infladas.">
                        <Input
                            type="text" inputMode="decimal" value={limiares.media_alta}
                            aria-label="Média alta a partir de"
                            onChange={(e) => campo('media_alta', e.target.value)}
                        />
                    </Field>
                    <Field label="Abaixo dos colegas em" hint="Pontos de diferença média.">
                        <Input
                            type="text" inputMode="decimal" value={limiares.desvio_abaixo}
                            aria-label="Abaixo dos colegas em"
                            onChange={(e) => campo('desvio_abaixo', e.target.value)}
                        />
                    </Field>
                    <Field label="Avaliação relâmpago abaixo de" hint="Minutos entre abrir e enviar.">
                        <Input
                            type="number" min="1" value={limiares.minutos_relampago}
                            aria-label="Avaliação relâmpago abaixo de"
                            onChange={(e) => campo('minutos_relampago', e.target.value)}
                        />
                    </Field>
                    <Field label="Mínimo de avaliações" hint="Com menos que isso não há padrão.">
                        <Input
                            type="number" min="1" value={limiares.min_avaliacoes}
                            aria-label="Mínimo de avaliações"
                            onChange={(e) => campo('min_avaliacoes', e.target.value)}
                        />
                    </Field>
                </div>
                <div className="mt-3">
                    <Button type="submit" loading={carregando}>
                        <span className="material-symbols-outlined text-[18px]">query_stats</span>
                        Analisar
                    </Button>
                </div>
            </form>

            {dados && (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                    <div className="px-4 py-3 bg-surface-variant/40">
                        <h2 className="font-display font-semibold text-on-surface">
                            {dados.total} {dados.total === 1 ? 'avaliador' : 'avaliadores'} com algum sinal
                        </h2>
                        <p className="text-xs text-on-surface-variant">
                            {dados.analisados} {dados.analisados === 1 ? 'avaliador analisado' : 'avaliadores analisados'}
                            {' '}(com {dados.limiares.min_avaliacoes} ou mais avaliações concluídas).
                        </p>
                    </div>

                    {dados.avaliadores.length === 0 ? (
                        <p className="p-6 text-center text-sm text-on-surface-variant">
                            Nenhum avaliador disparou os sinais com estes limiares.
                        </p>
                    ) : (
                        <ul className="divide-y divide-outline-variant/30">
                            {dados.avaliadores.map((l) => (
                                <Linha key={l.avaliador_id} linha={l} onVerNotas={onVerNotas} />
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {dados && (
                <dl className="mt-4 grid gap-2 sm:grid-cols-2 text-xs text-on-surface-variant">
                    {dados.padroes.map((p) => (
                        <div key={p.chave} className="rounded-lg border border-outline-variant/40 p-3">
                            <dt className="font-semibold text-on-surface">{p.titulo}</dt>
                            <dd>{p.descricao}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}
