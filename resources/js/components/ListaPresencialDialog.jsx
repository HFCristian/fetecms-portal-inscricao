import { useEffect, useMemo, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import { CampoCota, VAZIA, paraApi, preenchida } from './ListaFinalDialog.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getOpcoesListaPresencial, gerarListaPresencial } from '../lib/listasPresenciais.js';

const PASSOS = ['Categorias', 'Áreas'];

/** "3 em cada área": o campo guarda o que foi digitado e repassa para todas. */
function CampoTodasAsAreas({ categoria, onAplicar }) {
    const [cota, setCota] = useState(VAZIA);

    return (
        <CampoCota
            id={`lista-presencial-todas-${categoria}`}
            rotulo="Mesma quantidade em todas as áreas"
            cota={cota}
            placeholder="ex.: 3"
            onChange={(nova) => { setCota(nova); onAplicar(nova); }}
        />
    );
}

/**
 * "Gerar lista" do **ranking presencial** (Sprint 251): o assistente da lista
 * online sem o passo do interior — quantos projetos por **categoria** e, dentro
 * dela, por **área**. Entram os de maior média presencial que couberem; "3 em
 * cada área" é o pódio de cada grupo.
 *
 * Cada quantidade é número ou porcentagem do recorte de cima; em branco não
 * limita, 0 deixa o recorte de fora. A lista abre como rascunho.
 */
export default function ListaPresencialDialog({ open, teste = false, onClose }) {
    const [opcoes, setOpcoes] = useState(null);
    const [passo, setPasso] = useState(0);
    const [total, setTotal] = useState(VAZIA);
    // { [categoria]: { cota, areas: { [areaId]: { cota } } } }
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
        setOpcoes(null);
        getOpcoesListaPresencial(teste).then(setOpcoes).catch(() => setErro('Não foi possível carregar as opções.'));
    }, [open, teste]);

    const categoriasAtivas = useMemo(() => (opcoes?.categorias ?? []).filter((c) => {
        const cota = cotas[c.value]?.cota;
        return !preenchida(cota) || Number(cota.valor) > 0;
    }), [opcoes, cotas]);

    if (!open) return null;

    const setCotaCategoria = (categoria, cota) => setCotas((atual) => ({
        ...atual,
        [categoria]: { ...(atual[categoria] ?? {}), cota },
    }));

    const setCotaArea = (categoria, areaId, cota) => setCotas((atual) => {
        const daCategoria = atual[categoria] ?? {};
        const areas = daCategoria.areas ?? {};
        return { ...atual, [categoria]: { ...daCategoria, areas: { ...areas, [areaId]: { cota } } } };
    });

    // "3 em cada área": a mesma quantidade em todas as áreas da categoria.
    const aplicarEmTodas = (categoria, cota) => setCotas((atual) => {
        const c = opcoes.categorias.find((x) => x.value === categoria);
        const areas = Object.fromEntries(c.areas.map((a) => [a.id, { cota }]));
        return { ...atual, [categoria]: { ...(atual[categoria] ?? {}), areas } };
    });

    function montarPayload() {
        const categorias = {};

        for (const c of opcoes.categorias) {
            const daCategoria = cotas[c.value] ?? {};
            const areas = {};

            for (const [areaId, config] of Object.entries(daCategoria.areas ?? {})) {
                const cota = paraApi(config.cota);
                if (cota) areas[areaId] = { cota };
            }

            const cota = paraApi(daCategoria.cota);
            if (cota || Object.keys(areas).length > 0) categorias[c.value] = { cota, areas };
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
                    Entram os finalistas de <strong>maior média presencial</strong> que couberem nas quantidades
                    que você definir. Cada quantidade pode ser um <strong>número</strong> ou uma{' '}
                    <strong>porcentagem</strong> do recorte acima dela; em branco não limita nada. Depois, as
                    credenciais e os prêmios entram por conta própria.
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
                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-1">Quantos projetos em cada categoria?</legend>
                        <p className="text-xs text-on-surface-variant mb-2">
                            A porcentagem é sobre os {opcoes.total_disponivel} finalistas com avaliação presencial
                            {preenchida(total) ? ' (ou sobre o total que você definir abaixo)' : ''}.
                        </p>
                        {opcoes.categorias.map((c) => (
                            <CampoCota
                                key={c.value}
                                id={`lista-presencial-cat-${c.value}`}
                                rotulo={c.label}
                                detalhe={`${c.disponiveis} avaliado${c.disponiveis === 1 ? '' : 's'}`}
                                cota={cotas[c.value]?.cota}
                                onChange={(cota) => setCotaCategoria(c.value, cota)}
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
                ) : (
                    <div className="space-y-4">
                        <p className="text-xs text-on-surface-variant">
                            Dentro de cada categoria, quantos projetos de cada área — a colocação é contada dentro do
                            grupo categoria + área. A porcentagem é sobre a cota da categoria.
                        </p>
                        {categoriasAtivas.map((c) => (
                            <fieldset key={c.value}>
                                <legend className="text-sm font-semibold text-primary-container mb-1">{c.label}</legend>
                                <div className="rounded-lg bg-surface-container-low px-2 mb-1">
                                    <CampoTodasAsAreas categoria={c.value} onAplicar={(cota) => aplicarEmTodas(c.value, cota)} />
                                </div>
                                {c.areas.map((a) => (
                                    <CampoCota
                                        key={a.id}
                                        id={`lista-presencial-area-${c.value}-${a.id}`}
                                        rotulo={a.nome}
                                        detalhe={`${a.disponiveis} avaliado${a.disponiveis === 1 ? '' : 's'}`}
                                        cota={cotas[c.value]?.areas?.[a.id]?.cota}
                                        onChange={(cota) => setCotaArea(c.value, a.id, cota)}
                                    />
                                ))}
                            </fieldset>
                        ))}
                    </div>
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
