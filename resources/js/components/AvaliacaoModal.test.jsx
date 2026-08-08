import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getAvaliacao = vi.fn();
const concluirAvaliacao = vi.fn();
const salvarRascunhoAvaliacao = vi.fn();
vi.mock('../lib/avaliacao.js', () => ({
    getAvaliacao: (...a) => getAvaliacao(...a),
    iniciarAvaliacao: vi.fn(),
    concluirAvaliacao: (...a) => concluirAvaliacao(...a),
    salvarRascunhoAvaliacao: (...a) => salvarRascunhoAvaliacao(...a),
    getMinhaAvaliacao: vi.fn(),
}));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([
        { id: 1, nome: 'Ciências Exatas e da Terra' },
        { id: 2, nome: 'Ciências Biológicas' },
    ])),
    loadSubareas: vi.fn(() => Promise.resolve([{ id: 5, nome: 'Botânica', area_id: 2 }])),
    criarSubarea: vi.fn(),
}));

import AvaliacaoModal from './AvaliacaoModal.jsx';

const PROJETO = {
    id: 10, titulo: 'Projeto X', resumo: 'Resumo', palavras_chave: [], alunos: [], documentos: [],
    area: 'Ciências Exatas e da Terra', area_id: 1, subarea: null, subarea_id: null,
};

const emAndamento = (extra = {}) => ({
    avaliacao: {
        id: 1, status: 'em_andamento', status_label: 'Em andamento',
        nota: null, nota_maxima: 30, nota_maxima_quesito: 10,
        nota_video: null, comentario_video: null,
        nota_resumo: null, comentario_resumo: null,
        nota_pesquisa: null, comentario_pesquisa: null,
        area_correta: null, area_sugerida_id: null, area_sugerida: null,
        subarea_correta: null, subarea_sugerida_id: null, subarea_sugerida: null,
        rascunho_em: null,
        ...extra,
    },
    projeto: PROJETO,
});

/** Radios "Sim/Não": índice 0 = área, índice 1 = subárea. */
const confirmar = (i) => fireEvent.click(screen.getAllByRole('radio', { name: /Sim, está correta/i })[i]);
const negar = (i) => fireEvent.click(screen.getAllByRole('radio', { name: /Não, está incorreta/i })[i]);

/** Preenche os três quesitos e confirma a área — o mínimo para enviar. */
function preencherMinimo(notas = [8, 7, 9]) {
    const campos = screen.getAllByLabelText(/Nota \(0 a 10\)/);
    notas.forEach((n, i) => fireEvent.change(campos[i], { target: { value: String(n) } }));
    confirmar(0);
}

describe('AvaliacaoModal — rubrica', () => {
    beforeEach(() => {
        getAvaliacao.mockReset();
        concluirAvaliacao.mockReset();
        salvarRascunhoAvaliacao.mockReset();
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

    it('só libera o envio com os três quesitos e a área conferidos, somando a nota', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        const botao = await screen.findByRole('button', { name: /Enviar avaliação/i });
        expect(botao).toBeDisabled();

        const [video, resumo, pesquisa] = screen.getAllByLabelText(/Nota \(0 a 10\)/);
        fireEvent.change(video, { target: { value: '8' } });
        fireEvent.change(resumo, { target: { value: '7' } });
        expect(botao).toBeDisabled(); // ainda falta um quesito

        fireEvent.change(pesquisa, { target: { value: '9' } });
        expect(botao).toBeDisabled(); // notas ok, mas falta conferir a área

        confirmar(0);
        expect(botao).toBeEnabled();
        expect(screen.getByText('24')).toBeInTheDocument(); // 8 + 7 + 9
        expect(screen.getByText(/de 30/)).toBeInTheDocument();
    });

    it('aceita nota 0 num quesito', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        await screen.findAllByLabelText(/Nota \(0 a 10\)/);
        preencherMinimo([0, 0, 0]);

        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeEnabled();
    });

    it('envia a rubrica com comentários e sem a nota final (calculada no servidor)', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        concluirAvaliacao.mockResolvedValue({
            ...emAndamento().avaliacao, status: 'concluida', status_label: 'Concluída', nota: 24,
            nota_video: 8, nota_resumo: 7, nota_pesquisa: 9, comentario_video: 'Áudio oscila',
        });
        render(<AvaliacaoModal avaliacaoId={1} teste={false} />);

        await screen.findAllByLabelText(/Nota \(0 a 10\)/);
        preencherMinimo();

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
            area_correta: true, area_sugerida_id: null,
            subarea_correta: null, subarea_sugerida_id: null,
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
                area_correta: true, area_sugerida: null,
                subarea_correta: null, subarea_sugerida: null,
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

describe('AvaliacaoModal — conferência da classificação', () => {
    beforeEach(() => {
        getAvaliacao.mockReset();
        concluirAvaliacao.mockReset();
        salvarRascunhoAvaliacao.mockReset();
        getAvaliacao.mockResolvedValue(emAndamento());
    });

    it('mostra a classificação atual do projeto e as duas perguntas', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);

        const area = await screen.findByText(/A área do conhecimento está correta\?/);
        const subarea = screen.getByText(/A subárea está correta\?/);

        expect(screen.getByText(/Área informada:/)).toHaveTextContent('Ciências Exatas e da Terra');
        expect(screen.getByText(/Subárea informada:/)).toHaveTextContent('—');
        // A área é obrigatória (asterisco); conferir a subárea é opcional.
        expect(area).toHaveTextContent('*');
        expect(area).not.toHaveTextContent('(opcional)');
        expect(subarea).toHaveTextContent('(opcional)');
    });

    it('pede a área correta só depois de marcada como incorreta, sem repetir a atual', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await screen.findByText('A área do conhecimento está correta?');

        expect(screen.queryByLabelText(/Área correta/)).not.toBeInTheDocument();

        negar(0);
        const select = await screen.findByLabelText(/Área correta/);
        // A área atual do projeto não aparece como sugestão possível.
        expect(select).not.toHaveTextContent('Ciências Exatas e da Terra');
        expect(select).toHaveTextContent('Ciências Biológicas');
    });

    it('não libera o envio enquanto a área sugerida não for escolhida', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await screen.findAllByLabelText(/Nota \(0 a 10\)/);

        const notas = screen.getAllByLabelText(/Nota \(0 a 10\)/);
        notas.forEach((n) => fireEvent.change(n, { target: { value: '5' } }));
        negar(0);

        const botao = screen.getByRole('button', { name: /Enviar avaliação/i });
        expect(botao).toBeDisabled();

        fireEvent.change(await screen.findByLabelText(/Área correta/), { target: { value: '2' } });
        expect(botao).toBeEnabled();
    });

    it('marcar a subárea como incorreta exige a sugestão', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await screen.findAllByLabelText(/Nota \(0 a 10\)/);
        preencherMinimo();

        const botao = screen.getByRole('button', { name: /Enviar avaliação/i });
        expect(botao).toBeEnabled(); // subárea é opcional

        negar(1);
        expect(botao).toBeDisabled(); // agora precisa sugerir
        expect(await screen.findByText('Subárea correta')).toBeInTheDocument();
    });

    it('envia a área sugerida no payload', async () => {
        concluirAvaliacao.mockResolvedValue({ ...emAndamento().avaliacao, status: 'concluida', nota: 15 });
        render(<AvaliacaoModal avaliacaoId={1} />);
        await screen.findAllByLabelText(/Nota \(0 a 10\)/);

        screen.getAllByLabelText(/Nota \(0 a 10\)/)
            .forEach((n) => fireEvent.change(n, { target: { value: '5' } }));
        negar(0);
        fireEvent.change(await screen.findByLabelText(/Área correta/), { target: { value: '2' } });

        fireEvent.click(screen.getByRole('button', { name: /Enviar avaliação/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Enviar$/i }));

        await waitFor(() => expect(concluirAvaliacao).toHaveBeenCalled());
        const [, enviado] = concluirAvaliacao.mock.calls[0];
        expect(enviado.area_correta).toBe(false);
        expect(enviado.area_sugerida_id).toBe(2);
    });
});

