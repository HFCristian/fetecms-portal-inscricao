import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? '',
        fields: e?.response?.data?.errors
            ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]))
            : {},
    }),
}));

const lerCodigoCredenciamento = vi.fn();
vi.mock('../lib/credenciamento.js', () => ({
    lerCodigoCredenciamento: (...a) => lerCodigoCredenciamento(...a),
}));

const suporteCamera = vi.fn();
vi.mock('../lib/leitorCamera.js', () => ({ suporteCamera: () => suporteCamera() }));
// A câmera tem teste próprio; aqui ela é só um botão que "lê" um código.
vi.mock('./LeitorCamera.jsx', () => ({
    default: ({ onLer }) => (
        <button type="button" onClick={() => onLer('2026-31-111-A45')}>camera-falsa</button>
    ),
}));

import LeitorCodigo from './LeitorCodigo.jsx';

const achado = {
    codigo: '2026-31-111-A45',
    projeto: { id: 31, titulo: 'Bioplástico de mandioca', categoria: 'FETECMS', escola: 'EE Maria Constança' },
    participante: { nome: 'Zuleica Nunes', papel: 'A', papel_label: 'Aluno(a)' },
    credenciado: false,
};

describe('LeitorCodigo', () => {
    beforeEach(() => {
        lerCodigoCredenciamento.mockReset().mockResolvedValue(achado);
        suporteCamera.mockReset().mockReturnValue({ ok: true });
    });

    it('o leitor USB bipa e dá Enter: abre a ficha sem tocar no mouse', async () => {
        const onAbrir = vi.fn();
        render(<LeitorCodigo onAbrir={onAbrir} />);

        const campo = screen.getByLabelText('Código do crachá');
        // O campo já nasce com foco: o atendente aponta e bipa.
        expect(campo).toHaveFocus();

        fireEvent.change(campo, { target: { value: '2026-31-111-A45' } });
        fireEvent.keyDown(campo, { key: 'Enter' });

        await waitFor(() => expect(lerCodigoCredenciamento).toHaveBeenCalledWith('2026-31-111-A45', false));
        await waitFor(() => expect(onAbrir).toHaveBeenCalledWith(achado));
        expect(await screen.findByText('Zuleica Nunes')).toBeInTheDocument();
    });

    it('o botão faz o mesmo, para quem digitou à mão', async () => {
        render(<LeitorCodigo />);

        fireEvent.change(screen.getByLabelText('Código do crachá'), { target: { value: '2026-31-111-A45' } });
        fireEvent.click(screen.getByRole('button', { name: 'Abrir ficha' }));

        await waitFor(() => expect(lerCodigoCredenciamento).toHaveBeenCalled());
    });

    it('leva o modo de teste junto, para o ensaio não alcançar finalista de verdade', async () => {
        render(<LeitorCodigo teste />);

        fireEvent.change(screen.getByLabelText('Código do crachá'), { target: { value: '2026-31-111-A45' } });
        fireEvent.keyDown(screen.getByLabelText('Código do crachá'), { key: 'Enter' });

        await waitFor(() => expect(lerCodigoCredenciamento).toHaveBeenCalledWith('2026-31-111-A45', true));
    });

    it('mostra o motivo da recusa e devolve o foco para bipar de novo', async () => {
        const onAbrir = vi.fn();
        lerCodigoCredenciamento.mockRejectedValue({
            response: { data: { errors: { codigo: ['Este código não é de um projeto da lista final vigente.'] } } },
        });

        render(<LeitorCodigo onAbrir={onAbrir} />);
        const campo = screen.getByLabelText('Código do crachá');

        fireEvent.change(campo, { target: { value: '2026-99-111-A1' } });
        fireEvent.keyDown(campo, { key: 'Enter' });

        expect(await screen.findByText('Este código não é de um projeto da lista final vigente.')).toBeInTheDocument();
        expect(onAbrir).not.toHaveBeenCalled();
        expect(campo).toHaveFocus();
    });

    it('campo vazio não chama o servidor', () => {
        render(<LeitorCodigo />);

        fireEvent.click(screen.getByRole('button', { name: 'Abrir ficha' }));

        expect(lerCodigoCredenciamento).not.toHaveBeenCalled();
    });

    it('sem suporte a câmera, o botão some e a tela diz por quê', () => {
        suporteCamera.mockReturnValue({ ok: false, motivo: 'A câmera só abre com o portal em conexão segura (endereço com https).' });
        render(<LeitorCodigo />);

        expect(screen.queryByRole('button', { name: 'Ler com a câmera' })).not.toBeInTheDocument();
        expect(screen.getByText(/conexão segura/)).toBeInTheDocument();
    });

    it('a câmera lê, abre a ficha e se fecha', async () => {
        const onAbrir = vi.fn();
        render(<LeitorCodigo onAbrir={onAbrir} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ler com a câmera' }));
        fireEvent.click(screen.getByRole('button', { name: 'camera-falsa' }));

        await waitFor(() => expect(onAbrir).toHaveBeenCalledWith(achado));
        expect(lerCodigoCredenciamento).toHaveBeenCalledWith('2026-31-111-A45', false);
        expect(screen.queryByRole('button', { name: 'camera-falsa' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ler com a câmera' })).toBeInTheDocument();
    });
});
