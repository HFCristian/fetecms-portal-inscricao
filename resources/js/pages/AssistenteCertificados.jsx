import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import ConferirNomes from '../components/ConferirNomes.jsx';
import { Alert, Button, DateInput, Input } from '../components/ui.jsx';
import {
    getOpcoesCertificados, getListaCertificados, salvarListaCertificados, getResumoCertificados,
    gerarListaCertificados, duracao, dataBr,
} from '../lib/certificados.js';
import { extractErrors } from '../lib/auth.jsx';

const PASSOS = ['Fases', 'Público-alvo', 'Carga horária', 'Resumo'];

const ROTULO_MODO = {
    fixa: 'Carga fixa',
    por_projeto: 'Por projeto avaliado',
    por_turno: 'Por turno trabalhado',
};

const VAZIA = { fases: [], publicos: [], categorias: {} };

/** Horas e minutos em dois campos; devolve minutos (ou null em branco). */
function CampoDuracao({ id, rotulo, minutos, onChange, ajuda, erro }) {
    const h = minutos === null || minutos === undefined ? '' : Math.floor(minutos / 60);
    const m = minutos === null || minutos === undefined ? '' : minutos % 60;

    function mudar(horas, mins) {
        if (horas === '' && mins === '') {
            onChange(null);
            return;
        }
        onChange(Math.max(0, (Number(horas) || 0) * 60 + (Number(mins) || 0)));
    }

    return (
        <div>
            <span className="block text-sm font-semibold text-on-surface mb-1" id={`${id}-rotulo`}>{rotulo}</span>
            <div className="flex items-center gap-2" role="group" aria-labelledby={`${id}-rotulo`}>
                <div className="w-24">
                    <Input id={`${id}-h`} aria-label={`${rotulo} — horas`} type="number" min="0" max="1000"
                        value={h} error={erro} onChange={(e) => mudar(e.target.value, m)} />
                </div>
                <span className="text-sm text-on-surface-variant">h</span>
                <div className="w-24">
                    <Input id={`${id}-m`} aria-label={`${rotulo} — minutos`} type="number" min="0" max="59" step="5"
                        value={m} error={erro} onChange={(e) => mudar(h, Math.min(59, Number(e.target.value) || 0))} />
                </div>
                <span className="text-sm text-on-surface-variant">min</span>
            </div>
            {ajuda && <p className="text-xs text-on-surface-variant mt-1">{ajuda}</p>}
            {erro && <p className="text-xs text-error mt-1">{erro}</p>}
        </div>
    );
}

/** Uma categoria no passo da carga horária: modo, carga, teto e período. */
function CargaDaCategoria({ categoria, valor, onChange, erro }) {
    const variavel = valor.modo !== 'fixa';
    const unidade = valor.modo === 'por_turno' ? 'turno' : 'projeto avaliado';
    const set = (campo, v) => onChange({ ...valor, [campo]: v });

    return (
        <fieldset className="rounded-xl border border-outline-variant/60 p-4 space-y-3">
            <legend className="px-1 font-display font-semibold text-on-surface">{categoria.rotulo}</legend>
            {categoria.modos.length > 1 && (
                <div className="flex flex-wrap gap-4 text-sm">
                    {categoria.modos.map((modo) => (
                        <label key={modo} className="inline-flex items-center gap-2 cursor-pointer">
                            <input type="radio" name={`modo-${categoria.chave}`} checked={valor.modo === modo} onChange={() => set('modo', modo)} />
                            {ROTULO_MODO[modo]}
                        </label>
                    ))}
                </div>
            )}
            <div className="grid sm:grid-cols-2 gap-4">
                <CampoDuracao
                    id={`carga-${categoria.chave}`}
                    rotulo={variavel ? `Horas por ${unidade}` : 'Carga horária'}
                    minutos={valor.minutos}
                    erro={erro}
                    onChange={(v) => set('minutos', v)}
                    ajuda={valor.modo === 'por_turno' ? 'A conta sem turnos cadastrados conta como um turno (a janela de acesso).' : null}
                />
                {variavel && (
                    <CampoDuracao
                        id={`teto-${categoria.chave}`}
                        rotulo="Limite de horas (opcional)"
                        minutos={valor.teto_minutos}
                        onChange={(v) => set('teto_minutos', v === 0 ? null : v)}
                        ajuda="Ninguém passa deste total, por mais que tenha feito. Em branco, sem limite."
                    />
                )}
            </div>
            <div className="grid sm:grid-cols-2 gap-4">
                <label className="text-sm">
                    <span className="block font-semibold text-on-surface mb-1">Data de início</span>
                    <DateInput aria-label={`Data de início — ${categoria.rotulo}`} value={valor.data_inicio ?? ''}
                        onChange={(e) => set('data_inicio', e.target.value || null)} />
                </label>
                <label className="text-sm">
                    <span className="block font-semibold text-on-surface mb-1">Data de fim</span>
                    <DateInput aria-label={`Data de fim — ${categoria.rotulo}`} value={valor.data_fim ?? ''}
                        onChange={(e) => set('data_fim', e.target.value || null)} />
                </label>
            </div>
            <p className="text-xs text-on-surface-variant">
                O arquivo leva a carga em horas inteiras, arredondadas para cima (7h30 sai 8).
            </p>
        </fieldset>
    );
}

