import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input } from '../components/ui.jsx';
import LocalizadorWizard from '../components/LocalizadorWizard.jsx';
import MapaGoogle from '../components/MapaGoogle.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getMinhaLocalizacao, iniciarLocalizacao, prorrogarLocalizacao,
    encerrarLocalizacao, enviarPonto, getMapaComite,
} from '../lib/comite.js';

/** Intervalo de envio/leitura enquanto o servidor não responde (segundos). */
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
 * Comitê especial → Transporte de comitê.
 *
 * Lista quem está com o localizador ligado (e o que cada um configurou) e traz
 * o botão **Habilitar localização**. Com o localizador ligado, a tela vira o
 * acompanhamento do próprio trajeto: o ponto no mapa, o caminho recomendado até
 * o destino, a previsão de chegada e a próxima orientação.
 *
 * A posição vai para o servidor a cada 5 segundos, junto da estimativa que o
 * provedor de rotas calculou — é ela que alimenta o mapa de todo mundo.
 */
export default function ComiteTransporte() {
    const [dados, setDados] = useState(null);
    const [ativos, setAtivos] = useState([]);
    const [wizard, setWizard] = useState(false);
    const [erro, setErro] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [posicao, setPosicao] = useState(null);
    const [rota, setRota] = useState(null);
    const [minutos, setMinutos] = useState(60);

    // A última estimativa vai junto do próximo envio de posição.
    const estimativaRef = useRef(null);
    const intervaloRef = useRef(INTERVALO_PADRAO);

    const sessao = dados?.sessao ?? null;

    const carregar = useCallback(() => {
        getMinhaLocalizacao()
            .then((d) => {
                setDados(d);
                intervaloRef.current = d.intervalo_segundos ?? INTERVALO_PADRAO;
                if (d.sessao) setMinutos(Math.max(d.min_minutos ?? 5, d.sessao.minutos_restantes || 60));
            })
            .catch(() => setErro('Não foi possível carregar o localizador.'));
    }, []);

    useEffect(() => { carregar(); }, [carregar]);

    // Quem mais está a caminho agora (a lista da seção).
    useEffect(() => {
        let cancelado = false;
        const buscar = () => getMapaComite()
            .then((r) => { if (!cancelado) setAtivos(r.data ?? []); })
            .catch(() => {});

        buscar();
        const id = setInterval(buscar, (intervaloRef.current || 5) * 1000);
        return () => { cancelado = true; clearInterval(id); };
    }, [dados?.sessao?.id]);

    // Com o localizador ligado: acompanha a posição do aparelho e envia a cada
    // ciclo, junto da estimativa de rota mais recente.
    useEffect(() => {
        if (!sessao?.ativa || !navigator.geolocation) return undefined;

        let cancelado = false;

        const observador = navigator.geolocation.watchPosition(
            (p) => {
                if (cancelado) return;
                setPosicao({
                    lat: p.coords.latitude,
                    lng: p.coords.longitude,
                    precisao_m: Math.round(p.coords.accuracy ?? 0),
                });
            },
            () => setErro('Não foi possível ler a localização do aparelho.'),
            { enableHighAccuracy: true, maximumAge: 5000 },
        );

        return () => { cancelado = true; navigator.geolocation.clearWatch(observador); };
    }, [sessao?.ativa]);

    // Envio periódico: a posição atual + a estimativa da rota.
    useEffect(() => {
        if (!sessao?.ativa) return undefined;

        const id = setInterval(() => {
            if (!posicao) return;
            enviarPonto({
                latitude: posicao.lat,
                longitude: posicao.lng,
                precisao_m: posicao.precisao_m,
                distancia_m: estimativaRef.current?.distancia_m ?? null,
                duracao_s: estimativaRef.current?.duracao_s ?? null,
            }).catch(() => {});
        }, (intervaloRef.current || 5) * 1000);

        return () => clearInterval(id);
    }, [sessao?.ativa, posicao]);

    async function iniciar(payload, primeiraPosicao) {
        setSalvando(true);
        setErro('');
        try {
            const nova = await iniciarLocalizacao(payload);
            setDados((d) => ({ ...d, sessao: nova }));
            if (primeiraPosicao) setPosicao({ ...primeiraPosicao, precisao_m: null });
            setWizard(false);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível iniciar o localizador.');
        } finally {
            setSalvando(false);
        }
    }

    async function ajustarTempo() {
        try {
            const nova = await prorrogarLocalizacao(Number(minutos));
            setDados((d) => ({ ...d, sessao: nova }));
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível ajustar o tempo.');
        }
    }

    async function desligar() {
        try {
            await encerrarLocalizacao();
            setDados((d) => ({ ...d, sessao: null }));
            setPosicao(null);
            setRota(null);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível desligar o localizador.');
        }
    }

    const destino = sessao?.destino;
    const temDestinoGeo = destino?.lat != null && destino?.lng != null;

    return (
        <AppShell>
            <Link to="/admin/comite" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Comitê especial
            </Link>

            <div className="flex items-start justify-between gap-3 flex-wrap mb-6 max-w-3xl">
                <div className="min-w-0">
                    <h1 className="font-display text-2xl font-semibold text-primary mb-1">Transporte de comitê</h1>
                    <p className="text-sm text-on-surface-variant">
                        Quem está com o localizador ligado agora e o que cada um configurou.
                    </p>
                </div>
                {!sessao && (
                    <Button type="button" className="shrink-0" onClick={() => { setErro(''); setWizard(true); }}>
                        <span className="material-symbols-outlined text-[18px]">my_location</span>
                        Habilitar localização
                    </Button>
                )}
            </div>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            {/* Meu trajeto */}
            {sessao && (
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-6 max-w-3xl">
                    <h2 className="font-display text-primary font-semibold mb-1">Seu trajeto</h2>
                    <p className="text-sm text-on-surface-variant mb-3">
                        Destino: <strong>{destino?.nome}</strong> · {sessao.pessoas}{' '}
                        {sessao.pessoas === 1 ? 'pessoa' : 'pessoas'} · {sessao.transporte_label}
                    </p>

                    <MapaGoogle
                        marcadores={[
                            ...(posicao ? [{ id: 'eu', lat: posicao.lat, lng: posicao.lng, titulo: 'Você' }] : []),
                            ...(temDestinoGeo ? [{ id: 'destino', lat: destino.lat, lng: destino.lng, titulo: destino.nome }] : []),
                        ]}
                        trajeto={sessao.trajeto ?? []}
                        rota={posicao && temDestinoGeo ? {
                            origem: { lat: posicao.lat, lng: posicao.lng },
                            destino: { lat: destino.lat, lng: destino.lng },
                            modo: sessao.modo_rota,
                        } : null}
                        onRota={(r) => { estimativaRef.current = r; setRota(r); }}
                    />

                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                        <div>
                            <p className="text-xs text-on-surface-variant">Distância</p>
                            <p className="text-lg font-semibold text-on-surface">
                                {distancia(rota?.distancia_m ?? sessao.distancia_m)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-on-surface-variant">Tempo restante</p>
                            <p className="text-lg font-semibold text-on-surface">
                                {duracao(rota?.duracao_s ?? sessao.duracao_s)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-on-surface-variant">Chegada prevista</p>
                            <p className="text-lg font-semibold text-on-surface">{hora(sessao.chegada_em)}</p>
                        </div>
                        <div>
                            <p className="text-xs text-on-surface-variant">Localizador até</p>
                            <p className="text-lg font-semibold text-on-surface">{hora(sessao.expira_em)}</p>
                        </div>
                    </div>

                    {rota?.instrucao && (
                        <p className="mt-3 flex items-start gap-2 text-sm text-on-surface">
                            <span className="material-symbols-outlined text-primary-container text-[20px]">turn_right</span>
                            <span><strong>Próxima ação:</strong> {rota.instrucao}</span>
                        </p>
                    )}

                    <div className="flex flex-wrap items-end gap-3 mt-4">
                        <label className="text-sm">
                            <span className="block text-xs text-on-surface-variant mb-1">Manter ligado por (min)</span>
                            <Input
                                type="number"
                                min={dados?.min_minutos ?? 5}
                                max={dados?.max_minutos ?? 720}
                                value={minutos}
                                onChange={(e) => setMinutos(e.target.value)}
                                aria-label="Minutos com o localizador ligado"
                            />
                        </label>
                        <Button type="button" variant="outline" onClick={ajustarTempo}>Ajustar tempo</Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="text-error border-error/40 hover:bg-error-container/40"
                            onClick={desligar}
                        >
                            <span className="material-symbols-outlined text-[20px]">location_off</span>
                            Desligar localizador
                        </Button>
                    </div>
                </section>
            )}

            {/* Quem está a caminho */}
            <h2 className="font-display text-primary font-semibold mb-2">Localizadores ligados</h2>
            {ativos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm max-w-3xl">
                    Ninguém com o localizador ligado agora.
                </div>
            ) : (
                <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40 max-w-3xl">
                    {ativos.map((a) => (
                        <li key={a.id} className="p-4">
                            <p className="text-sm font-semibold text-on-surface">
                                {a.responsavel}
                                <span className="ml-2 text-xs font-normal text-on-surface-variant">
                                    {a.pessoas} {a.pessoas === 1 ? 'pessoa' : 'pessoas'} · {a.transporte_label}
                                </span>
                            </p>
                            <p className="text-xs text-on-surface-variant">
                                {a.origem?.nome ? `${a.origem.nome} → ` : ''}{a.destino?.nome}
                            </p>
                            <p className="text-xs text-on-surface-variant">
                                {distancia(a.distancia_m)} · {duracao(a.duracao_s)} · chegada {hora(a.chegada_em)} ·
                                {' '}desliga {hora(a.expira_em)}
                            </p>
                        </li>
                    ))}
                </ul>
            )}

            {wizard && (
                <LocalizadorWizard
                    transportes={dados?.transportes ?? []}
                    minMinutos={dados?.min_minutos}
                    maxMinutos={dados?.max_minutos}
                    salvando={salvando}
                    erro={erro}
                    onIniciar={iniciar}
                    onFechar={() => setWizard(false)}
                />
            )}
        </AppShell>
    );
}
