import { useRef, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import LeitorCamera from './LeitorCamera.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { lerCodigoCredenciamento } from '../lib/credenciamento.js';
import { suporteCamera } from '../lib/leitorCamera.js';

/**
 * O leitor de crachá do balcão.
 *
 * São dois caminhos para a mesma coisa, porque o balcão tem dois equipamentos:
 *
 * - **leitor USB**: ele se comporta como um teclado — digita o código e dá
 *   Enter. Por isso o campo é um input comum, com foco automático: o atendente
 *   aponta, bipa e a ficha abre, sem tocar no mouse. Digitar à mão também
 *   funciona, que é o que salva a etiqueta amassada;
 * - **câmera**: {@link LeitorCamera}, que usa a `BarcodeDetector` do navegador
 *   onde ela lê os dois formatos do crachá e o ZXing em WebAssembly no resto
 *   (iPhone incluído). Onde a câmera não pode abrir — fora de https, por
 *   exemplo — o botão **não aparece** e a tela diz por quê: melhor do que um
 *   botão que falha na hora do aperto.
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

    const suporte = suporteCamera();

    async function enviar(valor) {
        const limpo = (valor ?? '').trim();
        if (!limpo || lendo) return;

        setLendo(true);
        setErro('');
        try {
            const dados = await lerCodigoCredenciamento(limpo, teste);
            setAchado(dados);
            setCodigo('');
            setCamera(false);
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

                {suporte.ok && !camera && (
                    <Button type="button" variant="outline" onClick={() => { setErro(''); setCamera(true); }}>
                        Ler com a câmera
                    </Button>
                )}
                {camera && (
                    <Button type="button" variant="outline" onClick={() => setCamera(false)}>Fechar a câmera</Button>
                )}
            </div>

            {!suporte.ok && (
                <p className="text-xs text-on-surface-variant mt-2">
                    {suporte.motivo} Use o leitor de código de barras ou digite o código da etiqueta.
                </p>
            )}

            {camera && (
                <LeitorCamera
                    onLer={enviar}
                    onErro={(mensagem) => { setErro(mensagem); setCamera(false); }}
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
