import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? '',
        fields: e?.response?.data?.errors
            ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]))
            : {},
    }),
}));

const checkinPorCodigo = vi.fn();
vi.mock('../lib/cerimonial.js', () => ({
    checkinPorCodigo: (...a) => checkinPorCodigo(...a),
}));

const suporteCamera = vi.fn();
vi.mock('../lib/leitorCamera.js', () => ({ suporteCamera: () => suporteCamera() }));
// A câmera tem teste próprio; aqui ela é só um botão que "lê" um código.
vi.mock('./LeitorCamera.jsx', () => ({
    default: ({ onLer }) => (
        <button type="button" onClick={() => onLer('2026-31-123-A46')}>camera-falsa</button>
    ),
}));

import LeitorCerimonial from './LeitorCerimonial.jsx';

const resposta = (over = {}) => ({
    projeto_id: 31,
    projeto_titulo: 'Bioplástico de mandioca',
    participante: { chave: 'A45', nome: 'Ana Paula', papel: 'A', papel_label: 'Aluno(a)' },
    ja_presente: false,
    checkin_em: '14:32',
    ...over,
});

function bipar(valor) {
    const campo = screen.getByLabelText('Código do crachá');
    fireEvent.change(campo, { target: { value: valor } });
    fireEvent.keyDown(campo, { key: 'Enter' });
}

describe('LeitorCerimonial', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        checkinPorCodigo.mockReset().mockResolvedValue(resposta());
        suporteCamera.mockReturnValue({ ok: true });
    });

    it('o leitor bipa e a entrada já fica registrada, com o nome na tela', async () => {
        const onRegistrado = vi.fn();
        render(<LeitorCerimonial onRegistrado={onRegistrado} />);

        const campo = screen.getByLabelText('Código do crachá');
        expect(campo).toHaveFocus();
        bipar('2026-31-123-A45');

        await waitFor(() => expect(checkinPorCodigo).toHaveBeenCalledWith('2026-31-123-A45', false));
        expect(await screen.findByText('Ana Paula')).toBeInTheDocument();
        expect(screen.getByText('Check-in feito')).toBeInTheDocument();
        expect(onRegistrado).toHaveBeenCalledWith(resposta());
        // Pronto para a próxima bipada.
        expect(campo).toHaveValue('');
        expect(campo).toHaveFocus();
    });

    it('o card some em 2 segundos', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        try {
            render(<LeitorCerimonial />);
            bipar('2026-31-123-A45');

            expect(await screen.findByText('Ana Paula')).toBeInTheDocument();
            // O card nasceu fora de um act: deixa o efeito dele armar o relógio.
            await act(async () => {});
            act(() => vi.advanceTimersByTime(2000));
            expect(screen.queryByText('Ana Paula')).not.toBeInTheDocument();
        } finally {
            vi.useRealTimers();
        }
    });

    it('quem já tinha entrado aparece com a hora da entrada', async () => {
        checkinPorCodigo.mockResolvedValue(resposta({ ja_presente: true }));
        render(<LeitorCerimonial />);

        bipar('2026-31-123-A45');

        expect(await screen.findByText('Já tinha feito check-in')).toBeInTheDocument();
        expect(screen.getByText('Entrou às 14:32.')).toBeInTheDocument();
    });

    it('duas bipadas seguidas são atendidas em ordem, sem perder nenhuma', async () => {
        let primeira;
        checkinPorCodigo
            .mockReturnValueOnce(new Promise((r) => { primeira = r; }))
            .mockResolvedValueOnce(resposta({
                participante: { chave: 'A46', nome: 'Bruno Lima', papel: 'A', papel_label: 'Aluno(a)' },
            }));
        render(<LeitorCerimonial />);

        bipar('2026-31-123-A45');
        bipar('2026-31-123-A46');
        await waitFor(() => expect(checkinPorCodigo).toHaveBeenCalledTimes(1));
        // A segunda espera a primeira terminar.
        await act(async () => {});
        expect(checkinPorCodigo).toHaveBeenCalledTimes(1);

        await act(async () => primeira(resposta()));

        await waitFor(() => expect(checkinPorCodigo).toHaveBeenCalledTimes(2));
        expect(checkinPorCodigo).toHaveBeenNthCalledWith(2, '2026-31-123-A46', false);
        expect(await screen.findByText('Bruno Lima')).toBeInTheDocument();
    });

    it('crachá recusado mostra o motivo e não some sozinho', async () => {
        checkinPorCodigo.mockRejectedValue({
            response: { data: { errors: { codigo: ['Este código não é de um projeto da lista final vigente.'] } } },
        });
        const onRegistrado = vi.fn();
        render(<LeitorCerimonial onRegistrado={onRegistrado} />);

        bipar('2026-99-111-A1');

        expect(await screen.findByRole('alert')).toHaveTextContent('Este código não é de um projeto da lista final vigente.');
        expect(onRegistrado).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Ok' }));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Código do crachá')).toHaveFocus();
    });

    it('leva o modo de teste junto', async () => {
        render(<LeitorCerimonial teste />);

        bipar('2026-31-123-A45');

        await waitFor(() => expect(checkinPorCodigo).toHaveBeenCalledWith('2026-31-123-A45', true));
    });

    it('campo vazio não chama o servidor', () => {
        render(<LeitorCerimonial />);

        fireEvent.click(screen.getByRole('button', { name: 'Registrar entrada' }));
        expect(checkinPorCodigo).not.toHaveBeenCalled();
    });

    it('a câmera registra e continua aberta para a próxima pessoa', async () => {
        render(<LeitorCerimonial />);

        fireEvent.click(screen.getByRole('button', { name: /Ler com a câmera/ }));
        fireEvent.click(screen.getByRole('button', { name: 'camera-falsa' }));

        await waitFor(() => expect(checkinPorCodigo).toHaveBeenCalledWith('2026-31-123-A46', false));
        expect(await screen.findByText('Ana Paula')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'camera-falsa' })).toBeInTheDocument();
    });

    it('sem câmera possível, o botão some e a tela diz por quê', () => {
        suporteCamera.mockReturnValue({ ok: false, motivo: 'A câmera só abre com o portal em conexão segura (endereço com https).' });
        render(<LeitorCerimonial />);

        expect(screen.queryByRole('button', { name: /Ler com a câmera/ })).not.toBeInTheDocument();
        expect(screen.getByText(/conexão segura/)).toBeInTheDocument();
    });
});
