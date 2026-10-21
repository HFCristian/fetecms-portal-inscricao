import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getOpcoesListaPresencial = vi.fn();
const gerarListaPresencial = vi.fn();

vi.mock('../lib/auth.jsx', () => ({ extractErrors: (e) => ({ message: e?.message ?? '', fields: {} }) }));
vi.mock('../lib/listasPresenciais.js', () => ({
    getOpcoesListaPresencial: (...a) => getOpcoesListaPresencial(...a),
    gerarListaPresencial: (...a) => gerarListaPresencial(...a),
}));

import ListaPresencialDialog from './ListaPresencialDialog.jsx';

const AREAS = [
    { id: 1, nome: 'Ciências Agrárias', sigla: 'AGR', disponiveis: 4 },
    { id: 2, nome: 'Engenharias', sigla: 'ENG', disponiveis: 3 },
];

const OPCOES = {
    lista_final: { id: 9, nome: 'Lista final' },
    total_disponivel: 12,
    categorias: [
        { value: 'fetecms', label: 'FETECMS', sigla: 'FET', disponiveis: 7, areas: AREAS },
        { value: 'fetec_jr', label: 'FETEC Jr', sigla: 'JR', disponiveis: 5, areas: AREAS },
    ],
};

describe('ListaPresencialDialog — cotas por categoria e área', () => {
    beforeEach(() => {
        getOpcoesListaPresencial.mockResolvedValue(OPCOES);
        gerarListaPresencial.mockReset();
        gerarListaPresencial.mockResolvedValue({ lista: { id: 31 } });
    });

    it('não tem passo do interior', async () => {
        render(<ListaPresencialDialog open onClose={vi.fn()} />);
        expect(await screen.findByText('Quantos projetos em cada categoria?')).toBeInTheDocument();
        expect(screen.queryByText(/Interior/)).not.toBeInTheDocument();
    });

    it('“mesma quantidade em todas as áreas” monta o pódio de cada grupo', async () => {
        const onClose = vi.fn();
        render(<ListaPresencialDialog open teste onClose={onClose} />);

        await screen.findByText('Quantos projetos em cada categoria?');
        expect(getOpcoesListaPresencial).toHaveBeenCalledWith(true);
        fireEvent.change(screen.getByLabelText('Quantidade para FETEC Jr'), { target: { value: '0' } });
        fireEvent.click(screen.getByText('Continuar'));

        // A FETEC Jr zerada não pede áreas.
        const todas = await screen.findAllByLabelText('Quantidade para Mesma quantidade em todas as áreas');
        expect(todas).toHaveLength(1);
        fireEvent.change(todas[0], { target: { value: '3' } });
        expect(screen.getByLabelText('Quantidade para Engenharias')).toHaveValue(3);

        fireEvent.click(screen.getByLabelText(/Lista final/));
        fireEvent.change(screen.getByLabelText('Apelido da lista'), { target: { value: 'Pódio' } });
        fireEvent.click(screen.getByText('Montar rascunho'));

        await waitFor(() => expect(onClose).toHaveBeenCalledWith(31));
        const [payload, teste] = gerarListaPresencial.mock.calls[0];
        expect(teste).toBe(true);
        expect(payload).toMatchObject({
            tipo: 'final',
            nome: 'Pódio',
            total: null,
            categorias: {
                fetecms: { cota: null, areas: { 1: { cota: { tipo: 'fixo', valor: 3 } }, 2: { cota: { tipo: 'fixo', valor: 3 } } } },
                fetec_jr: { cota: { tipo: 'fixo', valor: 0 }, areas: {} },
            },
        });
    });

    it('o erro do servidor fica no diálogo', async () => {
        gerarListaPresencial.mockRejectedValue(new Error('A lista final ainda não foi gerada.'));
        render(<ListaPresencialDialog open onClose={vi.fn()} />);
        await screen.findByText('Quantos projetos em cada categoria?');
        fireEvent.click(screen.getByText('Continuar'));
        fireEvent.click(await screen.findByText('Montar rascunho'));

        expect(await screen.findByText('A lista final ainda não foi gerada.')).toBeInTheDocument();
    });
});
