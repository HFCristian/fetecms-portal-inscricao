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

// Escala do documento, como a API a entrega.
const ESCALA = [
    { valor: 0, rotulo: 'Não possui' },
    { valor: 2, rotulo: 'Muito ruim' },
    { valor: 4, rotulo: 'Ruim' },
    { valor: 6, rotulo: 'Regular' },
    { valor: 8, rotulo: 'Bom' },
    { valor: 10, rotulo: 'Muito bom' },
];

/**
 * Recorte da rubrica com a mesma forma que o backend manda: a conferência da
 * classificação, uma pergunta de Sim/Não, uma seção com escala + recomendação
 * e o passo final descritivo. Os pesos daqui fecham 2,15.
 */
const RUBRICA = {
    nota_maxima: 2.15,
    escala: ESCALA,
    secoes: [
        {
            chave: 'geral_inicio', titulo: 'Geral — início', icone: 'category',
            componente: 'classificacao', maximo: 0, perguntas: [],
            ajuda: 'Avalie a escolha da área e, caso necessário, faça a sugestão de adequação.',
        },
        {
            chave: 'titulo', titulo: 'Título', icone: 'title', componente: 'perguntas', maximo: 0.15,
            perguntas: [{
                chave: 'titulo_coerente', rotulo: 'coerência do título', tipo: 'sim_nao', peso: 0.15,
                texto: 'O título do projeto é coerente ao trabalho descrito?',
                ajuda: 'Avalie o título do projeto, levando em consideração sua adequação.',
            }],
        },
        {
            chave: 'video', titulo: 'Vídeo', icone: 'movie', componente: 'perguntas', maximo: 2,
            comentario: {
                campo: 'comentario_video',
                label: 'Recomendações, dicas e comentários sobre o vídeo',
                placeholder: 'O que a equipe pode melhorar na apresentação em vídeo?',
            },
            perguntas: [
                {
                    chave: 'video_engajamento', rotulo: 'engajamento no vídeo', tipo: 'escala', peso: 1,
                    texto: 'De que modo o vídeo expressa o engajamento dos integrantes?',
                    ajuda: 'Avaliar clareza e criatividade na apresentação.',
                },
                {
                    chave: 'video_dominio', rotulo: 'domínio do tema no vídeo', tipo: 'escala', peso: 1,
                    texto: 'A partir do vídeo, de que modo a equipe demonstra domínio do tema?',
                    ajuda: null, // o documento ainda não traz a orientação desta
                },
            ],
        },
        {
            chave: 'final', titulo: 'Final', icone: 'rate_review', componente: 'comentarios', maximo: 0,
            perguntas: [],
            ajuda: 'Considere os pontos fortes do projeto.',
            comentario: {
                campo: 'comentario_projeto',
                label: 'De modo geral, para o projeto, faça recomendações, dicas, sugestões, comentários etc',
                placeholder: 'Pontos fortes e melhorias possíveis.',
            },
        },
    ],
};

const PROJETO = {
    id: 10, titulo: 'Projeto X', resumo: 'Uma síntese do projeto.', palavras_chave: [], alunos: [], documentos: [],
    area: 'Ciências Exatas e da Terra', area_id: 1, subarea: null, subarea_id: null,
    continuacao: false, tempo_pesquisa_meses: null,
};

const PROJETO_COM_VIDEO = { ...PROJETO, link_video: 'https://www.youtube.com/watch?v=abcdefghijk' };

const emAndamento = (extra = {}, projeto = PROJETO) => ({
    avaliacao: {
        id: 1, status: 'em_andamento', status_label: 'Em andamento',
        nota: null, nota_maxima: 2.15,
        respostas: {},
        comentario_video: null, comentario_projeto: null,
        area_correta: null, area_sugerida_id: null, area_sugerida: null,
        subarea_correta: null, subarea_sugerida_id: null, subarea_sugerida: null,
        rascunho_em: null,
        ...extra,
    },
    projeto,
    rubrica: RUBRICA,
});

