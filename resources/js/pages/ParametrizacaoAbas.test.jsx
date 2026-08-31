import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? '', fields: {} }),
}));

const getOrdemAbas = vi.fn();
const salvarOrdemAbas = vi.fn();
const restaurarOrdemAbas = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getOrdemAbas: (...a) => getOrdemAbas(...a),
    salvarOrdemAbas: (...a) => salvarOrdemAbas(...a),
    restaurarOrdemAbas: (...a) => restaurarOrdemAbas(...a),
}));

import ParametrizacaoAbas from './ParametrizacaoAbas.jsx';

const ABAS = [
    { value: 'projetos', label: 'Projetos', descricao: 'Projetos por área.' },
    { value: 'dashboards', label: 'Dashboards', descricao: 'Os números da feira.' },
    { value: 'credenciamento', label: 'Credenciamento', descricao: 'O balcão do evento.' },
];

const config = (over = {}) => ({ personalizada: false, edicao: { id: 1, nome: 'XVI FETECMS' }, abas: ABAS, ...over });

describe('ParametrizacaoAbas', () => {
    beforeEach(() => {
        getOrdemAbas.mockReset().mockResolvedValue(config());
        salvarOrdemAbas.mockReset();
        restaurarOrdemAbas.mockReset();
    });

    it('lista as abas na ordem em vigor, numeradas', async () => {
        render(<ParametrizacaoAbas />);

        const itens = await screen.findAllByRole('listitem');

        expect(itens).toHaveLength(3);
        expect(within(itens[0]).getByText('Projetos')).toBeInTheDocument();
        expect(within(itens[0]).getByText('1')).toBeInTheDocument();
        expect(within(itens[2]).getByText('Credenciamento')).toBeInTheDocument();
        expect(within(itens[2]).getByText('3')).toBeInTheDocument();
    });

    it('sobe e desce uma aba pelas setas', async () => {
        render(<ParametrizacaoAbas />);
        await screen.findByText('Credenciamento');

        fireEvent.click(screen.getByTitle('Mover Credenciamento para cima'));

        const itens = screen.getAllByRole('listitem');
        expect(within(itens[1]).getByText('Credenciamento')).toBeInTheDocument();
        expect(within(itens[2]).getByText('Dashboards')).toBeInTheDocument();
    });

    it('só habilita salvar depois de mexer na ordem', async () => {
        render(<ParametrizacaoAbas />);
        await screen.findByText('Credenciamento');

        expect(screen.getByText('Salvar ordem')).toBeDisabled();

        fireEvent.click(screen.getByTitle('Mover Credenciamento para cima'));
        expect(screen.getByText('Salvar ordem')).not.toBeDisabled();
    });

    it('manda a lista inteira, na ordem da tela', async () => {
        salvarOrdemAbas.mockResolvedValue({
            data: config({ personalizada: true }), meta: { message: 'Ordem do menu salva.' },
        });
        render(<ParametrizacaoAbas />);
        await screen.findByText('Credenciamento');

        fireEvent.click(screen.getByTitle('Mover Credenciamento para cima'));
        fireEvent.click(screen.getByText('Salvar ordem'));

        await waitFor(() => expect(salvarOrdemAbas).toHaveBeenCalledWith([
            'projetos', 'credenciamento', 'dashboards',
        ]));
        expect(await screen.findByText('Ordem do menu salva.')).toBeInTheDocument();
    });

    it('as pontas não sobem nem descem além da lista', async () => {
        render(<ParametrizacaoAbas />);
        await screen.findByText('Projetos');

        expect(screen.getByTitle('Mover Projetos para cima')).toBeDisabled();
        expect(screen.getByTitle('Mover Credenciamento para baixo')).toBeDisabled();
    });

    it('só oferece restaurar quando a ordem foi personalizada', async () => {
        render(<ParametrizacaoAbas />);
        await screen.findByText('Projetos');
        expect(screen.queryByText('Restaurar ordem original')).not.toBeInTheDocument();

        getOrdemAbas.mockResolvedValue(config({ personalizada: true }));
        render(<ParametrizacaoAbas />);
        expect(await screen.findByText('Restaurar ordem original')).toBeInTheDocument();
    });
});
