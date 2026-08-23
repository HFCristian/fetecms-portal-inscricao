import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getInscricoesConfig = vi.fn();
const definirPrazoInscricoes = vi.fn();
const definirInicioInscricoes = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getInscricoesConfig: (...a) => getInscricoesConfig(...a),
    definirPrazoInscricoes: (...a) => definirPrazoInscricoes(...a),
    definirInicioInscricoes: (...a) => definirInicioInscricoes(...a),
}));

import ParametrizacaoInscricoes from './ParametrizacaoInscricoes.jsx';

const vazio = {
    abertas: true, encerradas: false, nao_iniciadas: false,
    inicio_input: null, inicio_label: null, prazo_input: null, prazo_label: null,
};
const comPrazo = { ...vazio, prazo_input: '2026-09-30T23:59', prazo_label: '30/09/2026 23:59' };
const naoIniciadas = {
    ...vazio, abertas: false, nao_iniciadas: true,
    inicio_input: '2026-09-01T08:00', inicio_label: '01/09/2026 08:00',
};

describe('ParametrizacaoInscricoes', () => {
    beforeEach(() => {
        getInscricoesConfig.mockReset().mockResolvedValue(vazio);
        definirPrazoInscricoes.mockReset();
        definirInicioInscricoes.mockReset();
    });

    it('mostra as duas pontas da janela sem data definida', async () => {
        render(<ParametrizacaoInscricoes />);

        expect(await screen.findByText('Sem data — já abertas')).toBeInTheDocument();
        expect(screen.getByText('Sem prazo definido')).toBeInTheDocument();
        expect(screen.getByText('Salvar abertura').closest('button')).toBeDisabled();
        expect(screen.queryByText('Remover')).not.toBeInTheDocument();
    });

    it('avisa quando as inscrições ainda não abriram', async () => {
        getInscricoesConfig.mockResolvedValue(naoIniciadas);
        render(<ParametrizacaoInscricoes />);

        expect(await screen.findByText('Abre em 01/09/2026 08:00')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de abertura das inscrições')).toHaveValue('2026-09-01T08:00');
    });

    it('salva a data de abertura digitada', async () => {
        definirInicioInscricoes.mockResolvedValue({
            data: naoIniciadas, meta: { message: 'Abertura das inscrições salva.' },
        });
        render(<ParametrizacaoInscricoes />);

        fireEvent.change(await screen.findByLabelText('Data de abertura das inscrições'), {
            target: { value: '2026-09-01T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar abertura'));

        await waitFor(() => expect(definirInicioInscricoes).toHaveBeenCalledWith('2026-09-01T08:00'));
        expect(await screen.findByText('Abertura das inscrições salva.')).toBeInTheDocument();
        expect(screen.getByText('Abre em 01/09/2026 08:00')).toBeInTheDocument();
    });

    it('salva e depois remove o prazo de submissão', async () => {
        definirPrazoInscricoes.mockResolvedValue({ data: comPrazo, meta: { message: 'Prazo de inscrição salvo.' } });
        render(<ParametrizacaoInscricoes />);

        fireEvent.change(await screen.findByLabelText('Data-limite de submissão'), {
            target: { value: '2026-09-30T23:59' },
        });
        fireEvent.click(screen.getByText('Salvar prazo'));

        await waitFor(() => expect(definirPrazoInscricoes).toHaveBeenCalledWith('2026-09-30T23:59'));
        expect(await screen.findByText('Encerra em 30/09/2026 23:59')).toBeInTheDocument();

        definirPrazoInscricoes.mockResolvedValue({ data: vazio, meta: { message: 'Prazo removido.' } });
        fireEvent.click(screen.getByText('Remover'));

        await waitFor(() => expect(definirPrazoInscricoes).toHaveBeenCalledWith(null));
        expect(await screen.findByText('Sem prazo definido')).toBeInTheDocument();
    });

    it('mostra o erro de ordem que vem do backend', async () => {
        getInscricoesConfig.mockResolvedValue(comPrazo);
        definirInicioInscricoes.mockRejectedValue({
            response: { status: 422, data: { message: 'A abertura precisa ser antes do prazo.' } },
        });
        render(<ParametrizacaoInscricoes />);

        fireEvent.change(await screen.findByLabelText('Data de abertura das inscrições'), {
            target: { value: '2026-10-05T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar abertura'));

        expect(await screen.findByText('A abertura precisa ser antes do prazo.')).toBeInTheDocument();
    });
});
