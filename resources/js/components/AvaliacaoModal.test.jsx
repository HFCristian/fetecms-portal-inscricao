import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
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

// Escala Likert como a API a entrega.
const ESCALA = [
    { valor: 1, rotulo: 'Muito insatisfeito' },
    { valor: 2, rotulo: 'Insatisfeito' },
    { valor: 3, rotulo: 'Neutro' },
    { valor: 4, rotulo: 'Satisfeito' },
    { valor: 5, rotulo: 'Muito satisfeito' },
];

const PROJETO = {
    id: 10, titulo: 'Projeto X', resumo: 'Resumo', palavras_chave: [], alunos: [], documentos: [],
    area: 'Ciências Exatas e da Terra', area_id: 1, subarea: null, subarea_id: null,
    continuacao: false, tempo_pesquisa_meses: null, avalia_continuidade: false,
};

const PROJETO_COM_VIDEO = { ...PROJETO, link_video: 'https://www.youtube.com/watch?v=abcdefghijk' };

// Projeto de continuação com o documento anexado: rende o quarto quesito.
const PROJETO_CONTINUACAO = {
    ...PROJETO, continuacao: true, tempo_pesquisa_meses: 18, avalia_continuidade: true,
};

const emAndamento = (extra = {}, projeto = PROJETO) => ({
    avaliacao: {
        id: 1, status: 'em_andamento', status_label: 'Em andamento',
        nota: null, nota_maxima: 15, nota_minima_quesito: 1, nota_maxima_quesito: 5, escala: ESCALA,
        nota_video: null, comentario_video: null,
        nota_resumo: null, comentario_resumo: null,
        nota_pesquisa: null, comentario_pesquisa: null,
        nota_continuidade: null, comentario_continuidade: null,
        area_correta: null, area_sugerida_id: null, area_sugerida: null,
        subarea_correta: null, subarea_sugerida_id: null, subarea_sugerida: null,
        rascunho_em: null,
        ...extra,
    },
    projeto,
});

/** Radios "Sim/Não": índice 0 = área, índice 1 = subárea. */
const confirmar = (i) => fireEvent.click(screen.getAllByRole('radio', { name: /Sim, está correta/i })[i]);
const negar = (i) => fireEvent.click(screen.getAllByRole('radio', { name: /Não, está incorreta/i })[i]);

/** Uma escala Likert por quesito, na ordem da rubrica. */
const escalas = () => screen.getAllByRole('radiogroup');
const acharEscalas = () => screen.findAllByRole('radiogroup');

/** Marca um ponto da escala (1 a 5) no quesito da posição indicada. */
function pontuar(indice, valor) {
    const rotulo = ESCALA.find((p) => p.valor === valor).rotulo;
    fireEvent.click(within(escalas()[indice]).getByRole('radio', { name: `${valor} — ${rotulo}` }));
}

/** Pontua os três quesitos e confirma a área — o mínimo para enviar. */
function preencherMinimo(notas = [4, 3, 5]) {
    notas.forEach((n, i) => pontuar(i, n));
    confirmar(0);
}

