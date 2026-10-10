import { useCallback, useEffect, useRef, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getDocumentosPresenciais, enviarArquivoItem, definirPessoasArquivo, removerArquivo,
} from '../lib/documentosPresenciais.js';

const tamanho = (bytes) =>
    bytes === null || bytes === undefined ? '' : `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '');

/** Abre o arquivo enviado (PDF ou foto) na rota autenticada de sempre. */
const urlArquivo = (id) => `/api/v1/documentos/${id}/preview`;

/**
 * "Agilize o processo!" (Sprint 174): o envio antecipado é um favor que o
 * orientador faz a si mesmo, não uma obrigação. O tom é de convite — quem não
 * enviar apresenta os papéis no evento, sem prejuízo nenhum.
 */
function AgilizeProcesso() {
    return (
        <section className="max-w-3xl mb-4 rounded-2xl border border-secondary/40 bg-secondary-container/40 p-5 flex gap-4">
            <span className="material-symbols-outlined text-secondary text-4xl shrink-0" aria-hidden="true">bolt</span>
            <div className="space-y-2 text-sm text-on-surface">
                <h2 className="font-display text-lg font-semibold text-on-surface">Agilize o processo!</h2>
                <p>
                    Anexe com antecedência o <strong>termo de responsabilidade</strong> e o{' '}
                    <strong>termo de uso de imagem</strong>: no dia da feira, o credenciamento e a checagem
                    do estande ficam bem mais rápidos.
                </p>
                <p className="text-on-surface-variant">
                    Não conseguiu enviar? Sem problema. Os projetos que não anexarem antes apresentam a
                    autorização de uso de imagem no credenciamento e o termo de responsabilidade na checagem
                    do estande.
                </p>
            </div>
        </section>
    );
}

/**
 * O laudo da conferência da assinatura digital, em palavras.
 *
 * A assinatura **não** decide se o termo vale — quem decide é a organização no
 * balcão. O que a tela faz é dizer o que o portal conseguiu verificar, para o
 * orientador corrigir antes do evento em vez de descobrir na fila.
 */
function Assinatura({ assinatura }) {
    const ok = assinatura.valida;
    const alerta = assinatura.assinado && !ok;

    return (
        <div className={`mt-2 rounded-lg border p-3 text-xs ${
            ok ? 'border-secondary-container bg-secondary-container/30'
                : alerta ? 'border-error/40 bg-error-container/20'
                    : 'border-outline-variant bg-surface-variant/40'
        }`}>
            <p className="font-semibold text-on-surface flex items-center gap-1">
                <span className="material-symbols-outlined text-[16px]">
                    {ok ? 'verified' : alerta ? 'gpp_maybe' : 'info'}
                </span>
                {assinatura.motivo}
            </p>
            {assinatura.signatarios.length > 0 && (
                <ul className="mt-1 text-on-surface-variant space-y-0.5">
                    {assinatura.signatarios.map((s, i) => (
                        <li key={`${s.nome}-${i}`}>
                            Assinado por <strong>{s.nome ?? 'signatário não identificado'}</strong>
                            {s.cpf ? ` · CPF ${s.cpf}` : ''}
                            {s.emissor ? ` · ${s.emissor}` : ''}
                        </li>
                    ))}
                </ul>
            )}
            {!assinatura.assinado && (
                <p className="mt-1 text-on-surface-variant">
                    O arquivo foi guardado assim mesmo. Se ele foi assinado à caneta e digitalizado,
                    apresente o original impresso na checagem do estande.
                </p>
            )}
        </div>
    );
}

/** Lista de pessoas com caixa de marcação — para quem o arquivo vale. */
function EscolhaPessoas({ pessoas, marcadas, onAlternar, rotulo }) {
    return (
        <fieldset className="space-y-1">
            <legend className="text-xs font-semibold text-on-surface mb-1">{rotulo}</legend>
            {pessoas.map((p) => (
                <label key={p.chave} className="flex items-center gap-2 text-sm text-on-surface cursor-pointer">
                    <input
                        type="checkbox"
                        checked={marcadas.includes(p.chave)}
                        onChange={() => onAlternar(p.chave)}
                        aria-label={p.nome}
                        className="accent-primary-container"
                    />
                    {p.nome} <span className="text-xs text-on-surface-variant">{p.tipo_label}</span>
                </label>
            ))}
        </fieldset>
    );
}

const alternarEm = (lista, id) => (lista.includes(id) ? lista.filter((x) => x !== id) : [...lista, id]);

/** Selo da situação: "Completo · 3 de 3", "Parcial · 1 de 3", "Enviado", "Pendente". */
function SeloSituacao({ item }) {
    const cor = {
        completo: 'bg-secondary-container text-on-secondary-container',
        parcial: 'bg-primary-fixed text-primary-container',
    }[item.situacao] ?? 'bg-surface-variant text-on-surface-variant';

    return (
        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${cor}`}>
            {item.situacao_label}
            {item.abrangencia === 'pessoa' && item.total > 0 && ` · ${item.cobertos} de ${item.total}`}
        </span>
    );
}

