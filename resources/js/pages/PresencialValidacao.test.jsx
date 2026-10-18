import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: {} }) }));

const PAINEL = {
    resumo: { total: 3, liberados: 1, aguardando: 2 },
    modo_teste: false,
    is_demo: false,
    avaliadores: [
        { id: 7, nome: 'Ana', email: 'ana@ufms.br', area: 'Agrárias', apenas_presencial: true, comissao: false, avaliacoes: 0, liberado: false },
        { id: 8, nome: 'Bruno', email: 'bruno@ufms.br', area: 'Biológicas', apenas_presencial: false, comissao: true, avaliacoes: 0, liberado: false },
        { id: 9, nome: 'Carla', email: 'carla@ufms.br', area: 'Exatas', apenas_presencial: false, comissao: false, avaliacoes: 2, liberado: true, liberado_por: 'Organização', liberado_em_label: '20/10/2026 08:10' },
    ],
};

const getValidacaoPresencial = vi.fn(() => Promise.resolve(PAINEL));
const liberarAvaliadoresPresencial = vi.fn(() => Promise.resolve({
    data: PAINEL, meta: { message: '2 avaliador(es) liberado(s) para a avaliação presencial.', resultado: { liberados: 2, ignorados: [] } },
}));
const revogarLiberacaoPresencial = vi.fn(() => Promise.resolve({ data: PAINEL, meta: { message: 'Liberação de Carla revogada.', resultado: { devolvidas: 0 } } }));
vi.mock('../lib/presencial.js', () => ({
    getValidacaoPresencial: (...a) => getValidacaoPresencial(...a),
    liberarAvaliadoresPresencial: (...a) => liberarAvaliadoresPresencial(...a),
    revogarLiberacaoPresencial: (...a) => revogarLiberacaoPresencial(...a),
}));

import PresencialValidacao from './PresencialValidacao.jsx';

describe('PresencialValidacao', () => {
    beforeEach(() => vi.clearAllMocks());

    it('abre em quem aguarda, com os números e a situação de cada um', async () => {
        render(<PresencialValidacao />);

        await waitFor(() => expect(getValidacaoPresencial).toHaveBeenCalledWith({ busca: '', situacao: 'aguardando' }, false));
        expect(await screen.findByText('Aguardando liberação', { selector: 'div' })).toBeInTheDocument();
        expect(screen.getByText('Só presencial')).toBeInTheDocument();
        expect(screen.getByText(/liberado por Organização em 20\/10\/2026 08:10/)).toBeInTheDocument();
    });

    it('libera um avaliador', async () => {
        render(<PresencialValidacao />);

        fireEvent.click(await screen.findByRole('button', { name: 'Liberar Ana' }));

        await waitFor(() => expect(liberarAvaliadoresPresencial).toHaveBeenCalledWith([7], { busca: '', situacao: 'aguardando' }, false));
        expect(await screen.findByText(/2 avaliador\(es\) liberado\(s\)/)).toBeInTheDocument();
    });

    it('libera em lote os marcados', async () => {
        render(<PresencialValidacao />);

        fireEvent.click(await screen.findByLabelText('Marcar todos os que aguardam'));
        fireEvent.click(screen.getByRole('button', { name: /Liberar marcados \(2\)/ }));

        await waitFor(() => expect(liberarAvaliadoresPresencial).toHaveBeenCalledWith([7, 8], { busca: '', situacao: 'aguardando' }, false));
    });

    it('revoga com confirmação', async () => {
        render(<PresencialValidacao />);

        fireEvent.click(await screen.findByRole('button', { name: 'Revogar Carla' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Revogar' }));

        await waitFor(() => expect(revogarLiberacaoPresencial).toHaveBeenCalledWith(9, { busca: '', situacao: 'aguardando' }, false));
    });

    it('troca a situação e busca por submit', async () => {
        render(<PresencialValidacao />);
        await screen.findByText('Ana');

        fireEvent.click(screen.getByRole('radio', { name: 'Liberados' }));
        await waitFor(() => expect(getValidacaoPresencial).toHaveBeenLastCalledWith({ busca: '', situacao: 'liberados' }, false));

        fireEvent.change(screen.getByLabelText('Buscar por nome ou e-mail'), { target: { value: 'carla' } });
        fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));
        await waitFor(() => expect(getValidacaoPresencial).toHaveBeenLastCalledWith({ busca: 'carla', situacao: 'liberados' }, false));
    });
});
