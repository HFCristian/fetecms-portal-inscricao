import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/catalogos.js', () => ({ buscarInstituicoes: vi.fn(() => Promise.resolve([])) }));
vi.mock('../lib/admin.js', () => ({
    getInstituicoesAdmin: vi.fn(() => Promise.resolve({
        data: [{ id: 1, nome: 'Escola A', cidade: 'Campo Grande', tipo: 'particular', usos: 0 }],
        meta: { pagina_atual: 1, ultima_pagina: 3, total: 120, por_pagina: 50 },
    })),
    renomearInstituicao: vi.fn(),
    mesclarInstituicao: vi.fn(),
    excluirInstituicao: vi.fn(),
}));

import ParametrizacaoEscolas from './ParametrizacaoEscolas.jsx';

describe('ParametrizacaoEscolas — paginação e ordenação', () => {
    it('mostra a escola, o total e os controles de paginação', async () => {
        render(<ParametrizacaoEscolas />);
        expect(await screen.findByText('Escola A')).toBeInTheDocument();
        expect(screen.getByText('120 escolas.')).toBeInTheDocument();
        expect(screen.getByText('Página 1 de 3')).toBeInTheDocument();
        expect(screen.getByText('Próxima')).toBeInTheDocument();
    });

    it('oferece as opções de ordenação (nome e mais recentes)', async () => {
        render(<ParametrizacaoEscolas />);
        await screen.findByText('Escola A');
        expect(screen.getByRole('combobox', { name: 'Ordenar escolas' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Nome (A–Z)' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Mais recentes' })).toBeInTheDocument();
    });
});