/**
 * Um item que a organização quer receber antes (Sprint 183): os termos do
 * finalista e o que mais ela pedir na Parametrização. Pode vir em um ou mais
 * arquivos; no item de pessoa, cada arquivo diz para quem vale — a tela já
 * marca quem ainda falta e deixa corrigir sem reenviar.
 */
function SecaoItem({ projeto, item, maxKb, ocupado, onEnviar, onDefinirPessoas, onRemover }) {
    const input = useRef(null);
    const [novo, setNovo] = useState(null); // { arquivo, pessoas }
    const [editando, setEditando] = useState(null); // { id, pessoas }
    const dePessoa = item.abrangencia === 'pessoa';
    const nomes = Object.fromEntries((item.pessoas ?? []).map((p) => [p.chave, p.nome]));
    const faltando = (item.pessoas ?? []).filter((p) => item.faltando.includes(p.nome)).map((p) => p.chave);
    // O termo de responsabilidade é assinado no gov.br: o laudo aparece sempre.
    const mostraLaudo = (a) => a.pdf && (item.chave === 'termo_responsabilidade' || a.assinatura?.assinado);

    if (dePessoa && item.pessoas.length === 0) {
        return (
            <p className="text-sm text-on-surface-variant">
                Ninguém da equipe tem o papel a que este item se aplica — não há o que enviar.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <SeloSituacao item={item} />
                {dePessoa && item.faltando.length > 0 && (
                    <span className="text-xs text-on-surface-variant">Falta: {item.faltando.join(', ')}</span>
                )}
            </div>

            {item.arquivos.length > 0 && (
                <ul className="space-y-2">
                    {item.arquivos.map((a) => (
                        <li key={a.id} className="rounded-lg border border-outline-variant p-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <a
                                        href={urlArquivo(a.id)}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-sm text-on-surface hover:text-primary inline-flex items-center gap-1"
                                    >
                                        <span className="material-symbols-outlined text-[18px] text-primary-container">
                                            {a.pdf ? 'description' : 'image'}
                                        </span>
                                        {a.nome_original}
                                    </a>
                                    <p className="text-xs text-on-surface-variant">
                                        {tamanho(a.tamanho_bytes)} · enviado em {dataHora(a.enviado_em)}
                                    </p>
                                    {dePessoa && (
                                        <p className="text-xs text-on-surface mt-1">
                                            Vale para: {a.pessoas.map((c) => nomes[c] ?? c).join(', ')}
                                        </p>
                                    )}
                                </div>
                                <div className="flex gap-2 shrink-0">
                                    {dePessoa && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={ocupado}
                                            onClick={() => setEditando({ id: a.id, pessoas: a.pessoas })}
                                            aria-label={`Pessoas de ${a.nome_original}`}
                                        >
                                            <span className="material-symbols-outlined text-[20px]">group</span>
                                            Pessoas
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="text-error border-error/40 hover:bg-error-container/40"
                                        disabled={ocupado}
                                        onClick={() => onRemover(projeto, item, a)}
                                        aria-label={`Remover ${a.nome_original}`}
                                    >
                                        <span className="material-symbols-outlined text-[20px]">delete</span>
                                    </Button>
                                </div>
                            </div>
                            {mostraLaudo(a) && <Assinatura assinatura={a.assinatura} />}
                            {editando?.id === a.id && (
                                <div className="mt-3 space-y-2">
                                    <EscolhaPessoas
                                        pessoas={item.pessoas}
                                        marcadas={editando.pessoas}
                                        onAlternar={(c) => setEditando((e) => ({ ...e, pessoas: alternarEm(e.pessoas, c) }))}
                                        rotulo="Este arquivo vale para:"
                                    />
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            disabled={ocupado || editando.pessoas.length === 0}
                                            onClick={async () => {
                                                if (await onDefinirPessoas(projeto, item, a, editando.pessoas)) setEditando(null);
                                            }}
                                        >
                                            Salvar pessoas
                                        </Button>
                                        <Button type="button" variant="outline" disabled={ocupado} onClick={() => setEditando(null)}>
                                            Cancelar
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {novo ? (
                <div className="rounded-lg border border-primary-container/40 bg-primary-fixed/30 p-3 space-y-3">
                    <p className="text-sm text-on-surface flex items-center gap-1">
                        <span className="material-symbols-outlined text-[18px] text-primary-container">attach_file</span>
                        {novo.arquivo.name}
                        <span className="text-xs text-on-surface-variant">{tamanho(novo.arquivo.size)}</span>
                    </p>
                    {dePessoa && (
                        <>
                            <EscolhaPessoas
                                pessoas={item.pessoas}
                                marcadas={novo.pessoas}
                                onAlternar={(c) => setNovo((n) => ({ ...n, pessoas: alternarEm(n.pessoas, c) }))}
                                rotulo="Este arquivo vale para:"
                            />
                            {novo.pessoas.length === 0 && (
                                <p className="text-xs text-error">Marque ao menos uma pessoa.</p>
                            )}
                        </>
                    )}
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            disabled={ocupado || (dePessoa && novo.pessoas.length === 0)}
                            onClick={async () => {
                                if (await onEnviar(projeto, item, novo.arquivo, novo.pessoas)) setNovo(null);
                            }}
                        >
                            <span className="material-symbols-outlined text-[20px]">upload_file</span>
                            Enviar arquivo
                        </Button>
                        <Button type="button" variant="outline" disabled={ocupado} onClick={() => setNovo(null)}>
                            Cancelar
                        </Button>
                    </div>
                </div>
            ) : (
                <div>
                    <input
                        ref={input}
                        type="file"
                        accept="application/pdf,image/jpeg,image/png"
                        className="hidden"
                        aria-label={`${item.nome} de ${projeto.titulo}`}
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            e.target.value = '';
                            // Já vem marcado quem ainda falta: é o caso comum.
                            if (file) setNovo({ arquivo: file, pessoas: faltando });
                        }}
                    />
                    <Button
                        type="button"
                        variant={item.situacao === 'completo' ? 'outline' : 'primary'}
                        disabled={ocupado}
                        onClick={() => input.current?.click()}
                    >
                        <span className="material-symbols-outlined text-[20px]">upload_file</span>
                        {item.arquivos.length === 0 ? 'Anexar arquivo' : 'Anexar outro arquivo'}
                    </Button>
                    <p className="text-xs text-on-surface-variant mt-1">
                        PDF, JPG ou PNG (até {Math.round(maxKb / 1024)} MB)
                        {dePessoa
                            ? '. Pode ser um arquivo para todos ou um por pessoa — depois de escolher, marque para quem ele vale.'
                            : '. Se o documento tiver mais de uma página escaneada, envie cada uma.'}
                    </p>
                </div>
            )}
        </div>
    );
}

/** Um projeto finalista e os itens que a organização quer receber antes. */
function CardProjeto({ projeto, maxKb, ocupado, onEnviar, onDefinirPessoas, onRemover }) {
    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 space-y-4">
            <div className="min-w-0">
                <p className="font-semibold text-on-surface">{projeto.titulo}</p>
                <p className="text-xs text-on-surface-variant">
                    {projeto.area ?? 'Sem área'}{projeto.categoria ? ` · ${projeto.categoria}` : ''}
                </p>
            </div>

            {projeto.itens.length === 0 ? (
                <p className="text-sm text-on-surface-variant">A organização não pediu documentos antecipados.</p>
            ) : projeto.itens.map((item, i) => (
                <section
                    key={item.id}
                    aria-label={`${item.nome} — ${projeto.titulo}`}
                    className={i > 0 ? 'border-t border-outline-variant/40 pt-4' : ''}
                >
                    <h3 className="text-sm font-semibold text-on-surface">{item.nome}</h3>
                    {item.descricao && <p className="text-xs text-on-surface-variant mb-2">{item.descricao}</p>}
                    <div className={item.descricao ? '' : 'mt-2'}>
                        <SecaoItem
                            projeto={projeto}
                            item={item}
                            maxKb={maxKb}
                            ocupado={ocupado}
                            onEnviar={onEnviar}
                            onDefinirPessoas={onDefinirPessoas}
                            onRemover={onRemover}
                        />
                    </div>
                </section>
            ))}
        </li>
    );
}

/**
 * Aba "Documentos" do orientador: o que a organização quer receber dos
 * **finalistas** antes da feira — os itens da lista única marcados "receber
 * antes" (Sprint 183), entre eles os dois termos.
 *
 * Só aparece projeto da lista final vigente — antes dela publicada não há o que
 * pedir. O portal confere a assinatura digital do PDF e mostra o que encontrou,
 * mas **não recusa** o arquivo por causa dela: quem decide é o balcão.
 */
export default function DocumentosPresenciais() {
    const [dados, setDados] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    const [alert, setAlert] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback((teste) => getDocumentosPresenciais(teste)
        .then(setDados)
        .catch(() => setDados({ janela: { aberta: false, is_demo: false }, projetos: [] })), []);

    useEffect(() => { carregar(modoTeste); }, [carregar, modoTeste]);

    /**
     * As ações devolvem se deram certo — o formulário do item só fecha quando o
     * servidor aceitou (senão o orientador perderia o que marcou).
     */
    async function acao(executar, falha) {
        setOcupado(true); setAlert(''); setSucesso('');
        try {
            const resp = await executar();
            setSucesso(resp?.meta?.message ?? '');
            await carregar(modoTeste);
            return true;
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlert(Object.values(fields ?? {})[0] || message || falha);
            return false;
        } finally {
            setOcupado(false);
        }
    }

    const enviar = (projeto, item, file, pessoas) => acao(
        () => enviarArquivoItem(projeto.id, item.id, file, pessoas, modoTeste),
        'Não foi possível enviar o arquivo.',
    );
    const definirPessoas = (projeto, item, arquivo, pessoas) => acao(
        () => definirPessoasArquivo(arquivo.id, pessoas, modoTeste),
        'Não foi possível atualizar as pessoas do arquivo.',
    );
    const remover = (projeto, item, arquivo) => acao(
        () => removerArquivo(arquivo.id, modoTeste),
        'Não foi possível remover o arquivo.',
    );

    const janela = dados?.janela;

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Documentos</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Os documentos que a organização quer receber dos projetos <strong>finalistas</strong> antes
                da feira — entre eles o <strong>termo de responsabilidade</strong> (PDF assinado no gov.br) e
                o <strong>termo de uso de imagem</strong> dos estudantes. Cada um aceita PDF ou foto do papel
                assinado.
            </p>

            {janela?.aberta && dados.projetos.length > 0 && <AgilizeProcesso />}

            {janela?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Conta demo: usa a lista final de demonstração e ignora as datas do evento."
                    />
                </div>
            )}

            <div className="max-w-3xl space-y-3">
                <Alert>{alert}</Alert>
                <Alert type="info">{sucesso}</Alert>
            </div>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : !janela.aberta ? (
                <div className="max-w-3xl bg-surface-container-lowest rounded-xl fetec-card-shadow p-6">
                    <span className="material-symbols-outlined text-primary-container text-3xl">lock_clock</span>
                    <h2 className="font-display text-lg font-semibold text-on-surface mt-2">
                        {janela.tem_lista ? 'O envio de documentos está encerrado' : 'A lista final ainda não saiu'}
                    </h2>
                    <p className="text-sm text-on-surface-variant mt-1">
                        {janela.tem_lista
                            ? `O evento terminou em ${janela.evento_ate_label ?? 'data não informada'}.`
                            : 'Quando a organização publicar a lista dos finalistas, os projetos selecionados aparecem aqui para o envio do termo.'}
                    </p>
                </div>
            ) : dados.projetos.length === 0 ? (
                <div className="max-w-3xl bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-sm text-on-surface-variant">
                    Nenhum dos seus projetos está na lista final desta edição — não há documento a enviar.
                </div>
            ) : (
                <ul className="max-w-3xl space-y-3 mt-3">
                    {dados.projetos.map((p) => (
                        <CardProjeto
                            key={p.id}
                            projeto={p}
                            maxKb={janela.max_kb}
                            ocupado={ocupado}
                            onEnviar={enviar}
                            onDefinirPessoas={definirPessoas}
                            onRemover={remover}
                        />
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
