import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useParams: () => ({ id: '3' }),
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? '', fields: {} }),
}));

const getFeedback = vi.fn();
const getDestinatariosFeedback = vi.fn();
const reenviarFalhasFeedback = vi.fn();
const encerrarFeedback = vi.fn();
const exportarFeedback = vi.fn();
vi.mock('../lib/feedback.js', () => ({
    getFeedback: (...a) => getFeedback(...a),
    getDestinatariosFeedback: (...a) => getDestinatariosFeedback(...a),
    reenviarFalhasFeedback: (...a) => reenviarFalhasFeedback(...a),
    encerrarFeedback: (...a) => encerrarFeedback(...a),
    exportarFeedback: (...a) => exportarFeedback(...a),
}));

import AdminFeedbackDetalhe from './AdminFeedbackDetalhe.jsx';

const detalhe = (over = {}) => ({
    id: 3,
    titulo: 'Como foi a XVI FETECMS para você?',
    descricao: null,
    status: 'ativo',
    status_label: 'No ar',
    publicos: ['Todos os orientadores'],
    criado_em: '30/08/2026 10:00',
    encerrado_em: null,
    resumo: {
        convidados: 10, viram: 8, dispensaram: 2, responderam: 6, taxa_resposta: 60,
        envio: { total: 10, pendente: 0, enviado: 9, falha: 1, invalido: 0, processados: 10 },
    },
    perguntas: [
        {
            id: 10, tipo: 'alternativa', enunciado: 'Como você avalia a organização?', respostas: 6,
            opcoes: [
                { opcao: 'Ruim', total: 0, percentual: 0 },
                { opcao: 'Boa', total: 2, percentual: 33.3 },
                { opcao: 'Ótima', total: 4, percentual: 66.7 },
            ],
        },
        {
            id: 11, tipo: 'dissertativa', enunciado: 'O que podemos melhorar?', respostas: 2,
            textos: ['Mais tempo de apresentação.', 'Melhorar o café.'],
        },
    ],
    ...over,
});

const destinatarios = {
    data: [
        { id: 1, nome: 'Ana', email: 'ana@x.test', status: 'enviado', status_label: 'Enviado', erro: null, enviado_em: '30/08/2026 10:01' },
        { id: 2, nome: 'Beto', email: 'beto@x.test', status: 'falha', status_label: 'Falha no envio', erro: 'Caixa cheia.', enviado_em: null },
    ],
    meta: { total: 2, pagina: 1, ultima_pagina: 1 },
};

describe('AdminFeedbackDetalhe', () => {
    beforeEach(() => {
        getFeedback.mockReset().mockResolvedValue(detalhe());
        getDestinatariosFeedback.mockReset().mockResolvedValue(destinatarios);
        reenviarFalhasFeedback.mockReset();
        encerrarFeedback.mockReset();
        exportarFeedback.mockReset();
    });

    it('mostra os números do pedido', async () => {
        render(<AdminFeedbackDetalhe />);

        expect(await screen.findByText('Convidados')).toBeInTheDocument();
        expect(screen.getByText('10')).toBeInTheDocument();
        expect(screen.getByText('Responderam')).toBeInTheDocument();
        expect(screen.getByText('60%')).toBeInTheDocument();
    });

    // Um gráfico sem a opção zerada esconde justamente que ela não convenceu.
    it('lista todas as alternativas, inclusive as que ninguém marcou', async () => {
        render(<AdminFeedbackDetalhe />);

        expect(await screen.findByText('Ruim')).toBeInTheDocument();
        expect(screen.getByText('0 · 0%')).toBeInTheDocument();
        expect(screen.getByText('4 · 66.7%')).toBeInTheDocument();
    });

    it('mostra as respostas escritas, sem autor', async () => {
        render(<AdminFeedbackDetalhe />);

        expect(await screen.findByText('Mais tempo de apresentação.')).toBeInTheDocument();
        expect(screen.getByText('Melhorar o café.')).toBeInTheDocument();
        expect(screen.getByText(/não há como saber quem escreveu o quê/)).toBeInTheDocument();
    });

    it('traz o relatório de envio e reenvia as falhas', async () => {
        reenviarFalhasFeedback.mockResolvedValue({ meta: { message: '1 convite(s) reenviado(s).' } });
        render(<AdminFeedbackDetalhe />);

        expect(await screen.findByText('Caixa cheia.')).toBeInTheDocument();
        expect(screen.getByText(/9 enviado\(s\) · 1 falha\(s\)/)).toBeInTheDocument();

        fireEvent.click(screen.getByText('Reenviar as falhas'));

        await waitFor(() => expect(reenviarFalhasFeedback).toHaveBeenCalledWith('3'));
        expect(await screen.findByText('1 convite(s) reenviado(s).')).toBeInTheDocument();
    });

    it('filtra o relatório por situação', async () => {
        render(<AdminFeedbackDetalhe />);

        fireEvent.change(await screen.findByLabelText('Filtrar por situação do envio'), {
            target: { value: 'falha' },
        });

        await waitFor(() => expect(getDestinatariosFeedback).toHaveBeenLastCalledWith('3', 'falha'));
    });

    it('encerra depois de confirmar', async () => {
        encerrarFeedback.mockResolvedValue({ meta: { message: 'Feedback encerrado.' } });
        render(<AdminFeedbackDetalhe />);

        fireEvent.click(await screen.findByText('Encerrar'));
        // O diálogo de confirmação repete o rótulo; o segundo é o dele.
        fireEvent.click(screen.getAllByText('Encerrar')[1]);

        await waitFor(() => expect(encerrarFeedback).toHaveBeenCalledWith('3'));
    });

    it('um pedido encerrado não oferece o botão de encerrar', async () => {
        getFeedback.mockResolvedValue(detalhe({
            status: 'encerrado', status_label: 'Encerrado', encerrado_em: '31/08/2026 09:00',
        }));
        render(<AdminFeedbackDetalhe />);

        expect(await screen.findByText('Encerrado')).toBeInTheDocument();
        expect(screen.queryByText('Encerrar')).not.toBeInTheDocument();
    });

    it('exporta as respostas em CSV', async () => {
        render(<AdminFeedbackDetalhe />);

        fireEvent.click(await screen.findByText('Exportar respostas (CSV)'));

        expect(exportarFeedback).toHaveBeenCalledWith('3');
    });
});
