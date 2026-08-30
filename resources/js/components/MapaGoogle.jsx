import { useEffect, useRef, useState } from 'react';
import { Alert } from './ui.jsx';
import { carregarMaps, motivoMapaIndisponivel } from '../lib/googleMaps.js';

// Campo Grande: onde o mapa abre enquanto não há nenhum ponto.
const CENTRO_PADRAO = { lat: -20.4697, lng: -54.6201 };

/**
 * Mapa do comitê (Google Maps).
 *
 * Desenha os pontos de quem está com o localizador ligado, o trajeto já
 * percorrido e — quando `rota` é informada — o caminho recomendado até o
 * destino, devolvendo a estimativa por `onRota({ distancia_m, duracao_s,
 * instrucao })`. É essa estimativa que vira a previsão de chegada e a
 * "próxima ação" na tela.
 *
 * Sem a chave do Google Maps o componente não quebra: mostra o motivo, e as
 * telas seguem funcionando sem a parte visual.
 */
export default function MapaGoogle({
    marcadores = [],
    trajeto = [],
    rota = null,
    onRota,
    onSelecionar,
    altura = 380,
}) {
    const divRef = useRef(null);
    const mapaRef = useRef(null);
    const marcadoresRef = useRef(new Map());
    const trajetoRef = useRef(null);
    const rendererRef = useRef(null);
    const [erro, setErro] = useState(null);

    // Carrega o script e cria o mapa uma vez.
    useEffect(() => {
        let cancelado = false;

        carregarMaps()
            .then((maps) => {
                if (cancelado || !divRef.current) return;
                mapaRef.current = new maps.Map(divRef.current, {
                    center: CENTRO_PADRAO,
                    zoom: 12,
                    mapTypeControl: false,
                    streetViewControl: false,
                });
                rendererRef.current = new maps.DirectionsRenderer({
                    map: mapaRef.current,
                    suppressMarkers: true,
                });
                setErro(null);
            })
            .catch((e) => { if (!cancelado) setErro(e); });

        return () => { cancelado = true; };
    }, []);

    // Marcadores: cria, move e remove conforme a lista muda.
    useEffect(() => {
        const maps = window.google?.maps;
        if (!maps || !mapaRef.current) return;

        const vistos = new Set();

        for (const ponto of marcadores) {
            if (ponto.lat == null || ponto.lng == null) continue;
            vistos.add(ponto.id);

            const posicao = { lat: Number(ponto.lat), lng: Number(ponto.lng) };
            const existente = marcadoresRef.current.get(ponto.id);

            if (existente) {
                existente.setPosition(posicao);
                existente.setTitle(ponto.titulo ?? '');
                continue;
            }

            const marcador = new maps.Marker({
                map: mapaRef.current,
                position: posicao,
                title: ponto.titulo ?? '',
                label: ponto.rotulo ? { text: String(ponto.rotulo), color: '#fff', fontSize: '11px' } : undefined,
            });

            if (onSelecionar) marcador.addListener('click', () => onSelecionar(ponto.id));
            marcadoresRef.current.set(ponto.id, marcador);
        }

        for (const [id, marcador] of marcadoresRef.current) {
            if (!vistos.has(id)) {
                marcador.setMap(null);
                marcadoresRef.current.delete(id);
            }
        }

        // Enquadra o que existe: um ponto centraliza, vários cabem na tela.
        const comPosicao = marcadores.filter((p) => p.lat != null && p.lng != null);
        if (comPosicao.length === 1) {
            mapaRef.current.setCenter({ lat: Number(comPosicao[0].lat), lng: Number(comPosicao[0].lng) });
        } else if (comPosicao.length > 1) {
            const limites = new maps.LatLngBounds();
            comPosicao.forEach((p) => limites.extend({ lat: Number(p.lat), lng: Number(p.lng) }));
            mapaRef.current.fitBounds(limites);
        }
    }, [marcadores, onSelecionar]);

    // Trajeto já percorrido.
    useEffect(() => {
        const maps = window.google?.maps;
        if (!maps || !mapaRef.current) return;

        trajetoRef.current?.setMap(null);

        if (trajeto.length > 1) {
            trajetoRef.current = new maps.Polyline({
                map: mapaRef.current,
                path: trajeto.map((p) => ({ lat: Number(p.lat), lng: Number(p.lng) })),
                strokeColor: '#43157A',
                strokeOpacity: 0.7,
                strokeWeight: 4,
            });
        }
    }, [trajeto]);

    // Rota recomendada até o destino + estimativa de chegada.
    useEffect(() => {
        const maps = window.google?.maps;
        if (!maps || !mapaRef.current || !rota?.origem || !rota?.destino) return;

        const servico = new maps.DirectionsService();

        servico.route(
            {
                origin: rota.origem,
                destination: rota.destino,
                travelMode: rota.modo ?? 'DRIVING',
            },
            (resultado, status) => {
                if (status !== 'OK' || !resultado?.routes?.length) return;

                rendererRef.current?.setDirections(resultado);

                const trecho = resultado.routes[0].legs?.[0];
                if (trecho && onRota) {
                    onRota({
                        distancia_m: trecho.distance?.value ?? null,
                        duracao_s: trecho.duration?.value ?? null,
                        // A primeira instrução é a "próxima ação" que a tela mostra.
                        instrucao: trecho.steps?.[0]?.instructions
                            ?.replace(/<[^>]*>/g, '') ?? null,
                    });
                }
            },
        );
        // `onRota` muda a cada render do pai; a rota só deve ser recalculada
        // quando a posição ou o destino mudam.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rota?.origem?.lat, rota?.origem?.lng, rota?.destino?.lat, rota?.destino?.lng, rota?.modo]);

    if (erro) {
        return <Alert>{motivoMapaIndisponivel(erro)}</Alert>;
    }

    return (
        <div
            ref={divRef}
            role="application"
            aria-label="Mapa do comitê"
            className="w-full rounded-xl overflow-hidden bg-surface-variant"
            style={{ height: altura }}
        />
    );
}
