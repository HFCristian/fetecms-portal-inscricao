import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, Select } from '../components/ui.jsx';
import { buscarEtiquetasAvulsas, baixarEtiquetasAvulsas } from '../lib/credenciamento.js';
import { useModoTeste } from '../lib/modoTeste.js';

const GRUPOS = [
    { value: 'participantes', label: 'Lista final', icone: 'groups', vazio: 'Ninguém da lista final com essa busca.' },
    { value: 'avaliadores', label: 'Avaliadores', icone: 'rate_review', vazio: 'Nenhum avaliador ativo com essa busca.' },
    { value: 'interpretes', label: 'Intérpretes', icone: 'sign_language', vazio: 'Nenhum intérprete cadastrado com essa busca.' },
    { value: 'voluntarios', label: 'Voluntários', icone: 'volunteer_activism', vazio: 'Nenhum voluntário com essa busca.' },
    { value: 'personalizada', label: 'Personalizada', icone: 'edit_note' },
];

const PLACEHOLDER = {
    participantes: 'Nome, código do crachá ou título do projeto',
    avaliadores: 'Nome ou e-mail',
    interpretes: 'Nome ou CPF',
    voluntarios: 'Nome ou e-mail',
};

// Valores de reserva até a primeira busca responder.
const TURNOS_PADRAO = [
    { value: 'A', label: 'Turno A (matutino)' },
    { value: 'B', label: 'Turno B (vespertino)' },
];
const POR_FOLHA = 16;
const MAXIMO = 64;
const COLUNAS = 2;

const PERSONALIZADA_VAZIA = { nome: '', papel: '', turno: '', estande: '', codigo: '' };

/** "Turno A (matutino) · Estande 42 · 2026-31-123-A45": o que a etiqueta leva além do nome. */
function resumo(e) {
    return [e.turno, e.estande != null && e.estande !== '' ? `Estande ${e.estande}` : null, e.codigo]
        .filter(Boolean).join(' · ');
}

/**
 * Credenciamento → **Etiquetas avulsas** (Sprint 218).
 *
 * Reimprime a etiqueta de quem perdeu, molhou ou esqueceu o crachá, sem gerar a
 * folha inteira: alguém da **lista final**, um **avaliador**, um **intérprete**,
 * um **voluntário** dos balcões — ou uma etiqueta **personalizada**, com os
 * mesmos campos da normal. A etiqueta de quem tem cadastro sai igual à da folha
 * original, porque é o servidor que a monta.
 *
 * As etiquetas vão para a mesma folha A4 de 16, e o balcão escolhe em que
 * posição a primeira cai: a folha já usada pela metade volta para a impressora
 * em vez de ir para o lixo. Cada geração fica registrada.
 */
