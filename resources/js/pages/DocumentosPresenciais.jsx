import { useCallback, useEffect, useRef, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getDocumentosPresenciais, enviarTermo, removerTermo,
    enviarTermoImagem, definirAlunosTermoImagem, removerTermoImagem,
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

/** Lista de estudantes com caixa de marcação — quem o arquivo cobre. */
function EscolhaAlunos({ alunos, marcados, onAlternar, rotulo }) {
    return (
        <fieldset className="space-y-1">
            <legend className="text-xs font-semibold text-on-surface mb-1">{rotulo}</legend>
            {alunos.map((a) => (
                <label key={a.id} className="flex items-center gap-2 text-sm text-on-surface cursor-pointer">
                    <input
                        type="checkbox"
                        checked={marcados.includes(a.id)}
                        onChange={() => onAlternar(a.id)}
                        className="accent-primary-container"
                    />
                    {a.nome}
                </label>
            ))}
        </fieldset>
    );
}

const alternarEm = (lista, id) => (lista.includes(id) ? lista.filter((x) => x !== id) : [...lista, id]);

/** Selo da cobertura: "Completo · 3 de 3", "Parcial · 1 de 3", "Pendente". */
function SeloCobertura({ termo }) {
    const cor = {
        completo: 'bg-secondary-container text-on-secondary-container',
        parcial: 'bg-primary-fixed text-primary-container',
    }[termo.situacao] ?? 'bg-surface-variant text-on-surface-variant';

    return (
        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${cor}`}>
            {termo.situacao_label}
            {termo.total > 0 && ` · ${termo.cobertos} de ${termo.total}`}
        </span>
    );
}

/**
 * O termo de uso de imagem de um projeto (Sprint 174): pode vir em um ou mais
 * arquivos, e cada um diz para quais estudantes vale. A tela mostra quem já
 * está coberto e quem ainda falta, e deixa corrigir os estudantes de um
 * arquivo sem reenviá-lo.
 */
function TermoImagem({ projeto, maxKb, ocupado, onEnviar, onDefinirAlunos, onRemover }) {
    const termo = projeto.termo_imagem;
    const alunos = projeto.alunos ?? [];
    const input = useRef(null);
    const [novo, setNovo] = useState(null); // { arquivo, alunos }
    const [editando, setEditando] = useState(null); // { id, alunos }

    if (!termo) return null;

    const faltando = termo.alunos.filter((a) => !a.coberto).map((a) => a.id);

    if (alunos.length === 0) {
        return (
            <p className="text-sm text-on-surface-variant">
                Este projeto não tem estudantes cadastrados — não há termo de imagem a enviar.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <SeloCobertura termo={termo} />
                {termo.faltando.length > 0 && (
                    <span className="text-xs text-on-surface-variant">Falta: {termo.faltando.join(', ')}</span>
                )}
            </div>

            {termo.arquivos.length > 0 && (
                <ul className="space-y-2">
                    {termo.arquivos.map((a) => (
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
                                    <p className="text-xs text-on-surface mt-1">
                                        Vale para: {a.alunos.map((x) => x.nome).join(', ')}
                                    </p>
                                </div>
                                <div className="flex gap-2 shrink-0">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={ocupado}
                                        onClick={() => setEditando({ id: a.id, alunos: a.alunos.map((x) => x.id) })}
                                        aria-label={`Estudantes de ${a.nome_original}`}
                                    >
                                        <span className="material-symbols-outlined text-[20px]">group</span>
                                        Estudantes
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="text-error border-error/40 hover:bg-error-container/40"
                                        disabled={ocupado}
                                        onClick={() => onRemover(projeto, a)}
                                        aria-label={`Remover ${a.nome_original}`}
                                    >
                                        <span className="material-symbols-outlined text-[20px]">delete</span>
                                    </Button>
                                </div>
                            </div>
                            {a.pdf && a.assinatura?.assinado && <Assinatura assinatura={a.assinatura} />}
                            {editando?.id === a.id && (
                                <div className="mt-3 space-y-2">
                                    <EscolhaAlunos
                                        alunos={alunos}
                                        marcados={editando.alunos}
                                        onAlternar={(id) => setEditando((e) => ({ ...e, alunos: alternarEm(e.alunos, id) }))}
                                        rotulo="Este arquivo vale para:"
                                    />
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            disabled={ocupado || editando.alunos.length === 0}
                                            onClick={async () => {
                                                if (await onDefinirAlunos(projeto, a, editando.alunos)) setEditando(null);
                                            }}
                                        >
                                            Salvar estudantes
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
                    <EscolhaAlunos
                        alunos={alunos}
                        marcados={novo.alunos}
                        onAlternar={(id) => setNovo((n) => ({ ...n, alunos: alternarEm(n.alunos, id) }))}
                        rotulo="Este arquivo vale para:"
                    />
                    {novo.alunos.length === 0 && (
                        <p className="text-xs text-error">Marque ao menos um estudante.</p>
                    )}
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            disabled={ocupado || novo.alunos.length === 0}
                            onClick={async () => {
                                if (await onEnviar(projeto, novo.arquivo, novo.alunos)) setNovo(null);
                            }}
                        >
                            <span className="material-symbols-outlined text-[20px]">upload_file</span>
                            Enviar termo
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
                        aria-label={`Termo de uso de imagem de ${projeto.titulo}`}
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            e.target.value = '';
                            // Já vem marcado quem ainda falta: é o caso comum.
                            if (file) setNovo({ arquivo: file, alunos: faltando });
                        }}
                    />
                    <Button
                        type="button"
                        variant={termo.situacao === 'completo' ? 'outline' : 'primary'}
                        disabled={ocupado}
                        onClick={() => input.current?.click()}
                    >
                        <span className="material-symbols-outlined text-[20px]">add_photo_alternate</span>
                        {termo.arquivos.length === 0 ? 'Anexar termo de imagem' : 'Anexar outro arquivo'}
                    </Button>
                    <p className="text-xs text-on-surface-variant mt-1">
                        PDF, JPG ou PNG (até {Math.round(maxKb / 1024)} MB). Pode ser um arquivo para a equipe toda
                        ou um por estudante — depois de escolher o arquivo, marque para quem ele vale.
                    </p>
                </div>
            )}
        </div>
    );
}

/** Um projeto finalista e os dois termos dele. */
function CardProjeto({ projeto, maxKb, ocupado, onEnviar, onRemover, onEnviarImagem, onDefinirAlunos, onRemoverImagem }) {
    const input = useRef(null);
    const termo = projeto.termo;

    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 space-y-4">
            <div className="min-w-0">
                <p className="font-semibold text-on-surface">{projeto.titulo}</p>
                <p className="text-xs text-on-surface-variant">
                    {projeto.area ?? 'Sem área'}{projeto.categoria ? ` · ${projeto.categoria}` : ''}
                </p>
            </div>

            <section aria-label={`Termo de responsabilidade — ${projeto.titulo}`}>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="text-sm font-semibold text-on-surface">Termo de responsabilidade</h3>
                    <div className="flex gap-2 shrink-0">
                        <input
                            ref={input}
                            type="file"
                            accept="application/pdf"
                            className="hidden"
                            aria-label={`Termo de responsabilidade de ${projeto.titulo}`}
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                e.target.value = '';
                                if (file) onEnviar(projeto, file);
                            }}
                        />
                        <Button type="button" variant={termo ? 'outline' : 'primary'} disabled={ocupado} onClick={() => input.current?.click()}>
                            <span className="material-symbols-outlined text-[20px]">upload_file</span>
                            {termo ? 'Substituir' : 'Anexar termo'}
                        </Button>
                        {termo && (
                            <Button
                                type="button"
                                variant="outline"
                                className="text-error border-error/40 hover:bg-error-container/40"
                                disabled={ocupado}
                                onClick={() => onRemover(projeto)}
                            >
                                <span className="material-symbols-outlined text-[20px]">delete</span>
                                Remover
                            </Button>
                        )}
                    </div>
                </div>

                {termo ? (
                    <>
                        <p className="text-sm text-on-surface mt-3 flex items-center gap-1">
                            <span className="material-symbols-outlined text-[18px] text-primary-container">description</span>
                            {termo.nome_original}
                            <span className="text-xs text-on-surface-variant">
                                {tamanho(termo.tamanho_bytes)} · enviado em {dataHora(termo.enviado_em)}
                            </span>
                        </p>
                        <Assinatura assinatura={termo.assinatura} />
                    </>
                ) : (
                    <p className="text-sm text-on-surface-variant mt-3">
                        Ainda sem termo. Envie o PDF assinado no gov.br (até {Math.round(maxKb / 1024)} MB).
                    </p>
                )}
            </section>

            {projeto.termo_imagem && (
                <section aria-label={`Termo de uso de imagem — ${projeto.titulo}`} className="border-t border-outline-variant/40 pt-4">
                    <h3 className="text-sm font-semibold text-on-surface mb-2">Termo de uso de imagem</h3>
                    <TermoImagem
                        projeto={projeto}
                        maxKb={maxKb}
                        ocupado={ocupado}
                        onEnviar={onEnviarImagem}
                        onDefinirAlunos={onDefinirAlunos}
                        onRemover={onRemoverImagem}
                    />
                </section>
            )}
        </li>
    );
}

/**
 * Aba "Documentos" do orientador: o termo de responsabilidade e o termo de uso
 * de imagem dos projetos que ficaram entre os **finalistas**.
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

    async function enviar(projeto, file) {
        setOcupado(true); setAlert(''); setSucesso('');
        try {
            const resp = await enviarTermo(projeto.id, file, modoTeste);
            setSucesso(resp.meta?.message ?? 'Termo enviado.');
            await carregar(modoTeste);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlert(Object.values(fields ?? {})[0] || message || 'Não foi possível enviar o termo.');
        } finally {
            setOcupado(false);
        }
    }

    async function remover(projeto) {
        setOcupado(true); setAlert(''); setSucesso('');
        try {
            await removerTermo(projeto.id, modoTeste);
            await carregar(modoTeste);
        } catch (e) {
            setAlert(extractErrors(e).message || 'Não foi possível remover o termo.');
        } finally {
            setOcupado(false);
        }
    }

    /**
     * As ações do termo de imagem devolvem se deram certo — o formulário do
     * cartão só fecha quando o servidor aceitou (senão o orientador perderia o
     * que marcou).
     */
    async function acaoImagem(executar, falha) {
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

    const enviarImagem = (projeto, file, alunos) => acaoImagem(
        () => enviarTermoImagem(projeto.id, file, alunos, modoTeste),
        'Não foi possível enviar o termo de uso de imagem.',
    );
    const definirAlunos = (projeto, arquivo, alunos) => acaoImagem(
        () => definirAlunosTermoImagem(arquivo.id, alunos, modoTeste),
        'Não foi possível atualizar os estudantes do termo.',
    );
    const removerImagem = (projeto, arquivo) => acaoImagem(
        () => removerTermoImagem(arquivo.id, modoTeste),
        'Não foi possível remover o arquivo.',
    );

    const janela = dados?.janela;

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Documentos</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Os documentos dos projetos <strong>finalistas</strong> para a feira: o{' '}
                <strong>termo de responsabilidade</strong> (PDF assinado no gov.br) e o{' '}
                <strong>termo de uso de imagem</strong> dos estudantes (PDF ou foto do papel assinado).
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
                    Nenhum dos seus projetos está na lista final desta edição — não há termo a enviar.
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
                            onRemover={remover}
                            onEnviarImagem={enviarImagem}
                            onDefinirAlunos={definirAlunos}
                            onRemoverImagem={removerImagem}
                        />
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
