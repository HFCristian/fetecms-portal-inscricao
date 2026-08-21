import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';

vi.mock('react-router-dom', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, useNavigate: () => vi.fn() };
});

vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({ registerAvaliador: vi.fn() }),
    extractErrors: () => ({ message: 'erro', fields: {} }),
    homeFor: () => '/avaliador',
}));
vi.mock('../lib/catalogos.js', () => ({
    useCatalogos: () => ({ areas: [{ id: 1, nome: 'Engenharias' }] }),
    loadSubareas: vi.fn(() => Promise.resolve([])),
}));

import CadastroAvaliador from './CadastroAvaliador.jsx';

/** O select de titulação (o Field não associa label e campo por id). */
const selectTitulacao = (container) =>
    [...container.querySelectorAll('select')].find((s) =>
        [...s.options].some((o) => o.value.startsWith('Mestrado')));

describe('CadastroAvaliador — titulação', () => {
    it('avisa que pós-graduação em andamento já habilita', () => {
        render(<MemoryRouter><CadastroAvaliador /></MemoryRouter>);

        expect(screen.getByText(/Quem está cursando pós-graduação já pode se cadastrar/))
            .toBeInTheDocument();
        expect(screen.getByText(/não é preciso ter concluído/)).toBeInTheDocument();
        expect(screen.getByText(/Basta estar cursando/)).toBeInTheDocument();
    });

    it('oferece cada nível nas duas situações (em andamento e concluído)', () => {
        const { container } = render(<MemoryRouter><CadastroAvaliador /></MemoryRouter>);

        const opcoes = [...selectTitulacao(container).options]
            .map((o) => o.value)
            .filter(Boolean);

        expect(opcoes).toEqual([
            'Especialização (em andamento)',
            'Especialização (concluída)',
            'Mestrado (em andamento)',
            'Mestrado (concluído)',
            'Doutorado (em andamento)',
            'Doutorado (concluído)',
        ]);
    });
});