export default function CredenciamentoEtiquetas() {
    const [teste] = useModoTeste();
    const [grupo, setGrupo] = useState('participantes');
    const [busca, setBusca] = useState('');
    const [resultados, setResultados] = useState(null);
    const [meta, setMeta] = useState({ turnos: TURNOS_PADRAO, por_folha: POR_FOLHA, maximo: MAXIMO });
    const [erroBusca, setErroBusca] = useState('');
    const [personalizada, setPersonalizada] = useState(PERSONALIZADA_VAZIA);
    const [fila, setFila] = useState([]);
    const [inicio, setInicio] = useState(1);
    const [gerando, setGerando] = useState(false);
    const [erro, setErro] = useState('');
    const [sucesso, setSucesso] = useState('');
    const proxima = useRef(1);

    const personalizando = grupo === 'personalizada';
    const cheia = fila.length >= meta.maximo;

    useEffect(() => {
        if (personalizando) return undefined;

        let vivo = true;
        const t = setTimeout(() => {
            buscarEtiquetasAvulsas(grupo, busca.trim(), teste)
                .then((r) => {
                    if (!vivo) return;
                    setResultados(r.data);
                    setMeta((m) => ({ ...m, ...r.meta }));
                    setErroBusca('');
                })
                .catch(() => vivo && setErroBusca('Não foi possível buscar agora.'));
        }, 250);

        return () => { vivo = false; clearTimeout(t); };
    }, [grupo, busca, teste, personalizando]);

    function trocarGrupo(valor) {
        setGrupo(valor);
        setBusca('');
        setResultados(null);
    }

    function adicionar(etiqueta) {
        if (cheia) return;
        setFila((f) => [...f, { ...etiqueta, chave: proxima.current++ }]);
        setSucesso('');
        setErro('');
    }

    function adicionarPersonalizada(e) {
        e.preventDefault();
        const nome = personalizada.nome.trim();
        if (nome === '') return;

        adicionar({
            grupo: 'personalizada',
            nome,
            papel_label: personalizada.papel.trim(),
            turno: meta.turnos.find((t) => t.value === personalizada.turno)?.label ?? null,
            turno_valor: personalizada.turno || null,
            estande: personalizada.estande === '' ? null : Number(personalizada.estande),
            codigo: personalizada.codigo.trim() || null,
            detalhe: 'Personalizada',
        });
        setPersonalizada(PERSONALIZADA_VAZIA);
    }

    function remover(chave) {
        setFila((f) => f.filter((e) => e.chave !== chave));
        setErro('');
    }

    async function gerar() {
        setGerando(true);
        setErro('');
        setSucesso('');
        try {
            await baixarEtiquetasAvulsas({
                inicio,
                itens: fila.map((e) => (e.grupo === 'personalizada'
                    ? {
                        grupo: e.grupo,
                        nome: e.nome,
                        papel: e.papel_label || null,
                        turno: e.turno_valor,
                        estande: e.estande,
                        codigo: e.codigo,
                    }
                    : { grupo: e.grupo, id: e.id })),
            }, teste);
            setSucesso(`PDF gerado com ${fila.length} ${fila.length === 1 ? 'etiqueta' : 'etiquetas'}. A impressão fica registrada.`);
        } catch (e) {
            const alvo = e.indice !== undefined ? fila[e.indice] : null;
            setErro(alvo ? `${alvo.nome}: ${e.message}` : e.message);
        } finally {
            setGerando(false);
        }
    }

    // As posições da primeira folha: antes do início, já usadas; a partir dele,
    // as que a fila vai ocupar.
    const posicoes = Array.from({ length: meta.por_folha }, (_, i) => i + 1);
    const folhas = Math.max(1, Math.ceil((fila.length + inicio - 1) / meta.por_folha));

    return (
        <AppShell>
            <Link to="/admin/credenciamento" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Credenciamento
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Etiquetas avulsas</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Reimprima a etiqueta de quem perdeu o crachá sem gerar a folha inteira. A de quem
                tem cadastro sai igual à original — mesmo código, turno e estande. Escolha em que
                posição da folha a primeira etiqueta cai para aproveitar uma folha já usada.
            </p>

            {teste && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        <strong>Modo demo</strong> — a lista final é a de demonstração.
                    </Alert>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] gap-5 items-start">
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 min-w-0">
                    <h2 className="font-display text-primary font-semibold mb-3">De quem é a etiqueta</h2>
                    <div className="flex flex-wrap gap-1 mb-4" role="tablist" aria-label="Origem da etiqueta">
                        {GRUPOS.map((g) => (
                            <button
                                key={g.value}
                                type="button"
                                role="tab"
                                aria-selected={grupo === g.value}
                                onClick={() => trocarGrupo(g.value)}
                                className={`inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-semibold border transition-colors ${grupo === g.value
                                    ? 'bg-primary-container text-on-primary border-primary-container'
                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'}`}
                            >
                                <span className="material-symbols-outlined text-[18px]" aria-hidden="true">{g.icone}</span>
                                {g.label}
                            </button>
                        ))}
                    </div>

                    {cheia && (
                        <div className="mb-3"><Alert type="info">No máximo {meta.maximo} etiquetas por vez. Gere o PDF e comece outra.</Alert></div>
                    )}

                    {personalizando ? (
                        <form onSubmit={adicionarPersonalizada} className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="sm:col-span-2">
                                <Field label="Nome" required>
                                    <Input
                                        value={personalizada.nome}
                                        maxLength={120}
                                        onChange={(e) => setPersonalizada((p) => ({ ...p, nome: e.target.value }))}
                                        aria-label="Nome"
                                    />
                                </Field>
                            </div>
                            <Field label="Papel" hint="Sai ao lado do nome, como “aluno(a)”.">
                                <Input
                                    value={personalizada.papel}
                                    maxLength={40}
                                    onChange={(e) => setPersonalizada((p) => ({ ...p, papel: e.target.value }))}
                                    aria-label="Papel"
                                />
                            </Field>
                            <Field label="Turno">
                                <Select
                                    value={personalizada.turno}
                                    onChange={(e) => setPersonalizada((p) => ({ ...p, turno: e.target.value }))}
                                    aria-label="Turno"
                                >
                                    <option value="">Sem turno</option>
                                    {meta.turnos.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                                </Select>
                            </Field>
                            <Field label="Estande">
                                <Input
                                    type="number"
                                    min={1}
                                    max={9999}
                                    value={personalizada.estande}
                                    onChange={(e) => setPersonalizada((p) => ({ ...p, estande: e.target.value }))}
                                    aria-label="Estande"
                                />
                            </Field>
                            <Field label="Código" hint="Opcional. Em branco, a etiqueta sai sem QR e sem barras.">
                                <Input
                                    value={personalizada.codigo}
                                    maxLength={40}
                                    onChange={(e) => setPersonalizada((p) => ({ ...p, codigo: e.target.value }))}
                                    aria-label="Código"
                                    className="font-mono"
                                />
                            </Field>
                            <div className="sm:col-span-2">
                                <Button type="submit" variant="outline" disabled={cheia || personalizada.nome.trim() === ''}>
                                    <span className="material-symbols-outlined text-[20px]">add</span>
                                    Adicionar à folha
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <>
                            <Input
                                value={busca}
                                onChange={(e) => setBusca(e.target.value)}
                                placeholder={PLACEHOLDER[grupo]}
                                aria-label="Buscar"
                            />
                            {erroBusca && <div className="mt-3"><Alert>{erroBusca}</Alert></div>}
                            {resultados === null ? (
                                <div className="text-center py-8 text-on-surface-variant">
                                    <span className="inline-block w-7 h-7 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                                </div>
                            ) : resultados.length === 0 ? (
                                <p className="text-sm text-on-surface-variant py-6 text-center">
                                    {GRUPOS.find((g) => g.value === grupo)?.vazio}
                                </p>
                            ) : (
                                <ul className="mt-3 divide-y divide-outline-variant/40">
                                    {resultados.map((r) => (
                                        <li key={`${r.grupo}:${r.id}`} className="py-2.5 flex items-center gap-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-on-surface truncate">
                                                    {r.nome}
                                                    <span className="font-normal text-on-surface-variant"> · {r.papel_label}</span>
                                                </p>
                                                {r.detalhe && <p className="text-xs text-on-surface-variant truncate">{r.detalhe}</p>}
                                                <p className="text-xs text-on-surface-variant truncate font-mono">{resumo(r)}</p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="px-3! py-1.5! text-sm"
                                                disabled={cheia}
                                                onClick={() => adicionar(r)}
                                                aria-label={`Adicionar etiqueta de ${r.nome}`}
                                            >
                                                <span className="material-symbols-outlined text-[18px]">add</span>
                                                Adicionar
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {resultados?.length === 30 && (
                                <p className="text-xs text-on-surface-variant mt-2">Mostrando os 30 primeiros. Refine a busca.</p>
                            )}
                        </>
                    )}
                </section>

                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 min-w-0">
                    <h2 className="font-display text-primary font-semibold mb-3">
                        Na folha {fila.length > 0 && <span className="text-on-surface-variant font-normal">({fila.length})</span>}
                    </h2>

                    {fila.length === 0 ? (
                        <p className="text-sm text-on-surface-variant mb-4">
                            Nenhuma etiqueta ainda. Busque a pessoa ao lado e clique em “Adicionar”.
                        </p>
                    ) : (
                        <ol className="space-y-2 mb-4">
                            {fila.map((e, i) => (
                                <li key={e.chave} className="flex items-start gap-2 rounded-lg border border-outline-variant/50 p-2">
                                    <span className="text-xs font-semibold text-primary-container w-6 shrink-0 pt-0.5">{i + 1}.</span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-on-surface truncate">
                                            {e.nome}
                                            {e.papel_label && <span className="font-normal text-on-surface-variant"> · {e.papel_label}</span>}
                                        </p>
                                        <p className="text-xs text-on-surface-variant truncate">{resumo(e) || 'Sem código'}</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => remover(e.chave)}
                                        aria-label={`Remover etiqueta de ${e.nome}`}
                                        className="text-on-surface-variant hover:text-error"
                                    >
                                        <span className="material-symbols-outlined text-[20px]">close</span>
                                    </button>
                                </li>
                            ))}
                        </ol>
                    )}

                    <p className="text-sm font-semibold text-on-surface mb-1">Começar na posição</p>
                    <p className="text-xs text-on-surface-variant mb-2">
                        Clique na primeira posição livre da folha. As anteriores ficam em branco.
                    </p>
                    <div
                        className="grid gap-1 mb-2 max-w-[12rem]"
                        style={{ gridTemplateColumns: `repeat(${COLUNAS}, minmax(0, 1fr))` }}
                        role="radiogroup"
                        aria-label="Posição da primeira etiqueta"
                    >
                        {posicoes.map((p) => {
                            const usada = p < inicio;
                            const ocupada = p >= inicio && p < inicio + fila.length;
                            return (
                                <button
                                    key={p}
                                    type="button"
                                    role="radio"
                                    aria-checked={p === inicio}
                                    aria-label={`Posição ${p}`}
                                    onClick={() => setInicio(p)}
                                    className={`h-7 rounded text-xs font-semibold border transition-colors ${p === inicio
                                        ? 'bg-primary-container text-on-primary border-primary-container'
                                        : ocupada
                                            ? 'bg-primary-fixed text-primary-container border-primary-fixed'
                                            : usada
                                                ? 'bg-surface-variant text-on-surface-variant/50 border-outline-variant/40 line-through'
                                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'}`}
                                >
                                    {p}
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-xs text-on-surface-variant mb-4">
                        {folhas > 1 ? `${folhas} folhas: o que não couber nesta continua numa folha nova.` : 'Cabe nesta folha.'}
                    </p>

                    {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
                    {sucesso && <div className="mb-3"><Alert type="info">{sucesso}</Alert></div>}

                    <Button type="button" className="w-full" loading={gerando} disabled={fila.length === 0} onClick={gerar}>
                        <span className="material-symbols-outlined text-[20px]">print</span>
                        Gerar PDF
                    </Button>
                    {fila.length > 0 && (
                        <button
                            type="button"
                            onClick={() => { setFila([]); setErro(''); setSucesso(''); }}
                            className="w-full text-xs text-on-surface-variant hover:text-error mt-2"
                        >
                            Limpar a folha
                        </button>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