describe('AvaliacaoModal — rubrica', () => {
    beforeEach(() => {
        getAvaliacao.mockReset();
        concluirAvaliacao.mockReset();
        salvarRascunhoAvaliacao.mockReset();
    });

    it('mostra os três quesitos com a escala Likert e campo de comentários', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        expect(await screen.findByText('Vídeo de apresentação')).toBeInTheDocument();
        expect(screen.getByText('Resumo do projeto')).toBeInTheDocument();
        expect(screen.getByText('Projeto de pesquisa')).toBeInTheDocument();
        // Uma escala de 5 pontos e um campo de comentário por quesito.
        expect(escalas()).toHaveLength(3);
        expect(within(escalas()[0]).getAllByRole('radio')).toHaveLength(5);
        expect(screen.getAllByLabelText(/Sugestões e comentários/)).toHaveLength(3);
        expect(within(escalas()[0]).getByRole('radio', { name: '1 — Muito insatisfeito' })).toBeInTheDocument();
        expect(within(escalas()[0]).getByRole('radio', { name: '5 — Muito satisfeito' })).toBeInTheDocument();
    });

    it('só libera o envio com os três quesitos e a área conferidos, somando a nota', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        const botao = await screen.findByRole('button', { name: /Enviar avaliação/i });
        expect(botao).toBeDisabled();

        pontuar(0, 4);
        pontuar(1, 3);
        expect(botao).toBeDisabled(); // ainda falta um quesito

        pontuar(2, 5);
        expect(botao).toBeDisabled(); // notas ok, mas falta conferir a área

        confirmar(0);
        expect(botao).toBeEnabled();
        expect(screen.getByText('12')).toBeInTheDocument(); // 4 + 3 + 5
        expect(screen.getByText(/de 15/)).toBeInTheDocument();
    });

    it('aceita os extremos da escala', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        await acharEscalas();
        preencherMinimo([1, 1, 5]);

        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeEnabled();
        expect(screen.getByText('7')).toBeInTheDocument();
    });

    it('envia a rubrica com comentários e sem a nota final (calculada no servidor)', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        concluirAvaliacao.mockResolvedValue({
            ...emAndamento().avaliacao, status: 'concluida', status_label: 'Concluída', nota: 12,
            nota_video: 4, nota_resumo: 3, nota_pesquisa: 5, comentario_video: 'Áudio oscila',
        });
        render(<AvaliacaoModal avaliacaoId={1} teste={false} />);

        await acharEscalas();
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
            nota_video: 4, comentario_video: 'Áudio oscila',
            nota_resumo: 3, comentario_resumo: null,
            nota_pesquisa: 5, comentario_pesquisa: null,
            area_correta: true, area_sugerida_id: null,
            subarea_correta: null, subarea_sugerida_id: null,
        });
        expect(rubrica).not.toHaveProperty('nota');
        // Projeto comum não manda o quesito de continuação.
        expect(rubrica).not.toHaveProperty('nota_continuidade');
    });

    it('mostra a rubrica em leitura quando a avaliação já foi concluída', async () => {
        getAvaliacao.mockResolvedValue({
            avaliacao: {
                ...emAndamento().avaliacao,
                status: 'concluida', status_label: 'Concluída', nota: 12,
                nota_video: 4, comentario_video: 'Áudio oscila',
                nota_resumo: 3, comentario_resumo: null,
                nota_pesquisa: 5, comentario_pesquisa: null,
                area_correta: true, area_sugerida: null,
                subarea_correta: null, subarea_sugerida: null,
            },
            projeto: PROJETO,
        });
        render(<AvaliacaoModal avaliacaoId={1} />);

        expect(await screen.findByText('Avaliação enviada')).toBeInTheDocument();
        expect(screen.getByText('4/5')).toBeInTheDocument();
        expect(screen.getByText('Satisfeito')).toBeInTheDocument(); // rótulo do ponto marcado
        expect(screen.getByText('Áudio oscila')).toBeInTheDocument();
        expect(screen.getByText('12 de 15')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Enviar avaliação/i })).not.toBeInTheDocument();
    });
});

describe('AvaliacaoModal — vídeo do projeto', () => {
    beforeEach(() => {
        getAvaliacao.mockReset();
    });

    it('embute o player abaixo do link, sem exigir que o avaliador o abra', async () => {
        getAvaliacao.mockResolvedValue(emAndamento({}, PROJETO_COM_VIDEO));
        render(<AvaliacaoModal avaliacaoId={1} />);

        const player = await screen.findByTitle('Pré-visualização do vídeo');
        expect(player).toHaveAttribute('src', 'https://www.youtube.com/embed/abcdefghijk');
        // O link continua disponível para quem preferir abrir fora.
        expect(screen.getByRole('link', { name: PROJETO_COM_VIDEO.link_video })).toBeInTheDocument();
    });

    it('não mostra player quando o projeto não tem link', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        await acharEscalas();
        expect(screen.queryByTitle('Pré-visualização do vídeo')).not.toBeInTheDocument();
    });
});

