import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getAvaliacao = vi.fn();
const concluirAvaliacao = vi.fn();
vi.mock('../lib/avaliacao.js', () => ({
    getAvaliacao: (...a) => getAvaliacao(...a),
    iniciarAvaliacao: vi.fn(),
    concluirAvaliacao: (...a) => concluirAvaliacao(...a),
    getMinhaAvaliacao: vi.fn(),
}));

import AvaliacaoModal from './AvaliacaoModal.jsx';

const PROJETO = {
    id: 10, titulo: 'Projeto X', resumo: 'Resumo', palavras_chave: [], alunos: [], documentos: [],
};

const emAndamento = (extra = {}) => ({
    avaliacao: {
        id: 1, status: 'em_andamento', status_label: 'Em andamento',
        nota: null, nota_maxima: 30, nota_maxima_quesito: 10,
        nota_video: null, comentario_video: null,
        nota_resumo: null, comentario_resumo: null,
        nota_pesquisa: null, comentario_pesquisa: null,
        ...extra,
    },
    projeto: PROJETO,
});

describe('AvaliacaoModal — rubrica', () => {
    beforeEach(() => {
        getAvaliacao.mockReset();
        concluirAvaliacao.mockReset();
    });

    it('mostra os três quesitos com nota e campo de comentários', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        expect(await screen.findByText('Vídeo de apresentação')).toBeInTheDocument();
        expect(screen.getByText('Resumo do projeto')).toBeInTheDocument();
        expect(screen.getByText('Projeto de pesquisa')).toBeInTheDocument();
        // Um seletor de nota e um campo de comentário por quesito.
        expect(screen.getAllByLabelText(/Nota \(0 a 10\)/)).toHaveLength(3);
        expect(screen.getAllByLabelText(/Sugestões e comentários/)).toHaveLength(3);
    });

    it('só libera o envio com os três quesitos avaliados e soma a nota final', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        const botao = await screen.findByRole('button', { name: /Enviar avaliação/i });
        expect(botao).toBeDisabled();
        expect(screen.getByText(/Dê nota aos três quesitos para enviar/)).toBeInTheDocument();

        const [video, resumo, pesquisa] = screen.getAllByLabelText(/Nota \(0 a 10\)/);
        fireEvent.change(video, { target: { value: '8' } });
        fireEvent.change(resumo, { target: { value: '7' } });
        expect(botao).toBeDisabled(); // ainda falta um

        fireEvent.change(pesquisa, { target: { value: '9' } });
        expect(botao).toBeEnabled();
        expect(screen.getByText('24')).toBeInTheDocument(); // 8 + 7 + 9
        expect(screen.getByText(/de 30/)).toBeInTheDocument();
    });

    it('aceita nota 0 num quesito', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        const notas = await screen.findAllByLabelText(/Nota \(0 a 10\)/);
        notas.forEach((n) => fireEvent.change(n, { target: { value: '0' } }));

        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeEnabled();
    });

    it('envia a rubrica com comentários e sem a nota final (calculada no servidor)', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        concluirAvaliacao.mockResolvedValue({
            ...emAndamento().avaliacao, status: 'concluida', status_label: 'Concluída', nota: 24,
            nota_video: 8, nota_resumo: 7, nota_pesquisa: 9, comentario_video: 'Áudio oscila',
        });
        render(<AvaliacaoModal avaliacaoId={1} teste={false} />);

        const notas = await screen.findAllByLabelText(/Nota \(0 a 10\)/);
        fireEvent.change(notas[0], { target: { value: '8' } });
        fireEvent.change(notas[1], { target: { value: '7' } });
        fireEvent.change(notas[2], { target: { value: '9' } });

        const comentarios = screen.getAllByLabelText(/Sugestões e comentários/);
        fireEvent.change(comentarios[0], { target: { value: 'Áudio oscila' } });
        fireEvent.change(comentarios[1], { target: { value: '   ' } }); // só espaços -> null

        fireEvent.click(screen.getByRole('button', { name: /Enviar avaliação/i }));
        // Confirmação antes de enviar (envio é irreversível).
        fireEvent.click(await screen.findByRole('button', { name: /^Enviar$/i }));

        await waitFor(() => expect(concluirAvaliacao).toHaveBeenCalled());
        const [, rubrica] = concluirAvaliacao.mock.calls[0];
        expect(rubrica).toEqual({
            nota_video: 8, comentario_video: 'Áudio oscila',
            nota_resumo: 7, comentario_resumo: null,
            nota_pesquisa: 9, comentario_pesquisa: null,
        });
        expect(rubrica).not.toHaveProperty('nota');
    });

    it('mostra a rubrica em leitura quando a avaliação já foi concluída', async () => {
        getAvaliacao.mockResolvedValue({
            avaliacao: {
                id: 1, status: 'concluida', status_label: 'Concluída',
                nota: 24, nota_maxima: 30, nota_maxima_quesito: 10,
                nota_video: 8, comentario_video: 'Áudio oscila',
                nota_resumo: 7, comentario_resumo: null,
                nota_pesquisa: 9, comentario_pesquisa: null,
            },
            projeto: PROJETO,
        });
        render(<AvaliacaoModal avaliacaoId={1} />);

        expect(await screen.findByText('Avaliação enviada')).toBeInTheDocument();
        expect(screen.getByText('8/10')).toBeInTheDocument();
        expect(screen.getByText('Áudio oscila')).toBeInTheDocument();
        expect(screen.getByText('24 de 30')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Enviar avaliação/i })).not.toBeInTheDocument();
    });
});
