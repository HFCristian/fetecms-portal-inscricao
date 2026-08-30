import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getFeedbackPendente = vi.fn();
const marcarFeedbackVisto = vi.fn(() => Promise.resolve({}));
const dispensarFeedback = vi.fn(() => Promise.resolve({}));
const responderFeedback = vi.fn(() => Promise.resolve({}));
vi.mock('../lib/feedback.js', () => ({
    getFeedbackPendente: (...a) => getFeedbackPendente(...a),
    marcarFeedbackVisto: (...a) => marcarFeedbackVisto(...a),
    dispensarFeedback: (...a) => dispensarFeedback(...a),
    responderFeedback: (...a) => responderFeedback(...a),
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? '',
        fields: e?.response?.data?.errors ?? {},
    }),
}));

import FeedbackCard from './FeedbackCard.jsx';

const pendente = {
    id: 3,
    titulo: 'Como foi a XVI FETECMS para você?',
    descricao: 'Sua opinião ajuda a organizar a próxima edição.',
    perguntas: [
        {
            id: 10, tipo: 'alternativa', enunciado: 'Como você avalia a organização?',
            obrigatoria: true, opcoes: ['Ruim', 'Boa', 'Ótima'], limite: null,
        },
        {
            id: 11, tipo: 'dissertativa', enunciado: 'O que podemos melhorar?',
            obrigatoria: false, opcoes: [], limite: 'Entre 2 e 50 palavras.',
        },
    ],
};

describe('FeedbackCard', () => {
    beforeEach(() => {
        getFeedbackPendente.mockReset().mockResolvedValue(pendente);
        marcarFeedbackVisto.mockClear();
        dispensarFeedback.mockClear();
        responderFeedback.mockReset().mockResolvedValue({});
    });

    it('não desenha nada quando não há feedback pendente', async () => {
        getFeedbackPendente.mockResolvedValue(null);
        const { container } = render(<FeedbackCard />);

        await waitFor(() => expect(getFeedbackPendente).toHaveBeenCalled());
        expect(container).toBeEmptyDOMElement();
    });

    it('mostra o convite e avisa que as respostas são anônimas', async () => {
        render(<FeedbackCard />);

        expect(await screen.findByText('Como foi a XVI FETECMS para você?')).toBeInTheDocument();
        expect(screen.getByText(/respostas anônimas/)).toBeInTheDocument();
        expect(screen.getByText(/2 pergunta\(s\)/)).toBeInTheDocument();
    });

    // É o que o relatório do admin conta como "viram o convite".
    it('registra o visto uma vez quando o card entra na tela', async () => {
        render(<FeedbackCard />);

        await waitFor(() => expect(marcarFeedbackVisto).toHaveBeenCalledWith(3));
        expect(marcarFeedbackVisto).toHaveBeenCalledTimes(1);
    });

    it('abre o questionário com as alternativas e o limite da dissertativa', async () => {
        render(<FeedbackCard />);

        fireEvent.click(await screen.findByText('Responder'));

        expect(screen.getByRole('radiogroup', { name: 'Como você avalia a organização?' })).toBeInTheDocument();
        expect(screen.getByLabelText('Ótima')).toBeInTheDocument();
        expect(screen.getByText('Entre 2 e 50 palavras.')).toBeInTheDocument();
        // A opcional é marcada como tal.
        expect(screen.getByText('(opcional)')).toBeInTheDocument();
    });

    it('envia as respostas e o card some', async () => {
        render(<FeedbackCard />);

        fireEvent.click(await screen.findByText('Responder'));
        fireEvent.click(screen.getByLabelText('Ótima'));
        fireEvent.change(screen.getByLabelText('O que podemos melhorar?'), {
            target: { value: 'Mais tempo de apresentação.' },
        });
        fireEvent.click(screen.getByText('Enviar respostas'));

        await waitFor(() => expect(responderFeedback).toHaveBeenCalledWith(3, {
            10: 'Ótima',
            11: 'Mais tempo de apresentação.',
        }));
        await waitFor(() => expect(screen.queryByText('Como foi a XVI FETECMS para você?')).not.toBeInTheDocument());
    });

    it('mostra o erro de cada pergunta quando o backend recusa', async () => {
        responderFeedback.mockRejectedValue({
            response: {
                data: {
                    message: 'Confira as respostas.',
                    errors: { 'respostas.11': 'Escreva ao menos 2 palavras.' },
                },
            },
        });
        render(<FeedbackCard />);

        fireEvent.click(await screen.findByText('Responder'));
        fireEvent.click(screen.getByLabelText('Ótima'));
        fireEvent.click(screen.getByText('Enviar respostas'));

        expect(await screen.findByText('Escreva ao menos 2 palavras.')).toBeInTheDocument();
        // O card fica, para a pessoa corrigir.
        expect(screen.getByText('Como foi a XVI FETECMS para você?')).toBeInTheDocument();
    });

    it('dispensar tira o card da tela e avisa o servidor', async () => {
        render(<FeedbackCard />);

        fireEvent.click(await screen.findByText('Não quero responder'));

        await waitFor(() => expect(dispensarFeedback).toHaveBeenCalledWith(3));
        expect(screen.queryByText('Como foi a XVI FETECMS para você?')).not.toBeInTheDocument();
    });

    it('não consulta quando desativado', async () => {
        render(<FeedbackCard ativo={false} />);

        expect(getFeedbackPendente).not.toHaveBeenCalled();
    });
});
