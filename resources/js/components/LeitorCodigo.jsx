import { useEffect, useRef, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { lerCodigoCredenciamento } from '../lib/credenciamento.js';

/**
 * O leitor de crachá do balcão.
 *
 * São dois caminhos para a mesma coisa, porque o balcão tem dois equipamentos:
 *
 * - **leitor USB**: ele se comporta como um teclado — digita o código e dá
 *   Enter. Por isso o campo é um input comum, com foco automático: o atendente
 *   aponta, bipa e a ficha abre, sem tocar no mouse. Digitar à mão também
 *   funciona, que é o que salva a etiqueta amassada;
 * - **câmera**: usa a `BarcodeDetector` do próprio navegador, sem biblioteca
 *   nenhuma. Onde ela não existe (Safari, por exemplo), o botão **não aparece**
 *   e a tela diz para usar o leitor ou digitar — melhor do que um botão que
 *   falha na hora do aperto.
 *
 * Quem valida o código é o servidor: formato, se o projeto está na lista que
 * vale (a oficial ou a de demonstração) e se a pessoa daquele crachá é mesmo da
 * equipe. A tela só mostra o motivo quando ele recusa.
 */
export default function LeitorCodigo({ teste = false, onAbrir }) {
    const [codigo, setCodigo] = useState('');
    const [lendo, setLendo] = useState(false);
    const [erro, setErro] = useState('');
    const [achado, setAchado] = useState(null);
    const [camera, setCamera] = useState(false);
    const campo = useRef(null);
    const video = useRef(null);
    const stream = useRef(null);

    const temCamera = typeof window !== 'undefined' && 'BarcodeDetector' in window;

    useEffect(() => () => pararCamera(), []);

    function pararCamera() {
        stream.current?.getTracks?.().forEach((t) => t.stop());
        stream.current = null;
        setCamera(false);
    }

    async function enviar(valor) {
        const limpo = (valor ?? '').trim();
        if (!limpo || lendo) return;

        setLendo(true);
        setErro('');
        try {
            const dados = await lerCodigoCredenciamento(limpo, teste);
            setAchado(dados);
            setCodigo('');
            pararCamera();
            onAbrir?.(dados);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(fields?.codigo || message || 'Não foi possível ler o código.');
            setAchado(null);
            // O foco volta para o campo: o atendente bipa de novo sem clicar.
            campo.current?.focus();
            campo.current?.select();
        } finally {
            setLendo(false);
        }
    }

    async function abrirCamera() {
        setErro('');
        try {
            const midia = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
            });
            stream.current = midia;
            setCamera(true);

            // O elemento de vídeo só existe depois do render.
            setTimeout(() => {
                if (video.current) {
                    video.current.srcObject = midia;
                    video.current.play?.();
                    procurar();
                }
            }, 0);
        } catch {
            setErro('Não foi possível abrir a câmera. Use o leitor ou digite o código.');
        }
    }

    async function procurar() {
        // eslint-disable-next-line no-undef
        const detector = new window.BarcodeDetector({ formats: ['qr_code', 'code_128'] });

        const tentar = async () => {
            if (!stream.current || !video.current) return;

            try {
                const codigos = await detector.detect(video.current);
                if (codigos.length > 0) {
                    await enviar(codigos[0].rawValue);
                    return;
                }
            } catch {
                // Quadro ruim (foco, luz): tenta o próximo, sem alarmar ninguém.
            }

            setTimeout(tentar, 400);
        };

        tentar();
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4">
            <div className="flex flex-wrap items-end gap-3">
                <div className="flex-1 min-w-[16rem]">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="leitor-codigo">
                        Ler o crachá
                    </label>
                    <input
                        id="leitor-codigo"
                        ref={campo}
                        autoFocus
                        value={codigo}
                        onChange={(e) => setCodigo(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); enviar(codigo); } }}
                        placeholder="Bipe o código de barras ou digite (2026-31-123-A45)"
                        aria-label="Código do crachá"
                        className="w-full mt-1 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                    />
                </div>

                <Button type="button" loading={lendo} onClick={() => enviar(codigo)}>Abrir ficha</Button>

                {temCamera && !camera && (
                    <Button type="button" variant="outline" onClick={abrirCamera}>Ler com a câmera</Button>
                )}
                {camera && (
                    <Button type="button" variant="outline" onClick={pararCamera}>Fechar a câmera</Button>
                )}
            </div>

            {!temCamera && (
                <p className="text-xs text-on-surface-variant mt-2">
                    Este navegador não lê QR pela câmera — use o leitor de código de barras ou
                    digite o código da etiqueta.
                </p>
            )}

            {camera && (
                <video
                    ref={video}
                    muted
                    playsInline
                    aria-label="Câmera para ler o QR Code"
                    className="mt-3 w-full max-w-sm rounded-lg border border-outline-variant"
                />
            )}

            {erro && <div className="mt-3"><Alert>{erro}</Alert></div>}

            {achado && (
                <div className="mt-3">
                    <Alert type="info">
                        <strong>{achado.participante.nome}</strong> ({achado.participante.papel_label}) —{' '}
                        {achado.projeto.titulo}
                        {achado.credenciado && ' · este projeto já foi credenciado'}
                    </Alert>
                </div>
            )}
        </section>
    );
}
