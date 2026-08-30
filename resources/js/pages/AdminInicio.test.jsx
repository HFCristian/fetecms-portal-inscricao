import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ to, children }) => <a href={to}>{children}</a>,
}));

const user = { id: 1, name: 'Ana Maria Souza', role: 'admin', abas: [] };
vi.mock('../lib/auth.jsx', () => ({ useAuth: () => ({ user }) }));

import AdminInicio from './AdminInicio.jsx';

const botao = (label) => screen.getByText(label).closest('a');

describe('AdminInicio', () => {
    it('mostra um botão por aba liberada, e só essas', () => {
        user.abas = ['projetos', 'comunicacao'];
        render(<AdminInicio />);

        expect(botao('Projetos')).toHaveAttribute('href', '/admin/projetos');
        expect(botao('Comunicação')).toHaveAttribute('href', '/admin/comunicacao');
        expect(screen.queryByText('Registros')).not.toBeInTheDocument();
        expect(screen.queryByText('Credenciamento')).not.toBeInTheDocument();
    });

    it('cumprimenta pelo primeiro nome', () => {
        user.abas = ['projetos'];
        render(<AdminInicio />);

        expect(screen.getByText('Olá, Ana')).toBeInTheDocument();
    });

    it('sem lista de abas (cache antigo), mostra todas — quem barra é o backend', () => {
        user.abas = undefined;
        render(<AdminInicio />);

        expect(botao('Projetos')).toBeInTheDocument();
        expect(botao('Registros')).toBeInTheDocument();
        expect(botao('Comitê especial')).toBeInTheDocument();
    });

    it('explica o vazio quando nenhum escopo abre aba alguma', () => {
        user.abas = [];
        render(<AdminInicio />);

        expect(screen.getByText('Nenhuma área liberada nesta edição')).toBeInTheDocument();
        expect(screen.queryByText('Projetos')).not.toBeInTheDocument();
    });
});
