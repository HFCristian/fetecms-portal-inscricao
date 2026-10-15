import { useRef, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import LeitorCamera from './LeitorCamera.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { lerCodigoCerimonial } from '../lib/cerimonial.js';
import { suporteCamera } from '../lib/leitorCamera.js';

/**
 * O leitor de crachá da porta da cerimônia.
 *
 * São os dois equipamentos de sempre, pelas mesmas razões do balcão de
 * credenciamento:
 *
 * - **leitor USB**: comporta-se como teclado — digita e dá Enter. Por isso o
 *   campo é um input comum com foco automático: aponta, bipa, abre a ficha.
 *   Digitar à mão também funciona, que é o que salva a etiqueta amassada;
 * - **câmera**: {@link LeitorCamera} — a `BarcodeDetector` do navegador onde
 *   ela lê os dois formatos do crachá e o ZXing em WebAssembly no resto (iPhone
 *   incluído). Onde a câmera não pode abrir o botão **não aparece** e a tela diz
 *   por quê — melhor do que um botão que falha na hora do aperto.
 *
 * Quem valida é o servidor: formato, se o projeto está na lista que vale e se a
 * pessoa é mesmo daquela equipe. Lido o crachá, quem abre é a **ficha do
 * projeto** — na cerimônia a equipe chega junta, e marcar um a um obrigaria a
 * quatro leituras.
 */
export default function LeitorCerimonial({ teste = false, onAbrir }) {
    const [codigo, setCodigo] = useState('');
    const [lendo, setLendo] = useState(false);
    const [erro, setErro] = useState('');
    const [camera, setCamera] = useState(false);
    const campo = useRef(null);

    const suporte = suporteCamera();

    async function enviar(valor) {
        const limpo = (valor ?? '').trim();
        if (!limpo || lendo) return;

        setLendo(true);
        setErro('');
        try {
            const dados = await lerCodigoCerimonial(limpo, teste);
            setCodigo('');
            setCamera(false);
            onAbrir?.(dados);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(fields?.codigo || message || 'Não foi possível ler o código.');
            // O foco volta para o campo: o atendente bipa de novo sem clicar.
            campo.current?.focus();
            campo.current?.select();
        } finally {
            setLendo(false);
        }
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4">
            <div className="flex flex-wrap items-end gap-3">
                <div className="flex-1 min-w-[16rem]">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="cerimonial-codigo">
                        Ler o crachá
                    </label>
                    <input
                        id="cerimonial-codigo"
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

                {suporte.ok && !camera && (
                    <Button type="button" variant="outline" onClick={() => { setErro(''); setCamera(true); }}>
                        <span className="material-symbols-outlined text-[20px]">photo_camera</span>
                        Ler com a câmera
                    </Button>
                )}
                {camera && (
                    <Button type="button" variant="outline" onClick={() => setCamera(false)}>Fechar a câmera</Button>
                )}
            </div>

            {!suporte.ok && (
                <p className="text-xs text-on-surface-variant mt-2">
                    {suporte.motivo} Use o leitor de código de barras, digite o código da etiqueta
                    ou procure pelo nome abaixo.
                </p>
            )}

            {camera && (
                <LeitorCamera
                    onLer={enviar}
                    onErro={(mensagem) => { setErro(mensagem); setCamera(false); }}
                />
            )}

            {erro && <div className="mt-3"><Alert>{erro}</Alert></div>}
        </section>
    );
}