describe('AvaliacaoModal — projeto de continuação', () => {
    beforeEach(() => {
        getAvaliacao.mockReset();
        concluirAvaliacao.mockReset();
    });

    it('acrescenta o quarto quesito e exige a nota dele', async () => {
        getAvaliacao.mockResolvedValue(emAndamento({}, PROJETO_CONTINUACAO));
        render(<AvaliacaoModal avaliacaoId={1} />);

        // O quesito aparece na rubrica, além do dado no resumo do projeto.
        expect(await screen.findByRole('heading', { name: 'Projeto de continuação' })).toBeInTheDocument();
        expect(escalas()).toHaveLength(4);
        expect(screen.getByText(/18 meses de pesquisa/)).toBeInTheDocument();

        const botao = screen.getByRole('button', { name: /Enviar avaliação/i });
        preencherMinimo();
        expect(botao).toBeDisabled(); // falta pontuar a continuação

        pontuar(3, 4);
        expect(botao).toBeEnabled();
    });

    it('usa a média entre pesquisa e continuação na nota final', async () => {
        getAvaliacao.mockResolvedValue(emAndamento({}, PROJETO_CONTINUACAO));
        render(<AvaliacaoModal avaliacaoId={1} />);

        await acharEscalas();
        preencherMinimo([4, 3, 5]);
        pontuar(3, 4);

        // 4 + 3 + média(5, 4) = 11,5 — o teto continua 15.
        expect(screen.getByText('11,5')).toBeInTheDocument();
        expect(screen.getByText(/de 15/)).toBeInTheDocument();
    });

    it('envia a nota da continuação no payload', async () => {
        getAvaliacao.mockResolvedValue(emAndamento({}, PROJETO_CONTINUACAO));
        concluirAvaliacao.mockResolvedValue({
            ...emAndamento().avaliacao, status: 'concluida', nota: 11.5, nota_continuidade: 4,
        });
        render(<AvaliacaoModal avaliacaoId={1} />);

        await acharEscalas();
        preencherMinimo();
        pontuar(3, 4);

        fireEvent.click(screen.getByRole('button', { name: /Enviar avaliação/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Enviar$/i }));

        await waitFor(() => expect(concluirAvaliacao).toHaveBeenCalled());
        const [, enviado] = concluirAvaliacao.mock.calls[0];
        expect(enviado.nota_continuidade).toBe(4);
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
        await acharEscalas();

        [0, 1, 2].forEach((i) => pontuar(i, 3));
        negar(0);

        const botao = screen.getByRole('button', { name: /Enviar avaliação/i });
        expect(botao).toBeDisabled();

        fireEvent.change(await screen.findByLabelText(/Área correta/), { target: { value: '2' } });
        expect(botao).toBeEnabled();
    });

    it('marcar a subárea como incorreta exige a sugestão', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await acharEscalas();
        preencherMinimo();

        const botao = screen.getByRole('button', { name: /Enviar avaliação/i });
        expect(botao).toBeEnabled(); // subárea é opcional

        negar(1);
        expect(botao).toBeDisabled(); // agora precisa sugerir
        expect(await screen.findByText('Subárea correta')).toBeInTheDocument();
    });

    it('envia a área sugerida no payload', async () => {
        concluirAvaliacao.mockResolvedValue({ ...emAndamento().avaliacao, status: 'concluida', nota: 9 });
        render(<AvaliacaoModal avaliacaoId={1} />);
        await acharEscalas();

        [0, 1, 2].forEach((i) => pontuar(i, 3));
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
        salvarRascunhoAvaliacao.mockResolvedValue({ ...emAndamento().avaliacao, nota_video: 4 });
        render(<AvaliacaoModal avaliacaoId={1} />);

        await acharEscalas();
        pontuar(0, 4);

        // Enviar continua bloqueado, mas salvar rascunho não.
        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeDisabled();
        const rascunho = screen.getByRole('button', { name: /Salvar rascunho/i });
        expect(rascunho).toBeEnabled();

        fireEvent.click(rascunho);
        await waitFor(() => expect(salvarRascunhoAvaliacao).toHaveBeenCalled());
        const [, enviado] = salvarRascunhoAvaliacao.mock.calls[0];
        expect(enviado.nota_video).toBe(4);
        expect(enviado.nota_resumo).toBeNull();
        expect(await screen.findByText(/Rascunho salvo/)).toBeInTheDocument();
    });

    it('recupera o rascunho salvo ao reabrir', async () => {
        getAvaliacao.mockResolvedValue(emAndamento({
            nota_video: 4, comentario_video: 'Faltou legenda.',
            area_correta: false, area_sugerida_id: 2,
            rascunho_em: '2026-08-08T12:00:00-04:00',
        }));
        render(<AvaliacaoModal avaliacaoId={1} />);

        const grupos = await acharEscalas();
        expect(within(grupos[0]).getByRole('radio', { name: '4 — Satisfeito' })).toBeChecked();
        expect(screen.getAllByLabelText(/Sugestões e comentários/)[0]).toHaveValue('Faltou legenda.');
        // A resposta "área incorreta" volta marcada, com o select da sugestão aberto.
        expect(screen.getAllByRole('radio', { name: /Não, está incorreta/i })[0]).toBeChecked();
        expect(await screen.findByLabelText(/Área correta/)).toHaveValue('2');
    });

    it('não mostra o botão de rascunho depois de enviada', async () => {
        getAvaliacao.mockResolvedValue({
            avaliacao: {
                ...emAndamento().avaliacao,
                status: 'concluida', status_label: 'Concluída', nota: 12,
                nota_video: 4, nota_resumo: 3, nota_pesquisa: 5, area_correta: true,
            },
            projeto: PROJETO,
        });
        render(<AvaliacaoModal avaliacaoId={1} />);

        await screen.findByText('Avaliação enviada');
        expect(screen.queryByRole('button', { name: /Salvar rascunho/i })).not.toBeInTheDocument();
    });
});
