import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getAlmoxarifadoProjetos, guardarNoAlmoxarifado } from '../lib/almoxarifado.js';
import { useModoTeste } from '../lib/modoTeste.js';

const PASSOS = ['Projeto', 'Quem está deixando', 'Itens', 'Conferência'];

/** Um item por linha: é a linha que vira volume, e é o volume que é retirado. */
const itensDoTexto = (texto) => String(texto ?? '')
    .split('\n')
    .map((l) => l.trim().replace(/\s+/g, ' '))
    .filter((l) => l !== '');

/**
 * Assistente de guarda do almoxarifado.
 *
 * São quatro passos, na ordem em que a conversa acontece no balcão: de qual
 * projeto é o material, quem está deixando, o que está sendo deixado e, por
 * fim, **a conferência em voz alta** — a última tela repete tudo, com data e
 * hora, para o admin confirmar com a pessoa antes de gravar. Cada bloco do
 * resumo tem um "Alterar" que volta ao passo dele sem perder o resto.
 */
export default function AlmoxarifadoNovo() {
    const navigate = useNavigate();
    const [teste] = useModoTeste();

    const [passo, setPasso] = useState(0);
    const [busca, setBusca] = useState('');
    const [projetos, setProjetos] = useState(null);
    const [projeto, setProjeto] = useState(null);
    const [pessoa, setPessoa] = useState(null);
    const [texto, setTexto] = useState('');
    const [erro, setErro] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [agora, setAgora] = useState(() => new Date());

    useEffect(() => {
        let vivo = true;
        const t = setTimeout(() => {
            getAlmoxarifadoProjetos(busca, teste)
                .then((lista) => { if (vivo) { setProjetos(lista); setErro(''); } })
                .catch(() => { if (vivo) setErro('Não foi possível buscar os projetos.'); });
        }, busca === '' ? 0 : 300);

        return () => { vivo = false; clearTimeout(t); };
    }, [busca, teste]);

    // O horário do resumo é o de quando o admin chega à conferência: é ele que
    // será lido em voz alta para a pessoa antes de gravar.
    useEffect(() => { if (passo === 3) setAgora(new Date()); }, [passo]);

    const itens = itensDoTexto(texto);

    async function finalizar() {
        setSalvando(true);
        setErro('');
        try {
            await guardarNoAlmoxarifado({
                projeto_id: projeto.id,
                responsavel_tipo: pessoa.tipo,
                responsavel_id: pessoa.id,
                itens,
            }, teste);
            navigate('/admin/almoxarifado/registros', { replace: true });
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível gravar o registro.');
            setSalvando(false);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/almoxarifado" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Almoxarifado
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Guardar material</h1>
            <p className="text-sm text-on-surface-variant mb-5 max-w-3xl">
                Passo {passo + 1} de {PASSOS.length} · {PASSOS[passo]}
            </p>

            {/* Trilha dos passos: dá para voltar, nunca para pular adiante. */}
            <ol className="flex flex-wrap gap-2 mb-6" aria-label="Passos do registro">
                {PASSOS.map((nome, i) => (
                    <li key={nome}>
                        <button
                            type="button"
                            disabled={i > passo}
                            onClick={() => setPasso(i)}
                            className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors disabled:opacity-40 ${i === passo
                                ? 'bg-primary-container text-on-primary border-primary-container'
                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'}`}
                        >
                            {i + 1}. {nome}
                        </button>
                    </li>
                ))}
            </ol>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 max-w-3xl">
                {passo === 0 && (
                    <>
                        <h2 className="font-display font-semibold text-on-surface mb-1">De qual projeto é o material?</h2>
                        <p className="text-sm text-on-surface-variant mb-3">
                            Só aparecem os finalistas da lista vigente.
                        </p>
                        <Input
                            placeholder="Buscar por título do projeto ou orientador"
                            value={busca}
                            aria-label="Buscar projeto"
                            onChange={(e) => setBusca(e.target.value)}
                        />
                        <ul className="mt-3 divide-y divide-outline-variant/40 border border-outline-variant/40 rounded-lg">
                            {(projetos ?? []).map((p) => (
                                <li key={p.id}>
                                    <button
                                        type="button"
                                        onClick={() => { setProjeto(p); setPessoa(null); setPasso(1); }}
                                        className="w-full text-left px-3 py-2.5 hover:bg-surface-variant transition-colors"
                                    >
                                        <span className="block text-sm font-semibold text-on-surface">{p.titulo}</span>
                                        <span className="block text-xs text-on-surface-variant">
                                            {[p.escola, p.orientador].filter(Boolean).join(' · ') || '—'}
                                        </span>
                                    </button>
                                </li>
                            ))}
                            {projetos !== null && projetos.length === 0 && (
                                <li className="px-3 py-3 text-sm text-on-surface-variant">
                                    Nenhum finalista encontrado.
                                </li>
                            )}
                        </ul>
                    </>
                )}

                {passo === 1 && (
                    <>
                        <h2 className="font-display font-semibold text-on-surface mb-1">Quem está deixando o material?</h2>
                        <p className="text-sm text-on-surface-variant mb-3">
                            A escolha é entre as pessoas de <strong>{projeto?.titulo}</strong>.
                        </p>
                        <ul className="divide-y divide-outline-variant/40 border border-outline-variant/40 rounded-lg">
                            {(projeto?.pessoas ?? []).map((p) => (
                                <li key={`${p.tipo}:${p.id}`}>
                                    <button
                                        type="button"
                                        onClick={() => { setPessoa(p); setPasso(2); }}
                                        className="w-full text-left px-3 py-2.5 hover:bg-surface-variant transition-colors"
                                    >
                                        <span className="block text-sm font-semibold text-on-surface">{p.nome}</span>
                                        <span className="block text-xs text-on-surface-variant">{p.tipo_label}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {passo === 2 && (
                    <>
                        <h2 className="font-display font-semibold text-on-surface mb-1">O que está sendo guardado?</h2>
                        <p className="text-sm text-on-surface-variant mb-3">
                            <strong>Um item por linha.</strong> É a linha que vira volume, e é o volume
                            que pode ser retirado sozinho depois.
                        </p>
                        <textarea
                            rows={6}
                            aria-label="Itens guardados"
                            value={texto}
                            onChange={(e) => setTexto(e.target.value)}
                            placeholder={'Maquete de madeira\nMochila azul\nCaixa de ferramentas'}
                            className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                        />
                        <p className="text-xs text-on-surface-variant mt-1">
                            {itens.length} {itens.length === 1 ? 'item' : 'itens'}.
                        </p>
                    </>
                )}

                {passo === 3 && (
                    <>
                        <h2 className="font-display font-semibold text-on-surface mb-1">Confira com a pessoa</h2>
                        <p className="text-sm text-on-surface-variant mb-4">
                            Leia em voz alta antes de finalizar. Algo errado? Use o <em>Alterar</em> do
                            bloco correspondente.
                        </p>

                        <dl className="space-y-3">
                            <div className="flex items-start gap-3">
                                <div className="min-w-0 flex-1">
                                    <dt className="text-xs font-semibold text-on-surface-variant">Projeto</dt>
                                    <dd className="text-sm text-on-surface">{projeto?.titulo}</dd>
                                </div>
                                <Button type="button" variant="outline" onClick={() => setPasso(0)}>Alterar</Button>
                            </div>
                            <div className="flex items-start gap-3">
                                <div className="min-w-0 flex-1">
                                    <dt className="text-xs font-semibold text-on-surface-variant">Quem está deixando</dt>
                                    <dd className="text-sm text-on-surface">{pessoa?.nome} · {pessoa?.tipo_label}</dd>
                                </div>
                                <Button type="button" variant="outline" onClick={() => setPasso(1)}>Alterar</Button>
                            </div>
                            <div className="flex items-start gap-3">
                                <div className="min-w-0 flex-1">
                                    <dt className="text-xs font-semibold text-on-surface-variant">
                                        Itens ({itens.length})
                                    </dt>
                                    <dd className="text-sm text-on-surface">
                                        <ul className="list-disc pl-5">
                                            {itens.map((item, i) => <li key={`${item}:${i}`}>{item}</li>)}
                                        </ul>
                                    </dd>
                                </div>
                                <Button type="button" variant="outline" onClick={() => setPasso(2)}>Alterar</Button>
                            </div>
                            <div>
                                <dt className="text-xs font-semibold text-on-surface-variant">Data e hora</dt>
                                <dd className="text-sm text-on-surface">{agora.toLocaleString('pt-BR')}</dd>
                            </div>
                        </dl>
                    </>
                )}
            </div>

            <div className="flex flex-wrap justify-end gap-3 mt-4 max-w-3xl">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => (passo === 0 ? navigate('/admin/almoxarifado') : setPasso(passo - 1))}
                >
                    Voltar
                </Button>
                {passo === 2 && (
                    <Button type="button" disabled={itens.length === 0} onClick={() => setPasso(3)}>
                        Conferir
                    </Button>
                )}
                {passo === 3 && (
                    <Button type="button" variant="success" loading={salvando} onClick={finalizar}>
                        <span className="material-symbols-outlined text-[20px]">inventory</span>
                        Finalizar
                    </Button>
                )}
            </div>
        </AppShell>
    );
}
