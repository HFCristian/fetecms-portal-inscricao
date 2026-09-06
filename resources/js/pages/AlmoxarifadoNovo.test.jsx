import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useNavigate: () => navigate,
}));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [false, vi.fn()] }));

const PROJETOS = [
    {
        id: 7,
        titulo: 'Bioplástico',
        escola: 'EE Alfa',
        orientador: 'Marta Orientadora',
        pessoas: [
            { tipo: 'aluno', tipo_label: 'Aluno', id: 30, nome: 'Ana Aluna' },
            { tipo: 'orientador', tipo_label: 'Orientador', id: 5, nome: 'Marta Orientadora' },
        ],
    },
];

const getAlmoxarifadoProjetos = vi.fn(() => Promise.resolve(PROJETOS));
const guardarNoAlmoxarifado = vi.fn(() => Promise.resolve({ id: 1 }));
vi.mock('../lib/almoxarifado.js', () => ({
    getAlmoxarifadoProjetos: (...a) => getAlmoxarifadoProjetos(...a),
    guardarNoAlmoxarifado: (...a) => guardarNoAlmoxarifado(...a),
}));

import AlmoxarifadoNovo from './AlmoxarifadoNovo.jsx';

/** Projeto → pessoa → itens, deixando o assistente na conferência. */
async function ateAConferencia(itens = 'Maquete de madeira\n\n  Mochila azul  ') {
    render(<AlmoxarifadoNovo />);
    fireEvent.click(await screen.findByText('Bioplástico'));
    fireEvent.click(await screen.findByText('Ana Aluna'));
    fireEvent.change(screen.getByLabelText('Itens guardados'), { target: { value: itens } });
    fireEvent.click(screen.getByRole('button', { name: 'Conferir' }));
}

describe('AlmoxarifadoNovo', () => {
    beforeEach(() => {
        guardarNoAlmoxarifado.mockClear();
        navigate.mockClear();
    });

    it('percorre os quatro passos e grava um item por linha', async () => {
        await ateAConferencia();

        // A conferência repete tudo antes de gravar.
        expect(screen.getByText('Itens (2)')).toBeInTheDocument();
        expect(screen.getByText('Mochila azul')).toBeInTheDocument();
        expect(screen.getByText('Ana Aluna · Aluno')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Finalizar/ }));

        await waitFor(() => expect(guardarNoAlmoxarifado).toHaveBeenCalledWith({
            projeto_id: 7,
            responsavel_tipo: 'aluno',
            responsavel_id: 30,
            itens: ['Maquete de madeira', 'Mochila azul'],
        }, false));
        expect(navigate).toHaveBeenCalledWith('/admin/almoxarifado/registros', { replace: true });
    });

    it('o "Alterar" da conferência volta ao passo sem perder o resto', async () => {
        await ateAConferencia();

        // Corrige só os itens; projeto e pessoa continuam escolhidos.
        fireEvent.click(screen.getAllByRole('button', { name: 'Alterar' })[2]);
        fireEvent.change(screen.getByLabelText('Itens guardados'), { target: { value: 'Só a maquete' } });
        fireEvent.click(screen.getByRole('button', { name: 'Conferir' }));
        fireEvent.click(screen.getByRole('button', { name: /Finalizar/ }));

        await waitFor(() => expect(guardarNoAlmoxarifado).toHaveBeenCalledWith(
            expect.objectContaining({ projeto_id: 7, responsavel_id: 30, itens: ['Só a maquete'] }),
            false,
        ));
    });

    it('sem item nenhum não dá para chegar à conferência', async () => {
        render(<AlmoxarifadoNovo />);
        fireEvent.click(await screen.findByText('Bioplástico'));
        fireEvent.click(await screen.findByText('Ana Aluna'));

        expect(screen.getByRole('button', { name: 'Conferir' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Itens guardados'), { target: { value: '   \n  ' } });
        expect(screen.getByRole('button', { name: 'Conferir' })).toBeDisabled();
    });

    it('não deixa pular passos que ainda não foram preenchidos', async () => {
        render(<AlmoxarifadoNovo />);
        await screen.findByText('Bioplástico');

        expect(screen.getByRole('button', { name: '3. Itens' })).toBeDisabled();
        expect(screen.getByRole('button', { name: '4. Conferência' })).toBeDisabled();
    });
});
