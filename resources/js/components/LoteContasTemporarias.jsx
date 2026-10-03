import { useRef, useState } from 'react';
import { Alert, Button, Field, Input } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    baixarModeloContas, previaLoteContas, criarLoteContas, baixarBase64,
} from '../lib/contasTemporarias.js';

const TURNO_VAZIO = { inicio: '', fim: '' };

/**
 * Os turnos de trabalho do voluntário: vários pares início/fim. Serve ao
 * cadastro avulso e ao padrão do lote.
 */
export function TurnosEditor({ turnos, onChange, errors = {}, prefixo = 'turno' }) {
    return (
        <>
            {errors.turnos && <div className="mb-3"><Alert>{errors.turnos}</Alert></div>}
            <div className="space-y-2">
                {turnos.map((t, i) => (
                    <div key={i} className="flex flex-wrap items-end gap-2">
                        <Field label={`Início do ${prefixo} ${i + 1}`} error={errors[`turnos.${i}.inicio`]}>
                            <Input
                                type="datetime-local"
                                aria-label={`Início do ${prefixo} ${i + 1}`}
                                value={t.inicio}
                                onChange={(e) => onChange(turnos.map((x, j) => (j === i ? { ...x, inicio: e.target.value } : x)))}
                            />
                        </Field>
                        <Field label={`Fim do ${prefixo} ${i + 1}`} error={errors[`turnos.${i}.fim`]}>
                            <Input
                                type="datetime-local"
                                aria-label={`Fim do ${prefixo} ${i + 1}`}
                                value={t.fim}
                                onChange={(e) => onChange(turnos.map((x, j) => (j === i ? { ...x, fim: e.target.value } : x)))}
                            />
                        </Field>
                        {turnos.length > 1 && (
                            <Button
                                type="button"
                                variant="outline"
                                className="text-error border-error/40 hover:bg-error-container/40"
                                onClick={() => onChange(turnos.filter((_, j) => j !== i))}
                            >
                                Remover
                            </Button>
                        )}
                    </div>
                ))}
            </div>
            <Button type="button" variant="outline" className="mt-2" onClick={() => onChange([...turnos, { ...TURNO_VAZIO }])}>
                <span className="material-symbols-outlined text-[20px]">add</span>
                Acrescentar turno
            </Button>
        </>
    );
}

/**
 * **Cadastro em lote** de contas temporárias (Sprint 155).
 *
 * Três passos, na ordem em que o responsável pela equipe trabalha: baixa o
 * **modelo** em Excel, preenche e devolve; a tela mostra a **prévia** linha a
 * linha — o que vai virar conta e o que não passa, com o motivo —; confirmado,
 * as contas nascem com **senha gerada**, e a **planilha de acesso** (nome,
 * e-mail e senha) é baixada na hora.
 *
 * As senhas só existem nessa resposta: o portal guarda o hash. Por isso a
 * tabela fica na tela até o admin fechar, e o botão de baixar de novo também.
 *
 * Os campos de início/horas (ou turnos, para voluntários) são o **padrão**: a
 * linha que deixar a coluna em branco recebe o que estiver aqui.
 */