/** Radios "Sim/Não" da classificação: índice 0 = área, índice 1 = subárea. */
const confirmar = (i) => fireEvent.click(screen.getAllByRole('radio', { name: /Sim, está correta/i })[i]);
const negar = (i) => fireEvent.click(screen.getAllByRole('radio', { name: /Não, está incorreta/i })[i]);

/** Vai para uma seção pelo passo a passo do topo. */
const irPara = (titulo) => fireEvent.click(screen.getByRole('button', { name: `Ir para ${titulo}` }));

const esperarWizard = () => screen.findByRole('button', { name: 'Ir para Título' });

/** Marca um ponto da escala na pergunta da posição indicada dentro da seção. */
function pontuar(indice, valor) {
    const escala = screen.getAllByRole('radiogroup')[indice];
    const rotulo = ESCALA.find((p) => p.valor === valor).rotulo;
    fireEvent.click(within(escala).getByRole('radio', { name: `${rotulo} (${valor})` }));
}

/** Responde tudo o que é obrigatório: área conferida, título e as duas do vídeo. */
function preencherTudo(video = [10, 10]) {
    irPara('Geral — início');
    confirmar(0);
    irPara('Título');
    fireEvent.click(screen.getByRole('radio', { name: 'Sim' }));
    irPara('Vídeo');
    video.forEach((v, i) => pontuar(i, v));
}

