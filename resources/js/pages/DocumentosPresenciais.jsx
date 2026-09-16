import { useCallback, useEffect, useRef, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getDocumentosPresenciais, enviarTermo, removerTermo,
} from '../lib/documentosPresenciais.js';

const tamanho = (bytes) =>
    bytes === null || bytes === undefined ? '' : `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '');

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
                    leve o original impresso no credenciamento.
                </p>
            )}
        </div>
    );
}

/** Um projeto finalista e o termo dele. */
function CardProjeto({ projeto, maxKb, ocupado, onEnviar, onRemover }) {
    const input = useRef(null);
    const termo = projeto.termo;

    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-semibold text-on-surface">{projeto.titulo}</p>
                    <p className="text-xs text-on-surface-variant">
                        {projeto.area ?? 'Sem área'}{projeto.categoria ? ` · ${projeto.categoria}` : ''}
                    </p>
                </div>

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
        </li>
    );
}

/**
 * Aba "Documentos" do orientador: o termo de responsabilidade dos projetos que
 * ficaram entre os **finalistas**.
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

    const janela = dados?.janela;

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Documentos</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Os documentos que a organização precisa receber antes da feira. O{' '}
                <strong>termo de responsabilidade</strong> é exigido dos projetos{' '}
                <strong>finalistas</strong> e deve ser enviado em PDF, assinado no gov.br.
            </p>

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
                        />
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
