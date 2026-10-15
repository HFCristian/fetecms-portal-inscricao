import { useEffect, useRef, useState } from 'react';
import { criarDetector, mensagemErroCamera } from '../lib/leitorCamera.js';

/** De quanto em quanto tempo um quadro da câmera é examinado. */
const INTERVALO_MS = 250;

/**
 * Por quanto tempo o mesmo código é ignorado. O crachá parado na frente da
 * câmera é lido de novo a cada quadro, e conta **uma vez**: o relógio só volta
 * a correr quando ele sai de cena.
 */
export const REPETICAO_MS = 4000;

/**
 * A câmera aberta, procurando QR Code e código de barras.
 *
 * Existe montada **só enquanto a câmera está aberta**: o fluxo inteiro mora no
 * efeito de montagem, que roda depois de o `<video>` estar na página. É isso
 * que prende o stream ao elemento certo — antes, ele era ligado num
 * `setTimeout` que podia correr antes de o React desenhar o vídeo, e a tela
 * ficava com um quadro em branco. Desmontar desliga a câmera.
 *
 * `onLer` pode ser assíncrono: enquanto ele não termina, nenhum quadro novo é
 * examinado — duas leituras do mesmo crachá não disputam o servidor.
 */
export default function LeitorCamera({ onLer, onErro }) {
    const video = useRef(null);
    const [pronta, setPronta] = useState(false);
    const onLerRef = useRef(onLer);
    const onErroRef = useRef(onErro);

    useEffect(() => {
        onLerRef.current = onLer;
        onErroRef.current = onErro;
    });

    useEffect(() => {
        let vivo = true;
        let midia = null;
        let timer = null;
        const ultimo = { valor: null, em: 0 };

        const desligar = () => midia?.getTracks?.().forEach((t) => t.stop());

        async function procurar(detector, el) {
            if (!vivo) return;

            // Sem quadro ainda (a câmera está acordando): espera o próximo.
            if (el.readyState >= 2) {
                try {
                    const codigos = await detector.detect(el);
                    const valor = codigos[0]?.rawValue?.trim();
                    const agora = Date.now();

                    if (vivo && valor) {
                        const novo = valor !== ultimo.valor || agora - ultimo.em > REPETICAO_MS;
                        ultimo.valor = valor;
                        ultimo.em = agora;
                        if (novo) await onLerRef.current?.(valor);
                    }
                } catch {
                    // Quadro ruim (foco, luz): tenta o próximo, sem alarmar ninguém.
                }
            }

            if (vivo) timer = setTimeout(() => procurar(detector, el), INTERVALO_MS);
        }

        async function iniciar() {
            const el = video.current;

            try {
                midia = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });
            } catch (e) {
                if (vivo) onErroRef.current?.(mensagemErroCamera(e));
                return;
            }

            if (!vivo || !el) {
                desligar();
                return;
            }

            // O iPhone só toca o vídeo sem toque na tela se ele estiver mudo, e
            // o React não escreve o `muted` no elemento — vai na propriedade.
            el.muted = true;
            el.srcObject = midia;

            try {
                await el.play();
            } catch {
                // O `autoPlay` segura o caso em que o play() é recusado.
            }

            let detector;
            try {
                detector = await criarDetector();
            } catch {
                if (vivo) {
                    desligar();
                    onErroRef.current?.('Não foi possível carregar o leitor de códigos. Confira a conexão e tente de novo, ou use o leitor USB.');
                }
                return;
            }

            if (!vivo) return;
            setPronta(true);
            procurar(detector, el);
        }

        iniciar();

        return () => {
            vivo = false;
            clearTimeout(timer);
            desligar();
        };
    }, []);

    return (
        <div className="mt-3 w-full max-w-sm">
            <div className="relative aspect-[4/3] rounded-lg overflow-hidden bg-black border border-outline-variant">
                <video
                    ref={video}
                    muted
                    autoPlay
                    playsInline
                    aria-label="Câmera para ler o QR Code"
                    className="w-full h-full object-cover"
                />
                {pronta ? (
                    <div className="absolute inset-[18%] rounded-lg border-2 border-white/80 pointer-events-none" aria-hidden="true" />
                ) : (
                    <div className="absolute inset-0 flex items-center justify-center gap-2 text-sm text-white">
                        <span className="material-symbols-outlined animate-spin text-[18px]" aria-hidden="true">progress_activity</span>
                        Abrindo a câmera…
                    </div>
                )}
            </div>
            <p className="text-xs text-on-surface-variant mt-1">
                Aponte para o QR Code ou para o código de barras do crachá.
            </p>
        </div>
    );
}
