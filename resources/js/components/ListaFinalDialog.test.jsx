import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getOpcoesListaFinal = vi.fn();
const baixarListaFinal = vi.fn();

vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/admin.js', () => ({
    getOpcoesListaFinal: (...a) => getOpcoesListaFinal(...a),
    baixarListaFinal: (...a) => baixarListaFinal(...a),
}));

import ListaFinalDialog from './ListaFinalDialog.jsx';

const AREAS = [
    { id: 1, nome: 'Ciências Agrárias', sigla: 'AGR', disponiveis: 12, interior_disponiveis: 7 },
    { id: 2, nome: 'Engenharias', sigla: 'ENG', disponiveis: 8, interior_disponiveis: 2 },
];

const OPCOES = {
    total_disponivel: 40,
    interior_disponivel: 18,
    categorias: [
        { value: 'fetecms', label: 'FETECMS', sigla: 'FET', disponiveis: 20, areas: AREAS },
        { value: 'fetecms_fundect', label: 'FETECMS FUNDECT', sigla: 'PIC', disponiveis: 20, areas: AREAS },
    ],
};

describe('ListaFinalDialog — cotas em três passos', () => {
    beforeEach(() => {
        getOpcoesListaFinal.mockResolvedValue(OPCOES);
        baixarListaFinal.mockResolvedValue();
    });

    it('começa perguntando a quantidade por categoria', async () => {
        render(<ListaFinalDialog open onClose={vi.fn()} />);
        expect(await screen.findByText('Quantos projetos em cada categoria?')).toBeInTheDocument();
        expect(screen.getByLabelText('Quantidade para FETECMS')).toBeInTheDocument();
        // As áreas só aparecem no passo seguinte.
        expect(screen.queryByLabelText('Quantidade para Ciências Agrárias')).not.toBeInTheDocument();
    });

    it('monta a cota aninhada categoria → área → interior, com fixo ou porcentagem', async () => {
        const onClose = vi.fn();
        render(<ListaFinalDialog open onClose={onClose} />);

        // Passo 1: 100 da FUNDECT.
        await screen.findByText('Quantos projetos em cada categoria?');
        fireEvent.change(screen.getByLabelText('Quantidade para FETECMS FUNDECT'), { target: { value: '100' } });
        fireEvent.click(screen.getByText('Continuar'));

        // Passo 2: 20 para agrárias, dentro da FUNDECT.
        // Uma seção por categoria: o campo da FUNDECT é o segundo.
        const camposAgrarias = await screen.findAllByLabelText('Quantidade para Ciências Agrárias');
        expect(camposAgrarias).toHaveLength(2);
        fireEvent.change(camposAgrarias[1], { target: { value: '20' } });
        fireEvent.click(screen.getByText('Continuar'));

        // Passo 3: 70% dessas 20 vagas para o interior.
        const interior = await screen.findAllByLabelText('Quantidade para Ciências Agrárias');
        fireEvent.change(interior[0], { target: { value: '70' } });
        fireEvent.change(screen.getAllByLabelText('Tipo da quantidade para Ciências Agrárias')[0], {
            target: { value: 'percentual' },
        });

        fireEvent.click(screen.getByText('Gerar TXT'));

        await waitFor(() => expect(baixarListaFinal).toHaveBeenCalled());
        const payload = baixarListaFinal.mock.calls[0][0];
        expect(payload.categorias.fetecms_fundect.cota).toEqual({ tipo: 'fixo', valor: 100 });
        expect(payload.categorias.fetecms_fundect.areas[1]).toEqual({
            cota: { tipo: 'fixo', valor: 20 },
            interior: { tipo: 'percentual', valor: 70 },
        });
        expect(onClose).toHaveBeenCalled();
    });

    it('no passo do interior só mostra as áreas que ganharam cota', async () => {
        render(<ListaFinalDialog open onClose={vi.fn()} />);
        await screen.findByText('Quantos projetos em cada categoria?');

        fireEvent.click(screen.getByText('3. Interior'));
        expect(await screen.findByText(/Nenhuma área tem cota definida/)).toBeInTheDocument();
    });
});
