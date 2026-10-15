import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getEventosCerimonia = vi.fn();
const criarEventoCerimonia = vi.fn();
const atualizarEventoCerimonia = vi.fn();
const excluirEventoCerimonia = vi.fn();
vi.mock('../lib/cerimonial.js', () => ({
    getEventosCerimonia: (...a) => getEventosCerimonia(...a),
    criarEventoCerimonia: (...a) => criarEventoCerimonia(...a),
    atualizarEventoCerimonia: (...a) => atualizarEventoCerimonia(...a),
    excluirEventoCerimonia: (...a) => excluirEventoCerimonia(...a),
}));

import CerimonialEventos from './CerimonialEventos.jsx';

const FETECMS = { id: 1, nome: 'Premiação FETECMS', categorias: ['fetecms'], publico: 'FETECMS', checkins: 12 };
const JR = { id: 2, nome: 'Premiação Jr', categorias: ['fetec_jr'], publico: 'FETEC Jr', checkins: 0 };

describe('CerimonialEventos', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getEventosCerimonia.mockResolvedValue([FETECMS]);
    });

    it('lista os eventos com o público e quantos já entraram', async () => {
        render(<CerimonialEventos />);

        expect(await screen.findByText('Premiação FETECMS')).toBeInTheDocument();
        expect(screen.getByText('Público: FETECMS · 12 check-in(s)')).toBeInTheDocument();
        // Com check-in, não se exclui: edita-se o público.
        expect(screen.getByRole('button', { name: 'Excluir' })).toBeDisabled();
    });

    it('cria um evento com o nome e as categorias do público', async () => {
        criarEventoCerimonia.mockResolvedValue({
            id: 2, nome: 'Premiação Jr e FUNDECT', categorias: ['fetec_jr', 'fetecms_fundect'],
            publico: 'FETEC Jr e FETECMS FUNDECT', checkins: 0,
        });
        render(<CerimonialEventos />);

        fireEvent.click(await screen.findByRole('button', { name: /Novo evento/ }));
        fireEvent.change(screen.getByLabelText('Nome do evento'), { target: { value: 'Premiação Jr e FUNDECT' } });
        fireEvent.click(screen.getByLabelText('FETEC Jr'));
        fireEvent.click(screen.getByLabelText('FETECMS FUNDECT'));
        fireEvent.click(screen.getByRole('button', { name: 'Criar evento' }));

        await waitFor(() => expect(criarEventoCerimonia).toHaveBeenCalledWith({
            nome: 'Premiação Jr e FUNDECT', categorias: ['fetec_jr', 'fetecms_fundect'],
        }));
        expect(await screen.findByText('Público: FETEC Jr e FETECMS FUNDECT · 0 check-in(s)')).toBeInTheDocument();
    });

    it('mostra o erro do servidor junto do campo', async () => {
        criarEventoCerimonia.mockRejectedValue({ fields: { categorias: 'Escolha ao menos uma categoria para o público do evento.' } });
        render(<CerimonialEventos />);

        fireEvent.click(await screen.findByRole('button', { name: /Novo evento/ }));
        fireEvent.change(screen.getByLabelText('Nome do evento'), { target: { value: 'Sem público' } });
        fireEvent.click(screen.getByRole('button', { name: 'Criar evento' }));

        expect(await screen.findByText('Escolha ao menos uma categoria para o público do evento.')).toBeInTheDocument();
    });

    it('edita o público de um evento existente', async () => {
        atualizarEventoCerimonia.mockResolvedValue({ ...FETECMS, categorias: ['fetecms', 'fetec_jr'], publico: 'FETECMS e FETEC Jr' });
        render(<CerimonialEventos />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        expect(screen.getByLabelText('FETECMS')).toBeChecked();
        fireEvent.click(screen.getByLabelText('FETEC Jr'));
        fireEvent.click(screen.getByRole('button', { name: 'Salvar' }));

        await waitFor(() => expect(atualizarEventoCerimonia).toHaveBeenCalledWith(1, {
            nome: 'Premiação FETECMS', categorias: ['fetecms', 'fetec_jr'],
        }));
        expect(await screen.findByText('Público: FETECMS e FETEC Jr · 12 check-in(s)')).toBeInTheDocument();
    });

    it('exclui, depois de confirmar, o evento que ainda não recebeu ninguém', async () => {
        getEventosCerimonia.mockResolvedValue([FETECMS, JR]);
        excluirEventoCerimonia.mockResolvedValue([FETECMS]);
        render(<CerimonialEventos />);

        await screen.findByText('Premiação Jr');
        fireEvent.click(screen.getAllByRole('button', { name: 'Excluir' })[1]);
        const dialogo = await screen.findByRole('dialog');
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Excluir' }));

        await waitFor(() => expect(excluirEventoCerimonia).toHaveBeenCalledWith(2));
        await waitFor(() => expect(screen.queryByText('Premiação Jr')).not.toBeInTheDocument());
    });
});
