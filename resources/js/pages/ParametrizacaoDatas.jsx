import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import CampoDataCard from '../components/CampoDataCard.jsx';
import { Alert, Button, Field, Input } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getInscricoesConfig, definirInicioInscricoes, definirPrazoInscricoes,
    getAvaliacaoConfig, definirLiberacaoAvaliacao, definirEncerramentoAvaliacao,
    definirInicioAjustes, definirFimAjustes,
} from '../lib/admin.js';
import { getParametrizacaoCredenciamento, definirJanelaEvento } from '../lib/credenciamento.js';

/**
 * Parametrização → **Datas e períodos**.
 *
 * Todas as janelas da edição num lugar só, na ordem em que a feira acontece:
 * inscrições → avaliação → ajustes do orientador → evento. Antes elas estavam
 * espalhadas por três telas (Inscrições, Avaliação Online e Credenciamento), o
 * que obrigava a caçar em qual delas cada data morava.
 *
 * Cada ponta continua tendo o seu endpoint — esta tela só reúne os campos —, e
 * as três telas de origem ficaram com o que não é data (limites, itens e
 * documentos).
 *
 * Regra geral: **campo em branco deixa aquela ponta aberta**. As duas exceções
 * estão sinalizadas na própria seção — sem data de início, o período de ajustes
 * e o credenciamento ficam **fechados**.
 */
const PILL = {
    aberta: 'bg-secondary-container text-on-secondary-container',
    futura: 'bg-primary-fixed text-primary-container',
    encerrada: 'bg-error-container text-on-error-container',
    vazia: 'bg-surface-variant text-on-surface-variant',
};

function Secao({ titulo, descricao, children }) {
    return (
        <section className="mb-10">
            <h2 className="font-display text-lg font-semibold text-on-surface">{titulo}</h2>
            <p className="text-sm text-on-surface-variant mb-4 max-w-3xl">{descricao}</p>
            {children}
        </section>
    );
}

function Carregando() {
    return (
        <div className="text-center py-6 text-on-surface-variant">
            <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
        </div>
    );
}

/** Inscrições: abertura e prazo de submissão. */
function SecaoInscricoes() {
    const [config, setConfig] = useState(null);

    useEffect(() => {
        getInscricoesConfig().then(setConfig).catch(() => setConfig({
            abertas: true, encerradas: false, nao_iniciadas: false,
            inicio_input: null, inicio_label: null, prazo_input: null, prazo_label: null,
        }));
    }, []);

    const statusInicio = !config ? null
        : config.nao_iniciadas ? { txt: `Abre em ${config.inicio_label}`, cor: PILL.futura }
            : config.inicio_label ? { txt: 'Já abriu', cor: PILL.aberta }
                : { txt: 'Sem data — já abertas', cor: PILL.vazia };

    const statusPrazo = !config ? null
        : config.encerradas ? { txt: 'Inscrições encerradas', cor: PILL.encerrada }
            : config.prazo_label ? { txt: `Encerra em ${config.prazo_label}`, cor: PILL.futura }
                : { txt: 'Sem prazo definido', cor: PILL.vazia };

    return (
        <Secao
            titulo="Inscrições"
            descricao={
                <>
                    A janela em que o orientador inscreve projetos. Fora dela a área dele fica{' '}
                    <strong>só de leitura</strong> — e você, como admin, continua agindo por fora das datas.
                </>
            }
        >
            {config === null ? <Carregando /> : (
                <>
                    <CampoDataCard
                        titulo="Abertura das inscrições"
                        status={statusInicio}
                        valor={config.inicio_input}
                        ariaLabel="Data de abertura das inscrições"
                        salvarLabel="Salvar abertura"
                        descricao={
                            <>
                                Data/hora (horário de Campo Grande) em que as inscrições abrem. Antes dela
                                ninguém cadastra projeto e o <strong>cadastro de orientador fica fechado</strong> —
                                o cadastro de avaliador continua liberado.
                            </>
                        }
                        onSalvar={definirInicioInscricoes}
                        onSalvo={setConfig}
                    />

                    <CampoDataCard
                        titulo="Prazo de submissão"
                        status={statusPrazo}
                        valor={config.prazo_input}
                        ariaLabel="Data-limite de submissão"
                        salvarLabel="Salvar prazo"
                        descricao={
                            <>
                                Data/hora em que as inscrições se encerram. Passado o prazo, ninguém cria,
                                edita, submete ou cancela projeto — nem quem tinha cancelado o envio para
                                editar. Criar conta continua permitido.
                            </>
                        }
                        onSalvar={definirPrazoInscricoes}
                        onSalvo={setConfig}
                    />
                </>
            )}
        </Secao>
    );
}