/**
 * O assistente da lista de certificados (Sprint 259): fases → público-alvo →
 * carga horária → resumo, com a conferência de nomes de cada categoria.
 *
 * O assistente é gravado a cada passo concluído e sempre que uma conferência
 * de nomes fecha; "Salvar rascunho" grava a qualquer momento. A lista nasce no
 * primeiro salvamento — até lá não há o que guardar. No fim, gerar congela a
 * lista e leva à tela dela.
 */
export default function AssistenteCertificados() {
    const { id: idDaRota } = useParams();
    const navigate = useNavigate();
    const [opcoes, setOpcoes] = useState(null);
    const [listaId, setListaId] = useState(idDaRota ? Number(idDaRota) : null);
    const [lista, setLista] = useState(null);
    const [nome, setNome] = useState('');
    const [config, setConfig] = useState(VAZIA);
    const [categorias, setCategorias] = useState([]);
    const [passo, setPasso] = useState(0);
    const [alcancado, setAlcancado] = useState(0);
    const [resumo, setResumo] = useState(null);
    const [conferindo, setConferindo] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [gerando, setGerando] = useState(false);
    const [salvoEm, setSalvoEm] = useState(null);
    const [erro, setErro] = useState('');
    const [erros, setErros] = useState({});
    const [carregando, setCarregando] = useState(true);

    function aplicar(dados) {
        setLista(dados);
        setListaId(dados.id);
        setNome(dados.nome);
        setConfig(dados.config ?? VAZIA);
        setCategorias(dados.categorias ?? []);
        setAlcancado((a) => Math.max(a, dados.passo ?? 0));
    }

    useEffect(() => {
        // A rota troca de /nova para /listas/:id quando a lista nasce: o que
        // está na tela já é ela, não há o que recarregar.
        if (opcoes && idDaRota && Number(idDaRota) === listaId) return;
        const pedidos = [getOpcoesCertificados()];
        if (idDaRota) pedidos.push(getListaCertificados(idDaRota));
        Promise.all(pedidos)
            .then(([o, dados]) => {
                setOpcoes(o);
                if (dados) {
                    aplicar(dados);
                    setPasso(Math.min(dados.passo ?? 0, PASSOS.length - 1));
                }
            })
            .catch(() => setErro('Não foi possível abrir o assistente.'))
            .finally(() => setCarregando(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [idDaRota]);

    useEffect(() => {
        if (passo === 3 && listaId) carregarResumo();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [passo, listaId]);

    function carregarResumo() {
        setResumo(null);
        getResumoCertificados(listaId).then(setResumo).catch(() => setErro('Não foi possível montar o resumo.'));
    }

    /** Grava nome, passo e configuração; cria a lista no primeiro salvamento. */
    async function salvar(passoGravado = passo, cfg = config) {
        setSalvando(true); setErro(''); setErros({});
        try {
            const dados = await salvarListaCertificados(listaId, { nome, passo: Math.max(alcancado, passoGravado), config: cfg });
            aplicar(dados);
            setSalvoEm(new Date());
            if (!listaId) navigate(`/admin/certificados/listas/${dados.id}/assistente`, { replace: true });
            return dados;
        } catch (e) {
            const ex = extractErrors(e);
            setErro(ex.message || 'Não foi possível salvar.');
            setErros(ex.fields ?? {});
            return null;
        } finally {
            setSalvando(false);
        }
    }

    const publicosDisponiveis = (opcoes?.publicos ?? []).filter((p) => p.fases === null || p.fases.some((f) => config.fases.includes(f)));

    function alternar(campo, valor) {
        setConfig((atual) => {
            const lista = atual[campo].includes(valor) ? atual[campo].filter((v) => v !== valor) : [...atual[campo], valor];
            return { ...atual, [campo]: lista };
        });
    }

    function validarPasso() {
        if (passo === 0 && config.fases.length === 0) return 'Escolha ao menos uma fase.';
        if (passo === 1 && !config.publicos.some((p) => publicosDisponiveis.some((d) => d.valor === p))) return 'Escolha ao menos um público-alvo.';
        if (passo === 2) {
            const faltando = categorias.filter((c) => !(config.categorias[c.chave]?.minutos > 0));
            if (faltando.length > 0) {
                setErros(Object.fromEntries(faltando.map((c) => [`config.categorias.${c.chave}.minutos`, ['Informe a carga horária.']])));
                return `Informe a carga horária de: ${faltando.map((c) => c.rotulo).join(', ')}.`;
            }
        }
        return null;
    }

    async function avancar() {
        const problema = validarPasso();
        if (problema) {
            setErro(problema);
            return;
        }
        const dados = await salvar(passo + 1);
        if (dados) setPasso(passo + 1);
    }

    async function irPara(i) {
        if (i === passo || i > alcancado) return;
        if (i > passo) {
            const problema = validarPasso();
            if (problema) { setErro(problema); return; }
        }
        const dados = await salvar(passo);
        if (dados) setPasso(i);
    }

    async function fecharConferencia() {
        setConferindo(null);
        // Fechar uma lista grava o assistente e refaz as contas do resumo.
        await salvar(passo);
        carregarResumo();
    }

    async function gerar() {
        setGerando(true); setErro('');
        try {
            await salvar(passo);
            const dados = await gerarListaCertificados(listaId);
            navigate(`/admin/certificados/listas/${dados.id}`);
        } catch (e) {
            const ex = extractErrors(e);
            setErro(ex.message || 'Não foi possível gerar a lista.');
            setErros(ex.fields ?? {});
            setGerando(false);
        }
    }

    const erroDe = (chave) => erros[`config.categorias.${chave}.minutos`]?.[0] ?? erros[`config.categorias.${chave}.data_fim`]?.[0];

    return (
        <AppShell>
            <Link to="/admin/certificados" className="text-sm text-primary font-semibold hover:underline inline-flex items-center gap-1 mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span>
                Certificados
            </Link>

            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow p-5 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3 mb-4">
                    <label className="flex-1 min-w-[16rem]">
                        <span className="block text-xs font-semibold uppercase tracking-wide text-on-surface-variant mb-1">Nome da lista</span>
                        <Input aria-label="Nome da lista" value={nome} maxLength={120} placeholder="Ex.: Certificados da fase online"
                            onChange={(e) => setNome(e.target.value)} />
                    </label>
                    <div className="flex items-center gap-3">
                        {salvoEm && (
                            <span className="text-xs text-on-surface-variant">
                                Salvo às {salvoEm.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}
                            </span>
                        )}
                        <Button type="button" variant="outline" loading={salvando && !gerando} disabled={carregando} onClick={() => salvar(passo)}>
                            <span className="material-symbols-outlined text-[18px]">save</span>
                            Salvar rascunho
                        </Button>
                    </div>
                </div>

                {lista?.status === 'gerada' && (
                    <div className="mb-4">
                        <Alert type="warning">
                            Esta lista já foi gerada. O que você mudar aqui só chega aos arquivos quando ela for gerada de novo, no último passo.
                        </Alert>
                    </div>
                )}

                <ol className="flex flex-wrap items-center gap-2 mb-5 text-xs">
                    {PASSOS.map((rotulo, i) => (
                        <li key={rotulo} className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => irPara(i)}
                                disabled={i > alcancado}
                                aria-current={i === passo ? 'step' : undefined}
                                className={`px-3 py-1.5 rounded-full font-semibold disabled:opacity-50 ${i === passo
                                    ? 'bg-primary-container text-on-primary'
                                    : 'text-on-surface-variant hover:bg-surface-variant'}`}
                            >
                                {i + 1}. {rotulo}
                            </button>
                            {i < PASSOS.length - 1 && <span className="text-on-surface-variant">›</span>}
                        </li>
                    ))}
                </ol>

                {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

                {carregando ? (
                    <p className="py-10 text-center text-on-surface-variant">Carregando…</p>
                ) : passo === 0 ? (
                    <section aria-label="Fases">
                        <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Para que fase são os certificados?</h2>
                        <p className="text-sm text-on-surface-variant mb-3">Pode marcar as duas: cada fase vira um certificado próprio.</p>
                        <div className="grid sm:grid-cols-2 gap-3">
                            {(opcoes?.fases ?? []).map((f) => (
                                <label key={f.valor} className={`flex items-start gap-3 rounded-xl border-2 p-4 cursor-pointer ${config.fases.includes(f.valor) ? 'border-primary-container bg-primary-fixed/40' : 'border-outline-variant/60'}`}>
                                    <input type="checkbox" className="mt-1" checked={config.fases.includes(f.valor)} onChange={() => alternar('fases', f.valor)} />
                                    <span>
                                        <span className="block font-semibold text-on-surface">{f.rotulo}</span>
                                        <span className="block text-xs text-on-surface-variant">
                                            {f.valor === 'online'
                                                ? 'Equipes dos projetos submetidos e avaliadores da avaliação online.'
                                                : 'Quem compareceu ao credenciamento, avaliadores no estande e voluntários.'}
                                        </span>
                                        {opcoes?.datas?.[f.valor]?.inicio && (
                                            <span className="block text-xs text-on-surface-variant mt-1">
                                                {dataBr(opcoes.datas[f.valor].inicio)} a {dataBr(opcoes.datas[f.valor].fim) ?? '—'}
                                            </span>
                                        )}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </section>
                ) : passo === 1 ? (
                    <section aria-label="Público-alvo">
                        <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Quem recebe os certificados?</h2>
                        <p className="text-sm text-on-surface-variant mb-3">Só aparecem os públicos das fases escolhidas.</p>
                        <div className="grid sm:grid-cols-2 gap-2">
                            {publicosDisponiveis.map((p) => (
                                <label key={p.valor} className={`flex items-center gap-3 rounded-lg border p-3 cursor-pointer ${config.publicos.includes(p.valor) ? 'border-primary-container bg-primary-fixed/40' : 'border-outline-variant/60'}`}>
                                    <input type="checkbox" checked={config.publicos.includes(p.valor)} onChange={() => alternar('publicos', p.valor)} />
                                    <span className="font-semibold text-on-surface text-sm">{p.rotulo}</span>
                                </label>
                            ))}
                        </div>
                    </section>
                ) : passo === 2 ? (
                    <section aria-label="Carga horária" className="space-y-4">
                        <div>
                            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Carga horária de cada certificado</h2>
                            <p className="text-sm text-on-surface-variant">
                                Orientadores, coorientadores, estudantes e admins têm carga fixa. Avaliadores podem ter carga
                                fixa ou por projeto avaliado, e voluntários por turno — com um limite de horas, se quiser.
                            </p>
                        </div>
                        {categorias.map((c) => (
                            <CargaDaCategoria
                                key={c.chave}
                                categoria={c}
                                valor={config.categorias[c.chave] ?? { modo: c.modos[0], minutos: null, teto_minutos: null }}
                                erro={erroDe(c.chave)}
                                onChange={(v) => setConfig((atual) => ({ ...atual, categorias: { ...atual.categorias, [c.chave]: v } }))}
                            />
                        ))}
                    </section>
                ) : (
                    <section aria-label="Resumo" className="space-y-4">
                        <div>
                            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Resumo</h2>
                            <p className="text-sm text-on-surface-variant">
                                {config.fases.map((f) => opcoes?.fases.find((x) => x.valor === f)?.rotulo).join(' e ')}. Confira os
                                nomes de cada categoria antes de gerar: a correção vale só nesta lista.
                            </p>
                        </div>
                        {resumo === null ? (
                            <p className="py-6 text-center text-on-surface-variant">Montando o resumo…</p>
                        ) : resumo.map((c) => (
                            <div key={c.chave} className="rounded-xl border border-outline-variant/60 p-4 flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-display font-semibold text-on-surface">{c.rotulo}</p>
                                    <p className="text-sm text-on-surface-variant">
                                        {c.carga_texto}
                                        {c.carga.data_inicio && ` · ${dataBr(c.carga.data_inicio)} a ${dataBr(c.carga.data_fim) ?? '—'}`}
                                    </p>
                                    <p className="text-sm text-on-surface mt-1">
                                        <strong>{c.certificados}</strong> certificado(s) · <strong>{c.pessoas}</strong> pessoa(s)
                                    </p>
                                    <div className="flex flex-wrap gap-2 mt-1 text-xs">
                                        {c.sem_cpf > 0 && <span className="rounded-full bg-error-container px-2 py-0.5 text-on-error-container">{c.sem_cpf} sem CPF</span>}
                                        {c.sem_email > 0 && <span className="rounded-full bg-error-container px-2 py-0.5 text-on-error-container">{c.sem_email} sem e-mail</span>}
                                        {c.nomes_corrigidos > 0 && <span className="rounded-full bg-primary-fixed px-2 py-0.5 text-primary">{c.nomes_corrigidos} nome(s) corrigido(s)</span>}
                                        {c.teto_aplicado > 0 && <span className="rounded-full bg-surface-variant px-2 py-0.5 text-on-surface-variant">{c.teto_aplicado} no limite de {duracao(c.carga.teto_minutos)}</span>}
                                    </div>
                                </div>
                                <Button type="button" variant="outline" disabled={c.certificados === 0} onClick={() => setConferindo(c)}>
                                    <span className="material-symbols-outlined text-[18px]">fact_check</span>
                                    Conferir nomes
                                </Button>
                            </div>
                        ))}
                    </section>
                )}

                <div className="flex justify-between gap-2 pt-5 mt-5 border-t border-outline-variant/40">
                    <Button type="button" variant="outline" disabled={passo === 0 || salvando} onClick={() => irPara(passo - 1)}>Voltar</Button>
                    {passo < PASSOS.length - 1 ? (
                        <Button type="button" loading={salvando} disabled={carregando} onClick={avancar}>Avançar</Button>
                    ) : (
                        <Button type="button" variant="success" loading={gerando} disabled={resumo === null} onClick={gerar}>
                            <span className="material-symbols-outlined text-[18px]">workspace_premium</span>
                            {lista?.status === 'gerada' ? 'Gerar novamente' : 'Gerar certificados'}
                        </Button>
                    )}
                </div>
            </div>

            {conferindo && listaId && (
                <ConferirNomes listaId={listaId} categoria={conferindo} onFechar={fecharConferencia} />
            )}
        </AppShell>
    );
}
