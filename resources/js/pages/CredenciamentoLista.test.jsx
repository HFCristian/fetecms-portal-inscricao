import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../components/LeitorCodigo.jsx', () => ({ default: () => null }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a>, useNavigate: () => vi.fn() }));
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [false, vi.fn()] }));

const getFinalistas = vi.fn();
vi.mock('../lib/credenciamento.js', () => ({ getFinalistas: (...a) => getFinalistas(...a) }));

import CredenciamentoLista from './CredenciamentoLista.jsx';

const RESPOSTA = {
    data: [{ id: 7, titulo: 'Bioplástico', categoria_label: 'FETECMS', area: 'Agrárias', codigo: 'FET.AGR-001', estande: 42, turno: 'A', turno_label: 'Turno A (matutino)' }],
    meta: {
        total: 1, pagina_atual: 1, ultima_pagina: 1,
        areas: [], categorias: [],
        turnos: [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }],
        config: { aberto: true },
    },
};

describe('CredenciamentoLista', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getFinalistas.mockResolvedValue(RESPOSTA);
    });

    it('a busca aceita o estande e os filtros de código, estande e turno vão ao servidor', async () => {
        render(<CredenciamentoLista />);
        await screen.findByText('Bioplástico');

        expect(screen.getByLabelText('Buscar')).toHaveAttribute('placeholder', 'Buscar por título, escola, orientador, código ou estande');
        fireEvent.change(screen.getByLabelText('Buscar'), { target: { value: 'estande 42' } });
        await waitFor(() => expect(getFinalistas).toHaveBeenLastCalledWith(
            { page: 1, situacao: 'pendentes', busca: 'estande 42' }, false,
        ));

        fireEvent.change(screen.getByLabelText('Número do estande'), { target: { value: '0a42' } });
        expect(screen.getByLabelText('Número do estande')).toHaveValue('042');
        fireEvent.change(screen.getByLabelText('Turno'), { target: { value: 'B' } });
        fireEvent.change(screen.getByLabelText('Código do projeto'), { target: { value: ' AGR ' } });

        await waitFor(() => expect(getFinalistas).toHaveBeenLastCalledWith(
            { page: 1, situacao: 'pendentes', busca: 'estande 42', estande: '042', turno: 'B', codigo: 'AGR' }, false,
        ));
    });
});