export default function LoteContasTemporarias({ setor, turnos: comTurnos, horasPadrao, onCriadas, onFechar }) {
    const [padroes, setPadroes] = useState({ valido_de: '', horas: '' });
    const [turnos, setTurnos] = useState([{ ...TURNO_VAZIO }]);
    const [arquivo, setArquivo] = useState(null);
    const [previa, setPrevia] = useState(null);
    const [resultado, setResultado] = useState(null);
    const [alerta, setAlerta] = useState('');
    const [ocupado, setOcupado] = useState(false);
    const input = useRef(null);

    const padroesEnviados = () => (comTurnos
        ? { turnos: turnos.filter((t) => t.inicio && t.fim) }
        : { valido_de: padroes.valido_de || undefined, horas: padroes.horas ? Number(padroes.horas) : undefined });

    async function modelo() {
        try {
            await baixarModeloContas(setor);
        } catch {
            setAlerta('Não foi possível baixar o modelo.');
        }
    }

    async function conferir() {
        if (!arquivo) return;
        setOcupado(true); setAlerta('');
        try {
            setPrevia(await previaLoteContas(arquivo, padroesEnviados(), setor));
        } catch (e) {
            const { message, fields } = extractErrors(e);
            const doArquivo = fields?.arquivo ? [].concat(fields.arquivo)[0] : null;
            setAlerta(doArquivo || message || 'Não foi possível ler a planilha.');
        } finally {
            setOcupado(false);
        }
    }

    async function criar() {
        const validas = previa.linhas.filter((l) => l.erros.length === 0);
        if (validas.length === 0) return;

        setOcupado(true); setAlerta('');
        try {
            const resp = await criarLoteContas(validas, padroesEnviados(), setor);
            setResultado(resp.meta);
            setPrevia(null);
            onCriadas?.(resp);
            if (resp.meta?.arquivo) baixarBase64(resp.meta.arquivo.base64, resp.meta.arquivo.nome);
        } catch (e) {
            const { message } = extractErrors(e);
            setAlerta(message || 'Não foi possível criar as contas.');
        } finally {
            setOcupado(false);
        }
    }

    function recomecar() {
        setPrevia(null); setArquivo(null); setResultado(null); setAlerta('');
        if (input.current) input.current.value = '';
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-5xl" aria-label="Cadastro em lote">
            <div className="flex items-start justify-between gap-3 mb-3">
                <div>
                    <h2 className="font-display text-primary font-semibold">Cadastro em lote</h2>
                    <p className="text-sm text-on-surface-variant max-w-3xl">
                        Baixe o modelo, preencha uma linha por pessoa e envie de volta (.xlsx ou .csv). Você
                        confere tudo antes de criar. Cada conta ganha uma <strong>senha gerada</strong>, e a
                        planilha com os acessos é baixada na hora — <strong>as senhas não ficam guardadas</strong>.
                    </p>
                </div>
                <Button type="button" variant="outline" onClick={onFechar}>Fechar</Button>
            </div>

            {alerta && <div className="mb-3"><Alert>{alerta}</Alert></div>}

            {resultado ? (
                <ResultadoLote resultado={resultado} onNovo={recomecar} onFechar={onFechar} />
            ) : previa ? (
                <PreviaLote previa={previa} ocupado={ocupado} onCriar={criar} onVoltar={recomecar} />
            ) : (
                <div className="space-y-4">
                    <Button type="button" variant="outline" onClick={modelo}>
                        <span className="material-symbols-outlined text-[20px]">download</span>
                        Baixar modelo (Excel)
                    </Button>

                    <div>
                        <p className="text-sm font-semibold text-on-surface mb-1">
                            {comTurnos ? 'Turnos padrão' : 'Acesso padrão'}
                        </p>
                        <p className="text-xs text-on-surface-variant mb-2">
                            {comTurnos
                                ? 'Vale para quem deixar a coluna de turnos em branco na planilha.'
                                : 'Vale para quem deixar as colunas de início e horas em branco na planilha.'}
                        </p>
                        {comTurnos ? (
                            <TurnosEditor turnos={turnos} onChange={setTurnos} />
                        ) : (
                            <div className="flex flex-wrap gap-3">
                                <Field label="Começa em" hint="Em branco, vale a partir da criação.">
                                    <Input
                                        type="datetime-local"
                                        aria-label="Início padrão do lote"
                                        value={padroes.valido_de}
                                        onChange={(e) => setPadroes((p) => ({ ...p, valido_de: e.target.value }))}
                                    />
                                </Field>
                                <Field label="Disponível por (horas)" hint={`Em branco, ${horasPadrao} horas.`}>
                                    <Input
                                        type="number"
                                        min="1"
                                        max="8760"
                                        aria-label="Horas padrão do lote"
                                        placeholder={String(horasPadrao)}
                                        value={padroes.horas}
                                        onChange={(e) => setPadroes((p) => ({ ...p, horas: e.target.value }))}
                                    />
                                </Field>
                            </div>
                        )}
                    </div>

                    <div className="flex flex-wrap items-end gap-3">
                        <label className="block">
                            <span className="text-sm font-semibold text-on-surface">Planilha preenchida</span>
                            <input
                                ref={input}
                                type="file"
                                accept=".xlsx,.csv"
                                aria-label="Planilha preenchida"
                                onChange={(e) => setArquivo(e.target.files?.[0] ?? null)}
                                className="mt-1 block text-sm text-on-surface-variant file:mr-3 file:rounded-lg file:border-0 file:bg-primary-fixed file:px-3 file:py-2 file:text-primary-container"
                            />
                        </label>
                        <Button type="button" onClick={conferir} disabled={!arquivo} loading={ocupado}>
                            Conferir planilha
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}

function PreviaLote({ previa, ocupado, onCriar, onVoltar }) {
    return (
        <div>
            <p className="text-sm text-on-surface mb-3">
                <strong>{previa.validas}</strong> {previa.validas === 1 ? 'conta pronta' : 'contas prontas'} para criar
                {previa.invalidas > 0 && (
                    <> · <strong className="text-error">{previa.invalidas}</strong> com problema (ficam de fora)</>
                )}
            </p>
            <div className="overflow-x-auto border border-outline-variant/40 rounded-lg mb-4">
                <table className="w-full text-sm">
                    <thead className="bg-surface-container text-left text-xs uppercase text-on-surface-variant">
                        <tr>
                            <th className="px-3 py-2">Linha</th>
                            <th className="px-3 py-2">Nome</th>
                            <th className="px-3 py-2">E-mail</th>
                            <th className="px-3 py-2">CPF</th>
                            <th className="px-3 py-2">Curso</th>
                            <th className="px-3 py-2">Acesso</th>
                            <th className="px-3 py-2">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        {previa.linhas.map((l) => (
                            <tr key={l.linha} className={`border-t border-outline-variant/30 ${l.erros.length ? 'bg-error-container/30' : ''}`}>
                                <td className="px-3 py-2 text-on-surface-variant">{l.linha}</td>
                                <td className="px-3 py-2">{l.name || '—'}</td>
                                <td className="px-3 py-2">{l.email || '—'}</td>
                                <td className="px-3 py-2 whitespace-nowrap">{l.cpf_formatado || '—'}</td>
                                <td className="px-3 py-2">{l.curso || '—'}</td>
                                <td className="px-3 py-2 text-xs">{l.acesso_label || '—'}</td>
                                <td className="px-3 py-2 text-xs">
                                    {l.erros.length === 0
                                        ? <span className="text-secondary font-semibold">Pronta</span>
                                        : <ul className="text-error list-disc pl-4">{l.erros.map((e) => <li key={e}>{e}</li>)}</ul>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex flex-wrap gap-2 justify-end">
                <Button type="button" variant="outline" onClick={onVoltar}>Escolher outra planilha</Button>
                <Button type="button" onClick={onCriar} loading={ocupado} disabled={previa.validas === 0}>
                    {previa.validas === 1 ? 'Criar 1 conta' : `Criar ${previa.validas} contas`}
                </Button>
            </div>
        </div>
    );
}

function ResultadoLote({ resultado, onNovo, onFechar }) {
    const [mostrar, setMostrar] = useState(false);

    return (
        <div className="space-y-3">
            <Alert type="info">{resultado.message}</Alert>

            {resultado.arquivo && (
                <div className="flex flex-wrap gap-2">
                    <Button type="button" onClick={() => baixarBase64(resultado.arquivo.base64, resultado.arquivo.nome)}>
                        <span className="material-symbols-outlined text-[20px]">download</span>
                        Baixar planilha de acesso de novo
                    </Button>
                    <Button type="button" variant="outline" onClick={() => setMostrar((m) => !m)}>
                        {mostrar ? 'Esconder senhas' : 'Mostrar senhas'}
                    </Button>
                </div>
            )}

            {resultado.criadas?.length > 0 && (
                <ul className="text-sm divide-y divide-outline-variant/30 border border-outline-variant/40 rounded-lg">
                    {resultado.criadas.map((c) => (
                        <li key={c.email} className="px-3 py-2 flex flex-wrap gap-x-4">
                            <span className="font-semibold">{c.nome}</span>
                            <span className="text-on-surface-variant">{c.email}</span>
                            <span className="font-mono">{mostrar ? c.senha : '••••••••••'}</span>
                        </li>
                    ))}
                </ul>
            )}

            {resultado.ignoradas?.length > 0 && (
                <div>
                    <p className="text-sm font-semibold text-error mb-1">Ficaram de fora</p>
                    <ul className="text-sm text-on-surface-variant list-disc pl-5">
                        {resultado.ignoradas.map((l) => (
                            <li key={l.linha}>Linha {l.linha} — {l.name || l.email || 'sem nome'}: {l.erros.join(' ')}</li>
                        ))}
                    </ul>
                </div>
            )}

            <p className="text-xs text-on-surface-variant">
                Depois de fechar, as senhas não aparecem mais. Quem perder a sua usa “Esqueci a senha” no login.
            </p>
            <div className="flex gap-2 justify-end">
                <Button type="button" variant="outline" onClick={onNovo}>Enviar outra planilha</Button>
                <Button type="button" onClick={onFechar}>Concluir</Button>
            </div>
        </div>
    );
}
