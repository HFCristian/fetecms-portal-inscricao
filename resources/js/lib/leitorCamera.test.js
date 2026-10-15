import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const prepareZXingModule = vi.fn();
class ZXingDetector {
    constructor(opcoes) { this.opcoes = opcoes; this.motor = 'zxing'; }
}
vi.mock('barcode-detector/ponyfill', () => ({
    BarcodeDetector: ZXingDetector,
    prepareZXingModule: (...a) => prepareZXingModule(...a),
}));
vi.mock('zxing-wasm/reader/zxing_reader.wasm?url', () => ({ default: '/build/assets/zxing_reader-abc.wasm' }));

describe('leitorCamera', () => {
    let lib;

    beforeEach(async () => {
        vi.resetModules();
        prepareZXingModule.mockReset().mockResolvedValue({});
        lib = await import('./leitorCamera.js');
    });

    afterEach(() => {
        delete window.BarcodeDetector;
        delete window.isSecureContext;
        delete navigator.mediaDevices;
    });

    // O jsdom não define `isSecureContext`; o navegador sempre define.
    const contextoSeguro = (valor) =>
        Object.defineProperty(window, 'isSecureContext', { configurable: true, value: valor });

    describe('suporteCamera', () => {
        it('fora de https a câmera não abre, e o motivo é a conexão', () => {
            contextoSeguro(false);

            expect(lib.suporteCamera()).toEqual({ ok: false, motivo: expect.stringMatching(/conexão segura/) });
        });

        it('sem getUserMedia não há câmera', () => {
            contextoSeguro(true);

            expect(lib.suporteCamera().ok).toBe(false);
        });

        it('com https e getUserMedia, abre — mesmo sem BarcodeDetector (iPhone)', () => {
            contextoSeguro(true);
            Object.defineProperty(navigator, 'mediaDevices', {
                configurable: true,
                value: { getUserMedia: vi.fn() },
            });

            expect(lib.suporteCamera()).toEqual({ ok: true });
        });
    });

    describe('criarDetector', () => {
        it('usa o detector do navegador quando ele lê QR e Code 128', async () => {
            window.BarcodeDetector = class {
                static getSupportedFormats() { return Promise.resolve(['qr_code', 'code_128', 'ean_13']); }
                constructor(opcoes) { this.opcoes = opcoes; this.motor = 'nativo'; }
            };

            const detector = await lib.criarDetector();

            expect(detector.motor).toBe('nativo');
            expect(detector.opcoes).toEqual({ formats: ['qr_code', 'code_128'] });
            expect(prepareZXingModule).not.toHaveBeenCalled();
        });

        it('API sem motor (Chrome no Windows/Linux) cai no ZXing', async () => {
            window.BarcodeDetector = class {
                static getSupportedFormats() { return Promise.resolve([]); }
            };

            expect((await lib.criarDetector()).motor).toBe('zxing');
        });

        it('sem a API (Safari no iPhone) usa o ZXing, com o .wasm servido pelo portal', async () => {
            const detector = await lib.criarDetector();

            expect(detector.motor).toBe('zxing');
            expect(detector.opcoes).toEqual({ formats: ['qr_code', 'code_128'] });

            const { overrides, fireImmediately } = prepareZXingModule.mock.calls[0][0];
            expect(fireImmediately).toBe(true);
            expect(overrides.locateFile('zxing_reader.wasm', 'https://cdn/')).toBe('/build/assets/zxing_reader-abc.wasm');
            expect(overrides.locateFile('outro.js', '/x/')).toBe('/x/outro.js');
        });

        it('monta uma vez e reaproveita; falhou, tenta de novo na próxima', async () => {
            prepareZXingModule.mockRejectedValueOnce(new Error('rede'));

            await expect(lib.criarDetector()).rejects.toThrow('rede');
            const primeiro = await lib.criarDetector();
            const segundo = await lib.criarDetector();

            expect(primeiro).toBe(segundo);
            expect(prepareZXingModule).toHaveBeenCalledTimes(2);
        });
    });

    it('cada erro da câmera tem a sua explicação', () => {
        expect(lib.mensagemErroCamera({ name: 'NotAllowedError' })).toMatch(/Ajustes › Safari › Câmera/);
        expect(lib.mensagemErroCamera({ name: 'NotFoundError' })).toMatch(/Nenhuma câmera/);
        expect(lib.mensagemErroCamera({ name: 'NotReadableError' })).toMatch(/em uso/);
        expect(lib.mensagemErroCamera(new Error('?'))).toMatch(/Não foi possível abrir a câmera/);
    });
});
