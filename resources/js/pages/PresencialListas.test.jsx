import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a>, useNavigate: () => navigate }));
const auth = { user: { id: 1, role: 'admin', is_demo: false } };
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? '', fields: {} }),
    useAuth: () => auth,
}));
vi.mock('../components/ListaPresencialDialog.jsx', () => ({ default: () => null }));

const LISTAS = [
    { id: 3, nome: 'Final da premiação', tipo: 'final', vigente: true, rascunho: false, versao: 1, projetos: 12, origens: [], gerada_em: '2026-10-21T10:00:00Z' },
    { id: 2, nome: 'Final antiga', tipo: 'final', vigente: false, rascunho: false, versao: 2, projetos: 10, origens: [], gerada_em: '2026-10-20T10:00:00Z' },
    { id: 1, nome: 'Pódio por área', descricao: 'Três por área.', tipo: 'preliminar', vigente: false, rascunho: false, versao: 1, projetos: 20, origens: [] },
    { id: 4, nome: 'Rascunho', tipo: 'preliminar', vigente: false, rascunho: true, versao: 1, projetos: 5, origens: [] },
];

const getListasPresenciais = vi.fn(() => Promise.resolve(LISTAS));
const montarListaPresencialPorUniao = vi.fn(() => Promise.resolve({ lista: { id: 9 } }));
const reativarListaPresencial = vi.fn(() => Promise.resolve({ meta: { message: 'Esta é agora a lista final presencial ativa.' } }));
const exportarListaPresencial = vi.fn(() => Promise.resolve());
vi.mock('../lib/listasPresenciais.js', () => ({
    getListasPresenciais: (...a) => getListasPresenciais(...a),
    montarListaPresencialPorUniao: (...a) => montarListaPresencialPorUniao(...a),
    reativarListaPresencial: (...a) => reativarListaPresencial(...a),
    exportarListaPresencial: (...a) => exportarListaPresencial(...a),
}));

import PresencialListas from './PresencialListas.jsx';

describe('PresencialListas', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        auth.user = { id: 1, role: 'admin', is_demo: false };
    });

    it('separa finais e preliminares, com a ativa marcada', async () => {
        render(<PresencialListas />);

        const ativa = (await screen.findByText('Final da premiação')).closest('li');
        expect(within(ativa).getByText('ativa')).toBeInTheDocument();
        expect(within(screen.getByText('Final antiga').closest('li')).getByText('inativa')).toBeInTheDocument();
        expect(screen.getByText('Três por área.')).toBeInTheDocument();
        expect(within(screen.getByText('Rascunho').closest('li')).getByRole('link', { name: /Editar e gerar/ }))
            .toHaveAttribute('href', '/admin/presencial/listas/4');
    });

    it('monta a união só com listas geradas', async () => {
        render(<PresencialListas />);
        fireEvent.click(await screen.findByRole('button', { name: /Montar a partir de outras listas/ }));

        const dialogo = screen.getByRole('dialog', { name: 'Montar lista a partir de outras' });
        expect(within(dialogo).queryByLabelText('Rascunho')).not.toBeInTheDocument();
        fireEvent.click(within(dialogo).getByLabelText('Final da premiação'));
        fireEvent.click(within(dialogo).getByLabelText('Pódio por área'));
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Montar rascunho' }));

        await waitFor(() => expect(montarListaPresencialPorUniao).toHaveBeenCalledWith(
            { listas: [3, 1], tipo: 'final', nome: null, descricao: null }, false,
        ));
        expect(navigate).toHaveBeenCalledWith('/admin/presencial/listas/9');
    });

    it('reativa a final inativa com justificativa', async () => {
        render(<PresencialListas />);
        fireEvent.click(within((await screen.findByText('Final antiga')).closest('li')).getByRole('button', { name: /Tornar ativa/ }));

        const dialogo = screen.getByRole('dialog', { name: 'Tornar ativa' });
        fireEvent.change(within(dialogo).getByLabelText('Justificativa da troca'), { target: { value: 'Correção.' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Tornar ativa' }));

        await waitFor(() => expect(reativarListaPresencial).toHaveBeenCalledWith(2, 'Correção.'));
        expect(await screen.findByText('Esta é agora a lista final presencial ativa.')).toBeInTheDocument();
    });

    it('baixa o TXT e, na conta demo, troca para a trilha do ensaio', async () => {
        auth.user = { id: 1, role: 'admin', is_demo: true };
        render(<PresencialListas />);

        fireEvent.click(within((await screen.findByText('Pódio por área')).closest('li')).getByRole('button', { name: /TXT/ }));
        await waitFor(() => expect(exportarListaPresencial).toHaveBeenCalledWith(1, 'txt'));

        fireEvent.click(screen.getByRole('switch', { name: /Modo de teste/ }));
        await waitFor(() => expect(getListasPresenciais).toHaveBeenLastCalledWith(true));
    });
});
