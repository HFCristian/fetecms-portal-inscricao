import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import AvaliacaoModal from '../components/AvaliacaoModal.jsx';
import DialogoNotasProjeto from '../components/DialogoNotasProjeto.jsx';
import PadroesAvaliadores from '../components/PadroesAvaliadores.jsx';
import { Alert, Button, Field, Input } from '../components/ui.jsx';
import {
    API_AVALIACAO_ORGANIZACAO,
    getNotasDoProjeto,
    getVerificacaoDisparidade,
    getVerificacoesDisparidade,
    gerarVerificacaoDisparidade,
} from '../lib/admin.js';
import { extractErrors } from '../lib/auth.jsx';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

/** Nota em pt_BR com duas casas (6,74). */
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

/**
 * Uma linha da lista: o projeto, a distância entre a maior e a menor nota e o
 * atalho para designar mais um avaliador — que é a ação que a tela existe para
 * provocar.
 */
function Item({ item, onVerNotas }) {
    return (
        <li className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-on-surface truncate">{item.titulo}</p>
                <p className="text-xs text-on-surface-variant truncate">
                    {item.area ?? 'Sem área'}{item.categoria ? ` · ${item.categoria}` : ''}
                    {' · '}{item.avaliacoes} {item.avaliacoes === 1 ? 'avaliação' : 'avaliações'}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-3 shrink-0">
                <div className="text-center">
                    <div className="text-xs text-on-surface-variant leading-tight">notas</div>
                    <div className="text-sm text-on-surface">
                        {nota(item.nota_min)} — {nota(item.nota_max)}
                    </div>
                </div>
                <div className="text-center w-20">
                    <div className="text-xl font-bold text-error">{nota(item.amplitude)}</div>
                    <div className="text-[10px] text-on-surface-variant leading-tight">
                        de diferença
                        <span className="block">média {nota(item.media)}</span>
                    </div>
                </div>
                {/* Antes de designar mais um parecer vale ver os que já
                    existem: é a comparação por seção que diz se a distância veio
                    de um desacordo real ou de uma nota fora do lugar. */}
                <button
                    type="button"
                    onClick={() => onVerNotas(item)}
                    className="inline-flex items-center gap-1 rounded-lg border border-outline-variant px-3 py-2 text-sm font-semibold text-on-surface hover:bg-surface-variant transition-colors"
                >
                    <span className="material-symbols-outlined text-[18px]">scoreboard</span>
                    Ver notas
                </button>
                {/* Leva o título junto para o diálogo de designação já achar o
                    projeto na busca do servidor. */}
                <Link
                    to={`/admin/avaliacao/designacoes?projeto=${item.projeto_id}&q=${encodeURIComponent(item.titulo)}`}
                    className="inline-flex items-center gap-1 rounded-lg border border-outline-variant px-3 py-2 text-sm font-semibold text-on-surface hover:bg-surface-variant transition-colors"
                >
                    <span className="material-symbols-outlined text-[18px]">person_add</span>
                    Designar
                </Link>
            </div>
        </li>
    );
}

/**
 * As duas leituras da mesma verificação: a lista olha para o **projeto** (onde
 * as notas se afastaram) e os padrões olham para o **avaliador** (quem as deu
 * de um jeito estranho). São perguntas diferentes sobre o mesmo material, e é
 * por isso que ficam na mesma tela em vez de virar duas.
 */
function Abas({ aba, setAba }) {
    const abas = [
        { chave: 'projetos', rotulo: 'Lista de projetos' },
        { chave: 'padroes', rotulo: 'Identificação de padrões' },
    ];

    return (
        <div className="flex flex-wrap gap-2 mb-6" role="tablist">
            {abas.map((t) => (
                <button
                    key={t.chave}
                    type="button"
                    role="tab"
                    aria-selected={aba === t.chave}
                    onClick={() => setAba(t.chave)}
                    className={`text-sm font-semibold px-4 py-2 rounded-lg border transition-colors ${
                        aba === t.chave
                            ? 'bg-primary-container text-on-primary border-primary-container'
                            : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:bg-surface-variant'
                    }`}
                >
                    {t.rotulo}
                </button>
            ))}
        </div>
    );
}

/**
 * Avaliação online → Ranking dos projetos → **Verificar disparidade**.
 *
 * O ranking ordena pela média, e a média esconde o desacordo: 9,50 com 4,50 dá
 * o mesmo 7,00 que 7,00 com 7,00. Aqui o admin diz de quanto é a diferença que
 * considera demais e recebe os projetos em que a distância entre a maior e a
 * menor nota chegou lá — cada um com o atalho para designar mais um avaliador.
 *
 * Toda lista gerada fica registrada: o que costuma vir depois dela é um parecer
 * a mais no projeto, e a trilha precisa poder explicar de onde ele saiu.
 */
