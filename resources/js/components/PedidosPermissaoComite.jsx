import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert, Button } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getPermissoesComite, decidirPermissaoComite } from '../lib/comite.js';

const ABAS = [
    { value: 'pendente', label: 'Aguardando' },
    { value: 'decididos', label: 'Decididos' },
];

const SELOS = {
    pendente: 'bg-[#fff4cc] text-[#5c4a00]',
    aprovado: 'bg-[#d4f0dc] text-[#004d15]',
    recusado: 'bg-error-container text-on-error-container',
};

const quando = (iso) => (iso
    ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
    : '');

const localDe = (p) => [p.codigo, p.estande ? `Estande ${String(p.estande).padStart(3, '0')}` : null, p.turno_label]
    .filter(Boolean).join(' · ');

/** Um pedido: quem, o quê, por quê — e aprovar ou recusar (com resposta opcional). */
function LinhaPedido({ pedido, onDecidido }) {
    const [recusando, setRecusando] = useState(false);
    const [resposta, setResposta] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');

    async function decidir(decisao) {
        setSalvando(true);
        setErro('');
        try {
            const r = await decidirPermissaoComite(pedido.id, decisao, decisao === 'recusar' ? resposta : null);
            setRecusando(false);
            setResposta('');
            onDecidido(r.meta?.message);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível decidir.');
        } finally {
            setSalvando(false);
        }
    }

    const p = pedido.projeto;

    return (
        <li className="py-3">
            <div className="flex flex-wrap items-start gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-sm text-on-surface">
                        <strong>{pedido.avaliador.nome}</strong> quer avaliar <strong>{p.titulo}</strong>
                    </p>
                    <p className="text-xs text-on-surface-variant">
                        {[localDe(p) || 'Sem estande', p.area, p.categoria, `pedido em ${quando(pedido.pedido_em)}`].filter(Boolean).join(' · ')}
                    </p>
                    {pedido.justificativa && <p className="text-xs text-on-surface mt-1">“{pedido.justificativa}”</p>}
                    {pedido.status !== 'pendente' && (
                        <p className="text-xs text-on-surface-variant mt-1">
                            {pedido.status_label} por {pedido.decidido_por ?? '—'} em {quando(pedido.decidido_em)}
                            {pedido.resposta ? ` — “${pedido.resposta}”` : ''}
                        </p>
                    )}
                </div>
                <span className={`text-xs font-semibold px-2 py-1 rounded-full ${SELOS[pedido.status] ?? ''}`}>{pedido.status_label}</span>
                <div className="flex gap-2">
                    {pedido.status !== 'aprovado' && (
                        <Button type="button" variant="outline" loading={salvando && !recusando} onClick={() => decidir('aprovar')} aria-label={`Aprovar o pedido de ${pedido.avaliador.nome}`}>
                            Aprovar
                        </Button>
                    )}
                    {pedido.status !== 'recusado' && !recusando && (
                        <Button type="button" variant="outline" onClick={() => setRecusando(true)} aria-label={`Recusar o pedido de ${pedido.avaliador.nome}`}>
                            Recusar
                        </Button>
                    )}
                </div>
            </div>
            {recusando && (
                <div className="mt-2 flex flex-wrap items-end gap-2">
                    <label className="flex-1 min-w-[14rem] text-xs text-on-surface-variant">
                        Resposta ao avaliador (opcional)
                        <input
                            className="mt-1 w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface outline-none focus:border-primary-container"
                            maxLength={500}
                            value={resposta}
                            onChange={(e) => setResposta(e.target.value)}
                        />
                    </label>
                    <Button type="button" loading={salvando} onClick={() => decidir('recusar')}>Confirmar recusa</Button>
                    <Button type="button" variant="outline" onClick={() => setRecusando(false)}>Cancelar</Button>
                </div>
            )}
            {erro && <div className="mt-2"><Alert>{erro}</Alert></div>}
        </li>
    );
}

/**
 * Comitê especial → Designações → **Pedidos de permissão** (Sprint 234): com a
 * regra "avaliar apenas projetos designados" ligada, o avaliador da comissão
 * pede para avaliar no estande um projeto fora das designações dele, e a
 * organização decide aqui. Aprovar libera o início; recusar pode levar uma
 * resposta, que o avaliador lê na tela dele.
 */
export default function PedidosPermissaoComite() {
    const [aba, setAba] = useState('pendente');
    const [pedidos, setPedidos] = useState(null);
    const [meta, setMeta] = useState({ ativa: false, pendentes: 0 });
    const [aviso, setAviso] = useState('');
    const [erro, setErro] = useState('');

    const carregar = useCallback(() => getPermissoesComite(aba)
        .then((r) => { setPedidos(r.data); setMeta(r.meta ?? {}); setErro(''); })
        .catch(() => setErro('Não foi possível carregar os pedidos de permissão.')), [aba]);

    useEffect(() => { carregar(); }, [carregar]);

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mt-6 max-w-4xl" aria-label="Pedidos de permissão">
            <div className="flex flex-wrap items-center justify-between gap-2 mb-1">
                <h2 className="font-display font-semibold text-on-surface">
                    Pedidos de permissão para avaliar
                    {meta.pendentes > 0 && (
                        <span className="ml-2 text-xs font-semibold rounded-full px-2 py-0.5 bg-[#fff4cc] text-[#5c4a00]">{meta.pendentes} aguardando</span>
                    )}
                </h2>
                <div className="flex gap-1" role="tablist" aria-label="Situação dos pedidos">
                    {ABAS.map((a) => (
                        <button
                            key={a.value}
                            type="button"
                            role="tab"
                            aria-selected={aba === a.value}
                            onClick={() => setAba(a.value)}
                            className={`px-3 py-1 rounded-full text-xs font-semibold border ${
                                aba === a.value
                                    ? 'bg-primary-container text-on-primary border-primary-container'
                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                            }`}
                        >
                            {a.label}
                        </button>
                    ))}
                </div>
            </div>
            <p className="text-sm text-on-surface-variant mb-3">
                Com a regra <em>avaliar apenas projetos designados</em> ligada, quem é da comissão especial só inicia no
                estande o que foi designado a ele. Para avaliar outro projeto, ele pede aqui.
            </p>
            {!meta.ativa && (
                <div className="mb-3">
                    <Alert type="info">
                        A regra está desligada: o comitê avalia qualquer projeto com vaga, e pedidos novos não chegam. Ligue em{' '}
                        <Link to="/admin/parametrizacao/presencial" className="underline">Parametrização → Avaliação presencial</Link>.
                    </Alert>
                </div>
            )}
            {aviso && <div className="mb-3"><Alert type="info">{aviso}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

            {pedidos === null ? (
                <p className="text-sm text-on-surface-variant">Carregando…</p>
            ) : pedidos.length === 0 ? (
                <p className="text-sm text-on-surface-variant">
                    {aba === 'pendente' ? 'Nenhum pedido aguardando.' : 'Nenhum pedido decidido ainda.'}
                </p>
            ) : (
                <ul className="divide-y divide-outline-variant/30">
                    {pedidos.map((p) => (
                        <LinhaPedido key={p.id} pedido={p} onDecidido={(msg) => { setAviso(msg ?? ''); carregar(); }} />
                    ))}
                </ul>
            )}
        </section>
    );
}
