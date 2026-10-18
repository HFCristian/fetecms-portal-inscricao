import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getParametrizacaoPresencial = vi.fn();
const salvarParametrizacaoPresencial = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getParametrizacaoPresencial: (...a) => getParametrizacaoPresencial(...a),
    salvarParametrizacaoPresencial: (...a) => salvarParametrizacaoPresencial(...a),
}));

import ParametrizacaoPresencial from './ParametrizacaoPresencial.jsx';

describe('ParametrizacaoPresencial', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getParametrizacaoPresencial.mockResolvedValue({ comite_so_designados: false, pedidos_pendentes: 0 });
    });

    it('liga a regra do comitê de avaliar apenas os projetos designados', async () => {
        salvarParametrizacaoPresencial.mockResolvedValue({
            data: { comite_so_designados: true, pedidos_pendentes: 2 },
            meta: { message: 'O comitê passa a avaliar só os projetos designados.' },
        });
        render(<ParametrizacaoPresencial />);

        const chave = await screen.findByRole('switch', { name: 'Avaliar apenas projetos designados' });
        expect(chave).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByRole('region', { name: 'Comitê especial' })).toBeInTheDocument();

        fireEvent.click(chave);

        await waitFor(() => expect(salvarParametrizacaoPresencial).toHaveBeenCalledWith(true));
        expect(await screen.findByText('O comitê passa a avaliar só os projetos designados.')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Avaliar apenas projetos designados' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByText('2')).toBeInTheDocument();
    });

    it('a falha ao salvar aparece', async () => {
        salvarParametrizacaoPresencial.mockRejectedValue(new Error('Sem permissão.'));
        render(<ParametrizacaoPresencial />);

        fireEvent.click(await screen.findByRole('switch', { name: 'Avaliar apenas projetos designados' }));

        expect(await screen.findByText('Sem permissão.')).toBeInTheDocument();
    });
});
