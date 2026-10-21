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
    lugares_padrao: 4,
    categorias: [
        { value: 'fetecms', label: 'FETECMS', sigla: 'FET', disponiveis: 7, areas: AREAS },
        { value: 'fetec_jr', label: 'FETEC Jr', sigla: 'JR', disponiveis: 5, areas: AREAS },
    ],
};

describe('ListaPresencialDialog — até o Nº lugar por área (Sprint 255)', () => {
    beforeEach(() => {
        getOpcoesListaPresencial.mockResolvedValue(OPCOES);
        gerarListaPresencial.mockReset();
        gerarListaPresencial.mockResolvedValue({ lista: { id: 31 } });
    });

    it('abre com do 1º ao 4º lugar preenchido em cada área de cada categoria', async () => {
        render(<ListaPresencialDialog open onClose={vi.fn()} />);

        expect(await screen.findByText(/1º ao 4º lugar/)).toBeInTheDocument();
        const campos = screen.getAllByLabelText('Até que lugar em Ciências Agrárias');
        expect(campos).toHaveLength(2);
        campos.forEach((c) => expect(c).toHaveValue(4));
        expect(screen.queryByText(/Interior/)).not.toBeInTheDocument();
    });

    it('sem mexer, manda o padrão explícito para todas as áreas', async () => {
        const onClose = vi.fn();
        render(<ListaPresencialDialog open teste onClose={onClose} />);
        await screen.findAllByLabelText('Até que lugar em Engenharias');
        expect(getOpcoesListaPresencial).toHaveBeenCalledWith(true);

        fireEvent.click(screen.getByText('Continuar'));
        fireEvent.click(screen.getByText('Montar rascunho'));

        await waitFor(() => expect(onClose).toHaveBeenCalledWith(31));
        const [payload, teste] = gerarListaPresencial.mock.calls[0];
        expect(teste).toBe(true);
        expect(payload).toMatchObject({
            tipo: 'preliminar',
            total: null,
            categorias: {
                fetecms: { cota: null, areas: { 1: { lugares: 4 }, 2: { lugares: 4 } } },
                fetec_jr: { cota: null, areas: { 1: { lugares: 4 }, 2: { lugares: 4 } } },
            },
        });
    });

    it('edita área a área ou a categoria inteira; em branco não limita', async () => {
        render(<ListaPresencialDialog open onClose={vi.fn()} />);
        await screen.findAllByLabelText('Até que lugar em Engenharias');

        // A FETEC Jr inteira até o 3º; na FETECMS, Agrárias sem limite e Engenharias de fora.
        fireEvent.change(screen.getByLabelText('Até que lugar em Todas as áreas da FETEC Jr'), { target: { value: '3' } });
        expect(screen.getAllByLabelText('Até que lugar em Engenharias')[1]).toHaveValue(3);
        fireEvent.change(screen.getAllByLabelText('Até que lugar em Ciências Agrárias')[0], { target: { value: '' } });
        fireEvent.change(screen.getAllByLabelText('Até que lugar em Engenharias')[0], { target: { value: '0' } });

        fireEvent.click(screen.getByText('Continuar'));
        // Passo 2: teto opcional da categoria.
        fireEvent.change(screen.getByLabelText('Quantidade para FETECMS'), { target: { value: '10' } });
        fireEvent.click(screen.getByLabelText(/Lista final/));
        fireEvent.change(screen.getByLabelText('Apelido da lista'), { target: { value: 'Pódio' } });
        fireEvent.click(screen.getByText('Montar rascunho'));

        await waitFor(() => expect(gerarListaPresencial).toHaveBeenCalled());
        expect(gerarListaPresencial.mock.calls[0][0]).toMatchObject({
            tipo: 'final',
            nome: 'Pódio',
            categorias: {
                fetecms: { cota: { tipo: 'fixo', valor: 10 }, areas: { 1: { lugares: null }, 2: { lugares: 0 } } },
                fetec_jr: { cota: null, areas: { 1: { lugares: 3 }, 2: { lugares: 3 } } },
            },
        });
    });

    it('o erro do servidor fica no diálogo', async () => {
        gerarListaPresencial.mockRejectedValue(new Error('A lista final ainda não foi gerada.'));
        render(<ListaPresencialDialog open onClose={vi.fn()} />);
        await screen.findAllByLabelText('Até que lugar em Engenharias');
        fireEvent.click(screen.getByText('Continuar'));
        fireEvent.click(await screen.findByText('Montar rascunho'));

        expect(await screen.findByText('A lista final ainda não foi gerada.')).toBeInTheDocument();
    });
});