/** Avaliação online e período de ajustes — as duas vivem no mesmo config. */
function SecoesAvaliacao() {
    const [config, setConfig] = useState(null);

    useEffect(() => {
        getAvaliacaoConfig().then(setConfig).catch(() => setConfig({
            liberada: false, encerrada: false,
            liberada_em_input: null, liberada_em_label: null,
            encerrada_em_input: null, encerrada_em_label: null,
        }));
    }, []);

    const statusInicio = !config ? null
        : config.liberada ? { txt: 'Avaliação liberada', cor: PILL.aberta }
            : config.liberada_em_label ? { txt: `Libera em ${config.liberada_em_label}`, cor: PILL.futura }
                : { txt: 'Sem data definida', cor: PILL.vazia };

    const statusFim = !config ? null
        : config.encerrada ? { txt: 'Avaliação encerrada', cor: PILL.encerrada }
            : config.encerrada_em_label ? { txt: `Encerra em ${config.encerrada_em_label}`, cor: PILL.futura }
                : { txt: 'Sem encerramento', cor: PILL.vazia };

    // Sem data de início a aba de ajustes fica FECHADA — ao contrário das
    // outras janelas, em que o campo vazio significa "aberto".
    const statusAjustesInicio = !config ? null
        : config.ajustes_abertos ? { txt: 'Ajustes abertos', cor: PILL.aberta }
            : config.ajustes_de_label ? { txt: `Abre em ${config.ajustes_de_label}`, cor: PILL.futura }
                : { txt: 'Aba fechada', cor: PILL.vazia };

    const statusAjustesFim = !config ? null
        : config.ajustes_ate_label ? { txt: `Ajustes até ${config.ajustes_ate_label}`, cor: PILL.futura }
            : { txt: 'Ajustes sem data de fim', cor: PILL.vazia };

    if (config === null) {
        return <Secao titulo="Avaliação online" descricao="A janela em que os avaliadores trabalham."><Carregando /></Secao>;
    }

    return (
        <>
            <Secao
                titulo="Avaliação online"
                descricao={
                    <>
                        Quando os avaliadores acessam os projetos designados. Os <strong>limites</strong> de
                        avaliação continuam em{' '}
                        <Link to="/admin/parametrizacao/avaliacao" className="underline">Avaliação Online</Link>.
                    </>
                }
            >
                <CampoDataCard
                    titulo="Início das avaliações"
                    status={statusInicio}
                    valor={config.liberada_em_input}
                    ariaLabel="Data de liberação da avaliação"
                    salvarLabel="Salvar início"
                    descricao={
                        <>
                            Data/hora (horário de Campo Grande) a partir da qual os avaliadores acessam os
                            projetos designados. A partir dela o orientador também <strong>não consegue mais
                            cancelar a submissão</strong>, e o avaliador não troca mais a própria área.
                            Deixe em branco para não liberar.
                        </>
                    }
                    onSalvar={definirLiberacaoAvaliacao}
                    onSalvo={setConfig}
                />

                <CampoDataCard
                    titulo="Fim das avaliações"
                    status={statusFim}
                    valor={config.encerrada_em_input}
                    ariaLabel="Data de encerramento da avaliação"
                    salvarLabel="Salvar fim"
                    descricao={
                        <>
                            Data/hora em que o período se encerra. Depois dela o avaliador ainda
                            <strong> consulta</strong> os projetos e o que respondeu, mas não inicia, não
                            salva rascunho e não envia avaliação. Deixe em branco para manter aberto.
                        </>
                    }
                    onSalvar={definirEncerramentoAvaliacao}
                    onSalvo={setConfig}
                />
            </Secao>

            <Secao
                titulo="Ajustes do orientador"
                descricao={
                    <>
                        A janela da aba <strong>Ajustes</strong>, em que o orientador responde às sugestões
                        de área e subárea feitas pelos avaliadores.
                    </>
                }
            >
                <CampoDataCard
                    titulo="Início do período de ajustes"
                    status={statusAjustesInicio}
                    valor={config.ajustes_de_input}
                    ariaLabel="Data de início do período de ajustes"
                    salvarLabel="Salvar início dos ajustes"
                    descricao={
                        <>
                            Quando a aba <strong>Ajustes</strong> do orientador abre. Em branco, a aba fica
                            <strong> fechada</strong>: ela aparece no menu, mas não abre — é a exceção à
                            regra de que campo vazio deixa a ponta aberta.
                        </>
                    }
                    onSalvar={definirInicioAjustes}
                    onSalvo={setConfig}
                />

                <CampoDataCard
                    titulo="Fim do período de ajustes"
                    status={statusAjustesFim}
                    valor={config.ajustes_ate_input}
                    ariaLabel="Data de fim do período de ajustes"
                    salvarLabel="Salvar fim dos ajustes"
                    descricao={
                        <>
                            Depois desta data o orientador não muda mais as decisões que tomou. Deixe
                            em branco para manter a aba aberta enquanto quiser.
                        </>
                    }
                    onSalvar={definirFimAjustes}
                    onSalvo={setConfig}
                />
            </Secao>
        </>
    );
}

