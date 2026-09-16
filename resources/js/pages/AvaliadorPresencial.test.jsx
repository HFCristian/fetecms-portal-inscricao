import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getPresencial = vi.fn();
const responderPresencial = vi.fn();
vi.mock('../lib/avaliacaoPresencial.js', () => ({
    getPresencial: (...a) => getPresencial(...a),
    responderPresencial: (...a) => responderPresencial(...a),
}));

import AvaliadorPresencial from './AvaliadorPresencial.jsx';

const SEM_RESPOSTA = {
    respondido: false, presencial: null, pode_alterar: true, evento_iniciado: false,
    evento_de_label: '20/10/2026 08:00', evento_ate_label: '22/10/2026 18:00',
    informacoes: null, is_demo: false,
};

const ACEITOU = {
    ...SEM_RESPOSTA,
    respondido: true, presencial: true,
    informacoes: 'Chegue às 7h30 no ginásio, com o crachá.',
};

describe('AvaliadorPresencial', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getPresencial.mockResolvedValue(SEM_RESPOSTA);
        responderPresencial.mockResolvedValue({
            data: ACEITOU,
            meta: { message: 'Participação presencial confirmada.' },
        });
    });

    it('pergunta e avisa que ainda não houve resposta', async () => {
        render(<AvaliadorPresencial />);

        expect(await screen.findByText(/Você quer avaliar presencialmente/i)).toBeInTheDocument();
        expect(screen.getByText('Você ainda não respondeu.')).toBeInTheDocument();
        // Sem "sim" não há orientações na tela.
        expect(screen.queryByText(/Orientações para o dia do evento/i)).not.toBeInTheDocument();
    });

    it('aceitar mostra as orientações da organização', async () => {
        render(<AvaliadorPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: /Sim, quero participar/i }));

        await waitFor(() => expect(responderPresencial).toHaveBeenCalledWith(true, false));
        expect(await screen.findByText(/Chegue às 7h30 no ginásio/)).toBeInTheDocument();
        expect(screen.getByText('Participação presencial confirmada.')).toBeInTheDocument();
    });

    it('recusar esconde as orientações e mantém a pergunta', async () => {
        getPresencial.mockResolvedValue({ ...SEM_RESPOSTA, respondido: true, presencial: false });
        render(<AvaliadorPresencial />);

        expect(await screen.findByText(/sua avaliação online continua valendo/i)).toBeInTheDocument();
        expect(screen.queryByText(/Orientações para o dia do evento/i)).not.toBeInTheDocument();
        // Ainda dá para voltar atrás.
        expect(screen.getByRole('button', { name: /Sim, quero participar/i })).toBeEnabled();
    });

    it('quem aceitou mas ainda não tem orientações publicadas é avisado', async () => {
        getPresencial.mockResolvedValue({ ...ACEITOU, informacoes: null });
        render(<AvaliadorPresencial />);

        expect(await screen.findByText(/ainda não publicou as orientações/i)).toBeInTheDocument();
    });

    it('com o evento em curso os botões travam e a tela explica', async () => {
        getPresencial.mockResolvedValue({ ...ACEITOU, pode_alterar: false, evento_iniciado: true });
        render(<AvaliadorPresencial />);

        expect(await screen.findByText(/fale com a organização/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Não vou participar/i })).toBeDisabled();
    });
});
