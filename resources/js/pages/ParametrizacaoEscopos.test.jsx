import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));

const DADOS = {
    escopos: [
        { id: 1, nome: 'Comunicação', abas: ['comunicacao', 'suporte'], admins: 2, pode_excluir: false },
        { id: 2, nome: 'Só projetos', abas: ['projetos'], admins: 0, pode_excluir: true },
    ],
    abas: [
        { value: 'projetos', label: 'Projetos', descricao: 'Painel e recortes.' },
        { value: 'comunicacao', label: 'Comunicação', descricao: 'Mala direta e avisos.' },
        { value: 'suporte', label: 'Suporte', descricao: 'Caixa de entrada do chat.' },
    ],
    edicao: { id: 1, nome: 'XVI FETECMS' },
};

const getEscopos = vi.fn(() => Promise.resolve(DADOS));
const criarEscopo = vi.fn(() => Promise.resolve(DADOS));
const atualizarEscopo = vi.fn(() => Promise.resolve(DADOS));
const excluirEscopo = vi.fn(() => Promise.resolve(DADOS));
vi.mock('../lib/admin.js', () => ({
    getEscopos: (...a) => getEscopos(...a),
    criarEscopo: (...a) => criarEscopo(...a),
    atualizarEscopo: (...a) => atualizarEscopo(...a),
    excluirEscopo: (...a) => excluirEscopo(...a),
}));

import ParametrizacaoEscopos from './ParametrizacaoEscopos.jsx';

describe('ParametrizacaoEscopos', () => {
    beforeEach(() => {
        criarEscopo.mockClear();
        atualizarEscopo.mockClear();
    });

    it('lista os escopos com as abas que cada um abre', async () => {
        render(<ParametrizacaoEscopos />);

        expect(await screen.findByText('Comunicação · Suporte')).toBeInTheDocument();
        expect(screen.getByText('2 administradores')).toBeInTheDocument();
        // Escopo em uso não oferece exclusão; o vazio, sim.
        expect(screen.getAllByRole('button', { name: /Excluir/ })).toHaveLength(1);
    });

    it('cria um escopo com as abas marcadas', async () => {
        render(<ParametrizacaoEscopos />);
        await screen.findByText('2 administradores');

        fireEvent.change(screen.getByPlaceholderText('Comunicação e suporte'), { target: { value: 'Curadoria' } });
        fireEvent.click(screen.getByRole('checkbox', { name: /Projetos/ }));
        fireEvent.click(screen.getByRole('button', { name: /Criar escopo/ }));

        await waitFor(() => expect(criarEscopo).toHaveBeenCalledWith({ nome: 'Curadoria', abas: ['projetos'] }));
    });

    it('não deixa criar escopo sem nenhuma aba', async () => {
        render(<ParametrizacaoEscopos />);
        await screen.findByText('2 administradores');

        fireEvent.change(screen.getByPlaceholderText('Comunicação e suporte'), { target: { value: 'Vazio' } });

        expect(screen.getByRole('button', { name: /Criar escopo/ })).toBeDisabled();
    });

    it('abre a edição já com as abas do escopo marcadas', async () => {
        render(<ParametrizacaoEscopos />);
        await screen.findByText('2 administradores');

        fireEvent.click(screen.getAllByRole('button', { name: /Editar/ })[0]);

        expect(screen.getByRole('checkbox', { name: /Comunicação/ })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: /Projetos/ })).not.toBeChecked();

        fireEvent.click(screen.getByRole('button', { name: /Salvar escopo/ }));
        await waitFor(() => expect(atualizarEscopo).toHaveBeenCalledWith(1, {
            nome: 'Comunicação', abas: ['comunicacao', 'suporte'],
        }));
    });
});
