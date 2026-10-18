import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getParametrizacaoPresencial = vi.fn();
const salvarParametrizacaoPresencial = vi.fn();
const salvarLancamentoNoturno = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getParametrizacaoPresencial: (...a) => getParametrizacaoPresencial(...a),
    salvarParametrizacaoPresencial: (...a) => salvarParametrizacaoPresencial(...a),
    salvarLancamentoNoturno: (...a) => salvarLancamentoNoturno(...a),
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

    it('salva o lançamento noturno do comitê e mostra o horário em vigor', async () => {
        salvarLancamentoNoturno.mockResolvedValue({
            data: {
                comite_so_designados: false, pedidos_pendentes: 0,
                comite_noite: { inicio: '22:00', fim: '01:00', rotulo: '22:00–01:00 (do dia seguinte)', ligado: true, sem_evento: false },
            },
            meta: { message: 'Lançamento noturno das 22:00–01:00 (do dia seguinte) salvo.' },
        });
        render(<ParametrizacaoPresencial />);

        expect(await screen.findByText(/Desligado: o comitê envia só no horário/)).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Início do lançamento noturno'), { target: { value: '22:00' } });
        fireEvent.change(screen.getByLabelText('Fim do lançamento noturno'), { target: { value: '01:00' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar horário' }));

        await waitFor(() => expect(salvarLancamentoNoturno).toHaveBeenCalledWith('22:00', '01:00'));
        expect(await screen.findByText('Lançamento noturno das 22:00–01:00 (do dia seguinte) salvo.')).toBeInTheDocument();
        expect(screen.getByText('22:00–01:00 (do dia seguinte)')).toBeInTheDocument();
        // Desligar manda os dois em branco.
        fireEvent.click(screen.getByRole('button', { name: 'Desligar' }));
        await waitFor(() => expect(salvarLancamentoNoturno).toHaveBeenLastCalledWith('', ''));
    });

    it('o erro do horário noturno aparece', async () => {
        salvarLancamentoNoturno.mockRejectedValue({ message: 'Erro.', fields: { fim: 'Informe o início e o fim do lançamento noturno.' } });
        render(<ParametrizacaoPresencial />);

        fireEvent.change(await screen.findByLabelText('Início do lançamento noturno'), { target: { value: '19:00' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar horário' }));

        expect(await screen.findByText('Informe o início e o fim do lançamento noturno.')).toBeInTheDocument();
    });
});
