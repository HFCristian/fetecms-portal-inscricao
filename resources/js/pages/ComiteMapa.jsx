import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import MapaGoogle from '../components/MapaGoogle.jsx';
import { getMapaComite, getPontoComite } from '../lib/comite.js';

const INTERVALO_PADRAO = 5;

const hora = (iso) => (iso ? new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '—');

/** "12 min" / "1 h 05" a partir de segundos. */
function duracao(segundos) {
    if (segundos == null) return '—';
    const min = Math.round(segundos / 60);
    if (min < 60) return `${min} min`;
    return `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')}`;
}

/** "800 m" / "8,2 km" a partir de metros. */
function distancia(metros) {
    if (metros == null) return '—';
    return metros < 1000 ? `${metros} m` : `${(metros / 1000).toFixed(1).replace('.', ',')} km`;
}

/**
 * Comitê especial → Mapa do comitê.
 *
 * Em tempo real (leitura a cada 5 segundos, o mesmo intervalo em que os
 * aparelhos enviam a posição): todos os grupos com o localizador ligado. Clicar
 * num ponto abre quem está lá — **quantas pessoas, os nomes e as áreas** —, a
 * **distância aproximada** até o destino e o **tempo estimado de chegada**.
 */
export default function ComiteMapa() {
    const [pontos, setPontos] = useState(null);
    const [selecionado, setSelecionado] = useState(null);
    const [erro, setErro] = useState('');
    const intervaloRef = useRef(INTERVALO_PADRAO);

    // Polling do mapa. `selecionado` é recarregado junto, para o painel lateral
    // acompanhar o ponto enquanto ele se move.
    useEffect(() => {
        let cancelado = false;

        const buscar = () => getMapaComite()
            .then((r) => {
                if (cancelado) return;
                setPontos(r.data ?? []);
                intervaloRef.current = r.meta?.intervalo_segundos ?? INTERVALO_PADRAO;
                setErro('');
            })
            .catch(() => { if (!cancelado) setErro('Não foi possível atualizar o mapa.'); });

        buscar();
        const id = setInterval(buscar, INTERVALO_PADRAO * 1000);
        return () => { cancelado = true; clearInterval(id); };
    }, []);

    const abrir = useCallback((id) => {
        getPontoComite(id)
            .then(setSelecionado)
            .catch(() => setErro('Este localizador não está mais ligado.'));
    }, []);

    // Mantém o detalhe em dia enquanto o ponto continua no ar.
    useEffect(() => {
        if (!selecionado) return undefined;
        const id = setInterval(() => {
            getPontoComite(selecionado.id).then(setSelecionado).catch(() => setSelecionado(null));
        }, INTERVALO_PADRAO * 1000);
        return () => clearInterval(id);
    }, [selecionado?.id]);

    const marcadores = (pontos ?? [])
        .filter((p) => p.posicao)
        .map((p) => ({
            id: p.id,
            lat: p.posicao.lat,
            lng: p.posicao.lng,
            titulo: `${p.responsavel} · ${p.pessoas} ${p.pessoas === 1 ? 'pessoa' : 'pessoas'}`,
            rotulo: p.pessoas,
        }));

    const semPosicao = (pontos ?? []).filter((p) => !p.posicao).length;

    return (
        <AppShell>
            <Link to="/admin/comite" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Comitê especial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Mapa do comitê</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Todos os grupos com o localizador ligado, atualizados a cada {INTERVALO_PADRAO} segundos.
                Clique num ponto para ver quem está lá e quanto falta para chegar.
            </p>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2">
                    <MapaGoogle marcadores={marcadores} onSelecionar={abrir} altura={460} />
                    {pontos !== null && marcadores.length === 0 && (
                        <p className="text-sm text-on-surface-variant mt-3">
                            Nenhum grupo com posição no mapa agora.
                            {semPosicao > 0 && ` ${semPosicao} localizador(es) ligado(s) ainda sem posição.`}
                        </p>
                    )}
                </div>

                {/* Detalhe do ponto selecionado */}
                <aside className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                    {!selecionado ? (
                        <p className="text-sm text-on-surface-variant">
                            Selecione um ponto no mapa para ver o grupo.
                        </p>
                    ) : (
                        <>
                            <div className="flex items-start justify-between gap-2 mb-2">
                                <h2 className="font-display text-primary font-semibold">{selecionado.responsavel}</h2>
                                <Button type="button" variant="outline" onClick={() => setSelecionado(null)}>Fechar</Button>
                            </div>

                            <p className="text-sm text-on-surface mb-1">
                                <strong>{selecionado.pessoas}</strong>{' '}
                                {selecionado.pessoas === 1 ? 'pessoa' : 'pessoas'} · {selecionado.transporte_label}
                            </p>

                            {(selecionado.acompanhantes ?? []).length > 0 && (
                                <ul className="text-sm text-on-surface-variant list-disc pl-5 mb-3">
                                    {selecionado.acompanhantes.map((p, i) => (
                                        <li key={`${p.nome ?? 'sem-nome'}-${i}`}>
                                            {p.nome ?? 'Sem nome'}
                                            {p.area ? ` — ${p.area}` : ''}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <dl className="space-y-2 text-sm">
                                <div>
                                    <dt className="text-xs text-on-surface-variant">Destino</dt>
                                    <dd className="text-on-surface">{selecionado.destino?.nome ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-on-surface-variant">Distância aproximada</dt>
                                    <dd className="text-on-surface">{distancia(selecionado.distancia_m)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-on-surface-variant">Tempo até o destino</dt>
                                    <dd className="text-on-surface">{duracao(selecionado.duracao_s)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-on-surface-variant">Chegada prevista</dt>
                                    <dd className="text-on-surface">{hora(selecionado.chegada_em)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-on-surface-variant">Localizador desliga às</dt>
                                    <dd className="text-on-surface">{hora(selecionado.expira_em)}</dd>
                                </div>
                            </dl>
                        </>
                    )}
                </aside>
            </div>
        </AppShell>
    );
}