/**
 * Período do evento (credenciamento). As duas pontas vão juntas num PATCH só,
 * então aqui é um formulário — e não dois `CampoDataCard`.
 */
function SecaoEvento() {
    const [janela, setJanela] = useState(null);
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [salvando, setSalvando] = useState(false);

    useEffect(() => {
        getParametrizacaoCredenciamento()
            .then((d) => setJanela({ de: d.config?.inicio_input ?? '', ate: d.config?.fim_input ?? '' }))
            .catch(() => setJanela({ de: '', ate: '' }));
    }, []);

    async function salvar(e) {
        e.preventDefault();
        setSalvando(true); setErrors({}); setAlerta(''); setSucesso('');
        try {
            const d = await definirJanelaEvento(janela.de, janela.ate);
            setJanela({ de: d.config?.inicio_input ?? '', ate: d.config?.fim_input ?? '' });
            setSucesso('Período do evento salvo.');
        } catch (e2) {
            const { message, fields } = extractErrors(e2);
            setErrors(fields ?? {});
            setAlerta(message || 'Não foi possível salvar o período.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <Secao
            titulo="Credenciamento (evento)"
            descricao={
                <>
                    Quando o balcão do evento funciona. <strong>Sem a data de início ele fica fechado</strong> —
                    credenciar é ato presencial, ninguém credencia por padrão. Os documentos e os itens
                    entregues continuam em{' '}
                    <Link to="/admin/parametrizacao/credenciamento" className="underline">Credenciamento</Link>.
                </>
            }
        >
            {janela === null ? <Carregando /> : (
                <form onSubmit={salvar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-3xl">
                    {alerta && <div className="mb-3"><Alert>{alerta}</Alert></div>}
                    {sucesso && <div className="mb-3"><Alert type="info">{sucesso}</Alert></div>}

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Field label="Início" error={errors.evento_de}>
                            <Input
                                type="datetime-local"
                                aria-label="Início do período do evento"
                                value={janela.de}
                                onChange={(e) => setJanela((j) => ({ ...j, de: e.target.value }))}
                                error={errors.evento_de}
                            />
                        </Field>
                        <Field label="Fim" error={errors.evento_ate}>
                            <Input
                                type="datetime-local"
                                aria-label="Fim do período do evento"
                                value={janela.ate}
                                onChange={(e) => setJanela((j) => ({ ...j, ate: e.target.value }))}
                                error={errors.evento_ate}
                            />
                        </Field>
                    </div>
                    <div className="flex justify-end mt-4">
                        <Button type="submit" loading={salvando}>
                            <span className="material-symbols-outlined text-[20px]">save</span>
                            Salvar período
                        </Button>
                    </div>
                </form>
            )}
        </Secao>
    );
}

export default function ParametrizacaoDatas() {
    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Datas e períodos</h1>
            <p className="text-on-surface-variant mb-8 max-w-3xl">
                Todas as janelas da edição, na ordem em que a feira acontece. As horas são as de parede de
                Campo Grande. Em regra, <strong>campo em branco deixa aquela ponta aberta</strong> — as duas
                exceções (ajustes e credenciamento) estão avisadas na própria seção.
            </p>

            <SecaoInscricoes />
            <SecoesAvaliacao />
            <SecaoEvento />
        </AppShell>
    );
}
