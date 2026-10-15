/**
 * A câmera dos balcões (credenciamento e cerimonial): o que o navegador
 * permite e o motor que decifra o crachá.
 *
 * O crachá traz um **QR Code** e um **Code 128**, e os dois são lidos pelo
 * mesmo detector. Ele é a `BarcodeDetector` do próprio navegador quando ela
 * existe **e** lê os dois formatos — Chrome no Android e no macOS. Nos demais
 * (Safari no iPhone, onde nenhum navegador tem a API; Chrome no Windows e no
 * Linux, que a expõem sem motor por trás) entra o `barcode-detector`, a mesma
 * API escrita sobre o ZXing em WebAssembly.
 *
 * O `.wasm` (~1 MB) é servido pelo **próprio portal**, como os demais assets do
 * build, e não pelo CDN que a biblioteca usa por padrão: o balcão não pode
 * depender de um terceiro no dia do evento. E ele só é baixado quando alguém
 * abre a câmera num navegador que precisa dele.
 */

const FORMATOS = ['qr_code', 'code_128'];

let detector = null;

/**
 * O navegador deixa abrir a câmera aqui?
 *
 * A câmera só existe em **contexto seguro** (https ou localhost): fora dele o
 * `navigator.mediaDevices` some, e o motivo merece ser dito, porque não é o
 * aparelho que está errado.
 *
 * @returns {{ ok: boolean, motivo?: string }}
 */
export function suporteCamera() {
    if (typeof window === 'undefined' || typeof navigator === 'undefined') {
        return { ok: false, motivo: 'Este navegador não dá acesso à câmera.' };
    }

    if (window.isSecureContext === false) {
        return { ok: false, motivo: 'A câmera só abre com o portal em conexão segura (endereço com https).' };
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        return { ok: false, motivo: 'Este navegador não dá acesso à câmera.' };
    }

    return { ok: true };
}

/** O detector de códigos, montado uma vez por página e reaproveitado. */
export function criarDetector() {
    detector ??= montarDetector().catch((e) => {
        // Falhou (rede caiu no meio do .wasm, por exemplo): a próxima abertura
        // da câmera tenta de novo, em vez de herdar o erro.
        detector = null;
        throw e;
    });

    return detector;
}

async function montarDetector() {
    const Nativo = typeof window !== 'undefined' ? window.BarcodeDetector : undefined;

    if (Nativo) {
        try {
            const formatos = await Nativo.getSupportedFormats();
            if (FORMATOS.every((f) => formatos.includes(f))) {
                return new Nativo({ formats: FORMATOS });
            }
        } catch {
            // A API existe sem motor por trás: segue para o ZXing.
        }
    }

    const [{ BarcodeDetector, prepareZXingModule }, { default: wasm }] = await Promise.all([
        import('barcode-detector/ponyfill'),
        import('zxing-wasm/reader/zxing_reader.wasm?url'),
    ]);

    // `fireImmediately` compila o .wasm agora: se ele não carregar, quem abriu
    // a câmera fica sabendo na hora, e não com uma câmera que nunca lê nada.
    await prepareZXingModule({
        overrides: {
            locateFile: (caminho, prefixo) => (caminho.endsWith('.wasm') ? wasm : prefixo + caminho),
        },
        fireImmediately: true,
    });

    return new BarcodeDetector({ formats: FORMATOS });
}

/** O motivo de a câmera não abrir, em palavras de quem está no balcão. */
export function mensagemErroCamera(erro) {
    switch (erro?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'A permissão da câmera foi negada. Libere a câmera para este site nas configurações do navegador (no iPhone: Ajustes › Safari › Câmera) e tente de novo.';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return 'Nenhuma câmera foi encontrada neste aparelho.';
        case 'NotReadableError':
        case 'AbortError':
            return 'A câmera está em uso por outro aplicativo. Feche-o e tente de novo.';
        default:
            return 'Não foi possível abrir a câmera. Use o leitor ou digite o código.';
    }
}