export default function AvaliacaoDisparidade() {
    const [diferenca, setDiferenca] = useState('1,00');
    const [verificacao, setVerificacao] = useState(null);
    const [historico, setHistorico] = useState(null);
    const [gerando, setGerando] = useState(false);
    const [erro, setErro] = useState('');
    const [notas, setNotas] = useState(null);      // { carregando, dados, erro }
    const [aba, setAba] = useState('projetos');
    // Substituição "eu mesmo avalio": a rubrica abre na hora, por cima do
    // diálogo de notas.
    const [avaliandoId, setAvaliandoId] = useState(null);

    const carregarHistorico = useCallback(() => {
        getVerificacoesDisparidade()
            .then(setHistorico)
            .catch(() => setHistorico([]));
    }, []);

    useEffect(() => { carregarHistorico(); }, [carregarHistorico]);

    async function gerar(e) {
        e.preventDefault();
        setGerando(true);
        setErro('');
        try {
            // O admin digita com vírgula; a API recebe ponto.
            const valor = Number(String(diferenca).replace(',', '.'));
            setVerificacao(await gerarVerificacaoDisparidade(valor));
            carregarHistorico();
        } catch (err) {
            const { message, fields } = extractErrors(err);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível gerar a lista.');
        } finally {
            setGerando(false);
        }
    }

    // Serve às duas abas: a lista manda o item do projeto, a tabela de padrões
    // manda a avaliação — as duas trazem o `projeto_id`, que é o que importa.
    async function verNotas(item) {
        setNotas({ carregando: true, dados: null, erro: '' });
        try {
            setNotas({ carregando: false, dados: await getNotasDoProjeto(item.projeto_id), erro: '' });
        } catch (err) {
            const { message, fields } = extractErrors(err);
            setNotas({
                carregando: false,
                dados: null,
                erro: Object.values(fields ?? {})[0] || message || 'Não foi possível abrir as notas.',
            });
        }
    }

    async function abrir(id) {
        setErro('');
        try {
            setVerificacao(await getVerificacaoDisparidade(id));
        } catch {
            setErro('Não foi possível abrir a verificação.');
        }
    }

    return (
        <AppShell>
            <Link to="/admin/avaliacao/ranking" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Ranking dos projetos
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Verificar disparidade</h1>
            <p className="text-sm text-on-surface-variant mb-4 max-w-3xl">
                Duas leituras do mesmo material: a <strong>lista de projetos</strong> mostra onde as
                notas se afastaram; a <strong>identificação de padrões</strong> mostra quem as deu de
                um jeito estranho.
            </p>

            <Abas aba={aba} setAba={setAba} />

            {aba === 'padroes' ? (
                <PadroesAvaliadores onVerNotas={verNotas} />
            ) : (
            <>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Projetos em que os avaliadores discordaram: a lista traz quem teve a{' '}
                <strong>maior e a menor nota</strong> afastadas pelo menos a diferença que você
                informar. Só entram projetos com duas ou mais avaliações concluídas — com uma só
                não há distância para medir. Cada lista gerada fica registrada.
            </p>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            <form onSubmit={gerar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                <div className="flex flex-col sm:flex-row sm:items-end gap-3">
                    <div className="sm:w-56">
                        <Field
                            label="Diferença entre as notas"
                            required
                            hint="Em pontos da nota final (0 a 10), com duas casas."
                        >
                            <Input
                                type="text"
                                inputMode="decimal"
                                value={diferenca}
                                onChange={(e) => setDiferenca(e.target.value)}
                                placeholder="1,00"
                                aria-label="Diferença entre as notas"
                            />
                        </Field>
                    </div>
                    <div className="sm:pb-1">
                        <Button type="submit" loading={gerando}>
                            <span className="material-symbols-outlined text-[18px]">rule</span>
                            Gerar lista
                        </Button>
                    </div>
                </div>
            </form>

            {verificacao && (
                <div className="max-w-5xl mb-8">
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                        <div className="px-4 py-3 bg-surface-variant/40">
                            <h2 className="font-display font-semibold text-on-surface">
                                Diferença de {nota(verificacao.diferenca)} ponto(s) ou mais
                            </h2>
                            <p className="text-xs text-on-surface-variant">
                                {verificacao.total} {verificacao.total === 1 ? 'projeto' : 'projetos'}
                                {verificacao.autor ? ` · gerada por ${verificacao.autor}` : ''}
                                {' · '}{dataHora(verificacao.criada_em)}
                            </p>
                        </div>
                        {verificacao.itens.length === 0 ? (
                            <p className="p-6 text-center text-sm text-on-surface-variant">
                                Nenhum projeto com essa diferença entre as notas.
                            </p>
                        ) : (
                            <ul className="divide-y divide-outline-variant/30">
                                {verificacao.itens.map((i) => (
                                    <Item key={i.projeto_id} item={i} onVerNotas={verNotas} />
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}

            <div className="max-w-3xl">
                <h2 className="font-display font-semibold text-on-surface mb-2">Verificações anteriores</h2>
                {historico === null ? (
                    <div className="text-center py-6 text-on-surface-variant">
                        <span className="inline-block w-6 h-6 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : historico.length === 0 ? (
                    <p className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant">
                        Nenhuma verificação registrada nesta edição.
                    </p>
                ) : (
                    <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40">
                        {historico.map((v) => (
                            <li key={v.id} className="p-4 flex items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-on-surface">
                                        Diferença de {nota(v.diferenca)} · {v.total}{' '}
                                        {v.total === 1 ? 'projeto' : 'projetos'}
                                    </p>
                                    <p className="text-xs text-on-surface-variant">
                                        {dataHora(v.criada_em)}{v.autor ? ` · ${v.autor}` : ''}
                                    </p>
                                </div>
                                <Button type="button" variant="outline" onClick={() => abrir(v.id)}>
                                    Abrir
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            </>
            )}

            {notas && (
                <DialogoNotasProjeto
                    dados={notas.dados}
                    carregando={notas.carregando}
                    erro={notas.erro}
                    onFechar={() => setNotas(null)}
                    onAtualizar={(dados) => setNotas({ carregando: false, dados, erro: '' })}
                    onAvaliarAgora={setAvaliandoId}
                />
            )}

            {avaliandoId && (
                <AvaliacaoModal
                    avaliacaoId={avaliandoId}
                    api={API_AVALIACAO_ORGANIZACAO}
                    onFechar={() => setAvaliandoId(null)}
                    onAtualizado={() => {
                        // A nota nova entra na média: recarrega a comparação.
                        if (notas?.dados?.projeto?.id) verNotas({ projeto_id: notas.dados.projeto.id });
                    }}
                />
            )}
        </AppShell>
    );
}