describe('AvaliacaoModal — rascunho', () => {
    beforeEach(() => {
        getAvaliacao.mockReset();
        concluirAvaliacao.mockReset();
        salvarRascunhoAvaliacao.mockReset();
    });

    it('salva o preenchimento parcial sem exigir nada', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        salvarRascunhoAvaliacao.mockResolvedValue({ ...emAndamento().avaliacao, nota_video: 6 });
        render(<AvaliacaoModal avaliacaoId={1} />);

        const notas = await screen.findAllByLabelText(/Nota \(0 a 10\)/);
        fireEvent.change(notas[0], { target: { value: '6' } });

        // Enviar continua bloqueado, mas salvar rascunho não.
        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeDisabled();
        const rascunho = screen.getByRole('button', { name: /Salvar rascunho/i });
        expect(rascunho).toBeEnabled();

        fireEvent.click(rascunho);
        await waitFor(() => expect(salvarRascunhoAvaliacao).toHaveBeenCalled());
        const [, enviado] = salvarRascunhoAvaliacao.mock.calls[0];
        expect(enviado.nota_video).toBe(6);
        expect(enviado.nota_resumo).toBeNull();
        expect(await screen.findByText(/Rascunho salvo/)).toBeInTheDocument();
    });

    it('recupera o rascunho salvo ao reabrir', async () => {
        getAvaliacao.mockResolvedValue(emAndamento({
            nota_video: 6, comentario_video: 'Faltou legenda.',
            area_correta: false, area_sugerida_id: 2,
            rascunho_em: '2026-08-08T12:00:00-04:00',
        }));
        render(<AvaliacaoModal avaliacaoId={1} />);

        const notas = await screen.findAllByLabelText(/Nota \(0 a 10\)/);
        expect(notas[0]).toHaveValue('6');
        expect(screen.getAllByLabelText(/Sugestões e comentários/)[0]).toHaveValue('Faltou legenda.');
        // A resposta "área incorreta" volta marcada, com o select da sugestão aberto.
        expect(screen.getAllByRole('radio', { name: /Não, está incorreta/i })[0]).toBeChecked();
        expect(await screen.findByLabelText(/Área correta/)).toHaveValue('2');
    });

    it('não mostra o botão de rascunho depois de enviada', async () => {
        getAvaliacao.mockResolvedValue({
            avaliacao: {
                ...emAndamento().avaliacao,
                status: 'concluida', status_label: 'Concluída', nota: 24,
                nota_video: 8, nota_resumo: 7, nota_pesquisa: 9, area_correta: true,
            },
            projeto: PROJETO,
        });
        render(<AvaliacaoModal avaliacaoId={1} />);

        await screen.findByText('Avaliação enviada');
        expect(screen.queryByRole('button', { name: /Salvar rascunho/i })).not.toBeInTheDocument();
    });
});