describe('AvaliacaoModal — wizard da rubrica', () => {
    beforeEach(() => {
        getAvaliacao.mockReset();
        concluirAvaliacao.mockReset();
        salvarRascunhoAvaliacao.mockReset();
        getAvaliacao.mockResolvedValue(emAndamento());
    });

    it('abre na conferência da classificação, com um passo por seção da rubrica', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);

        await esperarWizard();
        expect(screen.getByText('Passo 1 de 4')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Geral — início' })).toBeInTheDocument();
        expect(screen.getByText('A área do conhecimento está correta?')).toBeInTheDocument();
        // O passo a passo lista todas as seções, mesmo as que ainda não foram abertas.
        RUBRICA.secoes.forEach((s) => {
            expect(screen.getByRole('button', { name: `Ir para ${s.titulo}` })).toBeInTheDocument();
        });
    });

    it('avança e volta entre as seções, mostrando só a atual', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();

        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));
        expect(screen.getByText('Passo 2 de 4')).toBeInTheDocument();
        expect(screen.getByText('O título do projeto é coerente ao trabalho descrito?')).toBeInTheDocument();
        expect(screen.queryByText('A área do conhecimento está correta?')).not.toBeInTheDocument();
        // Cada seção mostra quanto vale da nota final.
        expect(screen.getByText('0,15')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Voltar' }));
        expect(screen.getByText('A área do conhecimento está correta?')).toBeInTheDocument();
    });

    it('pula direto para uma seção pelo passo a passo', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();

        irPara('Vídeo');
        expect(screen.getByText('Passo 3 de 4')).toBeInTheDocument();
        expect(screen.getAllByRole('radiogroup')).toHaveLength(2);
        expect(within(screen.getAllByRole('radiogroup')[0]).getAllByRole('radio')).toHaveLength(6);
    });

    it('abre as orientações da pergunta no balão de dúvida', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();
        irPara('Título');

        const orientacao = 'Avalie o título do projeto, levando em consideração sua adequação.';
        expect(screen.queryByText(orientacao)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Orientações para o avaliador' }));
        expect(screen.getByText(orientacao)).toBeInTheDocument();
    });

    it('não desenha o balão na pergunta sem orientação no documento', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();
        irPara('Vídeo');

        // Duas perguntas na seção, mas só uma tem orientação.
        expect(screen.getAllByRole('button', { name: 'Orientações para o avaliador' })).toHaveLength(1);
    });

    it('acompanha a nota parcial e o quanto já foi respondido', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();

        expect(screen.getByText(/0 de 3 perguntas respondidas/)).toBeInTheDocument();

        preencherTudo([6, 6]);
        // 0,15 (Sim) + 60% de 1 + 60% de 1 = 1,35.
        expect(screen.getByText('1,35')).toBeInTheDocument();
        expect(screen.getByText('de 2,15')).toBeInTheDocument();
        expect(screen.getByText(/3 de 3 perguntas respondidas/)).toBeInTheDocument();
    });

    it('só oferece o envio no último passo, e só com tudo respondido', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();

        // Nos passos do meio, o botão principal é o de avançar.
        expect(screen.queryByRole('button', { name: /Enviar avaliação/i })).not.toBeInTheDocument();

        irPara('Final');
        const enviar = screen.getByRole('button', { name: /Enviar avaliação/i });
        expect(enviar).toBeDisabled();

        preencherTudo();
        irPara('Final');
        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeEnabled();
    });

    it('envia as respostas e as recomendações, sem a nota final (calculada no servidor)', async () => {
        concluirAvaliacao.mockResolvedValue({
            ...emAndamento().avaliacao, status: 'concluida', status_label: 'Concluída', nota: 2.15,
        });
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();

        preencherTudo();
        fireEvent.change(screen.getByLabelText(/Recomendações, dicas e comentários sobre o vídeo/), {
            target: { value: 'Áudio oscila' },
        });
        irPara('Final');
        fireEvent.change(screen.getByLabelText(/faça recomendações/), { target: { value: '   ' } });

        fireEvent.click(screen.getByRole('button', { name: /Enviar avaliação/i }));
        // Confirmação antes de enviar (envio é irreversível).
        fireEvent.click(await screen.findByRole('button', { name: /^Enviar$/i }));

        await waitFor(() => expect(concluirAvaliacao).toHaveBeenCalled());
        const [, enviado] = concluirAvaliacao.mock.calls[0];
        expect(enviado).toEqual({
            respostas: { titulo_coerente: true, video_engajamento: 10, video_dominio: 10 },
            comentario_video: 'Áudio oscila',
            comentario_projeto: null, // só espaços vira nulo
            area_correta: true, area_sugerida_id: null,
            subarea_correta: null, subarea_sugerida_id: null,
        });
        expect(enviado).not.toHaveProperty('nota');
    });

    it('leva o avaliador à seção da pergunta recusada pelo servidor', async () => {
        concluirAvaliacao.mockRejectedValue({
            response: { data: { message: 'Falta responder.', errors: { 'respostas.video_dominio': ['Campo obrigatório.'] } } },
        });
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();

        preencherTudo();
        irPara('Final');
        fireEvent.click(screen.getByRole('button', { name: /Enviar avaliação/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Enviar$/i }));

        // O wizard volta para a seção do erro e mostra a mensagem na pergunta.
        expect(await screen.findByText('Campo obrigatório.')).toBeInTheDocument();
        expect(screen.getByText('Passo 3 de 4')).toBeInTheDocument();
    });

    it('mostra a rubrica em leitura quando a avaliação já foi concluída', async () => {
        getAvaliacao.mockResolvedValue({
            avaliacao: {
                ...emAndamento().avaliacao,
                status: 'concluida', status_label: 'Concluída', nota: 2.15,
                respostas: { titulo_coerente: true, video_engajamento: 10, video_dominio: 10 },
                comentario_video: 'Áudio oscila',
                area_correta: true, area_sugerida: null,
            },
            projeto: PROJETO,
            rubrica: RUBRICA,
        });
        render(<AvaliacaoModal avaliacaoId={1} />);

        expect(await screen.findByText('Avaliação enviada')).toBeInTheDocument();
        expect(screen.getByText('Sim')).toBeInTheDocument();
        expect(screen.getAllByText('10 — Muito bom')).toHaveLength(2);
        expect(screen.getByText('Áudio oscila')).toBeInTheDocument();
        expect(screen.getByText('2,15 de 2,15')).toBeInTheDocument();
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

        await esperarWizard();
        expect(screen.queryByTitle('Pré-visualização do vídeo')).not.toBeInTheDocument();
    });

    it('deixa recolher os dados do projeto para sobrar espaço à rubrica', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        render(<AvaliacaoModal avaliacaoId={1} />);

        await esperarWizard();
        expect(screen.getByText('Uma síntese do projeto.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Ocultar/ }));
        expect(screen.queryByText('Uma síntese do projeto.')).not.toBeInTheDocument();
        // A rubrica continua ali.
        expect(screen.getByText('A área do conhecimento está correta?')).toBeInTheDocument();
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
        await esperarWizard();

        preencherTudo();
        irPara('Geral — início');
        negar(0);
        irPara('Final');
        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeDisabled();

        irPara('Geral — início');
        fireEvent.change(await screen.findByLabelText(/Área correta/), { target: { value: '2' } });
        irPara('Final');
        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeEnabled();
    });

    it('marcar a subárea como incorreta exige a sugestão', async () => {
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();

        preencherTudo();
        irPara('Final');
        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeEnabled(); // subárea é opcional

        irPara('Geral — início');
        negar(1);
        expect(await screen.findByText('Subárea correta')).toBeInTheDocument();
        irPara('Final');
        expect(screen.getByRole('button', { name: /Enviar avaliação/i })).toBeDisabled();
    });

    it('envia a área sugerida no payload', async () => {
        concluirAvaliacao.mockResolvedValue({ ...emAndamento().avaliacao, status: 'concluida', nota: 2 });
        render(<AvaliacaoModal avaliacaoId={1} />);
        await esperarWizard();

        preencherTudo();
        irPara('Geral — início');
        negar(0);
        fireEvent.change(await screen.findByLabelText(/Área correta/), { target: { value: '2' } });

        irPara('Final');
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

    it('salva o preenchimento parcial de qualquer passo, sem exigir nada', async () => {
        getAvaliacao.mockResolvedValue(emAndamento());
        salvarRascunhoAvaliacao.mockResolvedValue(emAndamento({ respostas: { video_engajamento: 8 } }).avaliacao);
        render(<AvaliacaoModal avaliacaoId={1} />);

        await esperarWizard();
        irPara('Vídeo');
        pontuar(0, 8);

        const rascunho = screen.getByRole('button', { name: /Salvar rascunho/i });
        expect(rascunho).toBeEnabled();

        fireEvent.click(rascunho);
        await waitFor(() => expect(salvarRascunhoAvaliacao).toHaveBeenCalled());
        const [, enviado] = salvarRascunhoAvaliacao.mock.calls[0];
        expect(enviado.respostas).toEqual({ video_engajamento: 8 });
        expect(await screen.findByText(/Rascunho salvo/)).toBeInTheDocument();
    });

    it('recupera o rascunho salvo ao reabrir', async () => {
        getAvaliacao.mockResolvedValue(emAndamento({
            respostas: { video_engajamento: 8 },
            comentario_video: 'Faltou legenda.',
            area_correta: false, area_sugerida_id: 2,
            rascunho_em: '2026-08-21T12:00:00-04:00',
        }));
        render(<AvaliacaoModal avaliacaoId={1} />);

        await esperarWizard();
        // A resposta "área incorreta" volta marcada, com o select da sugestão aberto.
        expect(screen.getAllByRole('radio', { name: /Não, está incorreta/i })[0]).toBeChecked();
        // O valor só cola depois que o catálogo de áreas chega (carga assíncrona).
        const sugestao = await screen.findByLabelText(/Área correta/);
        await waitFor(() => expect(sugestao).toHaveValue('2'));

        irPara('Vídeo');
        const escala = screen.getAllByRole('radiogroup')[0];
        expect(within(escala).getByRole('radio', { name: 'Bom (8)' })).toBeChecked();
        expect(screen.getByLabelText(/Recomendações, dicas e comentários sobre o vídeo/)).toHaveValue('Faltou legenda.');
    });

    it('não mostra o botão de rascunho depois de enviada', async () => {
        getAvaliacao.mockResolvedValue({
            avaliacao: {
                ...emAndamento().avaliacao,
                status: 'concluida', status_label: 'Concluída', nota: 2.15,
                respostas: { titulo_coerente: true, video_engajamento: 10, video_dominio: 10 },
                area_correta: true,
            },
            projeto: PROJETO,
            rubrica: RUBRICA,
        });
        render(<AvaliacaoModal avaliacaoId={1} />);

        await screen.findByText('Avaliação enviada');
        expect(screen.queryByRole('button', { name: /Salvar rascunho/i })).not.toBeInTheDocument();
    });
});
