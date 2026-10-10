import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getParametrizacaoCredenciamento, definirItensCredenciamento } from '../lib/credenciamento.js';

/**
 * Parametrização → Credenciamento: o que o balcão entrega.
 *
 * A **janela do evento** mudou-se para Parametrização → Datas e períodos, e os
 * **documentos conferidos** para a lista única de itens do credenciamento e da
 * checagem (Sprint 181). Aqui ficam os **itens a entregar**, o lembrete que a
 * tela do balcão mostra ao concluir cada credenciamento.
 */
export default function ParametrizacaoCredenciamento() {
    const [dados, setDados] = useState(null);
    const [itens, setItens] = useState([]);
    const [novoItem, setNovoItem] = useState('');
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [salvando, setSalvando] = useState(false);

    useEffect(() => {
        getParametrizacaoCredenciamento()
            .then((d) => {
                setDados(d);
                setItens(d.config.itens ?? []);
            })
            .catch(() => setDados(null));
    }, []);

    function aplicar(novoEstado, mensagem) {
        setDados(novoEstado);
        setSucesso(mensagem);
        setAlerta('');
    }

    function falhar(e) {
        const { message } = extractErrors(e);
        setAlerta(message || 'Não foi possível concluir.');
        setSucesso('');
    }

    async function salvarItens(lista) {
        setSalvando(true);
        try {
            const novoEstado = await definirItensCredenciamento(lista);
            setItens(novoEstado.config.itens ?? []);
            aplicar(novoEstado, 'Itens salvos.');
        } catch (e) {
            falhar(e);
        } finally {
            setSalvando(false);
        }
    }

    function adicionarItem(ev) {
        ev.preventDefault();
        const texto = novoItem.trim();
        if (texto === '' || itens.includes(texto)) return;
        setNovoItem('');
        salvarItens([...itens, texto]);
    }

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Credenciamento</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                O que o balcão do evento entrega aos finalistas. Os <strong>documentos conferidos</strong> estão
                na{' '}
                <Link to="/admin/parametrizacao/itens-conferencia" className="underline">lista de itens do credenciamento e da checagem</Link>.
                O{' '}
                <strong>período do evento</strong> mudou-se para{' '}
                <Link to="/admin/parametrizacao/datas" className="underline">Datas e períodos</Link> — e,
                sem a data de início, o credenciamento continua <strong>fechado</strong>: ele é um ato
                presencial, ninguém credencia por padrão.
            </p>

            {alerta && <div className="mb-4"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            {/* Itens entregues no balcão */}
            <form onSubmit={adicionarItem} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
                <h2 className="font-display text-primary font-semibold mb-1">Itens entregues aos finalistas</h2>
                <p className="text-xs text-on-surface-variant mb-4">
                    Ao concluir cada credenciamento, a tela lembra o atendente de entregar estes itens.
                </p>
                <div className="flex gap-2">
                    <div className="flex-1">
                        <Input
                            value={novoItem}
                            onChange={(e) => setNovoItem(e.target.value)}
                            placeholder="Camiseta, crachá, kit do participante…"
                            aria-label="Novo item a entregar"
                        />
                    </div>
                    <Button type="submit" loading={salvando} disabled={!novoItem.trim()}>
                        <span className="material-symbols-outlined text-[20px]">add</span>
                        Adicionar
                    </Button>
                </div>
                {itens.length > 0 && (
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {itens.map((item) => (
                            <li key={item} className="inline-flex items-center gap-1 rounded-full bg-primary-fixed text-primary-container px-3 py-1 text-sm">
                                {item}
                                <button
                                    type="button"
                                    aria-label={`Remover ${item}`}
                                    onClick={() => salvarItens(itens.filter((i) => i !== item))}
                                    className="hover:opacity-70"
                                >
                                    <span className="material-symbols-outlined text-[16px]">close</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </form>

        </AppShell>
    );
}
