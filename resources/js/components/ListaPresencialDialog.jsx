import { useEffect, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import { CampoCota, VAZIA, paraApi } from './ListaFinalDialog.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getOpcoesListaPresencial, gerarListaPresencial } from '../lib/listasPresenciais.js';

const PASSOS = ['Colocação por área', 'Limites (opcional)'];

const lugarClass =
    'w-16 bg-surface border border-outline-variant rounded-lg px-2 py-2 text-sm text-on-surface text-right ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

/**
 * "Até o __º lugar" de uma área (Sprint 255): em branco não limita, 0 deixa a
 * área de fora.
 */
function CampoLugar({ id, rotulo, detalhe, valor, onChange }) {
    return (
        <div className="flex items-center gap-2 py-1">
            <label htmlFor={id} className="text-sm text-on-surface flex-1 min-w-0">
                <span className="block truncate">{rotulo}</span>
                {detalhe && <span className="block text-xs text-on-surface-variant">{detalhe}</span>}
            </label>
            <span className="text-xs text-on-surface-variant">até o</span>
            <input
                id={id}
                type="number"
                inputMode="numeric"
                min={0}
                max={99}
                placeholder="todos"
                aria-label={`Até que lugar em ${rotulo}`}
                value={valor}
                onChange={(e) => onChange(e.target.value)}
                className={lugarClass}
            />
            <span className="text-xs text-on-surface-variant w-10">º lugar</span>
        </div>
    );
}

/** O mesmo lugar em todas as áreas da categoria: guarda o digitado e repassa. */
function CampoTodasAsAreas({ categoria, rotulo, inicial, onAplicar }) {
    const [valor, setValor] = useState(inicial);

    return (
        <CampoLugar
            id={`lista-presencial-todas-${categoria}`}
            rotulo={rotulo}
            valor={valor}
            onChange={(novo) => { setValor(novo); onAplicar(novo); }}
        />
    );
}

/**
 * "Gerar lista" do **ranking presencial** (Sprints 251 e 255). Por padrão
 * premia **do 1º ao 4º lugar** de cada área em cada categoria — o assistente
 * já vem preenchido assim, e o admin muda área a área (ou a categoria
 * inteira). A colocação é pela média presencial no grupo: empate no último
 * lugar leva os dois. Depois, tetos opcionais por categoria e no total.
 *
 * A lista abre como rascunho.
 */
export default function ListaPresencialDialog({ open, teste = false, onClose }) {
    const [opcoes, setOpcoes] = useState(null);
    const [passo, setPasso] = useState(0);
    // { [categoria]: { [areaId]: '4' } } — texto do campo; '' não limita.
    const [lugares, setLugares] = useState({});
    const [total, setTotal] = useState(VAZIA);
    // Tetos opcionais por categoria: { [categoria]: cota }.
    const [cotas, setCotas] = useState({});
    const [tipo, setTipo] = useState('preliminar');
    const [nome, setNome] = useState('');
    const [descricao, setDescricao] = useState('');
    const [gerando, setGerando] = useState(false);
    const [erro, setErro] = useState('');

    useEffect(() => {
        if (!open) return;
        setErro('');
        setPasso(0);
        setTipo('preliminar');
        setNome('');
        setDescricao('');
        setTotal(VAZIA);
        setCotas({});
        setOpcoes(null);
        getOpcoesListaPresencial(teste)
            .then((o) => {
                // O padrão (do 1º ao 4º) já preenchido em todas as áreas.
                const padrao = String(o.lugares_padrao ?? 4);
                setLugares(Object.fromEntries(o.categorias.map((c) => [
                    c.value, Object.fromEntries(c.areas.map((a) => [a.id, padrao])),
                ])));
                setOpcoes(o);
            })
            .catch(() => setErro('Não foi possível carregar as opções.'));
    }, [open, teste]);

    const padrao = String(opcoes?.lugares_padrao ?? 4);

    if (!open) return null;

    const setLugar = (categoria, areaId, valor) => setLugares((atual) => ({
        ...atual, [categoria]: { ...(atual[categoria] ?? {}), [areaId]: valor },
    }));

    const aplicarEmTodas = (categoria, valor) => setLugares((atual) => {
        const c = opcoes.categorias.find((x) => x.value === categoria);
        return { ...atual, [categoria]: Object.fromEntries(c.areas.map((a) => [a.id, valor])) };
    });

    function montarPayload() {
        const categorias = {};

        for (const c of opcoes.categorias) {
            const areas = {};
            for (const a of c.areas) {
                const valor = lugares[c.value]?.[a.id] ?? padrao;
                areas[a.id] = { lugares: valor === '' ? null : Number(valor) };
            }
            categorias[c.value] = { cota: paraApi(cotas[c.value]), areas };
        }

        return {
            total: paraApi(total),
            categorias,
            tipo,
            nome: nome.trim() !== '' ? nome.trim() : null,
            descricao: descricao.trim() !== '' ? descricao.trim() : null,
        };
    }

    async function gerar() {
        setGerando(true); setErro('');
        try {
            const previa = await gerarListaPresencial(montarPayload(), teste);
            onClose(previa.lista.id);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível gerar a lista. Tente novamente.');
        } finally {
            setGerando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Gerar lista presencial">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 max-h-[90vh] overflow-auto">
                <h3 className="font-display text-xl font-semibold text-on-surface mb-1">Gerar lista presencial</h3>
                <p className="text-sm text-on-surface-variant mb-4">
                    Por padrão, premia do <strong>1º ao {padrao}º lugar</strong> de cada área do conhecimento em cada
                    categoria, pela <strong>média presencial</strong> — empate no último lugar leva os dois. Mude o que
                    quiser abaixo; em branco não limita, 0 deixa a área de fora. Depois, as credenciais e os prêmios
                    entram por conta própria.
                </p>

                <ol className="flex items-center gap-2 mb-4 text-xs">
                    {PASSOS.map((rotulo, i) => (
                        <li key={rotulo} className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setPasso(i)}
                                className={`px-2 py-1 rounded-full font-semibold ${i === passo
                                    ? 'bg-primary-container text-on-primary'
                                    : 'text-on-surface-variant hover:bg-surface-variant'}`}
                            >
                                {i + 1}. {rotulo}
                            </button>
                            {i < PASSOS.length - 1 && <span className="text-on-surface-variant">›</span>}
                        </li>
                    ))}
                </ol>

                {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

                {opcoes === null ? (
                    !erro && (
                        <div className="text-center py-8 text-on-surface-variant">
                            <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                        </div>
                    )
                ) : passo === 0 ? (
                    <div className="space-y-4">
                        {opcoes.categorias.map((c) => (
                            <fieldset key={c.value}>
                                <legend className="text-sm font-semibold text-primary-container mb-1">
                                    {c.label}
                                    <span className="ml-1 font-normal text-xs text-on-surface-variant">
                                        · {c.disponiveis} avaliado{c.disponiveis === 1 ? '' : 's'}
                                    </span>
                                </legend>
                                <div className="rounded-lg bg-surface-container-low px-2 mb-1">
                                    <CampoTodasAsAreas
                                        categoria={c.value}
                                        rotulo={`Todas as áreas da ${c.label}`}
                                        inicial={padrao}
                                        onAplicar={(valor) => aplicarEmTodas(c.value, valor)}
                                    />
                                </div>
                                {c.areas.map((a) => (
                                    <CampoLugar
                                        key={a.id}
                                        id={`lista-presencial-lugar-${c.value}-${a.id}`}
                                        rotulo={a.nome}
                                        detalhe={`${a.disponiveis} avaliado${a.disponiveis === 1 ? '' : 's'}`}
                                        valor={lugares[c.value]?.[a.id] ?? padrao}
                                        onChange={(valor) => setLugar(c.value, a.id, valor)}
                                    />
                                ))}
                            </fieldset>
                        ))}
                    </div>
                ) : (
                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-1">Limites (opcional)</legend>
                        <p className="text-xs text-on-surface-variant mb-2">
                            Teto de projetos por categoria e no total, por cima das colocações — os de maior média
                            primeiro. Em branco não limita. A porcentagem é sobre os {opcoes.total_disponivel} finalistas
                            com avaliação presencial.
                        </p>
                        {opcoes.categorias.map((c) => (
                            <CampoCota
                                key={c.value}
                                id={`lista-presencial-cat-${c.value}`}
                                rotulo={c.label}
                                detalhe={`${c.disponiveis} avaliado${c.disponiveis === 1 ? '' : 's'}`}
                                cota={cotas[c.value]}
                                onChange={(cota) => setCotas((atual) => ({ ...atual, [c.value]: cota }))}
                            />
                        ))}
                        <div className="border-t border-outline-variant/40 mt-3 pt-3">
                            <CampoCota
                                id="lista-presencial-total"
                                rotulo="Total geral da lista"
                                detalhe={`${opcoes.total_disponivel} avaliado(s) · opcional`}
                                cota={total}
                                onChange={setTotal}
                                placeholder="todos"
                            />
                        </div>
                    </fieldset>
                )}

                {passo === PASSOS.length - 1 && opcoes !== null && (
                    <fieldset className="mt-4 rounded-lg border border-outline-variant p-3 space-y-2">
                        <legend className="px-1 text-sm font-semibold text-on-surface">Que lista é esta?</legend>
                        {[
                            ['preliminar', 'Lista preliminar', 'Várias podem existir ao mesmo tempo. Depois, outras listas podem ser montadas juntando esta com as demais.'],
                            ['final', 'Lista final', 'Ao ser gerada, vira a única ativa: é o resultado oficial da premiação, e os premiados dela aparecem no Cerimonial.'],
                        ].map(([valor, rotulo, texto]) => (
                            <label key={valor} className="flex items-start gap-3 cursor-pointer">
                                <input type="radio" name="tipo-lista-presencial" value={valor} checked={tipo === valor} onChange={() => setTipo(valor)} className="mt-1" />
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold text-on-surface">{rotulo}</span>
                                    <span className="block text-xs text-on-surface-variant">{texto}</span>
                                </span>
                            </label>
                        ))}
                        <input
                            value={nome}
                            onChange={(e) => setNome(e.target.value)}
                            placeholder="Apelido da lista (opcional) — ex.: Pódio por área"
                            aria-label="Apelido da lista"
                            maxLength={120}
                            className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                        />
                        <textarea
                            value={descricao}
                            onChange={(e) => setDescricao(e.target.value)}
                            placeholder="Descrição: o que esta lista reúne (opcional)"
                            aria-label="Descrição da lista"
                            maxLength={1000}
                            rows={2}
                            className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                        />
                        <p className="text-xs text-on-surface-variant">
                            A lista abre como <strong>rascunho</strong>: você inclui e retira projetos à vontade e só
                            então a gera.
                        </p>
                    </fieldset>
                )}

                <div className="flex justify-between gap-2 pt-4">
                    <Button type="button" variant="outline" onClick={() => onClose(null)}>Cancelar</Button>
                    <div className="flex gap-2">
                        {passo > 0 && (
                            <Button type="button" variant="outline" onClick={() => setPasso(passo - 1)}>Voltar</Button>
                        )}
                        {passo < PASSOS.length - 1 ? (
                            <Button type="button" disabled={opcoes === null} onClick={() => setPasso(passo + 1)}>Continuar</Button>
                        ) : (
                            <Button type="button" loading={gerando} disabled={opcoes === null} onClick={gerar}>
                                <span className="material-symbols-outlined text-[18px]">preview</span>
                                Montar rascunho
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
