import { render, screen, waitFor, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const detect = vi.fn();
const criarDetector = vi.fn();
vi.mock('../lib/leitorCamera.js', async (original) => ({
    ...(await original()),
    criarDetector: () => criarDetector(),
}));

import LeitorCamera from './LeitorCamera.jsx';

/** Uma câmera de mentira: o stream e as trilhas que precisam ser desligadas. */
function cameraFalsa() {
    const trilha = { stop: vi.fn() };
    const stream = { getTracks: () => [trilha] };
    return { trilha, stream };
}

describe('LeitorCamera', () => {
    let camera;

    beforeEach(() => {
        vi.clearAllMocks();
        camera = cameraFalsa();
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getUserMedia: vi.fn().mockResolvedValue(camera.stream) },
        });
        // O jsdom não toca vídeo: o play() e o "já tem quadro" são de mentira.
        vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
        Object.defineProperty(HTMLMediaElement.prototype, 'readyState', { configurable: true, get: () => 4 });
        detect.mockResolvedValue([]);
        criarDetector.mockResolvedValue({ detect });
    });

    afterEach(() => {
        delete HTMLMediaElement.prototype.readyState;
    });

    it('liga o stream no vídeo que já está na tela, mudo e sem tela cheia (iPhone)', async () => {
        render(<LeitorCamera onLer={vi.fn()} />);

        const video = screen.getByLabelText('Câmera para ler o QR Code');
        await waitFor(() => expect(video.srcObject).toBe(camera.stream));
        expect(video.muted).toBe(true);
        expect(video).toHaveAttribute('playsinline');
        expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledWith({
            video: { facingMode: { ideal: 'environment' } },
            audio: false,
        });
        expect(HTMLMediaElement.prototype.play).toHaveBeenCalled();
    });

    it('mostra que está abrindo até o detector ficar pronto', async () => {
        let pronto;
        criarDetector.mockReturnValue(new Promise((r) => { pronto = r; }));
        render(<LeitorCamera onLer={vi.fn()} />);

        expect(screen.getByText('Abrindo a câmera…')).toBeInTheDocument();
        await act(async () => pronto({ detect }));
        await waitFor(() => expect(screen.queryByText('Abrindo a câmera…')).not.toBeInTheDocument());
    });

    it('entrega o código lido, e o mesmo crachá parado na frente conta uma vez', async () => {
        const onLer = vi.fn();
        detect.mockResolvedValue([{ rawValue: ' 2026-31-111-A45 ' }]);
        render(<LeitorCamera onLer={onLer} />);

        await waitFor(() => expect(onLer).toHaveBeenCalledWith('2026-31-111-A45'));
        // Vários quadros depois, o mesmo código continua na frente.
        await waitFor(() => expect(detect.mock.calls.length).toBeGreaterThan(2), { timeout: 2000 });
        expect(onLer).toHaveBeenCalledTimes(1);
    });

    it('um crachá diferente é lido na sequência', async () => {
        const onLer = vi.fn();
        detect
            .mockResolvedValueOnce([{ rawValue: '2026-31-111-A45' }])
            .mockResolvedValue([{ rawValue: '2026-31-222-A46' }]);
        render(<LeitorCamera onLer={onLer} />);

        await waitFor(() => expect(onLer).toHaveBeenCalledWith('2026-31-222-A46'));
        expect(onLer).toHaveBeenCalledTimes(2);
    });

    it('desligar a câmera desliga o aparelho', async () => {
        const { unmount } = render(<LeitorCamera onLer={vi.fn()} />);

        await waitFor(() => expect(criarDetector).toHaveBeenCalled());
        unmount();

        expect(camera.trilha.stop).toHaveBeenCalled();
    });

    it('permissão negada vira uma explicação, e não um quadro em branco', async () => {
        navigator.mediaDevices.getUserMedia.mockRejectedValue(Object.assign(new Error('x'), { name: 'NotAllowedError' }));
        const onErro = vi.fn();
        render(<LeitorCamera onLer={vi.fn()} onErro={onErro} />);

        await waitFor(() => expect(onErro).toHaveBeenCalledWith(expect.stringMatching(/permissão da câmera foi negada/)));
        expect(criarDetector).not.toHaveBeenCalled();
    });

    it('sem o leitor de códigos (o .wasm não carregou), a câmera é desligada e a tela avisa', async () => {
        criarDetector.mockRejectedValue(new Error('rede'));
        const onErro = vi.fn();
        render(<LeitorCamera onLer={vi.fn()} onErro={onErro} />);

        await waitFor(() => expect(onErro).toHaveBeenCalledWith(expect.stringMatching(/carregar o leitor de códigos/)));
        expect(camera.trilha.stop).toHaveBeenCalled();
    });
});
