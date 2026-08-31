import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getOrdemAbas, salvarOrdemAbas, restaurarOrdemAbas } from '../lib/admin.js';

/**
 * Parametrização → **Ordem do menu**.
 *
 * A ordem das abas do admin nasceu sendo a do código, e ela não acompanha o
 * calendário: no mês do evento o Credenciamento deveria abrir o menu; no
 * período de inscrição, Projetos. Aqui a organização arruma.
 *
 * A ordem é **da edição**, como os prazos e os limites — é uma decisão de
 * equipe, não de gosto pessoal: quem dá suporte precisa poder dizer "o terceiro
 * item do menu" e acertar. Ela vale no menu lateral e na Home do admin, e cada
 * pessoa continua vendo só as abas que os escopos dela abrem: mudar a ordem
 * nunca muda o acesso.
 *
 * A reordenação tem dois caminhos porque um só não serve a todo mundo: arrastar
 * (o gesto natural) e os botões de subir/descer (que funcionam no teclado e no
 * celular).
 */
export default function ParametrizacaoAbas() {
    const [config, setConfig] = useState(null);
    const [abas, setAbas] = useState([]);
    const [arrastando, setArrastando] = useState(null);
    const [sujo, setSujo] = useState(false);
    const [salvando, setSalvando] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [confirmar, dialogo] = useConfirm();

    useEffect(() => {
        getOrdemAbas()
            .then((dados) => { setConfig(dados); setAbas(dados.abas); })
            .catch(() => { setConfig({ abas: [] }); setAlerta('Não foi possível carregar as abas.'); });
    }, []);

    function aplicar(resp) {
        setConfig(resp.data);
        setAbas(resp.data.abas);
        setSujo(false);
        setSucesso(resp.meta?.message ?? '');
        setAlerta('');
    }

    /** Tira a aba de `de` e a devolve na posição `para`. */
    function mover(de, para) {
        if (para < 0 || para >= abas.length || de === para) return;

        setAbas((atual) => {
            const lista = [...atual];
            const [item] = lista.splice(de, 1);
            lista.splice(para, 0, item);
            return lista;
        });
        setSujo(true);
        setSucesso('');
    }

    async function salvar() {
        setSalvando(true); setAlerta(''); setSucesso('');
        try {
            aplicar(await salvarOrdemAbas(abas.map((a) => a.value)));
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível salvar a ordem.');
        } finally {
            setSalvando(false);
        }
    }

    async function restaurar() {
        const ok = await confirmar({
            title: 'Restaurar a ordem original?',
            message: 'O menu volta à ordem padrão do portal. A ordem que você montou é descartada.',
            confirmLabel: 'Restaurar',
        });
        if (!ok) return;

        setSalvando(true); setAlerta(''); setSucesso('');
        try {
            aplicar(await restaurarOrdemAbas());
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível restaurar a ordem.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Ordem do menu</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Em que ordem as abas aparecem no menu do administrador e na tela inicial. Arraste
                para reposicionar, ou use as setas. A ordem vale para <strong>todos os
                administradores</strong> desta edição — cada um continua vendo só as abas que os
                escopos dele abrem.
            </p>

            {alerta && <div className="mb-4 max-w-2xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-2xl"><Alert type="info">{sucesso}</Alert></div>}

            {config === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    <ol className="bg-surface-container-lowest rounded-xl fetec-card-shadow max-w-2xl overflow-hidden">
                        {abas.map((aba, indice) => (
                            <li
                                key={aba.value}
                                draggable
                                onDragStart={() => setArrastando(indice)}
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    if (arrastando !== null) mover(arrastando, indice);
                                    setArrastando(null);
                                }}
                                onDragEnd={() => setArrastando(null)}
                                className={`px-4 py-3 border-b border-outline-variant/30 last:border-0 flex items-center gap-3 ${
                                    arrastando === indice ? 'opacity-50' : ''
                                }`}
                            >
                                <span className="material-symbols-outlined text-on-surface-variant cursor-grab" aria-hidden="true">
                                    drag_indicator
                                </span>
                                <span className="w-6 text-sm font-semibold text-on-surface-variant tabular-nums">
                                    {indice + 1}
                                </span>
                                <div className="flex-1 min-w-0">
                                    <p className="font-semibold text-on-surface truncate">{aba.label}</p>
                                    <p className="text-xs text-on-surface-variant truncate">{aba.descricao}</p>
                                </div>
                                <div className="flex gap-1 shrink-0">
                                    <button
                                        type="button"
                                        onClick={() => mover(indice, indice - 1)}
                                        disabled={indice === 0}
                                        title={`Mover ${aba.label} para cima`}
                                        className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant disabled:opacity-30 transition-colors"
                                    >
                                        <span className="material-symbols-outlined text-[20px]">arrow_upward</span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => mover(indice, indice + 1)}
                                        disabled={indice === abas.length - 1}
                                        title={`Mover ${aba.label} para baixo`}
                                        className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant disabled:opacity-30 transition-colors"
                                    >
                                        <span className="material-symbols-outlined text-[20px]">arrow_downward</span>
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ol>

                    <div className="flex flex-wrap gap-3 mt-4 max-w-2xl">
                        <Button type="button" onClick={salvar} loading={salvando} disabled={!sujo}>
                            Salvar ordem
                        </Button>
                        {config.personalizada && (
                            <Button type="button" variant="outline" onClick={restaurar} disabled={salvando}>
                                Restaurar ordem original
                            </Button>
                        )}
                    </div>

                    <p className="text-xs text-on-surface-variant mt-3 max-w-2xl">
                        A nova ordem aparece no menu no próximo carregamento da página.
                    </p>
                </>
            )}

            {dialogo}
        </AppShell>
    );
}
