import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getEdicoes = vi.fn();
const trocarEdicao = vi.fn(() => Promise.resolve({}));
vi.mock('../lib/edicoes.js', () => ({
    getEdicoes: (...a) => getEdicoes(...a),
    trocarEdicao: (...a) => trocarEdicao(...a),
}));

import SeletorEdicao from './SeletorEdicao.jsx';

const DUAS = {
    atual_id: 1,
    edicoes: [
        { id: 2, nome: 'XVII FETECMS', ano: 2027, padrao: false },
        { id: 1, nome: 'XVI FETECMS', ano: 2026, padrao: true },
    ],
};

describe('SeletorEdicao', () => {
    beforeEach(() => {
        getEdicoes.mockReset();
        trocarEdicao.mockClear();
    });

    it('não aparece quando só existe uma edição', async () => {
        getEdicoes.mockResolvedValue({ atual_id: 1, edicoes: [{ id: 1, nome: 'XVI FETECMS', ano: 2026, padrao: true }] });
        render(<SeletorEdicao />);
        await waitFor(() => expect(getEdicoes).toHaveBeenCalled());
        expect(screen.queryByLabelText('Edição da feira')).not.toBeInTheDocument();
    });

    it('lista as edições, marca a padrão e troca a do usuário', async () => {
        getEdicoes.mockResolvedValue(DUAS);
        const onTrocar = vi.fn();
        render(<SeletorEdicao onTrocar={onTrocar} />);

        const select = await screen.findByLabelText('Edição da feira');
        expect(select.value).toBe('1');
        expect(screen.getByText('XVI FETECMS (2026) · padrão')).toBeInTheDocument();
        expect(screen.getByText('XVII FETECMS (2027)')).toBeInTheDocument();

        fireEvent.change(select, { target: { value: '2' } });

        await waitFor(() => expect(trocarEdicao).toHaveBeenCalledWith(2));
        expect(onTrocar).toHaveBeenCalled();
    });
});
